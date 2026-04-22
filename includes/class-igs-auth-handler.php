<?php
/**
 * Validates incoming requests from the Source site.
 *
 * Authentication uses two HTTP headers:
 *   X-IGS-Key  — the raw API key (must match igs_receiver_api_key option)
 *   X-IGS-Sig  — HMAC-SHA256 of the raw request body, keyed with the API key
 *
 * Both checks must pass before a request is accepted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Auth_Handler {

	/**
	 * Validate the request headers and body signature.
	 *
	 * @param  WP_REST_Request $request  Incoming REST request.
	 * @return true|WP_Error
	 */
	public function validate( WP_REST_Request $request ) {
		$stored_key = get_option( 'igs_receiver_api_key', '' );

		// Receiver not configured yet.
		if ( empty( $stored_key ) ) {
			return new WP_Error(
				'igs_not_configured',
				__( 'Receiver API key is not configured.', 'igs-migrator' ),
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

		// Constant-time comparison prevents timing attacks.
		if ( ! hash_equals( $stored_key, $incoming_key ) ) {
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

		$expected_sig = hash_hmac( 'sha256', $request->get_body(), $stored_key );

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
