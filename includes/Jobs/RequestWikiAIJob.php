<?php

namespace Miraheze\CreateWiki\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\Job;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MessageLocalizer;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Wikimedia\Stats\StatsFactory;
use function array_reverse;
use function htmlspecialchars;
use function implode;
use function json_decode;
use function json_encode;
use function Sentry\startTransaction;
use function sprintf;
use function str_replace;
use function substr;
use function trim;
use const ENT_QUOTES;

class RequestWikiAIJob extends Job {

	public const JOB_NAME = 'RequestWikiAIJob';

	private readonly int $id;
	private readonly MessageLocalizer $messageLocalizer;

	public function __construct(
		array $params,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly StatsFactory $statsFactory,
		private readonly WikiRequestManager $wikiRequestManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->id = $params['id'];
		$this->messageLocalizer = RequestContext::getMain();
	}

	/** @inheritDoc */
	public function run(): bool {
		if ( !$this->config->get( ConfigNames::AIEnabled ) ) {
			return true;
		}

		$apiKey = $this->config->get( ConfigNames::OpenAIConfig )['apikey'] ?? '';
		if ( !$apiKey ) {
			$this->logger->error( 'CreateWiki AI: OpenAI API key is not configured.' );
			$this->setLastError( 'OpenAI API key is missing.' );
			return true;
		}

		$this->wikiRequestManager->loadFromId( $this->id );

		// Re-reviews are triggered when the requester comments on or updates a
		// request the AI previously deferred or asked for more details on. Cap
		// the number of re-reviews so that a request the AI keeps bouncing back
		// is escalated to a human instead of being re-reviewed indefinitely.
		$isReReview = (bool)( $this->params['rereview'] ?? false );
		if ( $isReReview ) {
			$maxReReviews = (int)$this->config->get( ConfigNames::AIMaxReReviews );
			if ( $this->wikiRequestManager->getAIReReviewCount() >= $maxReReviews ) {
				$this->logger->debug(
					'CreateWiki AI: Request {id} reached the re-review limit and now needs human review.',
					[ 'id' => $this->id ]
				);
				$this->placeOnHold(
					'This request has been re-reviewed by AI the maximum number of times ' .
					'and now requires manual review by a human.'
				);
				$this->statsFactory->getCounter( 'createwiki_ai_outcome_total' )
					->setLabel( 'outcome', 'maxreviews' )
					->increment();
				return true;
			}

			$this->wikiRequestManager->incrementAIReReviewCount();
		}

		$sentry = $this->beginSentryTrace();
		$result = $this->queryOpenAI(
			$apiKey,
			$this->config->get( ConfigNames::DeferredSubjects ),
			$isReReview
		);
		$this->endSentryTrace( $sentry, $result );

		if ( $result === null ) {
			$this->logger->error(
				'CreateWiki AI: Failed to get a valid response from OpenAI for request {id}.',
				[ 'id' => $this->id ]
			);
			$this->placeOnHold( 'This request could not be automatically reviewed and has been placed on hold for manual attention.' );
			$this->statsFactory->getCounter( 'createwiki_ai_outcome_total' )
				->setLabel( 'outcome', 'error' )
				->increment();
			return true;
		}

		$action = $result['decision']['action'] ?? 'defer';
		$comment = $result['decision']['comment'] ?? '';

		$this->logger->debug(
			'AI decision for wiki request {id}: {action}',
			[
				'action' => $action,
				'id' => $this->id,
			]
		);

		$systemUser = User::newSystemUser( 'CreateWiki AI' );

		switch ( $action ) {
			case 'accept':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->approve(
					comment: $comment,
					user: $systemUser
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				break;

			case 'decline':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->decline(
					comment: $comment,
					user: $systemUser
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				break;

			case 'moredetails':
				$this->wikiRequestManager->startQueryBuilder();
				$this->wikiRequestManager->moredetails(
					comment: $comment,
					user: $systemUser
				);
				$this->wikiRequestManager->tryExecuteQueryBuilder();
				break;

			case 'defer':
			default:
				$this->placeOnHold( $comment );
				break;
		}

		// Record the decision in the request history so that, if the request
		// is later edited and resubmitted, the next re-review has the full
		// context of what the AI previously decided and why.
		$this->wikiRequestManager->addRequestHistory(
			action: 'ai-decision',
			details: $this->messageLocalizer->msg( 'requestwiki-ai-decision-history' )
				->params( $action, $comment )
				->inContentLanguage()
				->parse(),
			user: $systemUser
		);

		$this->statsFactory->getCounter( 'createwiki_ai_outcome_total' )
			->setLabel( 'outcome', $action )
			->increment();

		return true;
	}

	private function beginSentryTrace(): ?array {
		if ( !$this->config->get( ConfigNames::EnableSentry ) || !class_exists( SentrySdk::class ) ) {
			return null;
		}

		$txContext = new TransactionContext();
		$txContext->setName( 'RequestWikiAIJob' );
		$txContext->setOp( 'queue.process' );
		$txContext->setSampled( true );
		$transaction = startTransaction( $txContext );
		SentrySdk::getCurrentHub()->setSpan( $transaction );

		$spanContext = new SpanContext();
		$spanContext->setOp( 'gen_ai.invoke_agent' );
		$spanContext->setDescription( 'wiki_request_review' );
		$span = $transaction->startChild( $spanContext );
		$span->setData( [
			'gen_ai.system' => 'openai',
			'gen_ai.operation.name' => 'chat',
			'gen_ai.request.model' => $this->config->get( ConfigNames::OpenAIConfig )['model'] ?? 'gpt-5.4-mini',
		] );

		return [ 'transaction' => $transaction, 'span' => $span ];
	}

	private function endSentryTrace( ?array $sentry, ?array $result ): void {
		if ( $sentry === null ) {
			return;
		}

		$span = $sentry['span'];
		$transaction = $sentry['transaction'];

		if ( $result !== null ) {
			$usage = $result['usage'] ?? [];
			$span->setData( [
				'gen_ai.usage.input_tokens' => $usage['input_tokens'] ?? 0,
				'gen_ai.usage.output_tokens' => $usage['output_tokens'] ?? 0,
			] );
			$span->setStatus( SpanStatus::ok() );
		} else {
			$span->setStatus( SpanStatus::internalError() );
		}

		$span->finish();
		$transaction->finish();
	}

	private function placeOnHold( string $comment ): void {
		$this->wikiRequestManager->startQueryBuilder();
		$this->wikiRequestManager->onhold(
			comment: $comment,
			user: User::newSystemUser( 'CreateWiki AI' )
		);
		$this->wikiRequestManager->tryExecuteQueryBuilder();
	}

	/**
	 * Build a plain-text summary of the request's change history and prior AI
	 * decisions, oldest first, for inclusion in the re-review prompt.
	 */
	private function buildHistoryContext(): string {
		$history = $this->wikiRequestManager->getRequestHistory();
		if ( !$history ) {
			return '';
		}

		// getRequestHistory() returns the newest entry first; present the
		// timeline chronologically so the model reads it in order.
		$entries = [];
		foreach ( array_reverse( $history ) as $entry ) {
			$details = trim( str_replace( [ "\r\n", "\r" ], "\n", $entry['details'] ) );
			$entries[] = sprintf(
				'[%s] %s by %s: %s',
				$entry['timestamp'],
				$entry['action'],
				$entry['user']->getName(),
				$details
			);
		}

		return implode( "\n", $entries );
	}

	private function queryOpenAI( string $apiKey, array $deferredSubjects, bool $isReReview ): ?array {
		$openAIConfig = $this->config->get( ConfigNames::OpenAIConfig );
		$model = $openAIConfig['model'] ?? 'gpt-5.4-mini';
		$reasoning = $openAIConfig['reasoning'] ?? null;

		$reason = trim( str_replace( [ "\r\n", "\r" ], "\n", $this->wikiRequestManager->getReason() ) );

		$prompt = sprintf(
			'Wiki name: "%s". Subdomain: "%s". Language: "%s". Category: "%s". ' .
			'Private wiki: "%s". Focuses on real people or groups: "%s". ' .
			'Description: "%s".',
			htmlspecialchars( $this->wikiRequestManager->getSitename(), ENT_QUOTES ),
			htmlspecialchars( substr( $this->wikiRequestManager->getDBname(), 0, -4 ), ENT_QUOTES ),
			htmlspecialchars( $this->wikiRequestManager->getLanguage(), ENT_QUOTES ),
			htmlspecialchars( $this->wikiRequestManager->getCategory(), ENT_QUOTES ),
			$this->wikiRequestManager->isPrivate() ? 'Yes' : 'No',
			$this->wikiRequestManager->isBio() ? 'Yes' : 'No',
			htmlspecialchars( $reason, ENT_QUOTES )
		);

		// On a re-review the request has been edited and resubmitted (or the
		// requester responded to a deferred/more-details request). Give the
		// model the history of changes and prior AI decisions so it can take
		// the requester's response into account instead of reviewing blind.
		if ( $isReReview ) {
			$history = $this->buildHistoryContext();
			if ( $history !== '' ) {
				$prompt .= "\n\nThis request has been edited and resubmitted for re-review. " .
					"The following is the history of changes to the request and any previous AI " .
					"decisions, oldest first:\n" . $history;
			}
		}

		$systemPrompt = $this->config->get( ConfigNames::AISystemPrompt );

		if ( $deferredSubjects ) {
			$systemPrompt .= ' Always choose "defer" for requests related to any of the following subjects: ' .
				implode( ', ', $deferredSubjects ) . '.';
		}

		$payload = [
			'model' => $model,
			'input' => [
				[
					'role' => 'system',
					'content' => $systemPrompt,
				],
				[
					'role' => 'user',
					'content' => $prompt,
				],
			],
			'text' => [
				'format' => [
					'type' => 'json_schema',
					'name' => 'wiki_request_decision',
					'schema' => [
						'type' => 'object',
						'properties' => [
							'action' => [
								'type' => 'string',
								'enum' => [ 'accept', 'decline', 'moredetails', 'defer' ],
							],
							'comment' => [
								'type' => 'string',
							],
						],
						'required' => [ 'action', 'comment' ],
						'additionalProperties' => false,
					],
					'strict' => true,
				],
			],
		];

		if ( $reasoning ) {
			$payload['reasoning'] = $reasoning;
		}

		$body = json_encode( $payload );

		$request = $this->httpRequestFactory->createMultiClient(
			[ 'proxy' => $this->config->get( MainConfigNames::HTTPProxy ) ]
		)->run(
			[
				'url' => 'https://api.openai.com/v1/responses',
				'method' => 'POST',
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Content-Type' => 'application/json',
				],
				'body' => $body,
			],
			[ 'reqTimeout' => 90 ]
		);

		$this->logger->debug(
			'OpenAI Responses API replied for request {id} with HTTP {code}.',
			[
				'id' => $this->id,
				'code' => $request['code'],
			]
		);

		if ( $request['code'] !== 200 ) {
			$this->logger->error(
				'CreateWiki AI: OpenAI request failed with HTTP {code}: {body}',
				[
					'code' => $request['code'],
					'body' => $request['body'] ?? '',
				]
			);
			return null;
		}

		$data = (array)json_decode( $request['body'], true );

		// Reasoning models prepend a reasoning item to output before the message.
		// Find the message item by type rather than assuming index 0.
		$text = null;
		foreach ( $data['output'] ?? [] as $outputItem ) {
			if ( ( $outputItem['type'] ?? '' ) === 'message' ) {
				$text = $outputItem['content'][0]['text'] ?? null;
				break;
			}
		}

		if ( $text === null ) {
			$this->logger->error(
				'CreateWiki AI: Unexpected response structure from OpenAI: {body}',
				[ 'body' => $request['body'] ?? '' ]
			);
			return null;
		}

		return [
			'decision' => (array)json_decode( $text, true ),
			'usage' => $data['usage'] ?? [],
		];
	}
}
