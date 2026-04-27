<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: create-page
 *
 * Creates a new WordPress page as a draft.
 * Requires human approval to publish.
 */
class WP_AI_Daemon_Ability_Create_Page extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$title   = sanitize_text_field( $params['title'] ?? '' );
		$content = wp_kses_post( $params['content'] ?? '' );

		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'create_page requires a title.', 'wp-ai-daemon' ) );
		}

		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'page',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return [
			'post_id'      => $post_id,
			'title'        => $title,
			'edit_link'    => get_edit_post_link( $post_id, 'raw' ),
			'preview_link' => get_preview_post_link( $post_id ),
		];
	}

	public function get_ability_args(): array {
		return [
			'label'        => __( 'Create Page', 'wp-ai-daemon' ),
			'description'  => __( 'Creates a new WordPress page as a draft.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'title'   => [ 'type' => 'string', 'description' => 'Page title.' ],
					'content' => [ 'type' => 'string', 'description' => 'Page body content (HTML or plain text).' ],
				],
				'required' => [ 'title' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'post_id'      => [ 'type' => 'integer' ],
					'title'        => [ 'type' => 'string' ],
					'edit_link'    => [ 'type' => 'string' ],
					'preview_link' => [ 'type' => 'string' ],
				],
			],
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => [
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => false,
					'instructions' => $this->load_prompt(),
				],
			],
		];
	}
}
