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
		add_action( 'wp_ajax_igs_receiver_add_site',          array( $this, 'ajax_add_site' ) );
		add_action( 'wp_ajax_igs_receiver_remove_site',       array( $this, 'ajax_remove_site' ) );
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
				'nonceAddSite'      => wp_create_nonce( 'igs_receiver_add_site' ),
				'nonceRemoveSite'   => wp_create_nonce( 'igs_receiver_remove_site' ),
				'nonceAuthor'       => wp_create_nonce( 'igs_receiver_save_author' ),
				'nonceReplacements' => wp_create_nonce( 'igs_receiver_save_replacements' ),
					'i18n'        => array(
					'saving'        => __( 'Saving…', 'igs-migrator' ),
					'error'         => __( 'Error.', 'igs-migrator' ),
					'requestFailed' => __( 'Request failed.', 'igs-migrator' ),
					'confirmRemove' => __( 'Remove this connected site?', 'igs-migrator' ),
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

		$sites          = IGS_Auth_Handler::get_sites();
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
				<h2><?php esc_html_e( 'Connected Sites', 'igs-migrator' ); ?></h2>
				<p>
					<?php esc_html_e( 'Add one entry per Source site. Paste the API key that was generated for this receiver in each Source plugin\'s Settings → Sites panel. Any site with a matching key may import.', 'igs-migrator' ); ?>
				</p>

				<table class="widefat striped" style="max-width:720px;margin-bottom:16px;">
					<thead>
						<tr>
							<th style="width:30%;"><?php esc_html_e( 'Name', 'igs-migrator' ); ?></th>
							<th><?php esc_html_e( 'API Key', 'igs-migrator' ); ?></th>
							<th style="width:90px;"></th>
						</tr>
					</thead>
					<tbody id="igs-sites-tbody">
						<?php if ( empty( $sites ) ) : ?>
						<tr class="igs-no-sites">
							<td colspan="3" style="color:#d63638;">
								<?php esc_html_e( 'No connected sites. The receiver will reject all incoming requests.', 'igs-migrator' ); ?>
							</td>
						</tr>
						<?php else : ?>
							<?php foreach ( $sites as $site ) : ?>
							<tr data-id="<?php echo esc_attr( $site['id'] ); ?>">
								<td><strong><?php echo esc_html( $site['label'] ); ?></strong></td>
								<td><code><?php echo esc_html( str_repeat( '●', 32 ) ); ?></code></td>
								<td>
									<button type="button" class="button igs-remove-site-btn">
										<?php esc_html_e( 'Remove', 'igs-migrator' ); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<h3 style="margin-top:8px;"><?php esc_html_e( 'Add a site', 'igs-migrator' ); ?></h3>
				<div class="igs-key-row" style="flex-wrap:wrap;gap:8px;">
					<input
						type="text"
						id="igs-site-label-input"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Site name (e.g. Casino DE)', 'igs-migrator' ); ?>"
						style="max-width:220px;"
						autocomplete="off"
					/>
					<input
						type="text"
						id="igs-site-key-input"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Paste API key here…', 'igs-migrator' ); ?>"
						autocomplete="off"
					/>
					<button type="button" id="igs-add-site-btn" class="button button-primary">
						<?php esc_html_e( 'Add', 'igs-migrator' ); ?>
					</button>
					<span id="igs-site-feedback" class="igs-feedback"></span>
				</div>

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
			</div><!-- /.igs-card Connected Sites -->

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

	// ── AJAX — ADD SITE ───────────────────────────────────────────────────────

	/**
	 * AJAX handler: add a connected site (label + API key).
	 *
	 * Accepts POST params: nonce, label, api_key.
	 */
	public function ajax_add_site() {
		check_ajax_referer( 'igs_receiver_add_site', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'igs-migrator' ) ) );
		}

		$label   = sanitize_text_field( wp_unslash( $_POST['label']   ?? '' ) );
		$api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => __( 'API key cannot be empty.', 'igs-migrator' ) ) );
		}

		// Basic length validation — keys generated by the Source are 64 hex chars.
		if ( strlen( $api_key ) < 32 ) {
			wp_send_json_error( array( 'message' => __( 'API key looks too short. Please check the value and try again.', 'igs-migrator' ) ) );
		}

		$sites = IGS_Auth_Handler::get_sites();

		// Reject duplicate keys.
		foreach ( $sites as $site ) {
			if ( hash_equals( (string) $site['key'], $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'This API key is already connected.', 'igs-migrator' ) ) );
			}
		}

		if ( empty( $label ) ) {
			/* translators: %d running site number */
			$label = sprintf( __( 'Site %d', 'igs-migrator' ), count( $sites ) + 1 );
		}

		$new_site = array(
			'id'    => IGS_Auth_Handler::generate_id(),
			'label' => $label,
			'key'   => $api_key,
		);

		$sites[] = $new_site;
		IGS_Auth_Handler::save_sites( $sites );

		wp_send_json_success( array(
			'message' => __( 'Site added.', 'igs-migrator' ),
			'site'    => array(
				'id'    => $new_site['id'],
				'label' => $new_site['label'],
			),
		) );
	}

	// ── AJAX — REMOVE SITE ────────────────────────────────────────────────────

	/**
	 * AJAX handler: remove a connected site by id.
	 *
	 * Accepts POST params: nonce, id.
	 */
	public function ajax_remove_site() {
		check_ajax_referer( 'igs_receiver_remove_site', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'igs-migrator' ) ) );
		}

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		if ( empty( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing site id.', 'igs-migrator' ) ) );
		}

		$sites    = IGS_Auth_Handler::get_sites();
		$filtered = array_filter(
			$sites,
			function ( $site ) use ( $id ) {
				return ! isset( $site['id'] ) || $site['id'] !== $id;
			}
		);

		if ( count( $filtered ) === count( $sites ) ) {
			wp_send_json_error( array( 'message' => __( 'Site not found.', 'igs-migrator' ) ) );
		}

		IGS_Auth_Handler::save_sites( $filtered );

		wp_send_json_success( array( 'message' => __( 'Site removed.', 'igs-migrator' ) ) );
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

		if ( ! empty( IGS_Auth_Handler::get_sites() ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=igs-receiver-settings' );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s settings page link */
					esc_html__( 'IGS Papi Import: no connected sites configured. %s', 'igs-migrator' ),
					'<a href="' . esc_url( $settings_url ) . '">'
					. esc_html__( 'Configure now', 'igs-migrator' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
