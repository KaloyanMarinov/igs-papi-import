<?php
/**
 * Auto-updater — checks GitHub for new plugin releases.
 *
 * Uses plugin-update-checker (vendor/plugin-update-checker).
 * When a new GitHub Release / tag is published the standard
 * WordPress "Update available" notice appears in Plugins.
 *
 * @see https://github.com/YahnisElsts/plugin-update-checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$puc_bootstrap = IGS_RECEIVER_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $puc_bootstrap ) ) {
	require_once $puc_bootstrap;

	$igs_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/KaloyanMarinov/igs-papi-import/',
		IGS_RECEIVER_DIR . 'igs-migrator-receiver.php',
		'igs-papi-import'
	);

	$igs_updater->setBranch( 'master' );

	// Store globally so the settings page can trigger a manual check.
	$GLOBALS['igs_papi_updater'] = $igs_updater;
}
