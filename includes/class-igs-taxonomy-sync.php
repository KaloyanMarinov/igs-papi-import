<?php
/**
 * Handles taxonomy pre-check and translation sync on the Receiver site.
 *
 * Pre-check: given a list of { taxonomy, slug } descriptors from the Source,
 * returns which slugs do not exist locally.
 *
 * Sync: given a list of translations { taxonomy, slug, translated_name, meta },
 * finds or creates terms on the receiver:
 *   - Search by translated_name (case-insensitive, first match)
 *     → Found: update the term's slug to the source slug.
 *   - Not found: create new term with translated_name as name, source slug as
 *     slug, and apply the supplied meta (only for new terms).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Taxonomy_Sync {

	// ── PUBLIC API ────────────────────────────────────────────────────────────

	/**
	 * Check which taxonomy slugs from the Source do not exist locally.
	 *
	 * @param  array $terms  Array of { taxonomy: string, slug: string, name: string }.
	 * @return array         Subset of $terms whose slug is not found on this site.
	 */
	public function check_missing( array $terms ) {
		$missing = array();

		foreach ( $terms as $item ) {
			$taxonomy = sanitize_key( $item['taxonomy'] ?? '' );
			$slug     = sanitize_title( $item['slug'] ?? '' );

			if ( ! $taxonomy || ! $slug ) {
				continue;
			}

			// If the taxonomy itself doesn't exist here, treat the term as missing.
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$missing[] = $item;
				continue;
			}

			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( ! $term ) {
				$missing[] = $item;
			}
		}

		return $missing;
	}

	/**
	 * Apply translation pairs sent from the Source site.
	 *
	 * Each entry in $translations must contain:
	 *   taxonomy        string  — taxonomy name
	 *   slug            string  — source site slug (target slug on this site)
	 *   translated_name string  — term name in this site's language
	 *   meta            array   — term meta to apply only when creating a new term
	 *
	 * @param  array $translations
	 * @return array  Per-term results:
	 *                [ { taxonomy, slug, action: 'updated'|'created'|'error', message? }, ... ]
	 */
	public function apply_sync( array $translations ) {
		$results = array();

		foreach ( $translations as $item ) {
			$taxonomy        = sanitize_key( $item['taxonomy']             ?? '' );
			$slug            = sanitize_title( $item['slug']               ?? '' );
			$translated_name = sanitize_text_field( $item['translated_name'] ?? '' );
			$meta            = ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) ? $item['meta'] : array();

			if ( ! $taxonomy || ! $slug || ! $translated_name ) {
				$results[] = array(
					'taxonomy' => $taxonomy,
					'slug'     => $slug,
					'action'   => 'error',
					'message'  => 'Missing required fields (taxonomy, slug, translated_name).',
				);
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				$results[] = array(
					'taxonomy' => $taxonomy,
					'slug'     => $slug,
					'action'   => 'error',
					'message'  => "Taxonomy '{$taxonomy}' does not exist on this site.",
				);
				continue;
			}

			// ── Search by translated name (case-insensitive on utf8 collations) ──
			$existing = get_term_by( 'name', $translated_name, $taxonomy );

			if ( $existing ) {
				// Found by name — update its slug to match the source slug.
				$update = wp_update_term( $existing->term_id, $taxonomy, array( 'slug' => $slug ) );

				if ( is_wp_error( $update ) ) {
					$results[] = array(
						'taxonomy' => $taxonomy,
						'slug'     => $slug,
						'action'   => 'error',
						'message'  => $update->get_error_message(),
					);
				} else {
					$results[] = array(
						'taxonomy' => $taxonomy,
						'slug'     => $slug,
						'action'   => 'updated',
					);
				}

				continue;
			}

			// ── Not found — create new term ───────────────────────────────────────
			$insert = wp_insert_term(
				$translated_name,
				$taxonomy,
				array( 'slug' => $slug )
			);

			if ( is_wp_error( $insert ) ) {
				$results[] = array(
					'taxonomy' => $taxonomy,
					'slug'     => $slug,
					'action'   => 'error',
					'message'  => $insert->get_error_message(),
				);
				continue;
			}

			$term_id = (int) $insert['term_id'];

			// Apply term meta — only for newly created terms.
			foreach ( $meta as $meta_key => $meta_value ) {
				$clean_key = sanitize_key( $meta_key );
				if ( $clean_key ) {
					update_term_meta( $term_id, $clean_key, $meta_value );
				}
			}

			$results[] = array(
				'taxonomy' => $taxonomy,
				'slug'     => $slug,
				'action'   => 'created',
			);
		}

		return $results;
	}
}
