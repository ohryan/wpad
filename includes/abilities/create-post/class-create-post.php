<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: create-post
 *
 * Creates a new WordPress post as a draft.
 * Requires human approval to publish.
 */
class WP_AI_Daemon_Ability_Create_Post extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$title      = sanitize_text_field( $params['title'] ?? '' );
		$content    = wp_kses_post( $params['content'] ?? '' );
		$tags       = array_map( 'sanitize_text_field', (array) ( $params['tags'] ?? [] ) );
		$categories = array_map( 'sanitize_text_field', (array) ( $params['categories'] ?? [] ) );

		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'create_post requires a title.', 'wp-ai-daemon' ) );
		}

		$post_data = [
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_type'    => 'post',
		];

		if ( ! empty( $tags ) ) {
			$post_data['tags_input'] = $tags;
		}

		if ( ! empty( $categories ) ) {
			$cat_ids = [];
			foreach ( $categories as $cat ) {
				$term = get_term_by( 'slug', $cat, 'category' );
				if ( $term ) {
					$cat_ids[] = $term->term_id;
				} else {
					$term = get_term_by( 'name', $cat, 'category' );
					if ( $term ) {
						$cat_ids[] = $term->term_id;
					}
				}
			}
			if ( ! empty( $cat_ids ) ) {
				$post_data['post_category'] = $cat_ids;
			}
		}

		$post_id = wp_insert_post( $post_data, true );

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
			'label'        => __( 'Create Post', 'wp-ai-daemon' ),
			'description'  => __( 'Creates a new WordPress post as a draft.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'title'      => [ 'type' => 'string', 'description' => 'Post title.' ],
					'content'    => [ 'type' => 'string', 'description' => 'Post body content (HTML or plain text).' ],
					'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Tag names or slugs.' ],
					'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Category names or slugs.' ],
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
