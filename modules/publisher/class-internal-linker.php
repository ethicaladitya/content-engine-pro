<?php
namespace ContentEnginePro\Publisher;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Previously inserted a "Related Articles" block into post content via the_content filter.
 * Disabled: related articles are now rendered by the theme's template-parts/related-articles.php
 * template part (called from single.php), so the content-filter injection is no longer needed.
 */
class InternalLinker {

	/**
	 * Register hooks. Related articles injection is disabled — handled by the theme template.
	 */
	public function register(): void {
		// No-op: the_content filter for related articles removed in favour of theme template part.
	}
}
