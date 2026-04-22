<?php
/**
 * Queues and resolves unresolved post_object ACF relations.
 *
 * When a post is imported and a post_object field references another post
 * that has not yet been migrated to this site, we store the relation here.
 * Each subsequent import triggers resolve_pending(), which walks the queue
 * and patches any relation whose referenced post has since arrived.
 *
 * Resolution updates two things:
 *   1. The block JSON in post_content — so ACF block rendering uses the
 *      correct local ID.
 *   2. The post_meta entry — so get_field() and meta queries work correctly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Relation_Resolver {

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'igs_receiver_relations';
	}

	// ── PUBLIC API ────────────────────────────────────────────────────────────

	/**
	 * Add a pending relation to the queue.
	 *
	 * @param int    $local_post_id      Local ID of the post that owns the field.
	 * @param string $field_name         ACF field name (e.g. "igs_related_casino").
	 * @param string $acf_field_key      ACF field key (e.g. "field_68272edc93169").
	 * @param int    $source_related_id  Source-site post ID that was not yet imported.
	 * @param string $source_site        Source site URL for scoping lookups.
	 */
	public function queue( $local_post_id, $field_name, $acf_field_key, $source_related_id, $source_site ) {
		global $wpdb;

		// Skip if already queued to avoid duplicates.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table}
				 WHERE local_post_id     = %d
				   AND field_name        = %s
				   AND source_related_id = %d
				   AND source_site       = %s
				   AND resolved          = 0
				 LIMIT 1",
				$local_post_id,
				$field_name,
				$source_related_id,
				$source_site
			)
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$this->table,
			array(
				'local_post_id'     => absint( $local_post_id ),
				'field_name'        => sanitize_key( $field_name ),
				'acf_field_key'     => sanitize_text_field( $acf_field_key ),
				'source_related_id' => absint( $source_related_id ),
				'source_site'       => esc_url_raw( $source_site ),
				'resolved'          => 0,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Attempt to resolve all pending relations for the given source site.
	 *
	 * Called automatically after each successful import so that posts
	 * already in the queue can be patched as soon as their dependency arrives.
	 *
	 * @param string $source_site  Source site URL.
	 */
	public function resolve_pending( $source_site ) {
		global $wpdb;

		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE resolved   = 0
				   AND source_site = %s",
				esc_url_raw( $source_site )
			)
		);

		if ( empty( $pending ) ) {
			return;
		}

		$parser = new IGS_Block_Parser();

		foreach ( $pending as $relation ) {
			$related_local_id = $this->find_local_post(
				(int) $relation->source_related_id,
				$relation->source_site
			);

			if ( ! $related_local_id ) {
				continue; // Referenced post not yet imported — try again next time.
			}

			// ── 1. Patch post_content block JSON ──────────────────────────────
			$post = get_post( (int) $relation->local_post_id );
			if ( $post ) {
				$updated_content = $this->patch_block_content(
					$post->post_content,
					$relation->field_name,
					$relation->acf_field_key,
					(int) $relation->source_related_id,
					$related_local_id,
					$parser
				);

				if ( $updated_content !== $post->post_content ) {
					// Bypass post_modified timestamp update for background patches.
					$wpdb->update(
						$wpdb->posts,
						array( 'post_content' => $updated_content ),
						array( 'ID'           => $post->ID ),
						array( '%s' ),
						array( '%d' )
					);
					clean_post_cache( $post->ID );
				}
			}

			// ── 2. Patch post_meta ────────────────────────────────────────────
			update_post_meta(
				(int) $relation->local_post_id,
				$relation->field_name,
				$related_local_id
			);

			// ── 3. Mark resolved ──────────────────────────────────────────────
			$wpdb->update(
				$this->table,
				array( 'resolved' => 1 ),
				array( 'id'       => $relation->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	// ── PRIVATE HELPERS ───────────────────────────────────────────────────────

	/**
	 * Find the local post ID that was imported from the given source.
	 *
	 * @param  int    $source_post_id
	 * @param  string $source_site
	 * @return int  Local post ID, or 0 if not found.
	 */
	private function find_local_post( $source_post_id, $source_site ) {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_igs_source_post_id',
						'value' => $source_post_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_igs_source_site',
						'value' => $source_site,
					),
				),
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	/**
	 * Walk block data looking for a field that still holds the unresolved
	 * source post ID and replace it with the resolved local ID.
	 *
	 * Matches on:
	 *   - field_name key holding the raw source_related_id (set as placeholder)
	 *   - ACF reference key `_field_name` matching acf_field_key
	 *
	 * @param  string          $post_content
	 * @param  string          $field_name
	 * @param  string          $acf_field_key
	 * @param  int             $source_id
	 * @param  int             $local_id
	 * @param  IGS_Block_Parser $parser
	 * @return string  Updated post_content (unchanged if nothing matched).
	 */
	private function patch_block_content( $post_content, $field_name, $acf_field_key, $source_id, $local_id, IGS_Block_Parser $parser ) {
		$blocks    = $parser->parse( $post_content );
		$processed = array();
		$changed   = false;

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['invalid'] ) ) {
				continue;
			}

			$data    = $block['data'];
			$matched = false;

			// Check by field_name + value == source_id.
			if ( isset( $data[ $field_name ] ) && (int) $data[ $field_name ] === $source_id ) {
				$data[ $field_name ] = $local_id;
				$matched             = true;
			}

			// Also check by ACF reference key (_field_name) == acf_field_key.
			if ( ! $matched && $acf_field_key ) {
				foreach ( $data as $key => $value ) {
					if ( strpos( $key, '_' ) === 0 ) {
						continue; // skip ref keys themselves
					}
					$ref_key = isset( $data[ '_' . $key ] ) ? $data[ '_' . $key ] : '';
					if ( $ref_key === $acf_field_key && (int) $value === $source_id ) {
						$data[ $key ] = $local_id;
						$matched      = true;
						break;
					}
				}
			}

			if ( $matched ) {
				$changed = true;
			}

			$processed[] = array(
				'block'    => $block,
				'new_data' => $data,
			);
		}

		if ( ! $changed ) {
			return $post_content;
		}

		return $parser->rebuild_content( $post_content, $processed );
	}
}
