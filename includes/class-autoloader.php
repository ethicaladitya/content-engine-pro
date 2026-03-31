<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4-style autoloader for the ContentEnginePro namespace.
 *
 * Namespace → file mapping:
 *   ContentEnginePro\Settings          → includes/class-settings.php
 *   ContentEnginePro\Admin\AdminMenu   → admin/class-admin-menu.php
 *   ContentEnginePro\Crawl\Crawler     → modules/crawl-engine/class-crawler.php
 *   ContentEnginePro\Signal\SignalScorer → modules/signal-scoring/class-signal-scorer.php
 *   ContentEnginePro\Publisher\Publisher → modules/publisher/class-publisher.php
 *   ContentEnginePro\Affiliate\AffiliateManager → modules/affiliate/class-affiliate-manager.php
 *   ContentEnginePro\Reviews\ReviewManager → modules/reviews/class-review-manager.php
 *   ContentEnginePro\Frontend\Frontend → modules/frontend/class-frontend.php
 *   ContentEnginePro\Api\RestApi       → api/class-rest-api.php
 */
class Autoloader {

	/** Sub-namespace → directory path (relative to CEP_DIR) */
	private static array $ns_map = [
		'Admin\\'      => 'admin/',
		'Crawl\\'      => 'modules/crawl-engine/',
		'Signal\\'     => 'modules/signal-scoring/',
		'Publisher\\'  => 'modules/publisher/',
		'Affiliate\\'  => 'modules/affiliate/',
		'Reviews\\'    => 'modules/reviews/',
		'Articles\\'   => 'modules/articles/',
		'Jobs\\'       => 'modules/jobs/',
		'Research\\'   => 'modules/research/',
		'AiProcessing\\' => 'modules/ai-processing/',
		'Frontend\\'   => 'modules/frontend/',
		'Api\\'        => 'api/',
		'Cli\\'        => 'modules/cli/',
		'Trending\\'   => 'modules/trending/',
		'Seo\\'        => 'modules/seo-agent/',
		'PostType\\'   => 'post-types/',
		// Bare classes (no sub-namespace) → includes/
	];

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class ): void {
		$prefix = 'ContentEnginePro\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) ); // e.g. "Admin\\AdminMenu" or "Settings"

		$dir      = 'includes/';
		$basename = $relative;
		$matched  = false;

		foreach ( self::$ns_map as $ns => $path ) {
			if ( 0 === strpos( $relative, $ns ) ) {
				$dir      = $path;
				$basename = substr( $relative, strlen( $ns ) );
				$matched  = true;
				break;
			}
		}

		// CamelCase → kebab-case: SchemaInjector → schema-injector
		$kebab    = (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', str_replace( [ '_', '\\' ], [ '-', '-' ], $basename ) );
		$filename = 'class-' . strtolower( $kebab ) . '.php';

		if ( $matched ) {
			$file = CEP_DIR . $dir . $filename;
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			return;
		}

		// Bare classes: search includes/, post-types/, and known module directories
		foreach ( [ 'includes/', 'post-types/', 'modules/ai-processing/' ] as $search_dir ) {
			$file = CEP_DIR . $search_dir . $filename;
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
