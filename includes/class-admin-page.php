<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPAD > Chat — the main conversational interface.
 */
class WP_AI_Daemon_Admin_Page {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'setup_dashboard' ] );
	}

	public function setup_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wpad_chat',
			__( 'WPAD', 'wp-ai-daemon' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		if ( ! $this->is_ai_ready() ) {
			$connectors_url = admin_url( 'options-connectors.php' );
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				sprintf(
					/* translators: %s: link to Settings → Connectors */
					esc_html__( 'No AI provider is connected. Visit %s to add one.', 'wp-ai-daemon' ),
					'<a href="' . esc_url( $connectors_url ) . '"><strong>'
					. esc_html__( 'Settings → Connectors', 'wp-ai-daemon' )
					. '</strong></a>'
				)
			);
			return;
		}
		?>
		<div id="wpad-chat">
			<div id="wpad-layout">
				<div id="wpad-error" style="display:none;" role="alert" aria-live="assertive"></div>

				<div id="wpad-thread" role="log" aria-label="<?php esc_attr_e( 'Conversation', 'wp-ai-daemon' ); ?>" aria-live="polite">
					<div id="wpad-thread-empty">
						<?php esc_html_e( 'Start a conversation — ask me to fetch a URL, draft a post, write a plugin, or set up a recurring task.', 'wp-ai-daemon' ); ?>
					</div>
				</div>

				<div id="wpad-input-area">
					<textarea
						id="wpad-input"
						rows="3"
						placeholder="<?php esc_attr_e( 'What would you like me to do?', 'wp-ai-daemon' ); ?>"
						aria-label="<?php esc_attr_e( 'Message', 'wp-ai-daemon' ); ?>"
					></textarea>
					<div id="wpad-input-actions">
						<button id="wpad-send-btn" class="button button-primary">
							<?php esc_html_e( 'Send', 'wp-ai-daemon' ); ?>
						</button>
						<span id="wpad-send-spinner" class="spinner" aria-hidden="true"></span>
						<span id="wpad-hint" class="description">
							<?php esc_html_e( 'Ctrl+Enter for new line', 'wp-ai-daemon' ); ?>
						</span>
					</div>
				</div>
			</div><!-- #wpad-layout -->
		</div><!-- #wpad-chat -->
		<?php
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WPAD', 'wp-ai-daemon' ),
			__( 'WPAD', 'wp-ai-daemon' ),
			'manage_options',
			'wp-ai-daemon',
			[ $this, 'render' ],
			'dashicons-format-chat',
			30
		);

		add_submenu_page(
			'wp-ai-daemon',
			__( 'Chat', 'wp-ai-daemon' ),
			__( 'Chat', 'wp-ai-daemon' ),
			'manage_options',
			'wp-ai-daemon',
			[ $this, 'render' ]
		);

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Returns true if an AI provider is installed and has at least one configured service.
	 */
	private function is_ai_ready(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		// AI Services plugin (Felix Arntz) exposes ai_services() with has_available_services().
		if ( function_exists( 'ai_services' ) ) {
			try {
				return ai_services()->has_available_services();
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		// Fallback: function exists, assume something is configured.
		return true;
	}

	public function enqueue_assets( string $hook ): void {
		$allowed_hooks = [ 'toplevel_page_wp-ai-daemon', 'index.php' ];
		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		// Don't load chat assets if AI is not configured.
		if ( ! $this->is_ai_ready() ) {
			return;
		}

		wp_enqueue_style(
			'wpad-chat',
			WP_AI_DAEMON_URL . 'assets/chat.css',
			[ 'wpad-drawer' ],
			(string) filemtime( WP_AI_DAEMON_DIR . 'assets/chat.css' )
		);

		wp_enqueue_script(
			'wpad-chat',
			WP_AI_DAEMON_URL . 'assets/chat.js',
			[],
			(string) filemtime( WP_AI_DAEMON_DIR . 'assets/chat.js' ),
			true
		);

		wp_localize_script( 'wpad-chat', 'wpAiDaemon', [
			'restUrl'          => esc_url_raw( rest_url( 'wp-ai-daemon/v1/' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'connectorsUrl'    => esc_url( admin_url( 'options-connectors.php' ) ),
			'conversationId'   => isset( $_GET['conversation'] ) ? (int) $_GET['conversation'] : null,
		] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-daemon' ) );
		}

		if ( ! $this->is_ai_ready() ) {
			$connectors_url = admin_url( 'options-connectors.php' );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'WPAD', 'wp-ai-daemon' ); ?></h1>
				<div class="notice notice-warning inline" style="margin-top:16px;">
					<p>
						<?php
						printf(
							/* translators: %s: link to Settings → Connectors */
							esc_html__( 'No AI provider is connected. Visit %s to add one, then come back here.', 'wp-ai-daemon' ),
							'<a href="' . esc_url( $connectors_url ) . '"><strong>'
							. esc_html__( 'Settings → Connectors', 'wp-ai-daemon' )
							. '</strong></a>'
						);
						?>
					</p>
				</div>
			</div>
			<?php
			return;
		}
		?>
		<div class="wrap" id="wpad-chat">
			<h1>
				<?php esc_html_e( 'WPAD', 'wp-ai-daemon' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-daemon-settings&tab=conversations' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'All Conversations', 'wp-ai-daemon' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-daemon' ) ); ?>" class="page-title-action">
					<?php esc_html_e( '+ New', 'wp-ai-daemon' ); ?>
				</a>
			</h1>

			<div id="wpad-layout">
				<div id="wpad-error" style="display:none;" role="alert" aria-live="assertive"></div>

				<div id="wpad-thread" role="log" aria-label="<?php esc_attr_e( 'Conversation', 'wp-ai-daemon' ); ?>" aria-live="polite">
					<div id="wpad-thread-empty">
						<?php esc_html_e( 'Start a conversation — ask me to fetch a URL, draft a post, write a plugin, or set up a recurring task.', 'wp-ai-daemon' ); ?>
					</div>
				</div>

				<div id="wpad-input-area">
					<textarea
						id="wpad-input"
						rows="3"
						placeholder="<?php esc_attr_e( 'What would you like me to do?', 'wp-ai-daemon' ); ?>"
						aria-label="<?php esc_attr_e( 'Message', 'wp-ai-daemon' ); ?>"
					></textarea>
					<div id="wpad-input-actions">
						<button id="wpad-send-btn" class="button button-primary">
							<?php esc_html_e( 'Send', 'wp-ai-daemon' ); ?>
						</button>
						<span id="wpad-send-spinner" class="spinner" aria-hidden="true"></span>
						<span id="wpad-hint" class="description">
							<?php esc_html_e( 'Ctrl+Enter for new line', 'wp-ai-daemon' ); ?>
						</span>
					</div>
				</div>
			</div><!-- #wpad-layout -->
		</div><!-- #wpad-chat -->
		<?php
	}
}
