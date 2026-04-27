<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API endpoints for WPAD.
 * All endpoints require manage_options.
 */
class WP_AI_Daemon_REST_Controller {

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = 'wp-ai-daemon/v1';

		// Chat.
		register_rest_route( $ns, '/chat', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'chat' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
			'args'                => [
				'message'         => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => fn( $v ) => ! empty( trim( $v ) ),
				],
				'conversation_id' => [
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Conversations.
		register_rest_route( $ns, '/conversations', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_conversations' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
		] );

		register_rest_route( $ns, '/conversations/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_conversation' ],
				'permission_callback' => [ $this, 'manage_options_check' ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_conversation' ],
				'permission_callback' => [ $this, 'manage_options_check' ],
			],
		] );

		// Log.
		register_rest_route( $ns, '/log', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_log' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
			'args'                => [
				'level' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'limit' => [
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 20,
				],
			],
		] );

		// Status (for dashboard).
		register_rest_route( $ns, '/status', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_status' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
		] );

		// Inline chat actions.
		register_rest_route( $ns, '/posts/(?P<id>\d+)/publish', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'publish_post' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
		] );

		register_rest_route( $ns, '/plugins/activate', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'activate_plugin' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
			'args'                => [
				'plugin_file' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// Abilities.
		register_rest_route( $ns, '/abilities', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_abilities' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
		] );

		register_rest_route( $ns, '/abilities/toggle', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'toggle_ability' ],
			'permission_callback' => [ $this, 'manage_options_check' ],
			'args'                => [
				'name' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'enabled' => [
					'required' => true,
					'type'     => 'boolean',
				],
			],
		] );

	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function manage_options_check(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$service = new WP_AI_Daemon_Chat_Service();
		$result  = $service->process(
			get_current_user_id(),
			$request->get_param( 'message' ),
			$request->get_param( 'conversation_id' ) ?: null
		);

		return rest_ensure_response( $result );
	}

	public function list_conversations(): WP_REST_Response {
		$store = new WP_AI_Daemon_Conversation_Store();
		return rest_ensure_response( $store->list() );
	}

	public function get_conversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$store  = new WP_AI_Daemon_Conversation_Store();
		$result = $store->get( absint( $request->get_param( 'id' ) ) );
		return rest_ensure_response( $result );
	}

	public function delete_conversation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$store  = new WP_AI_Daemon_Conversation_Store();
		$result = $store->delete( absint( $request->get_param( 'id' ) ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public function get_log( WP_REST_Request $request ): WP_REST_Response {
		$level = $request->get_param( 'level' ) ?: null;
		$limit = min( absint( $request->get_param( 'limit' ) ) ?: 20, 200 );
		return rest_ensure_response( WP_AI_Daemon_Logger::get_recent( $limit, $level ) );
	}

	public function get_status(): WP_REST_Response {
		$errors = WP_AI_Daemon_Logger::get_recent( 5, 'error' );
		return rest_ensure_response( [ 'recent_errors' => $errors ] );
	}

	public function publish_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id      = absint( $request->get_param( 'id' ) );
		$post    = get_post( $id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'wp-ai-daemon' ), [ 'status' => 404 ] );
		}

		$updated = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		WP_AI_Daemon_Logger::info( 'Post published via chat.', [ 'post_id' => $id ] );

		return rest_ensure_response( [
			'published'  => true,
			'post_id'    => $id,
			'permalink'  => get_permalink( $id ),
		] );
	}

	public function get_abilities(): WP_REST_Response {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return rest_ensure_response( [] );
		}

		$disabled = get_option( 'wpad_disabled_abilities', [] );
		$result   = [];

		foreach ( wp_get_abilities() as $name => $ability ) {
			try {
				if ( ! $ability->check_permissions() ) {
					continue;
				}
				$result[] = [
					'name'        => $name,
					'label'       => $ability->get_label(),
					'description' => $ability->get_description(),
					'enabled'     => ! in_array( $name, $disabled, true ),
				];
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return rest_ensure_response( $result );
	}

	public function toggle_ability( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$name    = $request->get_param( 'name' );
		$enabled = (bool) $request->get_param( 'enabled' );

		if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( $name ) ) {
			return new WP_Error( 'not_found', __( 'Ability not found.', 'wp-ai-daemon' ), [ 'status' => 404 ] );
		}

		$disabled = get_option( 'wpad_disabled_abilities', [] );

		if ( $enabled ) {
			$disabled = array_values( array_filter( $disabled, fn( $n ) => $n !== $name ) );
		} elseif ( ! in_array( $name, $disabled, true ) ) {
			$disabled[] = $name;
		}

		update_option( 'wpad_disabled_abilities', $disabled );

		return rest_ensure_response( [
			'name'    => $name,
			'enabled' => $enabled,
		] );
	}

	public function activate_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$plugin_file = $request->get_param( 'plugin_file' );

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_AI_Daemon_Logger::info( 'Plugin activated via chat.', [ 'plugin_file' => $plugin_file ] );

		return rest_ensure_response( [
			'activated'   => true,
			'plugin_file' => $plugin_file,
		] );
	}

}
