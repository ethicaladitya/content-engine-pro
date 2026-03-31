<?php
namespace ContentEnginePro\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters applied to post content on the frontend.
 */
class ContentFilter {

	/**
	 * Strip the <h2>Sources</h2> section from displayed content.
	 * The section is preserved in the database.
	 */
	public static function strip_sources_section( string $content ): string {
		// Remove everything from <h2>Sources</h2> (or <h2>Source</h2>) to end
		$patterns = [
			'/<h2[^>]*>\s*Sources?\s*<\/h2>.*$/si',
			'/<h3[^>]*>\s*Sources?\s*<\/h3>.*$/si',
		];

		foreach ( $patterns as $pattern ) {
			$stripped = preg_replace( $pattern, '', $content );
			if ( null !== $stripped && $stripped !== $content ) {
				return apply_filters( 'cep_content_after_strip_sources', $stripped );
			}
		}

		return $content;
	}
}
