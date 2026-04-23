<?php
/**
 * Plugin Name: IGS Papi Import
 * Plugin URI:  https://igamingsolutions.com
 * Description: Receives and imports content migrated from the IGS Papi Export source site.
 * Version:     1.0.2
 * Author:      IGS
 * License:     GPL-2.0+
 * Text Domain: igs-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants
define( 'IGS_RECEIVER_VERSION',  '1.0.2' );
define( 'IGS_RECEIVER_DIR',      plugin_dir_path( __FILE__ ) );
define( 'IGS_RECEIVER_URL',      plugin_dir_url( __FILE__ ) );
define( 'IGS_RECEIVER_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader — resolves IGS_* classes from includes/ and admin/
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'IGS_' ) !== 0 ) {
		return;
	}

	$class_file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

	$dirs = array(
		IGS_RECEIVER_DIR . 'includes/',
		IGS_RECEIVER_DIR . 'admin/',
	);

	foreach ( $dirs as $dir ) {
		$file = $dir . $class_file;
		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}
	}
} );

// Activation / Deactivation hooks
register_activation_hook( __FILE__, array( 'IGS_Receiver_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'IGS_Receiver_Activator', 'deactivate' ) );

/**
 * Main plugin class — bootstraps the Receiver.
 */
final class IGS_Migrator_Receiver {

	/**
	 * @var IGS_Migrator_Receiver
	 */
	private static $instance = null;

	/**
	 * @return IGS_Migrator_Receiver
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — load dependencies and register hooks.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Require all includes.
	 */
	private function load_dependencies() {
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-receiver-activator.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-auth-handler.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-block-parser.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-image-processor.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-relation-resolver.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-import-engine.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-taxonomy-sync.php';
		require_once IGS_RECEIVER_DIR . 'includes/class-igs-rest-endpoint.php';
		require_once IGS_RECEIVER_DIR . 'admin/class-igs-receiver-settings.php';
		require_once IGS_RECEIVER_DIR . 'includes/igs-updater.php';
	}

	/**
	 * Register WordPress hooks.
	 */
	private function init_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
	}

	/**
	 * Register REST API routes via the endpoint class.
	 */
	public function register_rest_routes() {
		$endpoint = new IGS_Rest_Endpoint(
			new IGS_Auth_Handler(),
			new IGS_Import_Engine(),
			new IGS_Taxonomy_Sync()
		);
		$endpoint->register_routes();
	}

	/**
	 * Init admin on plugins_loaded.
	 */
	public function on_plugins_loaded() {
		if ( is_admin() ) {
			IGS_Receiver_Settings::instance();
		}
	}
}

// Boot
IGS_Migrator_Receiver::instance();
