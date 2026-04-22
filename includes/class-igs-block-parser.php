<?php
/**
 * Parses ACF Gutenberg blocks from post_content.
 *
 * This is the Receiver-side copy of IGS_Block_Parser from the Source plugin.
 * The class_exists guard prevents conflicts if both plugins are ever active
 * on the same WordPress installation.
 *
 * ACF blocks are stored as HTML comments in post_content:
 *   <!-- wp:acf/block-name {"name":"acf/block-name","data":{...}} /-->
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'IGS_Block_Parser' ) ) {
	return;
}

class IGS_Block_Parser {

	/**
	 * Regex pattern that matches a single self-closing ACF block comment.
	 *
	 * Group 1 — block name  (e.g. "acf/casino")
	 * Group 2 — raw JSON attributes string
	 */
	const BLOCK_PATTERN = '/<!--\s*wp:(acf\/[a-z0-9_-]+)\s+(\{.*?\})\s*\/-->/s';

	// ── PUBLIC API ────────────────────────────────────────────────────────────

	/**
	 * Parse all ACF blocks from a post_content string.
	 *
	 * Returns an array of block descriptors:
	 * [
	 *   'name'       => 'acf/casino',
	 *   'raw'        => '<!-- wp:acf/casino {...} /-->',
	 *   'attributes' => [ ... decoded JSON ... ],
	 *   'data'       => [ ... the "data" sub-object ... ],
	 *   'invalid'    => false,
	 * ]
	 *
	 * @param  string $post_content
	 * @return array
	 */
	public function parse( $post_content ) {
		$blocks = array();

		preg_match_all( self::BLOCK_PATTERN, $post_content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$raw        = $match[0];
			$block_name = $match[1];
			$json       = $match[2];

			$attributes = json_decode( $json, true );

			if ( ! is_array( $attributes ) ) {
				$blocks[] = array(
					'name'       => $block_name,
					'raw'        => $raw,
					'attributes' => array(),
					'data'       => array(),
					'invalid'    => true,
				);
				continue;
			}

			$blocks[] = array(
				'name'       => $block_name,
				'raw'        => $raw,
				'attributes' => $attributes,
				'data'       => isset( $attributes['data'] ) ? $attributes['data'] : array(),
				'invalid'    => false,
			);
		}

		return $blocks;
	}

	/**
	 * Rebuild a single block descriptor into its HTML comment string.
	 *
	 * @param  array $block     Block descriptor.
	 * @param  array $new_data  Replacement data array.
	 * @return string
	 */
	public function rebuild_block( array $block, array $new_data ) {
		$attributes         = $block['attributes'];
		$attributes['data'] = $new_data;

		$json = wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return sprintf( '<!-- wp:%s %s /-->', $block['name'], $json );
	}

	/**
	 * Replace every processed block inside post_content and return the
	 * updated content string.
	 *
	 * @param  string $post_content
	 * @param  array  $processed   Array of [ 'block' => $block, 'new_data' => $data ] pairs.
	 * @return string
	 */
	public function rebuild_content( $post_content, array $processed ) {
		foreach ( $processed as $item ) {
			$original = $item['block']['raw'];
			$rebuilt  = $this->rebuild_block( $item['block'], $item['new_data'] );

			$pos = strpos( $post_content, $original );
			if ( false !== $pos ) {
				$post_content = substr_replace( $post_content, $rebuilt, $pos, strlen( $original ) );
			}
		}

		return $post_content;
	}

	/**
	 * Extract all <img> tags from an HTML string.
	 *
	 * @param  string $html
	 * @return array  Array of [ 'raw', 'src', 'id', 'class' ] descriptors.
	 */
	public function extract_img_tags( $html ) {
		$images = array();

		preg_match_all( '/<img\s[^>]+>/i', $html, $matches );

		foreach ( $matches[0] as $img_tag ) {
			$src   = $this->get_attr( $img_tag, 'src' );
			$class = $this->get_attr( $img_tag, 'class' );
			$id    = 0;

			if ( preg_match( '/wp-image-(\d+)/', $class, $m ) ) {
				$id = (int) $m[1];
			}

			if ( ! $src ) {
				continue;
			}

			$images[] = array(
				'raw'   => $img_tag,
				'src'   => $src,
				'id'    => $id,
				'class' => $class,
			);
		}

		return $images;
	}

	// ── PRIVATE HELPERS ───────────────────────────────────────────────────────

	/**
	 * Extract the value of a single HTML attribute from a tag string.
	 *
	 * @param  string $tag
	 * @param  string $attr
	 * @return string
	 */
	private function get_attr( $tag, $attr ) {
		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '=["\']([^"\']*)["\']/i', $tag, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
