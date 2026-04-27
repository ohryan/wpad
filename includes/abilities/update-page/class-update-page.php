<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: update-page
 *
 * Updates an existing WordPress page. Saves as a draft, requires approval.
 */
class WP_AI_Daemon_Ability_Update_Page extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$post_id = absint( $params['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', __( 'update_page requires a post_id.', 'wp-ai-daemon' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || $post->post_type !== 'page' ) {
			return new WP_Error( 'not_found', sprintf( __( 'Page %d not found.', 'wp-ai-daemon' ), $post_id ), [ 'status' => 404 ] );
		}

		$update_data = [ 'ID' => $post_id ];

		if ( isset( $params['title'] ) ) {
			$update_data['post_title'] = sanitize_text_field( $params['title'] );
		}

		if ( isset( $params['content'] ) ) {
			$update_data['post_content'] = wp_kses_post( $params['content'] );
		}

		$update_data['post_status'] = 'draft';

		$result = wp_update_post( $update_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'post_id'      => $post_id,
			'edit_link'    => get_edit_post_link( $post_id, 'raw' ),
			'preview_link' => get_preview_post_link( $post_id ),
		];
	}

	public function get_ability_args(): array {
		return [
			'label'        => __( 'Update Page', 'wp-ai-daemon' ),
			'description'  => __( 'Updates an existing WordPress page by ID.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'ID of the page to update.' ],
					'title'   => [ 'type' => 'string', 'description' => 'New page title.' ],
					'content' => [ 'type' => 'string', 'description' => 'New page body content.' ],
				],
				'required' => [ 'post_id' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_id'      => [ 'type' => 'integer' ],
					'edit_link'    => [ 'type' => 'string' ],
					'preview_link' => [ 'type' => 'string' ],
				],
			],
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => [
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => $this->load_prompt(),
				],
			],
		];
	}
}
