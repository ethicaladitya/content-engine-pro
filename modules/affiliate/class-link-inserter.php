<?php
namespace ContentEnginePro\Affiliate;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contextually inserts affiliate links into post content via the_content filter.
 *
 * Strategy:
 *  - Splits content into alternating plain-text and HTML-tag segments.
 *  - Only plain-text segments outside existing <a> elements are processed.
 *  - One link per provider per article (first match wins).
 *  - Terms are checked longest-first (greedy) so "Bumble Premium" wins over "Bumble".
 */
class LinkInserter {

	public function register(): void {
		add_filter( 'the_content', [ $this, 'insert_links' ], 20 );
	}

	/**
	 * Main filter callback. Injects affiliate links into singular post/review content.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public function insert_links( string $content ): string {
		if ( ! is_singular() ) {
			return $content;
		}

		$post_type = get_post_type();
		$primary   = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews   = Settings::get( 'reviews_cpt_slug', 'review' );

		if ( ! in_array( $post_type, [ $primary, $reviews ], true ) ) {
			return $content;
		}

		$providers = AffiliateManager::get_providers();
		if ( empty( $providers ) ) {
			return $content;
		}

		// Build term → provider map.
		$term_map = [];
		foreach ( $providers as $provider ) {
			foreach ( $provider['match_terms'] as $term ) {
				$term = trim( (string) $term );
				if ( '' !== $term ) {
					$term_map[ $term ] = $provider;
				}
			}
		}

		// Sort by term length descending so longer phrases match before shorter ones (greedy).
		uksort(
			$term_map,
			static function ( string $a, string $b ): int {
				return strlen( $b ) - strlen( $a );
			}
		);

		$used_slugs = [];

		foreach ( $term_map as $term => $provider ) {
			if ( in_array( $provider['slug'], $used_slugs, true ) ) {
				continue;
			}

			$redirect_url = cep_get_affiliate_redirect_url( $provider['slug'] );

			$link = apply_filters(
				'cep_affiliate_link_html',
				sprintf(
					'<a href="%s" class="cep-affiliate-link" target="_blank" rel="noopener sponsored" data-provider="%s">%s</a>',
					esc_url( $redirect_url ),
					esc_attr( $provider['slug'] ),
					esc_html( $provider['name'] )
				),
				$provider,
				$redirect_url
			);

			$new_content = $this->replace_first_outside_tags( $content, $term, $link );

			if ( $new_content !== $content ) {
				$content      = $new_content;
				$used_slugs[] = $provider['slug'];
			}
		}

		return $content;
	}

	/**
	 * Replace the first whole-word occurrence of $term in $content, but only
	 * in text nodes that are outside HTML tags and outside existing <a> elements.
	 *
	 * Approach: preg_split on HTML tags, producing alternating text/tag segments.
	 * Only even-index (text) segments are searched; odd-index (tag) segments are
	 * passed through unchanged. We track <a> depth so nested anchors are skipped.
	 *
	 * @param string $content     Full HTML content.
	 * @param string $term        Plain-text term to search for (case-insensitive, word-boundary).
	 * @param string $replacement HTML replacement string (the affiliate link).
	 * @return string
	 */
	private function replace_first_outside_tags( string $content, string $term, string $replacement ): string {
		// Split into alternating [text, tag, text, tag, …] segments.
		// PREG_SPLIT_DELIM_CAPTURE keeps the tag delimiters in the result array.
		$parts = preg_split( '/(<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $content;
		}

		// (?<!\w) and (?!\w) are fixed-length (1-char) lookbehind/ahead — valid in PCRE.
		$pattern     = '/(?<!\w)' . preg_quote( $term, '/' ) . '(?!\w)/i';
		$replaced    = false;
		$anchor_depth = 0;
		$result      = '';

		foreach ( $parts as $i => $segment ) {
			if ( $i % 2 === 1 ) {
				// Odd index = HTML tag. Track <a> nesting depth, output unchanged.
				$tag_lower = strtolower( $segment );
				if ( preg_match( '/^<a[\s>]/i', $segment ) ) {
					$anchor_depth++;
				} elseif ( '</a>' === $tag_lower ) {
					$anchor_depth = max( 0, $anchor_depth - 1 );
				}
				$result .= $segment;
			} elseif ( $replaced || $anchor_depth > 0 ) {
				// Already replaced, or inside an existing <a> — output text as-is.
				$result .= $segment;
			} else {
				// Plain text outside any anchor — search for the term.
				$new_segment = preg_replace_callback(
					$pattern,
					static function ( array $matches ) use ( $replacement, &$replaced ): string {
						if ( $replaced ) {
							return $matches[0];
						}
						$replaced = true;
						return $replacement;
					},
					$segment
				);
				$result .= ( $new_segment ?? $segment );
			}
		}

		return $result;
	}
}
