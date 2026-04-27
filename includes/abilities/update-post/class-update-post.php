<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: update-post
 *
 * Updates an existing WordPress post. Saves as a draft, requires approval.
 */
class WP_AI_Daemon_Ability_Update_Post extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$post_id = absint( $params['post_id'] ?? 0 );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', __( 'update_post requires a post_id.', 'wp-ai-daemon' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || $post->post_type !== 'post' ) {
			return new WP_Error( 'not_found', sprintf( __( 'Post %d not found.', 'wp-ai-daemon' ), $post_id ), [ 'status' => 404 ] );
		}

		$update_data = [ 'ID' => $post_id ];

		if ( isset( $params['title'] ) ) {
			$update_data['post_title'] = sanitize_text_field( $params['title'] );
		}

		if ( isset( $params['content'] ) ) {
			$update_data['post_content'] = wp_kses_post( $params['content'] );
		}

		// Keep as draft so it goes through approval.
		$update_data['post_status'] = 'draft';

		$result = wp_update_post( $update_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $params['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', (array) $params['tags'] ), false );
		}

		return [
			'post_id'      => $post_id,
			'edit_link'    => get_edit_post_link( $post_id, 'raw' ),
			'preview_link' => get_preview_post_link( $post_id ),
		];
	}

	public function get_ability_args(): array {
		return [
			'label'        => __( 'Update Post', 'wp-ai-daemon' ),
			'description'  => __( 'Updates an existing WordPress post by ID.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'description' => 'ID of the post to update.' ],
					'title'   => [ 'type' => 'string', 'description' => 'New post title.' ],
					'content' => [ 'type' => 'string', 'description' => 'New post body content.' ],
					'tags'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Replacement tag list.' ],
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
