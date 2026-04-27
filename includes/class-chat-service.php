<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Conversational mode — process a user message, execute actions synchronously,
 * and return results inline so the chat UI can surface them immediately.
 *
 * web_fetch loops back through the LLM so it can act on fetched content.
 * All other actions execute in the same HTTP request — no queue involved.
 */
class WP_AI_Daemon_Chat_Service {

	/**
	 * Maximum number of loopback (web_fetch / run_snippet) → LLM round-trips per chat turn.
	 */
	private const MAX_FETCH_LOOPS = 5;

	/**
	 * Process a single chat turn.
	 *
	 * @param int      $user_id         The user who sent the message.
	 * @param string   $message         The current user message.
	 * @param int|null $conversation_id Existing conversation, or null to create one.
	 *
	 * @return array|WP_Error  { conversation_id, message, actions[] }
	 */
	public function process( int $user_id, string $message, ?int $conversation_id ): array|WP_Error {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 300 );

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'no_ai_client',
				__( 'No AI provider configured. Please visit Settings → Connectors.', 'wp-ai-daemon' ),
				[ 'status' => 503 ]
			);
		}

		if ( get_current_user_id() !== $user_id ) {
			wp_set_current_user( $user_id );
		}

		$store = new WP_AI_Daemon_Conversation_Store();

		if ( ! $conversation_id ) {
			$conversation_id = $store->create( $message );
			if ( is_wp_error( $conversation_id ) ) {
				return $conversation_id;
			}
		}

		$history = $this->build_history( $store->get_messages( $conversation_id ) );

		[ $final, $all_executed, $loop ] = $this->run_agentic_loop( $message, $history );

		if ( is_wp_error( $final ) ) {
			return $final;
		}

		if ( $loop >= self::MAX_FETCH_LOOPS ) {
			WP_AI_Daemon_Logger::error( 'Chat hit loopback limit without settling.', [
				'conversation_id' => $conversation_id,
			] );
		}

		// Persist messages.
		$store->add_message( $conversation_id, [
			'role'      => 'user',
			'content'   => $message,
			'timestamp' => time(),
		] );

		$store->add_message( $conversation_id, [
			'role'      => 'assistant',
			'content'   => $final['message'],
			'actions'   => $all_executed,
			'timestamp' => time(),
		] );

		WP_AI_Daemon_Logger::info( 'Chat turn processed.', [
			'conversation_id' => $conversation_id,
			'fetch_loops'     => $loop,
			'actions'         => count( $all_executed ),
		] );

		return [
			'conversation_id' => $conversation_id,
			'message'         => $final['message'],
			'actions'         => $all_executed,
		];
	}

	// -------------------------------------------------------------------------
	// LLM call
	// -------------------------------------------------------------------------

	/**
	 * @param string $injected  web_fetch results to append after the user message.
	 */
	private function call_llm( string $message, array $history, string $injected = '' ): array|WP_Error {
		$prompt   = $this->build_prompt( $message, $history, $injected );
		$sys_inst = $this->get_system_instruction();

		WP_AI_Daemon_Logger::debug( 'LLM call — prompt.', [
			'system_instruction' => $sys_inst,
			'prompt'             => $prompt,
		] );

		$response = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $sys_inst )
			->as_json_response( $this->response_schema() )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		WP_AI_Daemon_Logger::debug( 'LLM call — raw response.', [
			'response' => $response,
		] );

		$data = json_decode( $response, true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['message'] ) ) {
			return new WP_Error( 'parse_error', __( 'Failed to parse the AI response. Please try again.', 'wp-ai-daemon' ) );
		}

		$actions_raw     = $data['actions_json'] ?? '';
		$data['actions'] = ! empty( $actions_raw )
			? ( json_decode( $actions_raw, true ) ?? [] )
			: [];

		unset( $data['actions_json'] );

		return $data;
	}

	// -------------------------------------------------------------------------
	// Agentic loop
	// -------------------------------------------------------------------------

	/**
	 * Run the loopback loop: call the LLM, execute actions, inject loopback results,
	 * repeat until there are no more loopback abilities or the limit is reached.
	 *
	 * @return array  [ WP_Error|array $final, array $all_executed, int $loop_count ]
	 */
	private function run_agentic_loop( string $message, array $history ): array {
		$injected     = '';
		$all_executed = [];
		$final        = null;
		$loop         = 0;

		for ( ; $loop < self::MAX_FETCH_LOOPS; $loop++ ) {
			$result = $this->call_llm( $message, $history, $injected );

			if ( is_wp_error( $result ) ) {
				return [ $result, $all_executed, $loop ];
			}

			[ $loopbacks, $immediate ] = $this->partition_actions( $result['actions'] ?? [] );

			$executed     = $this->execute_actions( $immediate );
			$all_executed = array_merge( $all_executed, $executed );

			if ( empty( $loopbacks ) ) {
				$final = $result;
				break;
			}

			[ $injected_lines, $snippet_results ] = $this->execute_loopbacks( $loopbacks, $loop );
			$all_executed = array_merge( $all_executed, $snippet_results );
			$injected     = implode( "\n\n", $injected_lines );
			$final        = $result;
		}

		if ( ! $final ) {
			return [ new WP_Error( 'loop_error', __( 'Chat loop ended without a response.', 'wp-ai-daemon' ) ), $all_executed, $loop ];
		}

		return [ $final, $all_executed, $loop ];
	}

	/**
	 * Split actions into loopback abilities (web-fetch, run-snippet) and immediate ones.
	 *
	 * @return array  [ $loopbacks[], $immediate[] ]
	 */
	private function partition_actions( array $actions ): array {
		$loopbacks = [];
		$immediate = [];

		$loopback_abilities = [
			'wp-ai-daemon/web-fetch',
			'wp-ai-daemon/run-snippet',
			'wp-ai-daemon/list-scheduled-actions',
		];

		foreach ( $actions as $action ) {
			$ability = $action['ability'] ?? '';
			if ( in_array( $ability, $loopback_abilities, true ) ) {
				$loopbacks[] = $action;
			} else {
				$immediate[] = $action;
			}
		}

		return [ $loopbacks, $immediate ];
	}

	/**
	 * Execute loopback actions (web-fetch and run-snippet) and return their output
	 * formatted for injection into the next LLM call.
	 *
	 * @return array  [ $injected_lines[], $snippet_executed[] ]
	 */
	private function execute_loopbacks( array $loopbacks, int $loop ): array {
		$injected_lines   = [];
		$snippet_executed = [];

		$fetcher         = wp_get_ability( 'wp-ai-daemon/web-fetch' );
		$snippet_ability = wp_get_ability( 'wp-ai-daemon/run-snippet' );

		$disabled = get_option( 'wpad_disabled_abilities', [] );

		foreach ( $loopbacks as $action ) {
			$ability = $action['ability'] ?? '';
			$params  = $action['params'] ?? [];

			if ( in_array( $ability, $disabled, true ) ) {
				$injected_lines[] = "[{$ability} is disabled and was not executed.]";
				continue;
			}

			if ( $ability === 'wp-ai-daemon/web-fetch' ) {
				$injected_lines[] = $this->execute_fetch( $fetcher, $params, $loop );
			} elseif ( $ability === 'wp-ai-daemon/run-snippet' ) {
				[ $line, $executed ] = $this->execute_snippet( $snippet_ability, $params, $loop );
				$injected_lines[]    = $line;
				if ( $executed ) {
					$snippet_executed[] = $executed;
				}
			} elseif ( $ability === 'wp-ai-daemon/list-scheduled-actions' ) {
				$injected_lines[] = $this->execute_list_scheduled_actions( $loop );
			}
		}

		return [ $injected_lines, $snippet_executed ];
	}

	/**
	 * Execute a single web-fetch and return the formatted injection line.
	 */
	private function execute_fetch( $fetcher, array $params, int $loop ): string {
		$store_as = sanitize_key( $params['store_as'] ?? 'fetched_content' );

		WP_AI_Daemon_Logger::info( 'web_fetch executed inline (chat).', [
			'url'  => $params['url'] ?? '',
			'loop' => $loop,
		] );

		$result = $fetcher ? $fetcher->execute( $params ) : new WP_Error( 'no_ability', 'web-fetch ability not found.' );

		if ( is_wp_error( $result ) ) {
			return "[web_fetch result for \"{$store_as}\": ERROR — {$result->get_error_message()}]";
		}

		return "[web_fetch result for \"{$store_as}\":\n{$result['content']}]";
	}

	/**
	 * Execute list-scheduled-actions and return the formatted injection line.
	 */
	private function execute_list_scheduled_actions( int $loop ): string {
		WP_AI_Daemon_Logger::info( 'list_scheduled_actions executed inline (chat).', [ 'loop' => $loop ] );

		$ability = wp_get_ability( 'wp-ai-daemon/list-scheduled-actions' );
		$result  = $ability ? $ability->execute( [] ) : new WP_Error( 'no_ability', 'list-scheduled-actions ability not found.' );

		if ( is_wp_error( $result ) ) {
			return "[list_scheduled_actions result: ERROR — {$result->get_error_message()}]";
		}

		return "[list_scheduled_actions result:\n{$result['summary']}]";
	}

	/**
	 * Execute a single run-snippet and return the formatted injection line plus
	 * the completed action record (or null on error).
	 *
	 * @return array  [ string $injection_line, array|null $executed_record ]
	 */
	private function execute_snippet( $snippet_ability, array $params, int $loop ): array {
		$description = sanitize_text_field( $params['description'] ?? 'snippet' );

		WP_AI_Daemon_Logger::info( 'run_snippet executed inline (chat).', [
			'description' => $description,
			'loop'        => $loop,
		] );

		$result = $snippet_ability ? $snippet_ability->execute( $params ) : new WP_Error( 'no_ability', 'run-snippet ability not found.' );

		if ( is_wp_error( $result ) ) {
			return [ "[run_snippet result for \"{$description}\": ERROR — {$result->get_error_message()}]", null ];
		}

		$executed = [
			'ability'           => 'wp-ai-daemon/run-snippet',
			'status'            => 'success',
			'requires_approval' => false,
			'result'            => $result,
		];

		return [ "[run_snippet result for \"{$description}\":\n{$result['output']}]", $executed ];
	}

	// -------------------------------------------------------------------------
	// Action execution
	// -------------------------------------------------------------------------

	/**
	 * @return array[]  Each item: { ability, status, requires_approval, result } | { ability, status, error }
	 */
	private function execute_actions( array $actions ): array {
		if ( empty( $actions ) ) {
			return [];
		}

		$results = [];

		foreach ( $actions as $action_obj ) {
			$ability_name = $action_obj['ability'] ?? '';
			$params       = $action_obj['params'] ?? [];

			if ( empty( $ability_name ) ) {
				$results[] = [ 'ability' => '', 'status' => 'error', 'error' => 'Missing ability name in response.' ];
				continue;
			}

			$ability = wp_get_ability( $ability_name );

			if ( ! $ability ) {
				WP_AI_Daemon_Logger::error( "Unknown ability: {$ability_name}" );
				$results[] = [ 'ability' => $ability_name, 'status' => 'error', 'error' => "Unknown ability: {$ability_name}" ];
				continue;
			}

			$disabled = get_option( 'wpad_disabled_abilities', [] );
			if ( in_array( $ability_name, $disabled, true ) ) {
				WP_AI_Daemon_Logger::error( "Ability is disabled: {$ability_name}" );
				$results[] = [ 'ability' => $ability_name, 'status' => 'error', 'error' => "Ability is disabled: {$ability_name}" ];
				continue;
			}

			if ( ! $ability->check_permissions() ) {
				WP_AI_Daemon_Logger::error( "Permission denied: {$ability_name}" );
				$results[] = [ 'ability' => $ability_name, 'status' => 'error', 'error' => "Permission denied: {$ability_name}" ];
				continue;
			}

			WP_AI_Daemon_Logger::debug( "Ability call: {$ability_name}.", [
				'params' => $params,
			] );

			$result = $ability->execute( $params );
			$meta   = $ability->get_meta();

			if ( is_wp_error( $result ) ) {
				WP_AI_Daemon_Logger::error( "Ability failed: {$ability_name}", [ 'error' => $result->get_error_message() ] );
				WP_AI_Daemon_Logger::debug( "Ability result: {$ability_name} — error.", [
					'error' => $result->get_error_message(),
					'data'  => $result->get_error_data(),
				] );
				$results[] = [ 'ability' => $ability_name, 'status' => 'error', 'error' => $result->get_error_message() ];
			} else {
				WP_AI_Daemon_Logger::info( "Ability executed: {$ability_name}" );
				WP_AI_Daemon_Logger::debug( "Ability result: {$ability_name} — success.", [
					'result' => $result,
				] );
				$results[] = [
					'ability'           => $ability_name,
					'status'            => 'success',
					'requires_approval' => $meta['requires_approval'] ?? false,
					'result'            => $result,
				];
			}
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Prompt building
	// -------------------------------------------------------------------------

	private function build_prompt( string $message, array $history, string $injected = '' ): string {
		$context = $this->build_context();
		$prompt  = '';

		if ( $context ) {
			$prompt .= $context . "\n---\n\n";
		}

		if ( ! empty( $history ) ) {
			$prompt .= "Conversation so far:\n\n";

			foreach ( $history as $turn ) {
				$role    = $turn['role'] === 'user' ? 'User' : 'Assistant';
				$prompt .= "{$role}: {$turn['content']}\n\n";
			}

			$prompt .= "User: " . $message;
		} else {
			$prompt .= $message;
		}

		if ( $injected ) {
			$prompt .= "\n\n" . $injected;
		}

		return $prompt;
	}

	private function build_context(): string {
		return 'Current date/time (UTC): ' . gmdate( 'Y-m-d H:i:s' );
	}

	private function build_history( array $messages ): array {
		return array_map( function ( $msg ) {
			if ( $msg['role'] !== 'user' ) {
				$content = $msg['content'] ?? '';

				// Summarise executed actions for the LLM's context.
				if ( ! empty( $msg['actions'] ) ) {
					$successes = array_filter( $msg['actions'], fn( $a ) => ( $a['status'] ?? '' ) === 'success' );
					$failures  = array_filter( $msg['actions'], fn( $a ) => ( $a['status'] ?? '' ) === 'error' );

					if ( $successes ) {
						$labels = array_map( [ $this, 'summarize_action' ], array_values( $successes ) );
						$content .= "\n\n[Abilities executed: " . implode( ', ', $labels ) . ']';
					}
					if ( $failures ) {
						$labels = array_map( fn( $a ) => ( $a['ability'] ?? 'unknown' ) . ' (failed: ' . ( $a['error'] ?? '?' ) . ')', array_values( $failures ) );
						$content .= "\n[Abilities failed: " . implode( ', ', $labels ) . ']';
					}
				}

				return [ 'role' => 'model', 'content' => $content ];
			}

			return [ 'role' => 'user', 'content' => $msg['content'] ];
		}, $messages );
	}

	/**
	 * Produce a human-readable one-liner for a completed action to inject into
	 * conversation history, so the LLM remembers *what* it did, not just *that* it did it.
	 */
	private function summarize_action( array $action ): string {
		$ability = $action['ability'] ?? '';
		$result  = $action['result'] ?? [];
		$slug    = $ability ? substr( $ability, strrpos( $ability, '/' ) + 1 ) : $ability;

		switch ( $slug ) {
			case 'write-plugin':
				$detail = $result['plugin_slug'] ?? $result['plugin_file'] ?? '';
				return $detail ? "{$ability} → slug: {$detail}" : $ability;

			case 'create-post':
			case 'update-post':
			case 'create-page':
			case 'update-page':
				$parts = [];
				if ( ! empty( $result['post_id'] ) )  $parts[] = "post_id: {$result['post_id']}";
				if ( ! empty( $result['title'] ) )     $parts[] = "title: \"{$result['title']}\"";
				return $parts ? "{$ability} → " . implode( ', ', $parts ) : $ability;

			case 'run-snippet':
				$detail = $result['description'] ?? '';
				return $detail ? "{$ability} → {$detail}" : $ability;

			case 'web-fetch':
				$detail = $result['url'] ?? '';
				return $detail ? "{$ability} → {$detail}" : $ability;

			case 'list-scheduled-actions':
				$count = $result['count'] ?? 0;
				return "{$ability} → {$count} action(s) found";

			default:
				return $ability;
		}
	}

	private function get_system_instruction(): string {
		$file = WP_AI_DAEMON_DIR . 'system-prompt.md';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$base = file_exists( $file ) ? file_get_contents( $file ) : 'You are a personal AI agent for WordPress.';

		// Replace the {abilities} placeholder with the live registry.
		return str_replace( '{abilities}', $this->build_available_abilities(), $base );
	}

	/**
	 * Build the available abilities section of the system prompt dynamically
	 * from the live WordPress Abilities API registry.
	 *
	 * Every registered ability that passes check_permissions() for the current
	 * user is included — not just wp-ai-daemon/* ones. This means third-party
	 * abilities (WooCommerce, SEO plugins, etc.) are automatically available to
	 * the agent the moment they are registered, with no changes to WPAD.
	 */
	private function build_available_abilities(): string {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return '(Abilities API unavailable.)';
		}

		$blocks   = [];
		$disabled = get_option( 'wpad_disabled_abilities', [] );

		foreach ( wp_get_abilities() as $name => $ability ) {
			try {
				if ( ! $ability->check_permissions() ) {
					continue;
				}

				if ( in_array( $name, $disabled, true ) ) {
					continue;
				}

				$block  = "### {$name}\n";
				$block .= $ability->get_description();

				// Parameters from input schema (may be null if none declared).
				$schema   = $ability->get_input_schema() ?? [];
				$required = $schema['required'] ?? [];

				if ( ! empty( $schema['properties'] ) ) {
					$block .= "\n\nParameters:";

					foreach ( $schema['properties'] as $param => $def ) {
						$type    = is_array( $def ) ? ( $def['type'] ?? 'any' ) : 'any';
						$desc    = is_array( $def ) ? ( $def['description'] ?? '' ) : '';
						$req     = in_array( $param, $required, true ) ? ', required' : '';
						$block  .= "\n- `{$param}` ({$type}{$req})";
						if ( $desc ) {
							$block .= ": {$desc}";
						}
					}
				}

				// Per-ability constraints from annotations.instructions.
				$meta         = $ability->get_meta();
				$annotations  = $meta['annotations'] ?? [];
				$instructions = trim( $annotations['instructions'] ?? '' );
				if ( $instructions !== '' ) {
					$block .= "\n\nConstraints:\n{$instructions}";
				}

				$blocks[] = $block;

			} catch ( \Throwable $e ) {
				// Skip abilities that throw during introspection — don't let
				// a badly-formed third-party ability break the whole prompt.
				WP_AI_Daemon_Logger::error( "Failed to introspect ability: {$name}", [ 'error' => $e->getMessage() ] );
			}
		}

		if ( empty( $blocks ) ) {
			return '(No abilities registered.)';
		}

		return implode( "\n\n---\n\n", $blocks );
	}

	/**
	 * Flat JSON schema — WP AI Client requires all-string/number leaf properties.
	 * Actions are encoded as a JSON string and decoded on the PHP side.
	 */
	public function response_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'message'      => [ 'type' => 'string' ],
				'actions_json' => [ 'type' => 'string' ],
			],
			'required' => [ 'message' ],
		];
	}
}
