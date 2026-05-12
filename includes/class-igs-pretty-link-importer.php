<?php
/**
 * Imports a Pretty Links record from an export payload.
 *
 * Rules:
 *   - If a link with the same slug already exists → skip (no update).
 *   - If dynamic_redirection is "geo":
 *       • Find the geo_url whose geo_countries entry matches the site
 *         language (WPLANG option) and use it as the Basic URL.
 *       • Do NOT save geo_url / geo_countries meta.
 *       • Save prli_dynamic_redirection as "none".
 *   - All other dynamic_redirection values → create normally with full
 *     meta and rotations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Pretty_Link_Importer {

	/**
	 * Geo-related meta keys to suppress when dynamic_redirection is "geo".
	 */
	const GEO_META_KEYS = array( 'geo_url', 'geo_countries' );

	/**
	 * Import a single Pretty Links record.
	 *
	 * @param  array $pretty_link  [ 'link' => [...], 'meta' => [...], 'rotations' => [...] ]
	 */
	public function import( array $pretty_link ) {
		global $wpdb, $prli_link, $prli_link_meta;

		$link_data = $pretty_link['link']      ?? array();
		$meta_data = $pretty_link['meta']      ?? array();
		$rotations = $pretty_link['rotations'] ?? array();

		$slug = sanitize_text_field( $link_data['slug'] ?? '' );

		if ( ! $slug ) {
			return;
		}

		if ( ! $this->table_exists( $wpdb->prefix . 'prli_links' ) ) {
			return;
		}

		// ── Skip if slug already exists ───────────────────────────────────────
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}prli_links WHERE slug = %s LIMIT 1",
				$slug
			)
		);

		if ( $existing_id ) {
			return;
		}

		// ── Resolve URL and dynamic_redirection ───────────────────────────────
		$dynamic_redirection = $meta_data['prli_dynamic_redirection'] ?? 'none';
		$url                 = $link_data['url'] ?? '';

		if ( 'geo' === $dynamic_redirection ) {
			$geo_url = $this->resolve_geo_url( $meta_data );
			if ( $geo_url ) {
				$url = $geo_url;
			}
		}

		// ── Build values for PrliLink::create() ───────────────────────────────
		// PrliLink::sanitize() treats boolean fields as "present = 1, absent = 0",
		// so we only include them when their exported value is truthy.
		$values = array(
			'slug'          => $slug,
			'url'           => esc_url_raw( $url ),
			'name'          => sanitize_text_field( $link_data['name']        ?? '' ),
			'description'   => sanitize_textarea_field( $link_data['description'] ?? '' ),
			'redirect_type' => sanitize_key( $link_data['redirect_type']      ?? '307' ),
			'link_cpt_id'   => 0, // create() auto-creates the CPT post.
		);

		foreach ( array( 'nofollow', 'sponsored', 'param_forwarding', 'track_me' ) as $bool_field ) {
			if ( ! empty( $link_data[ $bool_field ] ) ) {
				$values[ $bool_field ] = 1;
			}
		}

		// ── Create the link ───────────────────────────────────────────────────
		if ( isset( $prli_link ) && is_object( $prli_link ) ) {
			$link_id = $prli_link->create( $values );
		} else {
			$link_id = $this->insert_link_direct( $values );
		}

		if ( ! $link_id ) {
			return;
		}

		// ── Save meta ─────────────────────────────────────────────────────────
		if ( ! isset( $prli_link_meta ) || ! is_object( $prli_link_meta ) ) {
			return;
		}

		foreach ( $meta_data as $key => $value ) {
			if ( 'geo' === $dynamic_redirection && in_array( $key, self::GEO_META_KEYS, true ) ) {
				continue;
			}

			if ( 'prli_dynamic_redirection' === $key && 'geo' === $dynamic_redirection ) {
				$prli_link_meta->update_link_meta( $link_id, $key, 'none' );
				continue;
			}

			$prli_link_meta->update_link_meta( $link_id, $key, $value );
		}

		// ── Save rotations (only for non-geo) ─────────────────────────────────
		if ( 'geo' !== $dynamic_redirection && ! empty( $rotations ) ) {
			$this->insert_rotations( $link_id, $rotations );
		}
	}

	// ── PRIVATE ───────────────────────────────────────────────────────────────

	/**
	 * Find the geo_url entry whose geo_countries value matches the site language.
	 *
	 * geo_countries entries are formatted as "Country Name [CC]", e.g. "Azerbaijan [AZ]".
	 * WPLANG is either "az" or "az_AZ" — we extract the country code from both.
	 *
	 * @param  array $meta
	 * @return string|null  Matched URL, or null if no match.
	 */
	private function resolve_geo_url( array $meta ) {
		$geo_countries = $meta['geo_countries'] ?? array();
		$geo_urls      = $meta['geo_url']       ?? array();

		if ( empty( $geo_countries ) || empty( $geo_urls ) ) {
			return null;
		}

		if ( ! is_array( $geo_countries ) ) {
			$geo_countries = array( $geo_countries );
		}

		if ( ! is_array( $geo_urls ) ) {
			$geo_urls = array( $geo_urls );
		}

		$country_code = $this->site_country_code();

		if ( ! $country_code ) {
			return null;
		}

		$needle = '[' . $country_code . ']';

		foreach ( $geo_countries as $i => $entry ) {
			if ( strpos( $entry, $needle ) !== false ) {
				return isset( $geo_urls[ $i ] ) ? esc_url_raw( $geo_urls[ $i ] ) : null;
			}
		}

		return null;
	}

	/**
	 * Derive a two-letter country code from the WPLANG option.
	 *
	 * "az"    → "AZ"
	 * "az_AZ" → "AZ"
	 * "bg_BG" → "BG"
	 *
	 * @return string  Uppercase country code, or '' if WPLANG is not set.
	 */
	private function site_country_code() {
		$wplang = get_option( 'WPLANG', '' );

		if ( ! $wplang ) {
			return '';
		}

		if ( strpos( $wplang, '_' ) !== false ) {
			return strtoupper( substr( $wplang, strpos( $wplang, '_' ) + 1 ) );
		}

		return strtoupper( $wplang );
	}

	/**
	 * Fallback direct insert when $prli_link global is unavailable.
	 *
	 * @param  array $values
	 * @return int|false  Inserted link ID, or false on failure.
	 */
	private function insert_link_direct( array $values ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array_merge(
			$values,
			array(
				'link_status' => 'enabled',
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$result = $wpdb->insert( $wpdb->prefix . 'prli_links', $row );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Insert rotation rows for a link.
	 *
	 * @param  int   $link_id
	 * @param  array $rotations  [ [ 'url', 'weight', 'r_index' ], ... ]
	 */
	private function insert_rotations( $link_id, array $rotations ) {
		global $wpdb;

		$table = $wpdb->prefix . 'prli_link_rotations';

		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$now = current_time( 'mysql' );

		foreach ( $rotations as $rotation ) {
			$wpdb->insert(
				$table,
				array(
					'url'        => esc_url_raw( $rotation['url']     ?? '' ),
					'weight'     => absint( $rotation['weight']        ?? 0 ),
					'r_index'    => absint( $rotation['r_index']       ?? 0 ),
					'link_id'    => $link_id,
					'created_at' => $now,
				)
			);
		}
	}

	/**
	 * @param  string $table  Full table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}
}
