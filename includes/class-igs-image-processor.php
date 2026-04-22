<?php
/**
 * Downloads remote images and maps Source attachment IDs to local ones.
 *
 * Deduplication strategy:
 *   On each successful download the attachment's source URL is saved as
 *   the post meta key `_igs_source_url`.  Before downloading, we look up
 *   that key to see whether the image already lives in the local media library.
 *   This prevents the same image being downloaded twice, even across separate
 *   migration runs.
 *
 * Supported input formats (as produced by IGS_Field_Processor on the Source):
 *   Image  : [ '_igs_export_type' => 'image', 'url' => '...', 'filename' => '...' ]
 *   Gallery: array of the above descriptors
 *   HTML   : string containing <img data-igs-export="..."> markers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IGS_Image_Processor {

	// ── PUBLIC API ────────────────────────────────────────────────────────────

	/**
	 * Resolve a single image descriptor to a local attachment ID.
	 *
	 * Returns 0 if the descriptor is invalid or the download fails.
	 *
	 * @param  array $descriptor  [ '_igs_export_type' => 'image', 'url' => ..., 'filename' => ... ]
	 * @return int  Local attachment ID, or 0 on failure.
	 */
	public function resolve_image( array $descriptor ) {
		if ( empty( $descriptor['url'] ) ) {
			return 0;
		}

		$url = esc_url_raw( $descriptor['url'] );

		// ── Dedup check ───────────────────────────────────────────────────────
		$existing_id = $this->find_by_source_url( $url );
		if ( $existing_id ) {
			return $existing_id;
		}

		// ── Download ──────────────────────────────────────────────────────────
		$filename = ! empty( $descriptor['filename'] )
			? sanitize_file_name( $descriptor['filename'] )
			: sanitize_file_name( basename( $url ) );

		return $this->download_and_attach( $url, $filename );
	}

	/**
	 * Resolve an array of image descriptors (gallery field).
	 *
	 * Entries that fail to resolve are silently skipped so a partial gallery
	 * is still imported.
	 *
	 * @param  array $descriptors
	 * @return array  Ordered array of local attachment IDs.
	 */
	public function resolve_gallery( array $descriptors ) {
		$ids = array();

		foreach ( $descriptors as $descriptor ) {
			if ( ! is_array( $descriptor ) ) {
				continue;
			}
			if ( empty( $descriptor['_igs_export_type'] ) || 'image' !== $descriptor['_igs_export_type'] ) {
				continue;
			}

			$local_id = $this->resolve_image( $descriptor );
			if ( $local_id ) {
				$ids[] = $local_id;
			}
		}

		return $ids;
	}

	/**
	 * Process an HTML string containing <img data-igs-export="..."> markers.
	 *
	 * Replaces each marked img tag:
	 *   - Downloads the original image (or reuses an existing one).
	 *   - Rewrites the src to the local URL.
	 *   - Updates the wp-image-{id} class with the new local ID.
	 *   - Removes the data-igs-export marker attribute.
	 *
	 * @param  string $html
	 * @return string  Processed HTML.
	 */
	public function process_html_imgs( $html ) {
		// Match img tags that carry the data-igs-export marker.
		preg_match_all( '/<img\s[^>]*data-igs-export=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$original_tag = $match[0];
			$raw_json     = html_entity_decode( $match[1], ENT_QUOTES );
			$export_data  = json_decode( $raw_json, true );

			if ( ! is_array( $export_data ) || empty( $export_data['original_src'] ) ) {
				continue;
			}

			$descriptor = array(
				'_igs_export_type' => 'image',
				'url'              => $export_data['original_src'],
				'filename'         => isset( $export_data['filename'] ) ? $export_data['filename'] : '',
			);

			$local_id = $this->resolve_image( $descriptor );
			if ( ! $local_id ) {
				// Could not download — strip the marker but leave the original src.
				$new_tag = preg_replace( '/\s+data-igs-export=["\'][^"\']*["\']/i', '', $original_tag );
				$html    = str_replace( $original_tag, $new_tag, $html );
				continue;
			}

			$local_url = wp_get_attachment_url( $local_id );
			if ( ! $local_url ) {
				continue;
			}

			// Build the updated tag.
			$new_tag = $original_tag;

			// Update src.
			$new_tag = preg_replace( '/src=["\'][^"\']*["\']/i', 'src="' . esc_url( $local_url ) . '"', $new_tag );

			// Update wp-image class.
			if ( ! empty( $export_data['original_id'] ) ) {
				$new_tag = str_replace(
					'wp-image-' . (int) $export_data['original_id'],
					'wp-image-' . $local_id,
					$new_tag
				);
			}

			// Remove the export marker attribute.
			$new_tag = preg_replace( '/\s+data-igs-export=["\'][^"\']*["\']/i', '', $new_tag );

			$html = str_replace( $original_tag, $new_tag, $html );
		}

		return $html;
	}

	// ── PRIVATE HELPERS ───────────────────────────────────────────────────────

	/**
	 * Look up an existing attachment by the _igs_source_url meta value.
	 *
	 * @param  string $url  The original Source site URL.
	 * @return int  Attachment ID, or 0 if not found.
	 */
	private function find_by_source_url( $url ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id
				 FROM {$wpdb->postmeta}
				 WHERE meta_key   = '_igs_source_url'
				   AND meta_value = %s
				 LIMIT 1",
				$url
			)
		);

		return (int) $attachment_id;
	}

	/**
	 * Download a remote file and insert it into the Media Library.
	 *
	 * SVG and other file types that WordPress blocks by default are
	 * temporarily allowed during import, then the filter is removed.
	 *
	 * @param  string $url       Remote file URL.
	 * @param  string $filename  Desired filename for the attachment.
	 * @return int  New attachment ID, or 0 on failure.
	 */
	private function download_and_attach( $url, $filename ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Download to a temporary file.
		$tmp = download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		// Temporarily extend the allowed MIME types so that SVG and other
		// file types blocked by WordPress core can be imported from the
		// source site's media library.
		$allow_extra_mimes = function ( $mimes ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
			$mimes['webp'] = 'image/webp';
			return $mimes;
		};
		add_filter( 'upload_mimes', $allow_extra_mimes );

		// WordPress 5.8+ also checks the file's real mime type against allowed
		// types via wp_check_filetype_and_ext(). We need to confirm SVG there too.
		$allow_svg_check = function ( $data, $file, $filename, $mimes ) {
			if ( empty( $data['ext'] ) && empty( $data['type'] ) ) {
				$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
					$data['ext']  = $ext;
					$data['type'] = 'image/svg+xml';
				}
			}
			return $data;
		};
		add_filter( 'wp_check_filetype_and_ext', $allow_svg_check, 10, 4 );

		// Sideload the file into the media library (not attached to any post).
		$attachment_id = media_handle_sideload( $file_array, 0 );

		// Remove the temporary MIME filters immediately after the upload.
		remove_filter( 'upload_mimes', $allow_extra_mimes );
		remove_filter( 'wp_check_filetype_and_ext', $allow_svg_check, 10 );

		// Always clean up the temp file.
		if ( file_exists( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		// Store the source URL for future dedup checks.
		update_post_meta( $attachment_id, '_igs_source_url', $url );

		return (int) $attachment_id;
	}
}
