<?php
/**
 * Validates incoming requests from the Source site.
 *
 * Authentication uses two HTTP headers:
 *   X-IGS-Key  — the raw API key (must match one of the connected sites)
 *   X-IGS-Sig  — HMAC-SHA256 of the raw request body, keyed with the API key
 *
 * Both checks must pass before a request is accepted. Multiple Source sites
 * can be connected, each with its own API key (stored in igs_receiver_api_keys).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Auth_Handler {

	/**
	 * Option storing the list of connected source sites.
	 *
	 * Structure: array of [ 'id' => string, 'label' => string, 'key' => string ].
	 * Each connected Source site has its own API key; any matching key is accepted.
	 */
	const OPTION_SITES = 'igs_receiver_api_keys';

	/**
	 * Legacy single-key option, kept for backward-compatible migration.
	 */
	const OPTION_LEGACY_KEY = 'igs_receiver_api_key';

	// ── STORAGE HELPERS ─────────────────────────────────────────────────────────

	/**
	 * Return the list of connected sites.
	 *
	 * On first read, transparently migrates the legacy single-key option into
	 * the new multi-site list so existing installs keep working.
	 *
	 * @return array  List of [ 'id', 'label', 'key' ] entries.
	 */
	public static function get_sites() {
		$sites = get_option( self::OPTION_SITES, null );

		if ( null === $sites || ! is_array( $sites ) ) {
			// Migrate from the legacy single-key option (if any).
			$legacy = get_option( self::OPTION_LEGACY_KEY, '' );
			$sites  = array();

			if ( ! empty( $legacy ) ) {
				$sites[] = array(
					'id'    => self::generate_id(),
					'label' => __( 'Site 1', 'igs-migrator' ),
					'key'   => $legacy,
				);
			}

			update_option( self::OPTION_SITES, $sites );
		}

		return $sites;
	}

	/**
	 * Persist the list of connected sites.
	 *
	 * @param array $sites  List of [ 'id', 'label', 'key' ] entries.
	 */
	public static function save_sites( array $sites ) {
		update_option( self::OPTION_SITES, array_values( $sites ) );
	}

	/**
	 * Generate a short unique identifier for a site entry.
	 *
	 * @return string
	 */
	public static function generate_id() {
		return substr( md5( uniqid( '', true ) ), 0, 12 );
	}

	// ── VALIDATION ──────────────────────────────────────────────────────────────

	/**
	 * Validate the request headers and body signature.
	 *
	 * The incoming key is matched against every configured site. Once a site
	 * matches, the body signature is verified using that site's own key.
	 *
	 * @param  WP_REST_Request $request  Incoming REST request.
	 * @return true|WP_Error
	 */
	public function validate( WP_REST_Request $request ) {
		$sites = self::get_sites();

		// Receiver not configured yet.
		if ( empty( $sites ) ) {
			return new WP_Error(
				'igs_not_configured',
				__( 'No connected sites are configured.', 'igs-migrator' ),
				array( 'status' => 403 )
			);
		}

		// ── Key check ──────────────────────────────────────────────────────────
		$incoming_key = $request->get_header( 'x_igs_key' );

		if ( empty( $incoming_key ) ) {
			return new WP_Error(
				'igs_missing_key',
				__( 'Missing X-IGS-Key header.', 'igs-migrator' ),
				array( 'status' => 401 )
			);
		}

		// Find the site whose key matches. Constant-time comparison per entry
		// prevents timing attacks.
		$matched = null;
		foreach ( $sites as $site ) {
			if ( ! empty( $site['key'] ) && hash_equals( (string) $site['key'], $incoming_key ) ) {
				$matched = $site;
				break;
			}
		}

		if ( null === $matched ) {
			return new WP_Error(
				'igs_invalid_key',
				__( 'Invalid API key.', 'igs-migrator' ),
				array( 'status' => 401 )
			);
		}

		// ── Signature check ───────────────────────────────────────────────────
		$incoming_sig = $request->get_header( 'x_igs_sig' );

		if ( empty( $incoming_sig ) ) {
			return new WP_Error(
				'igs_missing_sig',
				__( 'Missing X-IGS-Sig header.', 'igs-migrator' ),
				array( 'status' => 401 )
			);
		}

		$expected_sig = hash_hmac( 'sha256', $request->get_body(), $matched['key'] );

		if ( ! hash_equals( $expected_sig, $incoming_sig ) ) {
			return new WP_Error(
				'igs_invalid_sig',
				__( 'Payload signature mismatch.', 'igs-migrator' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}
}
