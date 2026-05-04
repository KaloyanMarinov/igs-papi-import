<?php
/**
 * Processes the migration payload and creates or updates posts.
 *
 * Responsibilities:
 *   - Apply the update_policy (skip / update / overwrite).
 *   - Resolve the parent post reference.
 *   - Process post_content: parse ACF blocks, resolve image/gallery/
 *     post_object descriptors back to local IDs, rebuild content.
 *   - Import post_meta.
 *   - Import children recursively.
 *   - Queue and immediately attempt resolution of post_object relations.
 *
 * Payload format (produced by IGS_Export_Engine on the Source):
 * {
 *   source_site:      string,
 *   source_post_id:   int,
 *   update_policy:    'skip' | 'update' | 'overwrite',
 *   post:             { post_title, post_name, post_status, post_type, ... },
 *   post_content:     string  (ACF block HTML with image descriptors),
 *   post_meta:        { key: value, ... },
 *   post_taxonomies:  [ { taxonomy, name, slug, meta } ],
 *   parent:           { source_post_id, post_name, post_type } | null,
 *   children:         [ ...child payloads... ],
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Import_Engine {

	/**
	 * @var IGS_Image_Processor
	 */
	private $images;

	/**
	 * @var IGS_Block_Parser
	 */
	private $parser;

	/**
	 * Collected pending relations discovered during block processing.
	 * Cleared at the start of each import_single() call.
	 *
	 * @var array  [ [ 'field_name', 'field_key', 'source_id' ], ... ]
	 */
	private $pending_relations = array();

	/**
	 * Source site URL for the current import operation.
	 * Set in import_single() before process_content() is called so that
	 * resolve_parent_ref() can call find_existing() with the correct scope.
	 *
	 * @var string
	 */
	private $current_source_site = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->images = new IGS_Image_Processor();
		$this->parser = new IGS_Block_Parser();
	}

	// ── PUBLIC API ────────────────────────────────────────────────────────────

	/**
	 * Import a full payload (post + optional children).
	 *
	 * @param  array $payload  Decoded JSON payload from the Source site.
	 * @return int|WP_Error  Local post ID of the main post on success.
	 */
	public function import( array $payload ) {
		$source_site    = sanitize_text_field( $payload['source_site']    ?? '' );
		$source_post_id = absint( $payload['source_post_id'] ?? 0 );
		$policy         = $this->sanitize_policy( $payload['update_policy'] ?? 'skip' );

		if ( ! $source_site || ! $source_post_id ) {
			return new WP_Error( 'igs_invalid_payload', __( 'Missing source_site or source_post_id.', 'igs-migrator' ) );
		}

		// Import the main post first.
		$local_id = $this->import_single( $payload, $source_site, $source_post_id, $policy, 0 );

		if ( is_wp_error( $local_id ) ) {
			return $local_id;
		}

		// Import children — each child inherits the same policy.
		if ( ! empty( $payload['children'] ) && is_array( $payload['children'] ) ) {
			foreach ( $payload['children'] as $child_payload ) {
				$child_payload['update_policy'] = $policy;
				$this->import_single(
					$child_payload,
					esc_url_raw( $child_payload['source_site'] ?? $source_site ),
					absint( $child_payload['source_post_id'] ?? 0 ),
					$policy,
					$local_id   // resolved local parent ID
				);
			}
		}

		return $local_id;
	}

	// ── PRIVATE — SINGLE POST ─────────────────────────────────────────────────

	/**
	 * Import one post (no recursion into children).
	 *
	 * @param  array  $payload
	 * @param  string $source_site
	 * @param  int    $source_post_id
	 * @param  string $policy
	 * @param  int    $resolved_parent_id  Already-resolved local parent ID (0 = none).
	 * @return int|WP_Error  Local post ID.
	 */
	private function import_single( array $payload, $source_site, $source_post_id, $policy, $resolved_parent_id ) {
		if ( ! $source_post_id ) {
			return new WP_Error( 'igs_missing_source_id', __( 'Missing source_post_id in payload.', 'igs-migrator' ) );
		}

		// ── Policy: skip ──────────────────────────────────────────────────────
		$existing_id = $this->find_existing( $source_post_id, $source_site );

		if ( $existing_id && 'skip' === $policy ) {
			return $existing_id;
		}

		// ── Resolve post_content ──────────────────────────────────────────────
		$this->pending_relations   = array();
		$this->current_source_site = $source_site;
		$post_content = $this->process_content( $payload['post_content'] ?? '' );

		// ── Resolve parent ────────────────────────────────────────────────────
		$parent_id = $resolved_parent_id;

		if ( ! $parent_id && ! empty( $payload['parent'] ) ) {
			$parent_id = $this->find_existing(
				absint( $payload['parent']['source_post_id'] ?? 0 ),
				$source_site
			);
		}

		// ── Build post data ───────────────────────────────────────────────────
		$post_fields = $payload['post'] ?? array();

		$default_author = (int) get_option( 'igs_receiver_default_author', 0 );

		$post_title = $this->apply_title_replacements(
			sanitize_text_field( $post_fields['post_title'] ?? '' )
		);

		// Determine post slug for casino posts.
		$post_name = '';
		if ( 'casino' === sanitize_key( $post_fields['post_type'] ?? '' ) ) {
			if ( ! $resolved_parent_id ) {
				// Main casino post — use igs_casino_slug from the casino-menu taxonomy term if set.
				$post_name = $this->get_casino_slug_from_taxonomy( $payload['post_taxonomies'] ?? array() );
			} else {
				// Child casino post — use igs_casino_slug directly if set,
				// otherwise strip the parent title from the child title.
				$casino_slug_part = $this->get_casino_slug_from_taxonomy( $payload['post_taxonomies'] ?? array() );

				if ( $casino_slug_part ) {
					$post_name = $casino_slug_part;
				} else {
					$parent_title = get_the_title( $resolved_parent_id );
					if ( $parent_title ) {
						$slug_base = trim( str_ireplace( $parent_title, '', $post_title ) );
						if ( $slug_base ) {
							$post_name = sanitize_title( $slug_base );
						}
					}
				}
			}
		}

		$post_data = array(
			'post_title'     => $post_title,
			'post_name'      => $post_name, // Empty = WP auto-generates from title.
			'post_status'    => 'draft', // Always import as draft — admin publishes manually.
			'post_type'      => sanitize_key( $post_fields['post_type']           ?? 'post' ),
			'post_excerpt'   => sanitize_textarea_field( $post_fields['post_excerpt'] ?? '' ),
			'post_date'      => sanitize_text_field( $post_fields['post_date']    ?? current_time( 'mysql' ) ),
			'menu_order'     => absint( $post_fields['menu_order']                ?? 0 ),
			'comment_status' => sanitize_key( $post_fields['comment_status']      ?? 'closed' ),
			'ping_status'    => sanitize_key( $post_fields['ping_status']         ?? 'closed' ),
			'post_content'   => $post_content,
			'post_parent'    => $parent_id,
			'post_author'    => $default_author,
		);

		// ── Insert or update ──────────────────────────────────────────────────
		// kses_remove_filters() prevents wp_kses_post() from HTML-encoding
		// ACF block comments (<!-- wp:acf/... -->).
		// wp_slash() counteracts the wp_unslash() called inside wp_insert_post(),
		// which would otherwise strip backslashes from JSON escape sequences
		// (e.g. \" in HTML attribute values inside block JSON).
		kses_remove_filters();

		if ( $existing_id && in_array( $policy, array( 'update', 'overwrite' ), true ) ) {
			$post_data['ID'] = $existing_id;
			$local_id = wp_update_post( wp_slash( $post_data ), true );
		} else {
			$local_id = wp_insert_post( wp_slash( $post_data ), true );
		}

		kses_init_filters();

		if ( is_wp_error( $local_id ) ) {
			return $local_id;
		}

		// ── Tracking meta ─────────────────────────────────────────────────────
		update_post_meta( $local_id, '_igs_source_post_id', $source_post_id );
		update_post_meta( $local_id, '_igs_source_site',    $source_site );
		update_post_meta( $local_id, '_igs_migrated_at',    current_time( 'mysql' ) );

		// ── Post meta ─────────────────────────────────────────────────────────
		if ( ! empty( $payload['post_meta'] ) && is_array( $payload['post_meta'] ) ) {
			$this->import_post_meta( $local_id, $payload['post_meta'], $policy );
		}

		// ── Title replacements in Yoast SEO meta ──────────────────────────────
		foreach ( array( '_yoast_wpseo_focuskw', '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ) as $yoast_key ) {
			$value = get_post_meta( $local_id, $yoast_key, true );
			if ( $value && is_string( $value ) ) {
				update_post_meta( $local_id, $yoast_key, $this->apply_title_replacements( $value ) );
			}
		}

		// ── Post taxonomies ───────────────────────────────────────────────────
		if ( ! empty( $payload['post_taxonomies'] ) && is_array( $payload['post_taxonomies'] ) ) {
			$this->import_post_taxonomies( $local_id, $payload['post_taxonomies'] );
		}

		// ── Relation queue ────────────────────────────────────────────────────
		$resolver = new IGS_Relation_Resolver();

		// Relations discovered while processing post_content blocks.
		foreach ( $this->pending_relations as $rel ) {
			$resolver->queue(
				$local_id,
				$rel['field_name'],
				$rel['field_key'],
				$rel['source_id'],
				$source_site
			);
		}

		// Immediately attempt resolution — newly imported post may unlock others.
		$resolver->resolve_pending( $source_site );

		return $local_id;
	}

	// ── PRIVATE — CONTENT PROCESSING ─────────────────────────────────────────

	/**
	 * Parse, resolve and rebuild post_content.
	 *
	 * Only blocks that actually contain image/relation descriptors are
	 * re-encoded.  Untouched blocks keep their original raw string so that
	 * Gutenberg block validation continues to pass on the receiver site.
	 *
	 * @param  string $post_content
	 * @return string  Processed post_content.
	 */
	private function process_content( $post_content ) {
		if ( empty( $post_content ) ) {
			return '';
		}

		$blocks    = $this->parser->parse( $post_content );
		$processed = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['invalid'] ) ) {
				continue;
			}

			$new_data = $this->resolve_block_data( $block['data'] );

			// Only rebuild blocks where something actually changed.
			// Rebuilding an unmodified block can produce subtly different JSON
			// (e.g. number ↔ string coercion) that breaks Gutenberg validation.
			if ( $new_data === $block['data'] ) {
				continue;
			}

			$processed[] = array(
				'block'    => $block,
				'new_data' => $new_data,
			);
		}

		return $this->parser->rebuild_content( $post_content, $processed );
	}

	/**
	 * Resolve all export descriptors inside a single block's data array.
	 *
	 * @param  array $data  Block data from the Source payload.
	 * @return array  Resolved data with local IDs.
	 */
	private function resolve_block_data( array $data ) {
		foreach ( $data as $key => $value ) {

			// Skip ACF internal reference keys (e.g. _igs_gallery → field_xxx).
			if ( strpos( $key, '_' ) === 0 ) {
				continue;
			}

			// ── Image descriptor ──────────────────────────────────────────────
			if ( $this->is_image_descriptor( $value ) ) {
				$data[ $key ] = $this->images->resolve_image( $value );
				continue;
			}

			// ── Gallery (array of image descriptors) ──────────────────────────
			if ( $this->is_gallery_descriptor( $value ) ) {
				$data[ $key ] = $this->images->resolve_gallery( $value );
				continue;
			}

			// ── Parent reference descriptor ───────────────────────────────────
			if ( $this->is_parent_ref_descriptor( $value ) ) {
				$data[ $key ] = $this->resolve_parent_ref( $value );
				continue;
			}

			// ── Post object descriptor ────────────────────────────────────────
			if ( $this->is_post_object_descriptor( $value ) ) {
				$field_key    = isset( $data[ '_' . $key ] ) ? $data[ '_' . $key ] : '';
				$data[ $key ] = $this->resolve_post_object( $key, $field_key, $value );
				continue;
			}

			// ── Taxonomy term descriptor (single) ─────────────────────────────
			if ( $this->is_taxonomy_term_descriptor( $value ) ) {
				$data[ $key ] = $this->find_term_by_slug( $value );
				continue;
			}

			// ── Taxonomy term array (multi) ───────────────────────────────────
			if ( $this->is_taxonomy_term_array( $value ) ) {
				$ids = array();
				foreach ( $value as $descriptor ) {
					if ( is_array( $descriptor ) ) {
						$id = $this->find_term_by_slug( $descriptor );
						if ( $id ) {
							$ids[] = $id;
						}
					}
				}
				$data[ $key ] = $ids;
				continue;
			}

			// ── HTML string with inline image markers ─────────────────────────
			if ( is_string( $value ) && strpos( $value, 'data-igs-export' ) !== false ) {
				$data[ $key ] = $this->images->process_html_imgs( $value );
				continue;
			}
		}

		return $data;
	}

	/**
	 * Resolve a post_object descriptor to a local post ID.
	 *
	 * If the referenced post is not yet imported, the source ID is stored in
	 * $this->pending_relations and returned as a placeholder.
	 *
	 * @param  string $field_name
	 * @param  string $field_key
	 * @param  array  $descriptor
	 * @return int  Local post ID, or source_id as placeholder.
	 */
	private function resolve_post_object( $field_name, $field_key, array $descriptor ) {
		$source_id = absint( $descriptor['source_id'] ?? 0 );

		if ( ! $source_id ) {
			return 0;
		}

		// Try to find the post locally (it may have been imported earlier).
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_igs_source_post_id',
						'value' => $source_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}

		// Not yet available — queue for later resolution.
		$this->pending_relations[] = array(
			'field_name' => $field_name,
			'field_key'  => $field_key,
			'source_id'  => $source_id,
		);

		// Return the source ID as a temporary placeholder.
		// resolve_pending() will overwrite it once the post arrives.
		return $source_id;
	}

	// ── PRIVATE — POST META ───────────────────────────────────────────────────

	/**
	 * Import post_meta from the payload.
	 *
	 * In "update" policy mode, existing meta keys are preserved (local
	 * overrides — e.g. translations — survive a content update).
	 * In "overwrite" policy mode, all meta is replaced.
	 *
	 * @param  int    $post_id
	 * @param  array  $meta    Raw post_meta from the Source payload.
	 * @param  string $policy
	 */
	private function import_post_meta( $post_id, array $meta, $policy ) {
		// These keys must never be imported (either WP internal or our tracking keys).
		$excluded = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_pingme',
			'_encloseme',
			'_igs_source_post_id',
			'_igs_source_site',
			'_igs_migrated_at',
		);

		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $excluded, true ) ) {
				continue;
			}

			// In "update" mode, keep values the receiver already has.
			if ( 'update' === $policy && metadata_exists( 'post', $post_id, $key ) ) {
				continue;
			}

			// Resolve image descriptors (e.g. _thumbnail_id) to local attachment IDs.
			if ( $this->is_image_descriptor( $value ) ) {
				$value = $this->images->resolve_image( $value );
			}

			// Unserialize if the value is a serialized string.
			if ( is_string( $value ) ) {
				$value = maybe_unserialize( $value );
			}

			update_post_meta( $post_id, $key, $value );
		}
	}

	// ── PRIVATE — HELPERS ─────────────────────────────────────────────────────

	/**
	 * Find the local post ID that corresponds to a Source post.
	 *
	 * @param  int    $source_post_id
	 * @param  string $source_site
	 * @return int  Local post ID, or 0 if not found.
	 */
	private function find_existing( $source_post_id, $source_site ) {
		if ( ! $source_post_id ) {
			return 0;
		}

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
	 * Sanitize update_policy value.
	 *
	 * @param  string $policy
	 * @return string  One of: skip | update | overwrite.
	 */
	private function sanitize_policy( $policy ) {
		$allowed = array( 'skip', 'update', 'overwrite' );
		return in_array( $policy, $allowed, true ) ? $policy : 'skip';
	}

	/**
	 * Replace words in a post title using the admin-configured word list.
	 *
	 * Pairs are stored as two newline-separated option strings:
	 *   igs_receiver_title_words        — original words (one per line)
	 *   igs_receiver_title_translations — translations   (one per line)
	 *
	 * Replacement is case-insensitive; pairs are matched by line index.
	 *
	 * @param  string $title
	 * @return string
	 */
	private function apply_title_replacements( $title ) {
		$words_raw        = get_option( 'igs_receiver_title_words', '' );
		$translations_raw = get_option( 'igs_receiver_title_translations', '' );

		if ( ! $words_raw || ! $translations_raw ) {
			return $title;
		}

		$from = array_values( array_filter( array_map( 'trim', explode( "\n", $words_raw ) ) ) );
		$to   = array_values( array_filter( array_map( 'trim', explode( "\n", $translations_raw ) ), 'strlen' ) );

		$count = min( count( $from ), count( $to ) );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( '' !== $from[ $i ] ) {
				$title = str_ireplace( $from[ $i ], $to[ $i ], $title );
			}
		}

		return $title;
	}

	/**
	 * Find the igs_casino_slug ACF field value from the casino-menu taxonomy term
	 * listed in the payload's post_taxonomies.
	 *
	 * @param  array $post_taxonomies  Raw post_taxonomies from the payload.
	 * @return string  Sanitized slug, or empty string if not found / not set.
	 */
	private function get_casino_slug_from_taxonomy( array $post_taxonomies ) {
		foreach ( $post_taxonomies as $descriptor ) {
			if ( empty( $descriptor['taxonomy'] ) || 'casino-menu' !== $descriptor['taxonomy'] ) {
				continue;
			}

			$term_id = $this->find_term_by_slug( $descriptor );
			if ( ! $term_id ) {
				continue;
			}

			$slug = get_field( 'igs_casino_slug', 'term_' . $term_id );
			if ( $slug ) {
				return sanitize_title( $slug );
			}
		}

		return '';
	}

	// ── PRIVATE — DESCRIPTOR TYPE CHECKS ─────────────────────────────────────

	/**
	 * @param  mixed $value
	 * @return bool
	 */
	private function is_image_descriptor( $value ) {
		return is_array( $value )
			&& isset( $value['_igs_export_type'] )
			&& 'image' === $value['_igs_export_type'];
	}

	/**
	 * Gallery: non-empty indexed array whose first element is an image descriptor.
	 *
	 * @param  mixed $value
	 * @return bool
	 */
	private function is_gallery_descriptor( $value ) {
		return is_array( $value )
			&& ! empty( $value )
			&& isset( $value[0] )
			&& is_array( $value[0] )
			&& isset( $value[0]['_igs_export_type'] )
			&& 'image' === $value[0]['_igs_export_type'];
	}

	/**
	 * @param  mixed $value
	 * @return bool
	 */
	private function is_parent_ref_descriptor( $value ) {
		return is_array( $value )
			&& isset( $value['_igs_export_type'] )
			&& 'parent_ref' === $value['_igs_export_type'];
	}

	/**
	 * Resolve a parent_ref descriptor to the local parent post ID.
	 *
	 * @param  array $descriptor  [ '_igs_export_type' => 'parent_ref', 'source_post_id' => 123 ]
	 * @return int  Local post ID, or 0 if not yet imported.
	 */
	private function resolve_parent_ref( array $descriptor ) {
		$source_post_id = absint( $descriptor['source_post_id'] ?? 0 );
		if ( ! $source_post_id ) {
			return 0;
		}
		return $this->find_existing( $source_post_id, $this->current_source_site );
	}

	/**
	 * @param  mixed $value
	 * @return bool
	 */
	private function is_post_object_descriptor( $value ) {
		return is_array( $value )
			&& isset( $value['_igs_export_type'] )
			&& 'post_object' === $value['_igs_export_type'];
	}

	/**
	 * @param  mixed $value
	 * @return bool
	 */
	private function is_taxonomy_term_descriptor( $value ) {
		return is_array( $value )
			&& isset( $value['_igs_export_type'] )
			&& 'taxonomy_term' === $value['_igs_export_type'];
	}

	/**
	 * Taxonomy multi-value: non-empty indexed array whose first element is
	 * a taxonomy_term descriptor.
	 *
	 * @param  mixed $value
	 * @return bool
	 */
	private function is_taxonomy_term_array( $value ) {
		return is_array( $value )
			&& ! empty( $value )
			&& isset( $value[0] )
			&& is_array( $value[0] )
			&& isset( $value[0]['_igs_export_type'] )
			&& 'taxonomy_term' === $value[0]['_igs_export_type'];
	}

	// ── PRIVATE — TAXONOMY ────────────────────────────────────────────────────

	/**
	 * Assign existing taxonomy terms to the post by matching slug.
	 * Terms that do not exist on the receiver site are silently skipped.
	 *
	 * @param  int   $post_id
	 * @param  array $post_taxonomies  Array of [ taxonomy, slug ] descriptors.
	 */
	private function import_post_taxonomies( $post_id, array $post_taxonomies ) {
		$grouped = array();
		foreach ( $post_taxonomies as $descriptor ) {
			if ( empty( $descriptor['taxonomy'] ) || empty( $descriptor['slug'] ) ) {
				continue;
			}
			$grouped[ $descriptor['taxonomy'] ][] = $descriptor;
		}

		foreach ( $grouped as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( $terms as $descriptor ) {
				$term_id = $this->find_term_by_slug( $descriptor );
				if ( $term_id ) {
					$term_ids[] = $term_id;
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Find an existing term by slug. Returns 0 if not found — never creates.
	 *
	 * @param  array $descriptor  [ taxonomy, slug ]
	 * @return int  Term ID, or 0 if not found.
	 */
	private function find_term_by_slug( array $descriptor ) {
		$taxonomy = sanitize_key( $descriptor['taxonomy'] ?? '' );
		$slug     = sanitize_title( $descriptor['slug']     ?? '' );

		if ( ! $taxonomy || ! $slug || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$term = get_term_by( 'slug', $slug, $taxonomy );

		return $term ? (int) $term->term_id : 0;
	}
}
