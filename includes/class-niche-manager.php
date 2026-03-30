<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Niche/vertical configuration.
 *
 * Everything the autopilot does is niche-aware:
 * - What to search for
 * - What products to review
 * - What job feeds to pull
 * - What keywords to score against
 *
 * Devs can register new verticals via the cep_register_niches filter.
 */
class NicheManager {

	/**
	 * Built-in niche presets.
	 * Each preset defines: keywords, review_types, article_categories, default sources, job_sources.
	 */
	private static array $presets = [
		'wordpress' => [
			'label'              => 'WordPress',
			'keywords'           => 'wordpress,plugin,theme,gutenberg,woocommerce,wp,elementor,block editor,php,cms',
			'review_types'       => 'plugin,hosting,theme,service,tool',
			'article_categories' => 'core,security,hosting,community,industry,jobs',
			'review_sources'     => [
				'https://wordpress.org/plugins/feed/',
				'https://wordpress.org/themes/feed/',
				'https://wptavern.com/feed',
				'https://torquemag.io/feed/',
			],
			'job_sources'        => [
				'https://wphired.com/feed/',
				'https://jobs.wordpress.net/feed/',
			],
			'article_sources'    => [
				'https://wordpress.org/news/feed/',
				'https://make.wordpress.org/core/feed/',
				'https://wptavern.com/feed',
			],
			'search_queries'     => [
				'wordpress plugin review',
				'best wordpress plugins',
				'wordpress news',
				'wordpress security',
			],
		],
		'ecommerce' => [
			'label'              => 'eCommerce',
			'keywords'           => 'ecommerce,shopify,woocommerce,magento,bigcommerce,store,checkout,payment,dropshipping',
			'review_types'       => 'platform,plugin,service,tool,payment',
			'article_categories' => 'news,reviews,tutorials,strategies,tools',
			'review_sources'     => [
				'https://www.shopify.com/blog/rss.xml',
			],
			'job_sources'        => [
				'https://remoteok.com/remote-ecommerce-jobs.rss',
			],
			'article_sources'    => [],
			'search_queries'     => [
				'ecommerce platform comparison',
				'best ecommerce tools',
			],
		],
		'saas' => [
			'label'              => 'SaaS / Software',
			'keywords'           => 'saas,software,startup,app,api,integration,automation,crm,erp,cloud',
			'review_types'       => 'software,service,tool,api,platform',
			'article_categories' => 'news,reviews,tutorials,trends,funding',
			'review_sources'     => [],
			'job_sources'        => [
				'https://remoteok.com/remote-saas-jobs.rss',
			],
			'article_sources'    => [],
			'search_queries'     => [
				'saas product review',
				'best business software',
			],
		],
		'marketing' => [
			'label'              => 'Digital Marketing',
			'keywords'           => 'seo,marketing,content,social media,email,ppc,analytics,conversion,funnel,growth',
			'review_types'       => 'tool,software,platform,service,agency',
			'article_categories' => 'seo,social,email,content,ads,analytics',
			'review_sources'     => [],
			'job_sources'        => [
				'https://remoteok.com/remote-marketing-jobs.rss',
			],
			'article_sources'    => [],
			'search_queries'     => [
				'best marketing tools',
				'digital marketing news',
			],
		],
		'custom' => [
			'label'              => 'Custom (configure below)',
			'keywords'           => '',
			'review_types'       => 'product,service,tool',
			'article_categories' => 'general',
			'review_sources'     => [],
			'job_sources'        => [],
			'article_sources'    => [],
			'search_queries'     => [],
		],
	];

	/**
	 * Get all registered niche presets.
	 */
	public static function get_presets(): array {
		return apply_filters( 'cep_register_niches', self::$presets );
	}

	/**
	 * Get the currently active niche config (preset merged with admin overrides).
	 */
	public static function get_active(): array {
		$vertical = Settings::get( 'niche_vertical', 'wordpress' );
		$presets  = self::get_presets();
		$preset   = $presets[ $vertical ] ?? $presets['custom'];

		// Admin overrides take priority over preset defaults
		return apply_filters( 'cep_active_niche', [
			'vertical'           => $vertical,
			'label'              => Settings::get( 'niche_name', $preset['label'] ),
			'keywords'           => Settings::get( 'niche_keywords', $preset['keywords'] ),
			'review_types'       => Settings::get( 'niche_review_types', $preset['review_types'] ),
			'article_categories' => Settings::get( 'niche_article_categories', $preset['article_categories'] ),
			'review_sources'     => self::merge_sources( 'review_discovery_sources', $preset['review_sources'] ),
			'job_sources'        => self::merge_sources( 'job_sources', $preset['job_sources'] ),
			'article_sources'    => self::merge_sources( 'article_sources', $preset['article_sources'] ),
			'search_queries'     => self::get_search_queries( $preset ),
		] );
	}

	/**
	 * Get keywords array for signal scoring.
	 */
	public static function get_keywords(): array {
		$raw = self::get_active()['keywords'];
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	/**
	 * Get review types array.
	 */
	public static function get_review_types(): array {
		$raw = self::get_active()['review_types'];
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	private static function merge_sources( string $setting_key, array $preset_sources ): array {
		$custom = Settings::get( $setting_key, '' );
		$custom_arr = $custom
			? array_filter( array_map( 'trim', explode( "\n", $custom ) ) )
			: [];
		return array_unique( array_merge( $preset_sources, $custom_arr ) );
	}

	private static function get_search_queries( array $preset ): array {
		$custom = Settings::get( 'niche_search_queries', '' );
		$custom_arr = $custom
			? array_filter( array_map( 'trim', explode( "\n", $custom ) ) )
			: [];
		return array_unique( array_merge( $preset['search_queries'] ?? [], $custom_arr ) );
	}
}
