<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sliding chat drawer — injected into every admin page via the toolbar.
 *
 * Adds a button to the WP admin bar. Clicking it slides a chat panel down
 * from below the toolbar, covering roughly half the screen. Works the same
 * as the dedicated chat page but lives in a persistent overlay so you can
 * use it without leaving whatever page you're on.
 */
class WP_AI_Daemon_Drawer {

	public function init(): void {
		// Only hook in for users who can actually use the daemon.
		add_action( 'admin_bar_menu',      [ $this, 'add_toolbar_button' ], 100 );
		add_action( 'wp_footer',           [ $this, 'render_drawer' ] );
		add_action( 'admin_footer',        [ $this, 'render_drawer' ] );
		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_toolbar_button( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'wpad-drawer-toggle',
			'title' => '<span class="ab-icon dashicons dashicons-format-chat" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'WPAD', 'wp-ai-daemon' ) . '</span>',
			'href'  => '#',
			'meta'  => [
				'class' => 'wpad-toolbar-btn',
				'title' => __( 'Open WPAD', 'wp-ai-daemon' ),
			],
		] );
	}

	public function render_drawer(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_admin_bar_showing() ) {
			return;
		}
		?>
		<div id="wpad-drawer" aria-hidden="true" aria-label="<?php esc_attr_e( 'WPAD chat', 'wp-ai-daemon' ); ?>">
			<div id="wpad-drawer-header">
				<span id="wpad-drawer-title"><?php esc_html_e( 'WPAD', 'wp-ai-daemon' ); ?></span>
				<div id="wpad-drawer-header-actions">
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-daemon' ) ); ?>"
						id="wpad-drawer-expand"
						title="<?php esc_attr_e( 'Open full chat page', 'wp-ai-daemon' ); ?>"
					>&#x2922;</a>
					<button id="wpad-drawer-close" aria-label="<?php esc_attr_e( 'Close', 'wp-ai-daemon' ); ?>">&#x2715;</button>
				</div>
			</div>

			<div id="wpad-drawer-thread" role="log" aria-live="polite">
				<div id="wpad-drawer-empty">
					<?php esc_html_e( 'Start a conversation — ask me to fetch a URL, draft a post, write a plugin, or set up a recurring task.', 'wp-ai-daemon' ); ?>
				</div>
			</div>

			<div id="wpad-drawer-input-area">
				<textarea
					id="wpad-drawer-input"
					rows="2"
					placeholder="<?php esc_attr_e( 'What would you like me to do?', 'wp-ai-daemon' ); ?>"
					aria-label="<?php esc_attr_e( 'Message', 'wp-ai-daemon' ); ?>"
				></textarea>
				<div id="wpad-drawer-input-actions">
					<button id="wpad-drawer-send" class="button button-primary">
						<?php esc_html_e( 'Send', 'wp-ai-daemon' ); ?>
					</button>
					<span id="wpad-drawer-spinner" class="spinner" aria-hidden="true"></span>
				</div>
			</div>
		</div>
		<?php
	}

	public function enqueue_assets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! is_admin_bar_showing() ) {
			return;
		}

		// Dashicons may not be enqueued on the front-end — ensure they are.
		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'wpad-drawer',
			WP_AI_DAEMON_URL . 'assets/drawer.css',
			[ 'dashicons' ],
			(string) filemtime( WP_AI_DAEMON_DIR . 'assets/drawer.css' )
		);

		wp_enqueue_script(
			'wpad-drawer',
			WP_AI_DAEMON_URL . 'assets/drawer.js',
			[],
			(string) filemtime( WP_AI_DAEMON_DIR . 'assets/drawer.js' ),
			true
		);

		wp_localize_script( 'wpad-drawer', 'wpAiDaemonDrawer', [
			'restUrl'       => esc_url_raw( rest_url( 'wp-ai-daemon/v1/' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'connectorsUrl' => esc_url( admin_url( 'options-connectors.php' ) ),
			'aiReady'       => $this->is_ai_ready(),
		] );
	}

	private function is_ai_ready(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( function_exists( 'ai_services' ) ) {
			try {
				return ai_services()->has_available_services();
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return true;
	}
}
