<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Removes:
 *   - Custom DB table: igs_receiver_relations
 *   - Plugin options:  igs_receiver_api_key, igs_receiver_db_version
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop custom table ─────────────────────────────────────────────────────────

$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}igs_receiver_relations`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// ── Delete plugin options ─────────────────────────────────────────────────────

$options = array(
	'igs_receiver_api_key',
	'igs_receiver_db_version',
	'igs_receiver_default_author',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
