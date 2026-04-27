<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Execution log — stored in a custom DB table for reliability.
 */
class WP_AI_Daemon_Logger {

	/**
	 * Create the log table. Called on plugin activation.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'wpad_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id         bigint(20)   NOT NULL AUTO_INCREMENT,
			level      varchar(10)  NOT NULL DEFAULT 'info',
			message    text         NOT NULL,
			context    longtext     NULL,
			created_at datetime     NOT NULL,
			PRIMARY KEY (id),
			KEY level      (level),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	public static function info( string $message, array $context = [] ): void {
		self::log( 'info', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Write a debug entry — only when WP_DEBUG is true.
	 * Logs full prompts, raw LLM responses, and per-ability call details.
	 */
	public static function debug( string $message, array $context = [] ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		self::log( 'debug', $message, $context );
	}

	public static function log( string $level, string $message, array $context = [] ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wpad_log',
			[
				'level'      => substr( $level, 0, 10 ),
				'message'    => $message,
				'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Return the most recent log entries.
	 *
	 * @param int         $limit  Number of rows to return.
	 * @param string|null $level  Filter by level ('info', 'error'), or null for all.
	 *
	 * @return array
	 */
	public static function get_recent( int $limit = 20, ?string $level = null ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wpad_log';

		if ( $level ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d",
					$level,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return $rows ?: [];
	}

	/**
	 * Delete log entries older than $days days.
	 */
	public static function prune( int $days ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wpad_log WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}
}
