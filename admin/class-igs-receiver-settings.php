<?php
/**
 * Settings page for the Receiver plugin.
 *
 * Provides:
 *   - A field to paste the API key generated on the Source site.
 *   - An admin notice when the key has not been configured.
 *
 * Menu path: Settings → IGS Papi Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Receiver_Settings {

	/**
	 * @var IGS_Receiver_Settings
	 */
	private static $instance = null;

	/**
	 * @return IGS_Receiver_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu',    array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'wp_ajax_igs_receiver_save_key',          array( $this, 'ajax_save_key' ) );
		add_action( 'wp_ajax_igs_receiver_save_author',       array( $this, 'ajax_save_author' ) );
		add_action( 'wp_ajax_igs_receiver_save_replacements', array( $this, 'ajax_save_replacements' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ── MENU ──────────────────────────────────────────────────────────────────

	/**
	 * Register the settings page under the Settings menu.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'IGS Papi Import', 'igs-migrator' ),
			__( 'IGS Papi Import', 'igs-migrator' ),
			'manage_options',
			'igs-receiver-settings',
			array( $this, 'render' ),
			'dashicons-migrate',
			80
		);
	}

	// ── ASSETS ────────────────────────────────────────────────────────────────

	/**
	 * Enqueue styles and scripts only on this plugin's settings page.
	 *
	 * @param string $hook  Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_igs-receiver-settings' !== $hook ) {
			return;
		}

		$base_url = IGS_RECEIVER_URL . 'admin/assets/';
		$version  = IGS_RECEIVER_VERSION . '.' . time();

		wp_enqueue_style(
			'igs-receiver-admin',
			$base_url . 'css/igs-receiver-admin.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'igs-receiver-admin',
			$base_url . 'js/igs-receiver-admin.js',
			array(),   // no jQuery dependency
			$version,
			true       // load in footer
		);

		// Pass PHP values to JS via a small data object.
		wp_localize_script(
			'igs-receiver-admin',
			'igsReceiver',
			array(
				'nonceKey'          => wp_create_nonce( 'igs_receiver_save_key' ),
				'nonceAuthor'       => wp_create_nonce( 'igs_receiver_save_author' ),
				'nonceReplacements' => wp_create_nonce( 'igs_receiver_save_replacements' ),
					'i18n'        => array(
					'saving'        => __( 'Saving…', 'igs-migrator' ),
					'error'         => __( 'Error.', 'igs-migrator' ),
					'requestFailed' => __( 'Request failed.', 'igs-migrator' ),
				),
			)
		);
	}

	// ── RENDER ────────────────────────────────────────────────────────────────

	/**
	 * Output the settings page HTML.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'igs-migrator' ) );
		}

		$current_key    = get_option( 'igs_receiver_api_key', '' );
		$masked_key     = $current_key ? str_repeat( '●', 32 ) : '';
		$current_author = (int) get_option( 'igs_receiver_default_author', 0 );

		// List users eligible to be set as post author.
		$authors = get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => array( 'ID', 'display_name' ),
		) );
		?>
		<div class="wrap igs-receiver-wrap">
			<h1><?php esc_html_e( 'IGS Papi Import — Settings', 'igs-migrator' ); ?></h1>

		<div class="igs-card">
				<h2><?php esc_html_e( 'API Key', 'igs-migrator' ); ?></h2>
				<p>
					<?php esc_html_e( 'Paste the API key that was generated for this site in the Source plugin\'s Settings → Sites panel.', 'igs-migrator' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th>
							<label for="igs-api-key-input">
								<?php esc_html_e( 'API Key', 'igs-migrator' ); ?>
							</label>
						</th>
						<td>
							<div class="igs-key-row">
								<input
									type="text"
									id="igs-api-key-input"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'Paste API key here…', 'igs-migrator' ); ?>"
									value=""
									autocomplete="off"
								/>
								<button type="button" id="igs-save-key-btn" class="button button-primary">
									<?php esc_html_e( 'Save', 'igs-migrator' ); ?>
								</button>
								<span id="igs-key-feedback" class="igs-feedback"></span>
							</div>
							<?php if ( $current_key ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: masked API key */
									esc_html__( 'Current key: %s', 'igs-migrator' ),
									'<code>' . esc_html( $masked_key ) . '</code>'
								);
								?>
							</p>
							<?php else : ?>
							<p class="description" style="color:#d63638;">
								<?php esc_html_e( 'No API key configured. The receiver will reject all incoming requests.', 'igs-migrator' ); ?>
							</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h3 style="margin-top:24px;"><?php esc_html_e( 'REST Endpoint', 'igs-migrator' ); ?></h3>
				<p><?php esc_html_e( 'The Source site pushes content to these endpoints:', 'igs-migrator' ); ?></p>
				<table class="widefat" style="max-width:520px;">
					<tbody>
						<tr>
							<td><strong>Ping</strong></td>
							<td><code><?php echo esc_html( trailingslashit( get_rest_url() ) . 'igs/v1/ping' ); ?></code></td>
						</tr>
						<tr>
							<td><strong>Import</strong></td>
							<td><code><?php echo esc_html( trailingslashit( get_rest_url() ) . 'igs/v1/import' ); ?></code></td>
						</tr>
					</tbody>
				</table>
			</div><!-- /.igs-card API Key -->

			<div class="igs-card">
				<h2><?php esc_html_e( 'Default Post Author', 'igs-migrator' ); ?></h2>
				<p>
					<?php esc_html_e( 'All imported posts will be assigned this author.', 'igs-migrator' ); ?>
				</p>

				<table class="form-table">
					<tr>
						<th>
							<label for="igs-author-select">
								<?php esc_html_e( 'Author', 'igs-migrator' ); ?>
							</label>
						</th>
						<td>
							<div class="igs-key-row">
								<select id="igs-author-select" style="min-width:240px;">
									<option value="0"><?php esc_html_e( '— Select author —', 'igs-migrator' ); ?></option>
									<?php foreach ( $authors as $user ) : ?>
									<option value="<?php echo absint( $user->ID ); ?>"
										<?php selected( $current_author, $user->ID ); ?>>
										<?php echo esc_html( $user->display_name ); ?>
									</option>
									<?php endforeach; ?>
								</select>
								<button type="button" id="igs-save-author-btn" class="button button-primary">
									<?php esc_html_e( 'Save', 'igs-migrator' ); ?>
								</button>
								<span id="igs-author-feedback" class="igs-feedback"></span>
							</div>
							<?php
							if ( $current_author ) :
								$author_obj = get_userdata( $current_author );
							?>
							<p class="description">
								<?php
								printf(
									/* translators: author display name */
									esc_html__( 'Current author: %s', 'igs-migrator' ),
									'<strong>' . esc_html( $author_obj ? $author_obj->display_name : "#{$current_author}" ) . '</strong>'
								);
								?>
							</p>
							<?php else : ?>
							<p class="description" style="color:#d63638;">
								<?php esc_html_e( 'No author selected. Imported posts will have no author.', 'igs-migrator' ); ?>
							</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
			</div><!-- /.igs-card Author -->

			<div class="igs-card">
				<h2><?php esc_html_e( 'Title Word Replacements', 'igs-migrator' ); ?></h2>
				<p>
					<?php esc_html_e( 'Enter words to replace in imported post titles. Each line is one pair — the order must match between the two columns.', 'igs-migrator' ); ?>
				</p>

				<div style="display:flex;gap:16px;align-items:flex-start;">
					<div style="flex:1;">
						<label for="igs-title-words" style="display:block;margin-bottom:4px;font-weight:600;">
							<?php esc_html_e( 'Original', 'igs-migrator' ); ?>
						</label>
						<textarea
							id="igs-title-words"
							rows="10"
							style="width:100%;font-family:monospace;"
							placeholder="<?php esc_attr_e( 'Casino', 'igs-migrator' ); ?>"
						><?php echo esc_textarea( get_option( 'igs_receiver_title_words', '' ) ); ?></textarea>
					</div>
					<div style="flex:1;">
						<label for="igs-title-translations" style="display:block;margin-bottom:4px;font-weight:600;">
							<?php esc_html_e( 'Translation', 'igs-migrator' ); ?>
						</label>
						<textarea
							id="igs-title-translations"
							rows="10"
							style="width:100%;font-family:monospace;"
							placeholder="<?php esc_attr_e( 'Kazino', 'igs-migrator' ); ?>"
						><?php echo esc_textarea( get_option( 'igs_receiver_title_translations', '' ) ); ?></textarea>
					</div>
				</div>

				<div style="margin-top:12px;">
					<button type="button" id="igs-save-replacements-btn" class="button button-primary">
						<?php esc_html_e( 'Save', 'igs-migrator' ); ?>
					</button>
					<span id="igs-replacements-feedback" class="igs-feedback"></span>
				</div>
			</div><!-- /.igs-card Replacements -->

		</div><!-- /.wrap -->
		<?php
	}

	// ── AJAX — SAVE KEY ───────────────────────────────────────────────────────

	/**
	 * AJAX handler: save the API key.
	 *
	 * Accepts POST params: nonce, api_key.
	 */
	public function ajax_save_key() {
		check_ajax_referer( 'igs_receiver_save_key', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'igs-migrator' ) ) );
		}

		$api_key = sanitize_text_field( $_POST['api_key'] ?? '' );

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key cannot be empty.', 'igs-migrator' ) ) );
		}

		// Basic length validation — keys generated by the Source are 64 hex chars.
		if ( strlen( $api_key ) < 32 ) {
			wp_send_json_error( array( 'message' => __( 'API key looks too short. Please check the value and try again.', 'igs-migrator' ) ) );
		}

		update_option( 'igs_receiver_api_key', $api_key );

		wp_send_json_success( array( 'message' => __( 'API key saved.', 'igs-migrator' ) ) );
	}

	// ── AJAX — SAVE AUTHOR ────────────────────────────────────────────────────

	/**
	 * AJAX handler: save the default post author.
	 *
	 * Accepts POST params: nonce, author_id.
	 */
	public function ajax_save_author() {
		check_ajax_referer( 'igs_receiver_save_author', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'igs-migrator' ) ) );
		}

		$author_id = absint( $_POST['author_id'] ?? 0 );

		if ( ! $author_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select an author.', 'igs-migrator' ) ) );
		}

		// Verify the user actually exists.
		$user = get_userdata( $author_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'Selected user does not exist.', 'igs-migrator' ) ) );
		}

		update_option( 'igs_receiver_default_author', $author_id );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: author display name */
				__( 'Author set to %s.', 'igs-migrator' ),
				$user->display_name
			),
		) );
	}

	// ── AJAX — SAVE TITLE REPLACEMENTS ───────────────────────────────────────

	/**
	 * AJAX handler: save the title word replacement lists.
	 *
	 * Accepts POST params: nonce, words, translations.
	 */
	public function ajax_save_replacements() {
		check_ajax_referer( 'igs_receiver_save_replacements', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'igs-migrator' ) ) );
		}

		// sanitize_textarea_field preserves newlines but strips disallowed characters.
		$words        = sanitize_textarea_field( wp_unslash( $_POST['words']        ?? '' ) );
		$translations = sanitize_textarea_field( wp_unslash( $_POST['translations'] ?? '' ) );

		update_option( 'igs_receiver_title_words',        $words );
		update_option( 'igs_receiver_title_translations', $translations );

		wp_send_json_success( array( 'message' => __( 'Replacements saved.', 'igs-migrator' ) ) );
	}

	// ── ADMIN NOTICE ──────────────────────────────────────────────────────────

	/**
	 * Show a warning in the admin when the API key has not been configured.
	 */
	public function maybe_show_notice() {
		// Only show to admins.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't show on the settings page itself.
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_igs-receiver-settings' === $screen->id ) {
			return;
		}

		if ( ! empty( get_option( 'igs_receiver_api_key', '' ) ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=igs-receiver-settings' );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s settings page link */
					esc_html__( 'IGS Papi Import: API key not configured. %s', 'igs-migrator' ),
					'<a href="' . esc_url( $settings_url ) . '">'
					. esc_html__( 'Configure now', 'igs-migrator' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
