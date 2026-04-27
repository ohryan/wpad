<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstract base class for all WPAD abilities.
 *
 * Handles loading a per-ability prompt.md file and applying optional
 * PHP-computed template variables into it. Concrete abilities extend this
 * class and call $this->load_prompt() inside get_ability_args() to populate
 * their annotations.instructions field.
 *
 * Directory convention: each ability lives in its own subdirectory alongside
 * its prompt.md:
 *
 *   includes/abilities/
 *     web-fetch/
 *       class-web-fetch.php
 *       prompt.md
 *
 * To inject dynamic PHP values into a prompt, override get_prompt_context()
 * and return an associative array. Keys become {key} placeholders in prompt.md:
 *
 *   protected function get_prompt_context(): array {
 *       return [ 'post_types' => implode( ', ', get_post_types() ) ];
 *   }
 *
 * Then in prompt.md: "Available post types: {post_types}"
 */
abstract class WP_AI_Daemon_Ability_Base implements WP_AI_Daemon_Action {

	/**
	 * Load this ability's prompt.md, apply any context variables, and return
	 * the result as a string suitable for annotations.instructions.
	 */
	protected function load_prompt(): string {
		$reflection = new \ReflectionClass( static::class );
		$file       = dirname( $reflection->getFileName() ) . '/prompt.md';

		if ( ! file_exists( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file );
		$context = $this->get_prompt_context();

		foreach ( $context as $key => $value ) {
			$content = str_replace( '{' . $key . '}', (string) $value, $content );
		}

		return trim( $content );
	}

	/**
	 * Override in a concrete ability to inject dynamic PHP values into prompt.md.
	 * Keys map to {key} placeholders in the markdown file.
	 *
	 * @return array<string, string>
	 */
	protected function get_prompt_context(): array {
		return [];
	}
}
