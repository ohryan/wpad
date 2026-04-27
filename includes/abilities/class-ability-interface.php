<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Interface that all WPAD ability classes must implement.
 *
 * Register implementations via the wp_ai_daemon_actions filter:
 *
 *   add_filter( 'wp_ai_daemon_actions', function( $actions ) {
 *       $actions['my_action'] = My_Ability_Class::class;
 *       return $actions;
 *   } );
 */
interface WP_AI_Daemon_Action {

	/**
	 * Execute the ability.
	 *
	 * @param array $params  Parameters from the LLM or queue item.
	 *
	 * @return array|WP_Error  Result data on success, WP_Error on failure.
	 */
	public function execute( array $params ): array|WP_Error;

	/**
	 * Return the arguments array for wp_register_ability().
	 *
	 * Must include at minimum: label, description, category, input_schema,
	 * output_schema, and meta (with annotations.instructions).
	 * Do NOT include execute_callback or permission_callback — those are added
	 * centrally during ability registration in wp-ai-daemon.php.
	 */
	public function get_ability_args(): array;
}
