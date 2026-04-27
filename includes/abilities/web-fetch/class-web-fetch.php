<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: web-fetch
 *
 * Fetches a URL using the WordPress HTTP API and stores the response body.
 * The result is injected back into the conversation context before the next LLM turn.
 */
class WP_AI_Daemon_Ability_Web_Fetch extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$url      = esc_url_raw( $params['url'] ?? '' );
		$store_as = sanitize_key( $params['store_as'] ?? 'fetched_content' );

		if ( empty( $url ) ) {
			return new WP_Error( 'missing_url', __( 'web_fetch requires a url parameter.', 'wp-ai-daemon' ) );
		}

		$response = wp_remote_get( $url, [
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:136.0) Gecko/20100101 Firefox/136.0',
			'sslverify'  => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'http_error',
				sprintf( __( 'HTTP %d fetching %s', 'wp-ai-daemon' ), $code, $url )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		// Strip HTML tags for cleaner LLM injection — keep meaningful text.
		$text = wp_strip_all_tags( $body );
		$text = preg_replace( '/\s{3,}/', "\n\n", $text );
		$text = trim( $text );

		// Truncate to avoid token overload — 8000 chars is ~2000 tokens.
		if ( strlen( $text ) > 8000 ) {
			$text = substr( $text, 0, 8000 ) . "\n\n[truncated]";
		}

		return [
			'url'      => $url,
			'store_as' => $store_as,
			'content'  => $text,
			'length'   => strlen( $text ),
		];
	}

	public function get_ability_args(): array {
		return [
			'label'        => __( 'Fetch URL', 'wp-ai-daemon' ),
			'description'  => __( 'Fetches the content of a URL via the WordPress HTTP API.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'url'      => [ 'type' => 'string', 'description' => 'The URL to fetch.' ],
					'store_as' => [ 'type' => 'string', 'description' => 'Variable name to reference the result by in subsequent steps.' ],
				],
				'required' => [ 'url' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'url'     => [ 'type' => 'string' ],
					'content' => [ 'type' => 'string' ],
					'length'  => [ 'type' => 'integer' ],
				],
			],
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => [
					'readonly'     => true,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => $this->load_prompt(),
				],
			],
		];
	}
}
