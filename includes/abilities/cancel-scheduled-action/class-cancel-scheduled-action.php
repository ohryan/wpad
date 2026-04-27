<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: cancel-scheduled-action
 *
 * Cancels a pending Action Scheduler action created by WP AI Daemon.
 * Only actions in the wp-ai-daemon group can be cancelled via this ability.
 */
class WP_AI_Daemon_Ability_Cancel_Scheduled_Action extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|\WP_Error {
		$id = isset( $params['action_scheduler_id'] ) ? (int) $params['action_scheduler_id'] : 0;

		if ( ! $id ) {
			return new WP_Error( 'missing_id', __( 'cancel_scheduled_action requires an action_scheduler_id.', 'wp-ai-daemon' ) );
		}

		// Fetch the action details before cancelling so we can confirm in the result.
		$actions = WP_AI_Daemon_Scheduled_Actions::list();
		$target  = null;
		foreach ( $actions as $item ) {
			if ( $item['id'] === $id ) {
				$target = $item;
				break;
			}
		}

		if ( ! $target ) {
			// The ID may have changed — recurring actions get a new ID after each run.
			// Return the current pending list so the LLM can identify the correct ID.
			$current = WP_AI_Daemon_Scheduled_Actions::format_for_context();
			return new WP_Error(
				'not_found',
				sprintf(
					__( 'No pending action found with ID %d — recurring actions get a new ID after each run. Current scheduled actions: %s', 'wp-ai-daemon' ),
					$id,
					$current
				),
				[ 'status' => 404 ]
			);
		}

		$result = WP_AI_Daemon_Scheduled_Actions::cancel( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WP_AI_Daemon_Logger::info( 'Scheduled action cancelled.', [
			'action_scheduler_id' => $id,
			'action'              => $target['action'],
		] );

		return [
			'cancelled'           => true,
			'action_scheduler_id' => $id,
			'action'              => $target['action'],
			'was_recurring'       => $target['is_recurring'],
		];
	}

	public function get_ability_args(): array {
		return [
			'label'       => __( 'Cancel Scheduled Action', 'wp-ai-daemon' ),
			'description' => __( 'Cancels a pending scheduled action by its Action Scheduler ID.', 'wp-ai-daemon' ),
			'category'    => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'action_scheduler_id' => [
						'type'        => 'integer',
						'description' => 'The Action Scheduler ID of the action to cancel. Use list-scheduled-actions to find it.',
					],
				],
				'required' => [ 'action_scheduler_id' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'cancelled'           => [ 'type' => 'boolean' ],
					'action_scheduler_id' => [ 'type' => 'integer' ],
					'action'              => [ 'type' => 'string' ],
					'was_recurring'       => [ 'type' => 'boolean' ],
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
