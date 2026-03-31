<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified AI client supporting OpenAI and Azure OpenAI.
 * All configuration is pulled from plugin settings.
 */
class AiClient {

	private static ?AiClient $instance = null;

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Send a chat completion request.
	 *
	 * @param array  $messages  Messages array.
	 * @param array  $options   Override options: model, temperature, response_format, max_tokens.
	 * @return array|WP_Error   Response data or error.
	 */
	public function chat( array $messages, array $options = [] ) {
		$provider = Settings::get( 'ai_provider', 'openai' );

		if ( 'azure' === $provider ) {
			return $this->azure_chat( $messages, $options );
		}

		return $this->openai_chat( $messages, $options );
	}

	/**
	 * Shorthand for a single user message.
	 */
	public function complete( string $user_prompt, string $system_prompt = '', array $options = [] ) {
		$messages = [];

		$system = $system_prompt ?: Settings::get( 'ai_system_prompt' );
		if ( $system ) {
			$messages[] = [ 'role' => 'system', 'content' => $system ];
		}
		$messages[] = [ 'role' => 'user', 'content' => $user_prompt ];

		return $this->chat( $messages, $options );
	}

	// ─── OpenAI ──────────────────────────────────────────────────────────────

	private function openai_chat( array $messages, array $options ) {
		$key = Settings::get( 'openai_key' ) ?: ( defined( 'CEP_OPENAI_KEY' ) ? CEP_OPENAI_KEY : '' );

		if ( empty( $key ) ) {
			return new \WP_Error( 'cep_no_api_key', 'OpenAI API key is not configured.' );
		}

		$model    = $options['model'] ?? Settings::get( 'ai_model', 'gpt-4o' );
		$temp     = (float) ( $options['temperature'] ?? Settings::get( 'ai_temperature', 0.7 ) );
		$timeout  = (int) Settings::get( 'ai_timeout', 90 );
		$retries  = (int) Settings::get( 'ai_retries', 3 );

		$body = [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => $temp,
		];

		if ( isset( $options['response_format'] ) ) {
			$body['response_format'] = $options['response_format'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $options['max_tokens'];
		}

		return $this->request(
			'https://api.openai.com/v1/chat/completions',
			$body,
			[ 'Authorization' => 'Bearer ' . $key ],
			$timeout,
			$retries
		);
	}

	// ─── Azure OpenAI ────────────────────────────────────────────────────────

	private function azure_chat( array $messages, array $options ) {
		$key        = Settings::get( 'azure_key' ) ?: ( defined( 'CEP_AZURE_OPENAI_KEY' ) ? CEP_AZURE_OPENAI_KEY : '' );
		$endpoint   = Settings::get( 'azure_endpoint' ) ?: ( defined( 'CEP_AZURE_OPENAI_ENDPOINT' ) ? CEP_AZURE_OPENAI_ENDPOINT : '' );
		$api_ver    = Settings::get( 'azure_api_version', '2024-08-01-preview' );
		$deployment = $options['mini'] ?? false
			? ( Settings::get( 'azure_deployment_mini' ) ?: Settings::get( 'azure_deployment' ) )
			: Settings::get( 'azure_deployment' );

		if ( empty( $key ) || empty( $endpoint ) || empty( $deployment ) ) {
			return new \WP_Error( 'cep_azure_config', 'Azure OpenAI is not fully configured.' );
		}

		$url     = trailingslashit( $endpoint ) . 'openai/deployments/' . $deployment . '/chat/completions?api-version=' . $api_ver;
		$timeout = (int) Settings::get( 'ai_timeout', 90 );
		$retries = (int) Settings::get( 'ai_retries', 3 );
		$temp    = (float) ( $options['temperature'] ?? Settings::get( 'ai_temperature', 0.7 ) );

		$body = [
			'messages'    => $messages,
			'temperature' => $temp,
		];

		if ( isset( $options['response_format'] ) ) {
			$body['response_format'] = $options['response_format'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $options['max_tokens'];
		}

		return $this->request( $url, $body, [ 'api-key' => $key ], $timeout, $retries );
	}

	// ─── HTTP ────────────────────────────────────────────────────────────────

	private function request( string $url, array $body, array $extra_headers, int $timeout, int $retries ) {
		$headers = array_merge(
			[
				'Content-Type' => 'application/json',
				'User-Agent'   => 'ContentEnginePro/' . CEP_VERSION,
			],
			$extra_headers
		);

		$attempt = 0;
		$last_error = null;

		while ( $attempt <= $retries ) {
			if ( $attempt > 0 ) {
				// Exponential backoff: 5s, 15s, 30s
				sleep( min( 5 * ( 2 ** ( $attempt - 1 ) ), 30 ) );
			}

			$response = wp_remote_post(
				$url,
				[
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
					'timeout' => $timeout,
				]
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				$attempt++;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			if ( 200 !== (int) $code ) {
				$msg = $data['error']['message'] ?? "HTTP {$code}";
				$last_error = new \WP_Error( 'cep_ai_error', $msg, [ 'code' => $code ] );
				if ( 429 === (int) $code || 500 <= (int) $code ) {
					$attempt++;
					continue;
				}
				return $last_error;
			}

			$content = $data['choices'][0]['message']['content'] ?? '';
			Logger::log( 'AI request completed', 'debug', 'ai', [ 'model' => $data['model'] ?? '', 'tokens' => $data['usage']['total_tokens'] ?? 0 ] );

			return [
				'content' => $content,
				'model'   => $data['model'] ?? '',
				'tokens'  => $data['usage']['total_tokens'] ?? 0,
				'raw'     => $data,
			];
		}

		Logger::log( 'AI request failed after retries: ' . $last_error->get_error_message(), 'error', 'ai' );
		return $last_error;
	}
}
