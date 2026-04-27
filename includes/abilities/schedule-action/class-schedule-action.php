<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: schedule-action
 *
 * Schedules a WP AI Daemon action to run once or on a recurring interval
 * via Action Scheduler. The scheduled action fires the wp_ai_daemon_scheduled_action
 * hook, which the queue runner will process.
 */
class WP_AI_Daemon_Ability_Schedule_Action extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|\WP_Error {
		$action           = sanitize_key( $params['action'] ?? '' );
		$action_params    = is_array( $params['action_params'] ?? null ) ? $params['action_params'] : [];
		$schedule         = $params['schedule'] ?? 'once';
		$interval_seconds = isset( $params['interval_seconds'] ) ? (int) $params['interval_seconds'] : 0;
		$first_run_str    = $params['first_run'] ?? '';

		if ( empty( $action ) ) {
			return new WP_Error( 'missing_action', __( 'schedule_action requires an action parameter.', 'wp-ai-daemon' ) );
		}

		// Verify the target action is a registered WPAD ability.
		$ability_name = 'wp-ai-daemon/' . str_replace( '_', '-', $action );
		if ( ! wp_get_ability( $ability_name ) ) {
			return new WP_Error(
				'unknown_action',
				sprintf( __( 'Unknown action type: %s', 'wp-ai-daemon' ), $action )
			);
		}

		// Parse first_run — default to now.
		$first_run = $first_run_str ? strtotime( $first_run_str ) : time();
		if ( ! $first_run ) {
			return new WP_Error( 'invalid_first_run', __( 'Could not parse first_run datetime.', 'wp-ai-daemon' ) );
		}

		$as_id = WP_AI_Daemon_Scheduled_Actions::schedule(
			$action,
			$action_params,
			$schedule,
			$interval_seconds,
			$first_run
		);

		if ( is_wp_error( $as_id ) ) {
			return $as_id;
		}

		WP_AI_Daemon_Logger::info( 'Scheduled action created.', [
			'as_id'            => $as_id,
			'action'           => $action,
			'schedule'         => $schedule,
			'interval_seconds' => $interval_seconds,
			'first_run'        => gmdate( 'Y-m-d H:i:s', $first_run ) . ' UTC',
		] );

		return [
			'action_scheduler_id' => $as_id,
			'action'              => $action,
			'schedule'            => $schedule,
			'interval_seconds'    => $interval_seconds ?: null,
			'first_run'           => gmdate( 'Y-m-d H:i:s', $first_run ) . ' UTC',
		];
	}

	public function get_ability_args(): array {
		return [
			'label'       => __( 'Schedule Action', 'wp-ai-daemon' ),
			'description' => __( 'Schedules a WP AI Daemon action to run once or on a recurring interval.', 'wp-ai-daemon' ),
			'category'    => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'action' => [
						'type'        => 'string',
						'description' => 'The WPAD action key to schedule (e.g. "web_fetch", "create_post").',
					],
					'action_params' => [
						'type'        => 'object',
						'description' => 'Parameters to pass to the action when it runs.',
					],
					'schedule' => [
						'type'        => 'string',
						'enum'        => [ 'once', 'recurring' ],
						'description' => 'Whether to run once or on a repeating interval.',
					],
					'interval_seconds' => [
						'type'        => 'integer',
						'description' => 'Seconds between runs. Required when schedule is "recurring". Minimum 60.',
						'minimum'     => 60,
					],
					'first_run' => [
						'type'        => 'string',
						'description' => 'ISO 8601 datetime for the first (or only) run. Defaults to now if omitted.',
					],
				],
				'required' => [ 'action', 'schedule' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'action_scheduler_id' => [ 'type' => 'integer', 'description' => 'The Action Scheduler ID for this scheduled action.' ],
					'action'              => [ 'type' => 'string' ],
					'schedule'            => [ 'type' => 'string' ],
					'interval_seconds'    => [ 'type' => [ 'integer', 'null' ] ],
					'first_run'           => [ 'type' => 'string' ],
				],
			],
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
					'instructions' => $this->load_prompt(),
				],
			],
		];
	}
}
