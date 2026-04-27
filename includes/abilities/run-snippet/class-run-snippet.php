<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: run-snippet
 *
 * Executes a one-off PHP snippet immediately within the current request and
 * returns its output inline. Snippets are ephemeral — they run once and are
 * not stored. For anything that should persist, use write_plugin instead.
 *
 * The snippet runs inside a PHP closure. Use `return` to pass back a value,
 * or `echo`/`print` to produce output. Any globals needed (e.g. $wpdb) must
 * be declared inside the snippet with `global $wpdb;`.
 */
class WP_AI_Daemon_Ability_Run_Snippet extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$description = sanitize_text_field( $params['description'] ?? '' );
		$code        = $params['code'] ?? '';

		if ( empty( trim( $code ) ) ) {
			return new WP_Error( 'missing_code', __( 'run_snippet requires code.', 'wp-ai-daemon' ) );
		}

		// Capture any output the snippet produces.
		ob_start();
		$return_value = null;

		try {
			// Wrap in a closure so the snippet can use `return`.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged
			$fn = eval( 'return function() { ' . $code . ' };' );

			if ( ! ( $fn instanceof \Closure ) ) {
				ob_end_clean();
				return new WP_Error( 'eval_failed', __( 'Snippet could not be compiled.', 'wp-ai-daemon' ) );
			}

			$return_value = $fn();

		} catch ( \ParseError $e ) {
			ob_end_clean();
			return new WP_Error(
				'syntax_error',
				sprintf(
					/* translators: %s: parse error message */
					__( 'Snippet syntax error: %s', 'wp-ai-daemon' ),
					$e->getMessage()
				)
			);
		} catch ( \Throwable $e ) {
			ob_end_clean();
			return new WP_Error(
				'snippet_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Snippet error: %s', 'wp-ai-daemon' ),
					$e->getMessage()
				)
			);
		}

		$echoed = trim( (string) ob_get_clean() );

		// Build the output string from echoed content and/or return value.
		$parts = [];

		if ( $echoed !== '' ) {
			$parts[] = $echoed;
		}

		if ( $return_value !== null ) {
			if ( is_bool( $return_value ) ) {
				$parts[] = $return_value ? 'true' : 'false';
			} elseif ( is_scalar( $return_value ) ) {
				$parts[] = (string) $return_value;
			} else {
				$parts[] = print_r( $return_value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}

		$output = implode( "\n", $parts );

		if ( $output === '' ) {
			$output = '(no output)';
		}

		do_action( 'wp_ai_daemon_snippet_executed', $description, $output );

		return [
			'description' => $description,
			'output'      => $output,
			'code'        => $code,
		];
	}

	public function get_ability_args(): array {
		return [
			'label'        => __( 'Run PHP Snippet', 'wp-ai-daemon' ),
			'description'  => __( 'Executes a PHP snippet within the current request and returns the output inline.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'description' => [ 'type' => 'string', 'description' => 'One-line summary of what this snippet does — shown in the chat UI.' ],
					'code'        => [ 'type' => 'string', 'description' => 'Raw PHP with no opening <?php tag. Use return to pass back a value, or echo/print for output. Declare globals you need (e.g. global $wpdb;).' ],
				],
				'required' => [ 'code' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'description' => [ 'type' => 'string' ],
					'output'      => [ 'type' => 'string' ],
				],
			],
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => [
					'readonly'     => false,
					'destructive'  => true,
					'idempotent'   => false,
					'instructions' => $this->load_prompt(),
				],
			],
		];
	}
}
