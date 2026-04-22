<?php
/**
 * Registers the REST API routes used by the Receiver.
 *
 * Routes:
 *   POST /wp-json/igs/v1/ping            — connection test from Source site
 *   POST /wp-json/igs/v1/import          — full migration payload from Source site
 *   POST /wp-json/igs/v1/taxonomy-check  — pre-check which taxonomy slugs are missing
 *   POST /wp-json/igs/v1/taxonomy-sync   — apply translated term names / create missing terms
 *
 * All routes require a valid X-IGS-Key + X-IGS-Sig pair (see IGS_Auth_Handler).
 * Standard WordPress cookie / nonce authentication is intentionally bypassed
 * because requests come from a different domain.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Rest_Endpoint {

	/**
	 * @var IGS_Auth_Handler
	 */
	private $auth;

	/**
	 * @var IGS_Import_Engine
	 */
	private $engine;

	/**
	 * @var IGS_Taxonomy_Sync
	 */
	private $taxonomy_sync;

	/**
	 * Constructor.
	 *
	 * @param IGS_Auth_Handler  $auth
	 * @param IGS_Import_Engine $engine
	 * @param IGS_Taxonomy_Sync $taxonomy_sync
	 */
	public function __construct( IGS_Auth_Handler $auth, IGS_Import_Engine $engine, IGS_Taxonomy_Sync $taxonomy_sync ) {
		$this->auth          = $auth;
		$this->engine        = $engine;
		$this->taxonomy_sync = $taxonomy_sync;
	}

	// ── PUBLIC — ROUTE REGISTRATION ───────────────────────────────────────────

	/**
	 * Register both REST routes.
	 * Must be called from the rest_api_init hook.
	 */
	public function register_routes() {
		// ── Ping ──────────────────────────────────────────────────────────────
		register_rest_route(
			'igs/v1',
			'/ping',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ── Import ────────────────────────────────────────────────────────────
		register_rest_route(
			'igs/v1',
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ── Taxonomy check ────────────────────────────────────────────────────
		register_rest_route(
			'igs/v1',
			'/taxonomy-check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_taxonomy_check' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// ── Taxonomy sync ─────────────────────────────────────────────────────
		register_rest_route(
			'igs/v1',
			'/taxonomy-sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_taxonomy_sync' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// ── PERMISSION CALLBACK ───────────────────────────────────────────────────

	/**
	 * Validate the incoming request via HMAC authentication.
	 *
	 * Returning a WP_Error causes WordPress to respond with the error's
	 * status code and message before the route callback is invoked.
	 *
	 * @param  WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		return $this->auth->validate( $request );
	}

	// ── PING HANDLER ─────────────────────────────────────────────────────────

	/**
	 * Respond to a ping from the Source site.
	 *
	 * Used by the Source site's "Test Connection" feature.
	 * Returns 200 with a simple pong message.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_ping( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'pong',
				'site'    => get_site_url(),
			),
			200
		);
	}

	// ── IMPORT HANDLER ────────────────────────────────────────────────────────

	/**
	 * Receive and process a migration payload from the Source site.
	 *
	 * On success returns 200 with the local post ID.
	 * On validation or import error returns 400 / 500 with a message.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_import( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Empty request body.', 'igs-migrator' ) ),
				400
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Invalid JSON payload.', 'igs-migrator' ) ),
				400
			);
		}

		// Increase execution time limit for large payloads with many images.
		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 300 );
		}

		$result = $this->engine->import( $payload );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Import completed successfully.', 'igs-migrator' ),
				'post_id' => $result,
			),
			200
		);
	}

	// ── TAXONOMY CHECK HANDLER ────────────────────────────────────────────────

	/**
	 * Receive a list of taxonomy terms from the Source and return the subset
	 * whose slugs do not exist on this receiver site.
	 *
	 * Expects JSON body: { "terms": [ { taxonomy, slug, name }, ... ] }
	 * Returns 200:       { "success": true, "missing": [ ... ] }
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_taxonomy_check( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Empty request body.', 'igs-migrator' ) ),
				400
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) || ! isset( $payload['terms'] ) || ! is_array( $payload['terms'] ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Invalid payload — expected { terms: [...] }.', 'igs-migrator' ) ),
				400
			);
		}

		$missing = $this->taxonomy_sync->check_missing( $payload['terms'] );

		return new WP_REST_Response(
			array(
				'success' => true,
				'missing' => $missing,
			),
			200
		);
	}

	// ── TAXONOMY SYNC HANDLER ─────────────────────────────────────────────────

	/**
	 * Receive a list of translation pairs from the Source and create/update
	 * the corresponding terms on this receiver site.
	 *
	 * Expects JSON body: { "translations": [ { taxonomy, slug, translated_name, meta }, ... ] }
	 * Returns 200:       { "success": true, "results": [ { taxonomy, slug, action }, ... ] }
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_taxonomy_sync( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Empty request body.', 'igs-migrator' ) ),
				400
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) || ! isset( $payload['translations'] ) || ! is_array( $payload['translations'] ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Invalid payload — expected { translations: [...] }.', 'igs-migrator' ) ),
				400
			);
		}

		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 120 );
		}

		$results = $this->taxonomy_sync->apply_sync( $payload['translations'] );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Taxonomy sync completed.', 'igs-migrator' ),
				'results' => $results,
			),
			200
		);
	}
}
