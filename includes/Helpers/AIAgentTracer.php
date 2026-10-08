<?php

namespace Miraheze\CreateWiki\Helpers;

use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function is_array;
use function is_string;
use function json_encode;
use function mb_substr;
use function microtime;
use function Sentry\startTransaction;
use function str_ends_with;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @see https://docs.sentry.io/product/agents/
 * @see https://develop.sentry.dev/sdk/telemetry/traces/modules/ai-agents/
 */
class AIAgentTracer {

	public const AGENT_NAME = 'CreateWiki AI';

	private const PROVIDER = 'anthropic';
	private const ORIGIN = 'manual.ai.createwiki';

	private const MAX_RESULT_LENGTH = 2000;

	private readonly bool $enabled;
	private ?Span $agentSpan = null;
	private ?Span $previousSpan = null;
	private bool $ownsTransaction = false;

	public function __construct(
		private readonly int $requestId
	) {
		$this->enabled = class_exists( SentrySdk::class ) &&
			SentrySdk::getCurrentHub()->getClient() !== null;
	}

	public function startAgent( string $systemPrompt, string $userMessage, array $tools ): void {
		if ( !$this->enabled ) {
			return;
		}

		$hub = SentrySdk::getCurrentHub();
		$this->previousSpan = $hub->getSpan();
		$description = 'invoke_agent ' . self::AGENT_NAME;

		if ( $this->previousSpan !== null ) {
			$this->agentSpan = $this->previousSpan->startChild(
				SpanContext::make()
					->setOp( 'gen_ai.invoke_agent' )
					->setDescription( $description )
					->setOrigin( self::ORIGIN )
			);
		} else {
			$context = TransactionContext::make()
				->setName( $description )
				->setOp( 'gen_ai.invoke_agent' )
				->setOrigin( self::ORIGIN )
				->setSource( TransactionSource::task() );
			$this->agentSpan = startTransaction( $context );
			$this->ownsTransaction = true;
		}

		$this->agentSpan->setData( [
			'gen_ai.operation.name' => 'invoke_agent',
			'gen_ai.agent.name' => self::AGENT_NAME,
			'gen_ai.conversation.id' => $this->conversationId(),
			'gen_ai.provider.name' => self::PROVIDER,
			'gen_ai.system_instructions' => $this->encode( [
				[ 'type' => 'text', 'content' => $systemPrompt ],
			] ),
			'gen_ai.input.messages' => $this->encode( [
				[ 'role' => 'user', 'parts' => [ [ 'type' => 'text', 'content' => $userMessage ] ] ],
			] ),
			'gen_ai.tool.definitions' => $this->encode( $this->toolDefinitions( $tools ) ),
			'createwiki.request.id' => $this->requestId,
		] );

		$hub->setSpan( $this->agentSpan );
	}

	public function finishAgent( ?array $decision, ?string $model, ?string $error ): void {
		if ( !$this->agentSpan ) {
			return;
		}

		$data = [];
		if ( $model !== null ) {
			$data['gen_ai.response.model'] = $model;
		}

		if ( $decision !== null ) {
			$data['gen_ai.output.messages'] = $this->encode( [ [
				'role' => 'assistant',
				'parts' => [ [ 'type' => 'text', 'content' => $this->encode( $decision ) ] ],
			] ] );
			$data['createwiki.ai.outcome'] = (string)( $decision['outcome'] ?? 'unknown' );
			$data['createwiki.ai.confidence'] = (int)( $decision['confidence'] ?? 0 );
		}

		if ( $error !== null ) {
			$data['createwiki.ai.error'] = $error;
		}

		$this->agentSpan->setData( $data );
		$this->agentSpan->setStatus( $decision !== null ? SpanStatus::ok() : SpanStatus::internalError() );
		$this->agentSpan->finish();

		SentrySdk::getCurrentHub()->setSpan( $this->ownsTransaction ? null : $this->previousSpan );
		$this->agentSpan = null;
		$this->previousSpan = null;
	}

	public function startChat( array $body ): ?Span {
		if ( !$this->agentSpan ) {
			return null;
		}

		$model = (string)$body['model'];
		$span = $this->agentSpan->startChild(
			SpanContext::make()
				->setOp( 'gen_ai.chat' )
				->setDescription( 'chat ' . $model )
				->setOrigin( self::ORIGIN )
		);

		$data = [
			'gen_ai.operation.name' => 'chat',
			'gen_ai.agent.name' => self::AGENT_NAME,
			'gen_ai.conversation.id' => $this->conversationId(),
			'gen_ai.provider.name' => self::PROVIDER,
			'gen_ai.request.model' => $model,
			'gen_ai.request.max_tokens' => $body['max_tokens'] ?? null,
			'gen_ai.system_instructions' => $this->encode( [
				[ 'type' => 'text', 'content' => (string)( $body['system'] ?? '' ) ],
			] ),
			'gen_ai.input.messages' => $this->encode( $this->convertMessages( $body['messages'] ?? [] ) ),
			'gen_ai.tool.definitions' => $this->encode( $this->toolDefinitions( $body['tools'] ?? [] ) ),
			'createwiki.request.id' => $this->requestId,
		];

		if ( isset( $body['output_config']['effort'] ) ) {
			$data['gen_ai.request.reasoning.level'] = $body['output_config']['effort'];
		}

		$span->setData( array_filter( $data, static fn ( $value ) => $value !== null ) );
		return $span;
	}

	public function finishChat( ?Span $span, ?array $response ): void {
		if ( !$span ) {
			return;
		}

		if ( !$response ) {
			$span->setStatus( SpanStatus::internalError() );
			$span->finish();
			return;
		}

		$content = $response['content'] ?? [];
		$usage = $response['usage'] ?? [];
		$cachedInput = (int)( $usage['cache_read_input_tokens'] ?? 0 );
		$cacheWrite = (int)( $usage['cache_creation_input_tokens'] ?? 0 );

		$inputTokens = (int)( $usage['input_tokens'] ?? 0 ) + $cachedInput + $cacheWrite;
		$outputTokens = (int)( $usage['output_tokens'] ?? 0 );

		$data = [
			'gen_ai.response.id' => $response['id'] ?? null,
			'gen_ai.response.model' => $response['model'] ?? null,
			'gen_ai.response.finish_reasons' => $this->encode( [ $response['stop_reason'] ?? 'unknown' ] ),
			'gen_ai.output.messages' => $this->encode( $this->convertMessages( [
				[ 'role' => 'assistant', 'content' => $content ],
			] ) ),
			'gen_ai.usage.input_tokens' => $inputTokens,
			'gen_ai.usage.cache_read.input_tokens' => $cachedInput,
			'gen_ai.usage.cache_creation.input_tokens' => $cacheWrite,
			'gen_ai.usage.output_tokens' => $outputTokens,
			'gen_ai.usage.total_tokens' => $inputTokens + $outputTokens,
			'anthropic.usage.web_search_requests' => $usage['server_tool_use']['web_search_requests'] ?? null,
			'anthropic.usage.web_fetch_requests' => $usage['server_tool_use']['web_fetch_requests'] ?? null,
			'anthropic.stop_details.category' => $response['stop_details']['category'] ?? null,
		];

		$span->setData( array_filter( $data, static fn ( $value ) => $value !== null ) );
		$this->recordToolCalls( $span, $content );

		$span->setStatus( ( $response['stop_reason'] ?? null ) === 'refusal' ?
			SpanStatus::permissionDenied() : SpanStatus::ok()
		);
		$span->finish();
	}

	private function recordToolCalls( Span $chatSpan, array $content ): void {
		$results = [];
		foreach ( $content as $block ) {
			if ( isset( $block['tool_use_id'] ) && str_ends_with( (string)( $block['type'] ?? '' ), '_tool_result' ) ) {
				$results[$block['tool_use_id']] = $block;
			}
		}

		$end = microtime( true );
		foreach ( $content as $block ) {
			$type = $block['type'] ?? null;
			if ( $type !== 'tool_use' && $type !== 'server_tool_use' ) {
				continue;
			}

			$name = (string)( $block['name'] ?? 'unknown' );
			$toolSpan = $chatSpan->startChild(
				SpanContext::make()
					->setOp( 'gen_ai.execute_tool' )
					->setDescription( 'execute_tool ' . $name )
					->setOrigin( self::ORIGIN )
					->setStartTimestamp( $chatSpan->getStartTimestamp() )
			);

			$data = [
				'gen_ai.operation.name' => 'execute_tool',
				'gen_ai.agent.name' => self::AGENT_NAME,
				'gen_ai.conversation.id' => $this->conversationId(),
				'gen_ai.tool.name' => $name,
				'gen_ai.tool.type' => $type === 'server_tool_use' ? 'extension' : 'function',
				'gen_ai.tool.call.id' => $block['id'] ?? null,
				'gen_ai.tool.call.arguments' => $this->encode( $block['input'] ?? [] ),
			];

			$result = $results[$block['id'] ?? ''] ?? null;
			if ( $result !== null ) {
				$data['gen_ai.tool.call.result'] = $this->encode( $this->summariseResult( $result ) );
			}

			$toolSpan->setData( array_filter( $data, static fn ( $value ) => $value !== null ) );
			$toolSpan->setStatus( $this->isErrorResult( $result ) ?
				SpanStatus::internalError() : SpanStatus::ok()
			);
			$toolSpan->finish( $end );
		}
	}

	private function convertMessages( array $messages ): array {
		return array_map( function ( array $message ): array {
			$role = (string)( $message['role'] ?? 'user' );
			$content = $message['content'] ?? [];
			if ( is_string( $content ) ) {
				return [ 'role' => $role, 'parts' => [ [ 'type' => 'text', 'content' => $content ] ] ];
			}

			$parts = [];
			foreach ( $content as $block ) {
				$type = $block['type'] ?? null;
				if ( $type === 'text' ) {
					$parts[] = [ 'type' => 'text', 'content' => (string)( $block['text'] ?? '' ) ];
				} elseif ( $type === 'thinking' && ( $block['thinking'] ?? '' ) !== '' ) {
					$parts[] = [ 'type' => 'reasoning', 'content' => (string)$block['thinking'] ];
				} elseif ( $type === 'tool_use' || $type === 'server_tool_use' ) {
					$parts[] = [
						'type' => 'tool_call',
						'id' => $block['id'] ?? null,
						'name' => $block['name'] ?? null,
						'arguments' => $block['input'] ?? [],
					];
				} elseif ( isset( $block['tool_use_id'] ) ) {
					$parts[] = [
						'type' => 'tool_call_response',
						'id' => $block['tool_use_id'],
						'result' => $this->summariseResult( $block ),
					];
				}
			}

			return [ 'role' => $role, 'parts' => $parts ];
		}, $messages );
	}

	private function summariseResult( array $block ): mixed {
		$content = $block['content'] ?? null;
		$type = $block['type'] ?? '';

		if ( $type === 'web_search_tool_result' && is_array( $content ) && isset( $content[0] ) ) {
			return array_map( static fn ( array $hit ): array => array_filter( [
				'title' => $hit['title'] ?? null,
				'url' => $hit['url'] ?? null,
				'page_age' => $hit['page_age'] ?? null,
			] ), $content );
		}

		if ( $type === 'web_fetch_tool_result' && ( $content['type'] ?? null ) === 'web_fetch_result' ) {
			$document = $content['content'] ?? [];
			$text = $document['source']['data'] ?? null;
			return array_filter( [
				'url' => $content['url'] ?? null,
				'title' => $document['title'] ?? null,
				'retrieved_at' => $content['retrieved_at'] ?? null,
				'content' => is_string( $text ) ? mb_substr( $text, 0, self::MAX_RESULT_LENGTH ) : null,
			] );
		}

		if ( is_string( $content ) ) {
			return mb_substr( $content, 0, self::MAX_RESULT_LENGTH );
		}

		return $content;
	}

	private function isErrorResult( ?array $block ): bool {
		$content = $block['content'] ?? null;
		return is_array( $content ) && isset( $content['error_code'] );
	}

	private function toolDefinitions( array $tools ): array {
		return array_values( array_map( static fn ( array $tool ): array => array_filter( [
			'name' => $tool['name'] ?? null,
			'type' => $tool['type'] ?? 'function',
			'description' => $tool['description'] ?? null,
		] ), $tools ) );
	}

	private function conversationId(): string {
		return 'createwiki-request-' . $this->requestId;
	}

	private function encode( mixed $value ): string {
		return (string)json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
