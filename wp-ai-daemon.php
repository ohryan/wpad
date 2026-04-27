<?php
/**
 * Plugin Name: WPAD
 * Description: A personal AI agent for WordPress — chat interface with synchronous action execution.
 * Version:     1.0.0
 * Author:      Claude
 * License:     GPL-2.0-or-later
 * Text Domain: wp-ai-daemon
 * Requires at least: 7.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Composer autoloader — loads class definitions for Composer dependencies.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Action Scheduler bootstrap — registers WP hooks and makes as_*() functions available.
if ( file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

define( 'WP_AI_DAEMON_VERSION', '1.0.0' );
define( 'WP_AI_DAEMON_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_DAEMON_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once WP_AI_DAEMON_DIR . 'includes/class-conversation-store.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-logger.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-chat-service.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-scheduled-actions.php';

// Admin pages.
require_once WP_AI_DAEMON_DIR . 'includes/class-admin-page.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-drawer.php';
require_once WP_AI_DAEMON_DIR . 'includes/class-settings-page.php';

// REST API.
require_once WP_AI_DAEMON_DIR . 'includes/class-rest-controller.php';

// Abilities — each in its own subdirectory alongside its prompt.md.
require_once WP_AI_DAEMON_DIR . 'includes/abilities/class-ability-interface.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/class-ability-base.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/web-fetch/class-web-fetch.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/create-post/class-create-post.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/update-post/class-update-post.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/create-page/class-create-page.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/update-page/class-update-page.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/write-plugin/class-write-plugin.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/run-snippet/class-run-snippet.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/list-scheduled-actions/class-list-scheduled-actions.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/schedule-action/class-schedule-action.php';
require_once WP_AI_DAEMON_DIR . 'includes/abilities/cancel-scheduled-action/class-cancel-scheduled-action.php';

// -------------------------------------------------------------------------
// Abilities API registration
// -------------------------------------------------------------------------

/**
 * Maps WPAD action keys (underscore, LLM-facing) to their class names.
 * The ability name is wp-ai-daemon/{hyphenated-key}.
 */
function wp_ai_daemon_action_classes(): array {
	return [
		'web_fetch'               => WP_AI_Daemon_Ability_Web_Fetch::class,
		'create_post'             => WP_AI_Daemon_Ability_Create_Post::class,
		'update_post'             => WP_AI_Daemon_Ability_Update_Post::class,
		'create_page'             => WP_AI_Daemon_Ability_Create_Page::class,
		'update_page'             => WP_AI_Daemon_Ability_Update_Page::class,
		'write_plugin'            => WP_AI_Daemon_Ability_Write_Plugin::class,
		'run_snippet'             => WP_AI_Daemon_Ability_Run_Snippet::class,
		'list_scheduled_actions'  => WP_AI_Daemon_Ability_List_Scheduled_Actions::class,
		'schedule_action'         => WP_AI_Daemon_Ability_Schedule_Action::class,
		'cancel_scheduled_action' => WP_AI_Daemon_Ability_Cancel_Scheduled_Action::class,
	];
}

// Category must be registered on wp_abilities_api_categories_init.
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'wp-ai-daemon', [
		'label'       => __( 'WPAD', 'wp-ai-daemon' ),
		'description' => __( 'Actions available to the WPAD agent.', 'wp-ai-daemon' ),
	] );
} );

// Abilities must be registered on wp_abilities_api_init.
add_action( 'wp_abilities_api_init', function () {
	foreach ( wp_ai_daemon_action_classes() as $action_key => $class_name ) {
		$instance     = new $class_name();
		$args         = $instance->get_ability_args();
		$ability_name = 'wp-ai-daemon/' . str_replace( '_', '-', $action_key );

		$args['execute_callback']    = [ $instance, 'execute' ];
		$args['permission_callback'] = static function () {
			return current_user_can( 'manage_options' );
		};

		wp_register_ability( $ability_name, $args );
	}
} );

// -------------------------------------------------------------------------
// Boot
// -------------------------------------------------------------------------

add_action( 'init', function () {
	( new WP_AI_Daemon_Conversation_Store() )->register_post_type();
} );

add_action( 'init', function () {
	( new WP_AI_Daemon_Admin_Page() )->init();
	( new WP_AI_Daemon_Drawer() )->init();
	( new WP_AI_Daemon_Settings_Page() )->init();
	( new WP_AI_Daemon_REST_Controller() )->init();
} );

// Log snippet executions (fired by WP_AI_Daemon_Action_Run_Snippet).
add_action( 'wp_ai_daemon_snippet_executed', function ( string $description, string $output ) {
	WP_AI_Daemon_Logger::info( 'Snippet executed.', [
		'description' => $description ?: '(no description)',
	] );
}, 10, 2 );

// Fires when a scheduled action created by the schedule-action ability reaches its run time.
// Resolves the ability and executes it directly.
add_action( 'wp_ai_daemon_scheduled_action', function ( array $item ) {
	$action = sanitize_key( $item['action'] ?? '' );
	$params = $item['params'] ?? [];

	if ( ! $action ) {
		WP_AI_Daemon_Logger::error( 'Scheduled action fired with no action key.' );
		return;
	}

	$ability_name = 'wp-ai-daemon/' . str_replace( '_', '-', $action );
	$ability      = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;

	if ( ! $ability ) {
		WP_AI_Daemon_Logger::error( "Scheduled action: unknown ability '{$ability_name}'." );
		return;
	}

	WP_AI_Daemon_Logger::info( "Scheduled action executing: {$ability_name}.", [ 'params' => $params ] );

	$result = $ability->execute( $params );

	if ( is_wp_error( $result ) ) {
		WP_AI_Daemon_Logger::error( "Scheduled action failed: {$ability_name}.", [ 'error' => $result->get_error_message() ] );
	} else {
		WP_AI_Daemon_Logger::info( "Scheduled action completed: {$ability_name}.", [ 'result' => $result ] );
	}
} );

// -------------------------------------------------------------------------
// Activation / deactivation
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, function () {
	WP_AI_Daemon_Logger::create_tables();
} );

// -------------------------------------------------------------------------
// Extend HTTP timeout for AI provider requests.
// -------------------------------------------------------------------------

add_filter( 'http_request_args', function ( $args, $url ) {
	$ai_hosts = [
		'api.anthropic.com',
		'api.openai.com',
		'generativelanguage.googleapis.com',
	];

	foreach ( $ai_hosts as $host ) {
		if ( str_contains( $url, $host ) ) {
			$args['timeout'] = max( $args['timeout'] ?? 0, 120 );
			break;
		}
	}

	return $args;
}, 10, 2 );
