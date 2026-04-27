<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ability: write-plugin
 *
 * Writes a WordPress plugin to disk via WP_Filesystem.
 * Does NOT activate the plugin — that requires human approval.
 */
class WP_AI_Daemon_Ability_Write_Plugin extends WP_AI_Daemon_Ability_Base {

	public function execute( array $params ): array|WP_Error {
		$slug     = sanitize_title( $params['slug'] ?? '' );
		$filename = sanitize_file_name( $params['filename'] ?? '' );
		$code     = $params['code'] ?? '';

		if ( empty( $slug ) ) {
			return new WP_Error( 'missing_slug', __( 'write_plugin requires a slug.', 'wp-ai-daemon' ) );
		}

		if ( empty( $filename ) ) {
			$filename = $slug . '.php';
		}

		if ( empty( $code ) ) {
			return new WP_Error( 'missing_code', __( 'write_plugin requires code.', 'wp-ai-daemon' ) );
		}

		if ( strpos( ltrim( $code ), '<?php' ) !== 0 ) {
			return new WP_Error( 'invalid_code', __( 'Generated code does not appear to be valid PHP.', 'wp-ai-daemon' ) );
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		WP_Filesystem();

		$plugin_dir      = WP_CONTENT_DIR . '/plugins/' . $slug;
		$plugin_file     = $plugin_dir . '/' . $filename;
		$plugin_rel_file = $slug . '/' . $filename;
		$is_update       = $wp_filesystem->exists( $plugin_file );

		if ( ! $wp_filesystem->is_dir( $plugin_dir ) ) {
			if ( ! $wp_filesystem->mkdir( $plugin_dir, FS_CHMOD_DIR ) ) {
				return new WP_Error( 'mkdir_failed', __( 'Could not create plugin directory. Check filesystem permissions.', 'wp-ai-daemon' ) );
			}
		}

		if ( ! $wp_filesystem->put_contents( $plugin_file, $code, FS_CHMOD_FILE ) ) {
			$wp_filesystem->rmdir( $plugin_dir );
			return new WP_Error( 'write_failed', __( 'Could not write plugin file. Check filesystem permissions.', 'wp-ai-daemon' ) );
		}

		return [
			'plugin_file' => $plugin_rel_file,
			'plugin_slug' => $slug,
			'updated'     => $is_update,
			'code'        => $code,
		];
	}

	public function get_ability_args(): array {
		return [
			'label'        => __( 'Write Plugin', 'wp-ai-daemon' ),
			'description'  => __( 'Writes a WordPress plugin to disk.', 'wp-ai-daemon' ),
			'category'     => 'wp-ai-daemon',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'slug'     => [ 'type' => 'string', 'description' => 'Plugin directory/slug name (lowercase, hyphens).' ],
					'filename' => [ 'type' => 'string', 'description' => 'PHP filename — defaults to slug.php.' ],
					'code'     => [ 'type' => 'string', 'description' => 'Full plugin PHP source, starting with <?php and a valid plugin header.' ],
				],
				'required' => [ 'slug', 'code' ],
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'plugin_file' => [ 'type' => 'string' ],
					'plugin_slug' => [ 'type' => 'string' ],
					'updated'     => [ 'type' => 'boolean' ],
				],
			],
			'meta' => [
				'show_in_rest' => true,
				'annotations'  => [
					'readonly'     => false,
					'destructive'  => false,
					'idempotent'   => true,
					'instructions' => $this->load_prompt(),
				],
			],
		];
	}
}
