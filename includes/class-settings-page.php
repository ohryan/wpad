<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPAD > Settings.
 *
 * Tabs:
 *   - General       — log retention, AI provider link
 *   - Abilities     — enable/disable individual abilities
 *   - Conversations — list / delete past conversations
 *   - Logs          — execution log viewer
 */
class WP_AI_Daemon_Settings_Page {

	private string $hook = '';

	private const TABS = [ 'general', 'abilities', 'conversations', 'logs' ];

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_wpad_save_settings', [ $this, 'save' ] );
		add_action( 'admin_init', [ $this, 'handle_conversation_delete' ] );
	}

	public function register_menu(): void {
		$this->hook = add_submenu_page(
			'wp-ai-daemon',
			__( 'Settings', 'wp-ai-daemon' ),
			__( 'Settings', 'wp-ai-daemon' ),
			'manage_options',
			'wp-ai-daemon-settings',
			[ $this, 'render' ]
		);

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		// Abilities assets.
		wp_enqueue_style(
			'wpad-abilities',
			WP_AI_DAEMON_URL . 'assets/abilities.css',
			[],
			(string) filemtime( WP_AI_DAEMON_DIR . 'assets/abilities.css' )
		);
		wp_enqueue_script(
			'wpad-abilities',
			WP_AI_DAEMON_URL . 'assets/abilities.js',
			[],
			(string) filemtime( WP_AI_DAEMON_DIR . 'assets/abilities.js' ),
			true
		);
		wp_localize_script( 'wpad-abilities', 'wpAiDaemonAbilities', [
			'restUrl' => esc_url_raw( rest_url( 'wp-ai-daemon/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );

		// Logs assets.
		wp_enqueue_style(
			'wpad-logs',
			WP_AI_DAEMON_URL . 'assets/logs.css',
			[],
			WP_AI_DAEMON_VERSION
		);
		wp_enqueue_script(
			'wpad-logs',
			WP_AI_DAEMON_URL . 'assets/logs.js',
			[],
			WP_AI_DAEMON_VERSION,
			true
		);
		wp_localize_script( 'wpad-logs', 'wpAiDaemon', [
			'restUrl' => esc_url_raw( rest_url( 'wp-ai-daemon/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-ai-daemon' ) );
		}

		$tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], self::TABS, true )
			? $_GET['tab']
			: 'general';

		$tab_labels = [
			'general'       => __( 'General', 'wp-ai-daemon' ),
			'abilities'     => __( 'Abilities', 'wp-ai-daemon' ),
			'conversations' => __( 'Conversations', 'wp-ai-daemon' ),
			'logs'          => __( 'Logs', 'wp-ai-daemon' ),
		];
		?>
		<div class="wrap" id="wpad-settings">
			<h1><?php esc_html_e( 'WPAD — Settings', 'wp-ai-daemon' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tab_labels as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-daemon-settings' . ( $slug !== 'general' ? '&tab=' . $slug : '' ) ) ); ?>"
					   class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			match ( $tab ) {
				'abilities'     => $this->render_abilities_tab(),
				'conversations' => $this->render_conversations_tab(),
				'logs'          => $this->render_logs_tab(),
				default         => $this->render_general_tab(),
			};
			?>
		</div><!-- #wpad-settings -->
		<?php
	}

	// -------------------------------------------------------------------------
	// General tab
	// -------------------------------------------------------------------------

	private function render_general_tab(): void {
		$log_retention = (int) get_option( 'wpad_log_retention_days', 30 );
		?>
		<?php settings_errors( 'wpad_settings' ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpad_save_settings">
			<?php wp_nonce_field( 'wpad_save_settings' ); ?>

			<h2><?php esc_html_e( 'Log', 'wp-ai-daemon' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wpad-log-retention"><?php esc_html_e( 'Log retention (days)', 'wp-ai-daemon' ); ?></label>
					</th>
					<td>
						<input type="number" id="wpad-log-retention" name="log_retention_days"
							value="<?php echo esc_attr( $log_retention ); ?>"
							min="1" max="365" class="small-text">
						<p class="description"><?php esc_html_e( 'Log entries older than this will be automatically pruned.', 'wp-ai-daemon' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'AI Provider', 'wp-ai-daemon' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: link to connectors page */
					esc_html__( 'Configure your AI provider (Anthropic, Google, or OpenAI) under %s.', 'wp-ai-daemon' ),
					'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">'
					. esc_html__( 'Settings → Connectors', 'wp-ai-daemon' )
					. '</a>'
				);
				?>
			</p>

			<?php submit_button( __( 'Save Settings', 'wp-ai-daemon' ) ); ?>
		</form>
		<?php
	}

	// -------------------------------------------------------------------------
	// Abilities tab
	// -------------------------------------------------------------------------

	private function render_abilities_tab(): void {
		$disabled  = get_option( 'wpad_disabled_abilities', [] );
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : [];

		$groups = [];
		foreach ( $abilities as $name => $ability ) {
			try {
				if ( ! $ability->check_permissions() ) {
					continue;
				}
			} catch ( \Throwable $e ) {
				continue;
			}

			$slash     = strpos( $name, '/' );
			$namespace = $slash !== false ? substr( $name, 0, $slash ) : $name;
			$groups[ $namespace ][ $name ] = $ability;
		}

		uksort( $groups, function ( string $a, string $b ): int {
			if ( $a === 'wp-ai-daemon' ) return -1;
			if ( $b === 'wp-ai-daemon' ) return  1;
			return strcmp( $a, $b );
		} );
		?>
		<div id="wpad-abilities-intro">
			<p>
				<?php esc_html_e( 'Abilities are the actions the AI agent can take on your behalf. Each ability is registered by a plugin and exposed to the agent through a shared API — the agent reads the list at the start of every conversation and decides which abilities to use based on your request.', 'wp-ai-daemon' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Disabling an ability hides it from the agent entirely — it will not be mentioned in the system prompt and cannot be executed, even if the agent tries. Other plugins can register their own abilities, which appear here grouped by namespace.', 'wp-ai-daemon' ); ?>
			</p>
		</div>

		<?php if ( empty( $groups ) ) : ?>
			<p><?php esc_html_e( 'No abilities are registered.', 'wp-ai-daemon' ); ?></p>
		<?php else : ?>
			<?php foreach ( $groups as $namespace => $group_abilities ) : ?>
			<div class="wpad-ability-group">
				<h2 class="wpad-ability-group__heading"><?php echo esc_html( $namespace ); ?></h2>
				<div class="wpad-abilities-grid">
					<?php foreach ( $group_abilities as $name => $ability ) :
						$enabled  = ! in_array( $name, $disabled, true );
						$label    = $ability->get_label();
						$desc     = $ability->get_description();
						$input_id = 'wpad-ability-' . sanitize_html_class( str_replace( [ '/', '_' ], '-', $name ) );
					?>
					<div class="wpad-ability-card<?php echo $enabled ? '' : ' wpad-ability-card--disabled'; ?>" data-ability="<?php echo esc_attr( $name ); ?>">
						<div class="wpad-ability-card__header">
							<div class="wpad-ability-card__info">
								<strong class="wpad-ability-card__name"><?php echo esc_html( $label ); ?></strong>
								<span class="wpad-ability-card__slug"><?php echo esc_html( $name ); ?></span>
							</div>
							<label class="wpad-toggle" for="<?php echo esc_attr( $input_id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $input_id ); ?>"
									class="wpad-toggle__input"
									data-ability="<?php echo esc_attr( $name ); ?>"
									<?php checked( $enabled ); ?>
								>
								<span class="wpad-toggle__track" aria-hidden="true"></span>
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: %s: ability label */
										esc_html__( 'Enable %s', 'wp-ai-daemon' ),
										esc_html( $label )
									);
									?>
								</span>
							</label>
						</div>
						<p class="wpad-ability-card__desc"><?php echo esc_html( $desc ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	// -------------------------------------------------------------------------
	// Conversations tab
	// -------------------------------------------------------------------------

	private function render_conversations_tab(): void {
		$store         = new WP_AI_Daemon_Conversation_Store();
		$conversations = $store->list( 100 );
		$chat_url      = admin_url( 'admin.php?page=wp-ai-daemon' );
		?>
		<p style="margin-top:1em;">
			<a href="<?php echo esc_url( $chat_url ); ?>" class="button button-primary">
				<?php esc_html_e( '+ New Conversation', 'wp-ai-daemon' ); ?>
			</a>
		</p>

		<?php if ( empty( $conversations ) ) : ?>
			<p><?php esc_html_e( 'No conversations yet. Start one from the Chat page.', 'wp-ai-daemon' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'wp-ai-daemon' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Date', 'wp-ai-daemon' ); ?></th>
						<th style="width:100px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $conversations as $conv ) :
						$load_url   = add_query_arg( 'conversation', (int) $conv['id'], $chat_url );
						$delete_url = wp_nonce_url(
							add_query_arg( [
								'page'             => 'wp-ai-daemon-settings',
								'tab'              => 'conversations',
								'wpad_delete_conv' => (int) $conv['id'],
							], admin_url( 'admin.php' ) ),
							'wpad_delete_conv_' . $conv['id']
						);
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $load_url ); ?>">
								<?php echo esc_html( $conv['title'] ?: __( 'Untitled', 'wp-ai-daemon' ) ); ?>
							</a>
						</td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $conv['modified'] ) ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $delete_url ); ?>"
							   onclick="return confirm('<?php esc_attr_e( 'Delete this conversation?', 'wp-ai-daemon' ); ?>')"
							   style="color:#b32d2e;">
								<?php esc_html_e( 'Delete', 'wp-ai-daemon' ); ?>
							</a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Handle conversation delete action — runs on admin_init before render.
	 */
	public function handle_conversation_delete(): void {
		if ( ! isset( $_GET['wpad_delete_conv'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$id = (int) $_GET['wpad_delete_conv'];

		if ( ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wpad_delete_conv_' . $id ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		( new WP_AI_Daemon_Conversation_Store() )->delete( $id );

		wp_redirect( admin_url( 'admin.php?page=wp-ai-daemon-settings&tab=conversations&deleted=1' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Logs tab
	// -------------------------------------------------------------------------

	private function render_logs_tab(): void {
		?>
		<div id="wpad-logs" style="margin-top:1em;">
			<div id="wpad-log-filter">
				<button class="button wpad-log-filter-btn is-active" data-level=""><?php esc_html_e( 'All', 'wp-ai-daemon' ); ?></button>
				<button class="button wpad-log-filter-btn" data-level="error"><?php esc_html_e( 'Errors only', 'wp-ai-daemon' ); ?></button>
				<button class="button wpad-log-filter-btn" data-level="debug"><?php esc_html_e( 'Debug', 'wp-ai-daemon' ); ?></button>
				<button class="button wpad-log-copy" id="wpad-log-copy" style="margin-left:auto;"><?php esc_html_e( 'Copy log as JSON', 'wp-ai-daemon' ); ?></button>
			</div>
			<div id="wpad-log-loading"><?php esc_html_e( 'Loading…', 'wp-ai-daemon' ); ?></div>
			<table id="wpad-log-table" class="widefat striped" style="display:none;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'wp-ai-daemon' ); ?></th>
						<th><?php esc_html_e( 'Level', 'wp-ai-daemon' ); ?></th>
						<th><?php esc_html_e( 'Message', 'wp-ai-daemon' ); ?></th>
						<th><?php esc_html_e( 'Context', 'wp-ai-daemon' ); ?></th>
					</tr>
				</thead>
				<tbody id="wpad-log-body"></tbody>
			</table>
			<p id="wpad-log-empty" style="display:none;"><?php esc_html_e( 'No log entries yet.', 'wp-ai-daemon' ); ?></p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save (General tab)
	// -------------------------------------------------------------------------

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-ai-daemon' ) );
		}

		check_admin_referer( 'wpad_save_settings' );

		$log_retention = absint( $_POST['log_retention_days'] ?? 30 );
		update_option( 'wpad_log_retention_days', max( 1, min( 365, $log_retention ) ) );

		add_settings_error( 'wpad_settings', 'saved', __( 'Settings saved.', 'wp-ai-daemon' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-daemon-settings&settings-updated=1' ) );
		exit;
	}

}
