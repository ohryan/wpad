<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: list-scheduled-actions
 *
 * Returns all pending Action Scheduler actions created by WP AI Daemon.
 * Use this before scheduling or cancelling to see what is already set up,
 * or to answer user questions like "what do I have scheduled?"
 */
class WP_AI_Daemon_Ability_List_Scheduled_Actions extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|\WP_Error {
		$actions = WP_AI_Daemon_Scheduled_Actions::list();

		return [
			'count'   => count( $actions ),
			'actions' => $actions,
			'summary' => WP_AI_Daemon_Scheduled_Actions::format_for_context(),
		];
	}

	public function get_ability_args(): array {
		return [
			'label'       => __( 'List Scheduled Actions', 'wp-ai-daemon' ),
			'description' => __( 'Returns all pending scheduled actions managed by WP AI Daemon.', 'wp-ai-daemon' ),
			'category'    => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'count'   => [ 'type' => 'integer', 'description' => 'Number of scheduled actions.' ],
					'summary' => [ 'type' => 'string', 'description' => 'Human-readable summary of scheduled actions.' ],
					'actions' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'               => [ 'type' => 'integer', 'description' => 'Action Scheduler ID — use this to cancel.' ],
								'action'           => [ 'type' => 'string', 'description' => 'The WPAD action key that will run.' ],
								'params'           => [ 'type' => 'object' ],
								'is_recurring'     => [ 'type' => 'boolean' ],
								'interval_seconds' => [ 'type' => [ 'integer', 'null' ] ],
								'next_run'         => [ 'type' => [ 'string', 'null' ], 'description' => 'Next scheduled run time in UTC.' ],
							],
						],
					],
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
