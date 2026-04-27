<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared helper for querying and managing Action Scheduler actions
 * created by WP AI Daemon.
 *
 * All three scheduling abilities delegate to this class. Actions are stored
 * under hook `wp_ai_daemon_scheduled_action`, group `wp-ai-daemon`, so they
 * are isolated from WP-Cron and other plugins' AS usage.
 */
class WP_AI_Daemon_Scheduled_Actions {

	const HOOK  = 'wp_ai_daemon_scheduled_action';
	const GROUP = 'wp-ai-daemon';

	/**
	 * Return all pending scheduled actions created by WP AI Daemon.
	 *
	 * @return array[]
	 */
	public static function list(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) return [];

		$raw = as_get_scheduled_actions( [
			'hook'     => self::HOOK,
			'group'    => self::GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
			'orderby'  => 'date',
			'order'    => 'ASC',
		] );

		$result = [];
		foreach ( $raw as $id => $action ) {
			$args     = $action->get_args();
			$item     = $args[0] ?? [];
			$schedule = $action->get_schedule();

			$is_recurring     = $schedule instanceof \ActionScheduler_IntervalSchedule;
			$interval_seconds = $is_recurring ? (int) $schedule->get_recurrence() : null;

			$next_run = null;
			if ( method_exists( $schedule, 'next' ) ) {
				$next_dt  = $schedule->next();
				$next_run = $next_dt ? gmdate( 'Y-m-d H:i:s', $next_dt->getTimestamp() ) . ' UTC' : null;
			}

			$result[] = [
				'id'               => (int) $id,
				'action'           => $item['action'] ?? 'unknown',
				'params'           => $item['params'] ?? [],
				'is_recurring'     => $is_recurring,
				'interval_seconds' => $interval_seconds,
				'next_run'         => $next_run,
			];
		}

		return $result;
	}

	/**
	 * Schedule a new action.
	 *
	 * @param string $action          The WPAD action key (e.g. 'web_fetch').
	 * @param array  $params          Params for the nested action.
	 * @param string $schedule        'once' or 'recurring'.
	 * @param int    $interval_seconds Required when $schedule = 'recurring'. Min 60.
	 * @param int    $first_run       Unix timestamp for first execution.
	 * @return int|\WP_Error          Action Scheduler action ID or error.
	 */
	public static function schedule( string $action, array $params, string $schedule, int $interval_seconds, int $first_run ): int|\WP_Error {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'no_action_scheduler', __( 'Action Scheduler is not available.', 'wp-ai-daemon' ) );
		}

		if ( ! in_array( $schedule, [ 'once', 'recurring' ], true ) ) {
			return new WP_Error( 'invalid_schedule', __( 'schedule must be "once" or "recurring".', 'wp-ai-daemon' ) );
		}

		if ( $schedule === 'recurring' && $interval_seconds < 60 ) {
			return new WP_Error( 'interval_too_short', __( 'interval_seconds must be at least 60.', 'wp-ai-daemon' ) );
		}

		$item = [
			'action'       => sanitize_key( $action ),
			'params'       => $params,
			'triggered_by' => 'autonomous',
		];

		if ( $schedule === 'recurring' ) {
			$as_id = as_schedule_recurring_action( $first_run, $interval_seconds, self::HOOK, [ $item ], self::GROUP );
		} else {
			$as_id = as_schedule_single_action( $first_run, self::HOOK, [ $item ], self::GROUP );
		}

		return (int) $as_id;
	}

	/**
	 * Cancel a scheduled action by its Action Scheduler ID.
	 *
	 * @param int $id Action Scheduler action ID.
	 * @return bool|\WP_Error
	 */
	public static function cancel( int $id ): bool|\WP_Error {
		if ( ! $id || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return new WP_Error( 'no_action_scheduler', __( 'Action Scheduler is not available.', 'wp-ai-daemon' ) );
		}

		// Verify this action belongs to wp-ai-daemon before cancelling.
		// Fetch all pending actions (not just 1) so we can check any ID.
		$actions = as_get_scheduled_actions( [
			'hook'     => self::HOOK,
			'group'    => self::GROUP,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		] );

		if ( ! isset( $actions[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Scheduled action not found or does not belong to WP AI Daemon.', 'wp-ai-daemon' ), [ 'status' => 404 ] );
		}

		try {
			\ActionScheduler::store()->cancel_action( $id );
			return true;
		} catch ( \Exception $e ) {
			WP_AI_Daemon_Logger::error( 'Failed to cancel scheduled action.', [
				'action_scheduler_id' => $id,
				'error'               => $e->getMessage(),
			] );
			return new WP_Error( 'cancel_failed', $e->getMessage() );
		}
	}

	/**
	 * Format the scheduled actions list as a human-readable string for LLM context.
	 */
	public static function format_for_context(): string {
		$actions = self::list();

		if ( empty( $actions ) ) {
			return 'No scheduled actions currently set up.';
		}

		$lines = [ 'Currently scheduled actions (' . count( $actions ) . '):' ];
		foreach ( $actions as $item ) {
			$schedule_desc = $item['is_recurring']
				? 'recurring every ' . self::human_interval( $item['interval_seconds'] )
				: 'one-time';
			$next    = $item['next_run'] ? ', next run: ' . $item['next_run'] : '';
			$params  = ! empty( $item['params'] ) ? ' (' . wp_json_encode( $item['params'] ) . ')' : '';
			$lines[] = "  [ID: {$item['id']}] {$item['action']}{$params} — {$schedule_desc}{$next}";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convert seconds to a human-readable interval string.
	 */
	private static function human_interval( ?int $seconds ): string {
		if ( ! $seconds ) return '?';
		if ( $seconds % DAY_IN_SECONDS === 0 )  return ( $seconds / DAY_IN_SECONDS ) . 'd';
		if ( $seconds % HOUR_IN_SECONDS === 0 ) return ( $seconds / HOUR_IN_SECONDS ) . 'h';
		if ( $seconds % MINUTE_IN_SECONDS === 0 ) return ( $seconds / MINUTE_IN_SECONDS ) . 'm';
		return $seconds . 's';
	}
}
