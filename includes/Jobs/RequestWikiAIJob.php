<?php

namespace Miraheze\CreateWiki\Jobs;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\Job;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Psr\Log\LoggerInterface;
use Wikimedia\Stats\StatsFactory;
use function htmlspecialchars;
use function implode;
use function json_decode;
use function json_encode;
use function sprintf;
use function str_replace;
use function substr;
use function trim;
use const ENT_QUOTES;

class RequestWikiAIJob extends Job {

	public const JOB_NAME = 'RequestWikiAIJob';

	private readonly int $id;

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

		$decision = $this->queryOpenAI( $apiKey, $this->config->get( ConfigNames::DeferredSubjects ) );

		if ( $decision === null ) {
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

		$action = $decision['action'] ?? 'defer';
		$comment = $decision['comment'] ?? '';

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

			case 'defer':
			default:
				$this->placeOnHold( $comment );
				break;
		}

		$this->statsFactory->getCounter( 'createwiki_ai_outcome_total' )
			->setLabel( 'outcome', $action )
			->increment();

		return true;
	}

	private function placeOnHold( string $comment ): void {
		$this->wikiRequestManager->startQueryBuilder();
		$this->wikiRequestManager->onhold(
			comment: $comment,
			user: User::newSystemUser( 'CreateWiki AI' )
		);
		$this->wikiRequestManager->tryExecuteQueryBuilder();
	}

	private function queryOpenAI( string $apiKey, array $deferredSubjects ): ?array {
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

		$systemPrompt =
			'You are reviewing wiki creation requests for a MediaWiki-based wiki hosting platform. ' .
			'Evaluate each request and choose one of three actions: ' .
			'"accept" — the request is legitimate, clearly written, and meets standard wiki hosting requirements; ' .
			'"decline" — the request is clearly abusive, spam, policy-violating, or otherwise unacceptable; ' .
			'"defer" — the request is ambiguous, borderline, or requires human judgement to decide. ' .
			'Provide a concise comment explaining the decision. ' .
			'This comment will be shown to the requester, so keep it professional and informative.';

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
								'enum' => [ 'accept', 'decline', 'defer' ],
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

		return (array)json_decode( $text, true );
	}
}
