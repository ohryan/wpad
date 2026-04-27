<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Persists conversations as a private custom post type.
 * Adapted from Plugin! Plugin! Plugin! (ohryan/plugin-plugin-plugin).
 */
class WP_AI_Daemon_Conversation_Store {

	const POST_TYPE = 'wpad_conversation';

	public function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'public'   => false,
			'show_ui'  => false,
			'supports' => [ 'title', 'author' ],
		] );
	}

	// -------------------------------------------------------------------------
	// Conversation lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Create a new conversation. Returns the post ID or WP_Error.
	 */
	public function create( string $first_message ): int|WP_Error {
		$id = wp_insert_post( [
			'post_type'   => self::POST_TYPE,
			'post_title'  => wp_trim_words( $first_message, 8, '…' ),
			'post_status' => 'private',
			'post_author' => get_current_user_id(),
		], true );

		if ( ! is_wp_error( $id ) ) {
			add_post_meta( $id, '_wpad_messages', [], true );
		}

		return $id;
	}

	/**
	 * Return a single conversation as an array, or WP_Error.
	 */
	public function get( int $id ): array|WP_Error {
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'wp-ai-daemon' ), [ 'status' => 404 ] );
		}

		if ( ! $this->current_user_can_access( $post ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to access this conversation.', 'wp-ai-daemon' ), [ 'status' => 403 ] );
		}

		return [
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'date'     => $post->post_date,
			'messages' => $this->get_messages( $id ),
		];
	}

	/**
	 * List conversations for the current user, newest first.
	 */
	public function list( int $limit = 50 ): array {
		$posts = get_posts( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'private',
			'author'         => get_current_user_id(),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		return array_map( fn( $post ) => [
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'modified' => $post->post_modified,
		], $posts );
	}

	/**
	 * Permanently delete a conversation. Returns true or WP_Error.
	 */
	public function delete( int $id ): bool|WP_Error {
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== self::POST_TYPE ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'wp-ai-daemon' ), [ 'status' => 404 ] );
		}

		if ( ! $this->current_user_can_access( $post ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete this conversation.', 'wp-ai-daemon' ), [ 'status' => 403 ] );
		}

		return (bool) wp_delete_post( $id, true );
	}

	// -------------------------------------------------------------------------
	// Messages
	// -------------------------------------------------------------------------

	public function get_messages( int $id ): array {
		$messages = get_post_meta( $id, '_wpad_messages', true );
		return is_array( $messages ) ? $messages : [];
	}

	/**
	 * Append a message to the conversation.
	 *
	 * Message structure:
	 *   'role'      => 'user' | 'assistant'
	 *   'content'   => string
	 *   'actions'   => array  (optional; queued action objects from assistant turn)
	 *   'timestamp' => int
	 */
	public function add_message( int $id, array $message ): bool {
		$messages   = $this->get_messages( $id );
		$messages[] = $message;
		return (bool) update_post_meta( $id, '_wpad_messages', $messages );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function current_user_can_access( WP_Post $post ): bool {
		return (int) $post->post_author === get_current_user_id()
			|| current_user_can( 'manage_options' );
	}
}
