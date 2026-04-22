<?php
/**
 * Handles plugin activation and deactivation for the Receiver.
 *
 * Creates the custom database table needed by the Receiver:
 *   - {prefix}igs_receiver_relations — queued unresolved post_object relations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Receiver_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::set_default_options();
	}

	/**
	 * Run on plugin deactivation.
	 * Tables and options are intentionally kept.
	 */
	public static function deactivate() {
		// Nothing to do on deactivate.
	}

	/**
	 * Create custom DB tables using dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		// ── Relation queue table ──────────────────────────────────────────────────
		// Stores unresolved post_object ACF relations waiting for the
		// referenced post to be imported.
		$sql = "CREATE TABLE {$wpdb->prefix}igs_receiver_relations (
			id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			local_post_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			field_name          VARCHAR(100)        NOT NULL DEFAULT '',
			acf_field_key       VARCHAR(100)        NOT NULL DEFAULT '',
			source_related_id   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source_site         VARCHAR(255)        NOT NULL DEFAULT '',
			resolved            TINYINT(1)          NOT NULL DEFAULT 0,
			created_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY        (id),
			KEY local_post_id  (local_post_id),
			KEY resolved       (resolved),
			KEY source_site    (source_site(100))
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'igs_receiver_db_version', IGS_RECEIVER_VERSION );
	}

	/**
	 * Save default options if they do not exist yet.
	 */
	private static function set_default_options() {
		// API key entered by the admin — copied from the Source site's site registry.
		if ( ! get_option( 'igs_receiver_api_key' ) ) {
			update_option( 'igs_receiver_api_key', '' );
		}
	}
}
