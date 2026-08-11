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
		global $wpdb;

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

		// ── Build normalized field values ─────────────────────────────────────
		$fields = array(
			'slug'             => $slug,
			'url'              => esc_url_raw( $url ),
			'name'             => sanitize_text_field( $link_data['name']        ?? '' ),
			'description'      => sanitize_textarea_field( $link_data['description'] ?? '' ),
			'redirect_type'    => sanitize_key( $link_data['redirect_type']      ?? '307' ),
			'nofollow'         => $this->truthy( $link_data['nofollow']         ?? 0 ),
			'sponsored'        => $this->truthy( $link_data['sponsored']        ?? 0 ),
			'param_forwarding' => $this->truthy( $link_data['param_forwarding'] ?? 0 ),
			'track_me'         => $this->truthy( $link_data['track_me']         ?? 0 ),
		);

		// ── Create the link ───────────────────────────────────────────────────
		$link_id = $this->create_link( $fields );

		if ( ! $link_id ) {
			return;
		}

		// ── Save meta ─────────────────────────────────────────────────────────
		$this->save_link_meta( $link_id, $meta_data, $dynamic_redirection );

		// ── Save rotations (only for non-geo) ─────────────────────────────────
		if ( 'geo' !== $dynamic_redirection && ! empty( $rotations ) ) {
			$this->insert_rotations( $link_id, $rotations );
		}
	}

	/**
	 * Create a Pretty Links record using the most appropriate available API.
	 *
	 * Strategy, in order of preference:
	 *   1. Pretty Links v4 repository (\PrettyLinks\Repositories\Links) — clean,
	 *      no deprecation notices, creates the backing CPT post too.
	 *   2. prli_create_pretty_link() — the documented public function present in
	 *      both v3 and v4 (a deprecation-logging shim in v4).
	 *   3. Legacy v3 global object $prli_link->create().
	 *   4. Direct DB insert into prli_links as a last resort.
	 *
	 * @param  array $fields  Normalized link fields (booleans as 1/0).
	 * @return int  New link ID, or 0 on failure.
	 */
	private function create_link( array $fields ) {
		// ── 1. v4 repository ──────────────────────────────────────────────────
		if ( class_exists( '\\PrettyLinks\\Repositories\\Links' ) ) {
			try {
				$repo   = new \PrettyLinks\Repositories\Links();
				$result = $repo->create( array(
					'url'              => $fields['url'],
					'slug'             => $fields['slug'],
					'name'             => $fields['name'],
					'description'      => $fields['description'],
					'redirect_type'    => $fields['redirect_type'],
					'track_me'         => $fields['track_me'],
					'nofollow'         => $fields['nofollow'],
					'sponsored'        => $fields['sponsored'],
					'param_forwarding' => $fields['param_forwarding'] ? 'on' : 'off',
				) );

				if ( is_array( $result ) && empty( $result['error'] ) && ! empty( $result['id'] ) ) {
					return (int) $result['id'];
				}
			} catch ( \Throwable $e ) {
				// Fall through to the next strategy.
				error_log( '[IGS Papi Import] PrettyLinks repo create() failed: ' . $e->getMessage() );
			}
		}

		// ── 2. Public function (v3 + v4) ──────────────────────────────────────
		// Pass explicit 1/0 (not '') so the source's on/off state is preserved
		// instead of falling back to the destination site's defaults.
		if ( function_exists( 'prli_create_pretty_link' ) ) {
			$link_id = prli_create_pretty_link(
				$fields['url'],
				$fields['slug'],
				$fields['name'],
				$fields['description'],
				0,
				$fields['track_me'],
				$fields['nofollow'],
				$fields['sponsored'],
				$fields['redirect_type'],
				$fields['param_forwarding']
			);

			if ( $link_id ) {
				return (int) $link_id;
			}
		}

		// ── 3. Legacy v3 object ───────────────────────────────────────────────
		global $prli_link;
		if ( isset( $prli_link ) && is_object( $prli_link ) && method_exists( $prli_link, 'create' ) ) {
			$values = array(
				'slug'          => $fields['slug'],
				'url'           => $fields['url'],
				'name'          => $fields['name'],
				'description'   => $fields['description'],
				'redirect_type' => $fields['redirect_type'],
				'link_cpt_id'   => 0,
			);
			foreach ( array( 'nofollow', 'sponsored', 'param_forwarding', 'track_me' ) as $bool_field ) {
				if ( $fields[ $bool_field ] ) {
					$values[ $bool_field ] = 1;
				}
			}

			$link_id = $prli_link->create( $values );
			if ( $link_id ) {
				return (int) $link_id;
			}
		}

		// ── 4. Direct DB insert ───────────────────────────────────────────────
		return (int) $this->insert_link_direct( $fields );
	}

	/**
	 * Save link meta directly into prli_link_metas, mirroring the format the
	 * export collector reads (grouped rows per meta_key, ordered by meta_order).
	 *
	 * Writing directly avoids any dependency on Pretty Links internals, which
	 * changed substantially between v3 and v4.
	 *
	 * @param  int    $link_id
	 * @param  array  $meta_data            [ meta_key => value|value[] ]
	 * @param  string $dynamic_redirection  Effective redirection mode.
	 */
	private function save_link_meta( $link_id, array $meta_data, $dynamic_redirection ) {
		global $wpdb;

		$table = $wpdb->prefix . 'prli_link_metas';

		if ( empty( $meta_data ) || ! $this->table_exists( $table ) ) {
			return;
		}

		foreach ( $meta_data as $key => $value ) {
			// In geo mode: do not persist geo_url / geo_countries, and force the
			// redirection mode to "none" (the resolved URL is now the basic URL).
			if ( 'geo' === $dynamic_redirection && in_array( $key, self::GEO_META_KEYS, true ) ) {
				continue;
			}

			if ( 'prli_dynamic_redirection' === $key && 'geo' === $dynamic_redirection ) {
				$value = 'none';
			}

			// Replace any rows the create() call may have seeded for this key.
			$wpdb->delete( $table, array( 'link_id' => $link_id, 'meta_key' => $key ) );

			// Pretty Links v4 dropped the meta_order column; row order is
			// preserved by the auto-increment id (insert order below).
			$values = is_array( $value ) ? array_values( $value ) : array( $value );

			foreach ( $values as $single ) {
				$wpdb->insert(
					$table,
					array(
						'link_id'    => $link_id,
						'meta_key'   => $key,
						'meta_value' => is_scalar( $single ) ? (string) $single : maybe_serialize( $single ),
						// prli_link_metas.created_at is NOT NULL with no default —
						// omitting it makes the INSERT fail under MySQL strict mode,
						// silently dropping all imported meta. Pretty Links v4 writes
						// this column itself (LinkMetas::set) for the same reason.
						'created_at' => current_time( 'mysql', true ),
					)
				);
			}
		}
	}

	/**
	 * Normalize a loosely-typed exported flag to 1 or 0.
	 *
	 * Handles v3 integers (1/0), v4 strings ('on'/'off'), and booleans.
	 *
	 * @param  mixed $value
	 * @return int  1 or 0.
	 */
	private function truthy( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '', '0', 'off', 'no', 'false' ), true ) ) {
				return 0;
			}
			return 1;
		}

		return $value ? 1 : 0;
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
	 * Last-resort direct insert into prli_links when no Pretty Links API is
	 * available (create_link() strategies 1–3 all failed).
	 *
	 * @param  array $values  Normalized link fields (valid prli_links columns).
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
