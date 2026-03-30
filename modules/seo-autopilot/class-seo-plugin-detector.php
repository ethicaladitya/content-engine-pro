<?php
namespace ContentEnginePro\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the active SEO plugin and returns a normalized data object for each post.
 *
 * Supported plugins:
 *  - Rank Math SEO
 *  - Yoast SEO (free & premium)
 *  - SmartCrawl (WPMU Dev)
 *  - All in One SEO (AIOSEO)
 *
 * If no plugin is detected, returns 'none' with empty meta.
 */
class SeoPluginDetector {

	// ── Plugin Identifiers ────────────────────────────────────────────────────

	const PLUGIN_RANKMATH   = 'rankmath';
	const PLUGIN_YOAST      = 'yoast';
	const PLUGIN_SMARTCRAWL = 'smartcrawl';
	const PLUGIN_AIOSEO     = 'aioseo';
	const PLUGIN_NONE       = 'none';

	/** @var string|null Cached detection result */
	private static ?string $detected_plugin = null;

	// ── Meta Key Maps ─────────────────────────────────────────────────────────

	private static array $meta_maps = [
		self::PLUGIN_RANKMATH => [
			'title'    => 'rank_math_title',
			'desc'     => 'rank_math_description',
			'keyword'  => 'rank_math_focus_keyword',
			'score'    => 'rank_math_seo_score',
			'robots'   => 'rank_math_robots',
			'pillar'   => 'rank_math_pillar_content',
			'schema'   => 'rank_math_rich_snippet',
		],
		self::PLUGIN_YOAST => [
			'title'    => '_yoast_wpseo_title',
			'desc'     => '_yoast_wpseo_metadesc',
			'keyword'  => '_yoast_wpseo_focuskw',
			'score'    => '_yoast_wpseo_content_score',
			'robots'   => '_yoast_wpseo_meta-robots-noindex',
			'pillar'   => '_yoast_wpseo_is_cornerstone',
			'schema'   => '_yoast_wpseo_schema_page_type',
		],
		self::PLUGIN_SMARTCRAWL => [
			'title'    => '_wds_title',
			'desc'     => '_wds_metadesc',
			'keyword'  => '_wds_focus-keywords',
			'score'    => '_wds_analysis_focus',
			'robots'   => '_wds_robots',
			'pillar'   => '',
			'schema'   => '',
		],
		self::PLUGIN_AIOSEO => [
			'title'    => '_aioseo_title',
			'desc'     => '_aioseo_description',
			'keyword'  => '_aioseo_keywords',
			'score'    => '',  // stored in dedicated DB table
			'robots'   => '_aioseo_robots_default',
			'pillar'   => '',
			'schema'   => '_aioseo_schema',
		],
	];

	// ── Detection ─────────────────────────────────────────────────────────────

	/**
	 * Detect which SEO plugin is currently active.
	 */
	public static function detect(): string {
		if ( null !== self::$detected_plugin ) {
			return self::$detected_plugin;
		}

		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			self::$detected_plugin = self::PLUGIN_RANKMATH;
		} elseif ( class_exists( 'WPSEO_Options' ) || defined( 'WPSEO_VERSION' ) ) {
			self::$detected_plugin = self::PLUGIN_YOAST;
		} elseif ( class_exists( 'Smartcrawl_Settings' ) || defined( 'SMARTCRAWL_SITEURL' ) ) {
			self::$detected_plugin = self::PLUGIN_SMARTCRAWL;
		} elseif ( class_exists( 'AIOSEO\Plugin\AIOSEO' ) || defined( 'AIOSEO_VERSION' ) ) {
			self::$detected_plugin = self::PLUGIN_AIOSEO;
		} else {
			self::$detected_plugin = self::PLUGIN_NONE;
		}

		return self::$detected_plugin;
	}

	/**
	 * Return a human-readable plugin label.
	 */
	public static function detect_label(): string {
		$labels = [
			self::PLUGIN_RANKMATH   => 'Rank Math SEO',
			self::PLUGIN_YOAST      => 'Yoast SEO',
			self::PLUGIN_SMARTCRAWL => 'SmartCrawl',
			self::PLUGIN_AIOSEO     => 'All in One SEO',
			self::PLUGIN_NONE       => 'None detected',
		];
		return $labels[ self::detect() ] ?? 'Unknown';
	}

	// ── Per-Post Data ─────────────────────────────────────────────────────────

	/**
	 * Get normalized SEO data for a post from the active SEO plugin.
	 *
	 * @param int $post_id
	 * @return SeoPluginData
	 */
	public static function get_post_data( int $post_id ): SeoPluginData {
		$plugin = self::detect();

		if ( self::PLUGIN_NONE === $plugin ) {
			return new SeoPluginData( $plugin, '', '', '', null, [] );
		}

		$map = self::$meta_maps[ $plugin ];

		$title    = (string) get_post_meta( $post_id, $map['title'], true );
		$desc     = (string) get_post_meta( $post_id, $map['desc'], true );
		$keyword  = (string) get_post_meta( $post_id, $map['keyword'], true );

		// Score — special handling per plugin
		$score    = self::get_plugin_score( $post_id, $plugin, $map );

		// Plugin-surfaced issues
		$issues   = self::get_plugin_issues( $post_id, $plugin );

		return new SeoPluginData( $plugin, $title, $desc, $keyword, $score, $issues );
	}

	/**
	 * Write SEO meta back to the active plugin's meta keys.
	 *
	 * @param int    $post_id
	 * @param string $title
	 * @param string $description
	 */
	public static function write_meta( int $post_id, string $title, string $description ): void {
		$plugin = self::detect();

		if ( self::PLUGIN_NONE === $plugin ) {
			// Fallback: write generic WP meta used by many themes
			update_post_meta( $post_id, '_seo_title', $title );
			update_post_meta( $post_id, '_seo_description', $description );
			return;
		}

		$map = self::$meta_maps[ $plugin ];

		if ( $title ) {
			update_post_meta( $post_id, $map['title'], $title );
		}
		if ( $description ) {
			update_post_meta( $post_id, $map['desc'], $description );
		}

		// AIOSEO also stores data in a custom table — update that too
		if ( self::PLUGIN_AIOSEO === $plugin ) {
			self::update_aioseo_table( $post_id, $title, $description );
		}
	}

	/**
	 * Write focus keyword back to the active plugin's meta key.
	 */
	public static function write_keyword( int $post_id, string $keyword ): void {
		$plugin = self::detect();
		if ( self::PLUGIN_NONE === $plugin ) {
			return;
		}
		$map = self::$meta_maps[ $plugin ];
		if ( $map['keyword'] ) {
			update_post_meta( $post_id, $map['keyword'], $keyword );
		}
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Get the SEO score for a post from the active plugin.
	 */
	private static function get_plugin_score( int $post_id, string $plugin, array $map ): ?int {
		if ( self::PLUGIN_AIOSEO === $plugin ) {
			return self::get_aioseo_score( $post_id );
		}

		if ( empty( $map['score'] ) ) {
			return null;
		}

		$raw = get_post_meta( $post_id, $map['score'], true );

		if ( '' === $raw || false === $raw ) {
			return null;
		}

		// Yoast stores content score as a string like "ok"/"good"/"bad" for content score.
		// Rank Math stores it as a numeric value 0-100.
		// SmartCrawl stores it as an array of analysis results.
		if ( self::PLUGIN_YOAST === $plugin ) {
			// Yoast uses _yoast_wpseo_linkdex for SEO score (0-100 numeric)
			$seo_score = (int) get_post_meta( $post_id, '_yoast_wpseo_linkdex', true );
			return $seo_score > 0 ? $seo_score : null;
		}

		if ( self::PLUGIN_SMARTCRAWL === $plugin ) {
			// SmartCrawl analysis is an array; count passed checks
			$analysis = maybe_unserialize( $raw );
			if ( is_array( $analysis ) ) {
				$pass  = count( array_filter( $analysis, fn( $v ) => ! empty( $v['status'] ) && 'ok' === $v['status'] ) );
				$total = count( $analysis );
				return $total > 0 ? (int) round( ( $pass / $total ) * 100 ) : null;
			}
			return null;
		}

		return is_numeric( $raw ) ? (int) $raw : null;
	}

	/**
	 * Attempt to pull SEO issues already surfaced by the active plugin.
	 *
	 * Returns a flat array of human-readable issue strings.
	 */
	private static function get_plugin_issues( int $post_id, string $plugin ): array {
		$issues = [];

		switch ( $plugin ) {
			case self::PLUGIN_RANKMATH:
				// Rank Math does not persist individual issue strings in meta by default;
				// we surface the score and let the auditor infer from it.
				$score = (int) get_post_meta( $post_id, 'rank_math_seo_score', true );
				if ( $score > 0 && $score < 50 ) {
					$issues[] = "Rank Math SEO score is very low ({$score}/100). Immediate attention needed.";
				} elseif ( $score > 0 && $score < 70 ) {
					$issues[] = "Rank Math SEO score is below target ({$score}/100).";
				}
				break;

			case self::PLUGIN_YOAST:
				// Yoast stores overall readability and SEO status as strings
				$seo_status  = get_post_meta( $post_id, '_yoast_wpseo_overall-score', true );
				$read_status = get_post_meta( $post_id, '_yoast_wpseo_content_score', true );
				if ( in_array( $seo_status, [ 'bad', 'needs-improvement' ], true ) ) {
					$issues[] = 'Yoast SEO analysis: SEO score is poor.';
				}
				if ( in_array( $read_status, [ 'bad', 'needs-improvement' ], true ) ) {
					$issues[] = 'Yoast readability analysis: content readability needs improvement.';
				}
				break;

			case self::PLUGIN_SMARTCRAWL:
				$raw      = get_post_meta( $post_id, '_wds_analysis_focus', true );
				$analysis = maybe_unserialize( $raw );
				if ( is_array( $analysis ) ) {
					foreach ( $analysis as $key => $result ) {
						if ( ! empty( $result['status'] ) && 'error' === $result['status'] ) {
							$issues[] = 'SmartCrawl: ' . ( $result['reason'] ?? $key );
						}
					}
				}
				break;

			case self::PLUGIN_AIOSEO:
				$analysis = self::get_aioseo_analysis( $post_id );
				foreach ( $analysis as $item ) {
					if ( ! empty( $item['error'] ) ) {
						$issues[] = 'AIOSEO: ' . $item['error'];
					}
				}
				break;
		}

		return $issues;
	}

	/**
	 * Query the AIOSEO custom table for the SEO score.
	 */
	private static function get_aioseo_score( int $post_id ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$score = $wpdb->get_var( $wpdb->prepare(
			"SELECT seo_score FROM {$table} WHERE post_id = %d LIMIT 1",
			$post_id
		) );
		return null !== $score ? (int) $score : null;
	}

	/**
	 * Query the AIOSEO custom table for the page analysis JSON.
	 */
	private static function get_aioseo_analysis( int $post_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT page_analysis FROM {$table} WHERE post_id = %d LIMIT 1",
			$post_id
		) );
		if ( empty( $raw ) ) {
			return [];
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Update the AIOSEO custom table row with new title/description.
	 */
	private static function update_aioseo_table( int $post_id, string $title, string $description ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE post_id = %d LIMIT 1",
			$post_id
		) );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				[
					'title'       => $title,
					'description' => $description,
					'updated'     => current_time( 'mysql' ),
				],
				[ 'post_id' => $post_id ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}
	}
}

/**
 * Simple value object returned by SeoPluginDetector::get_post_data().
 */
class SeoPluginData {

	public string  $plugin_name;
	public string  $meta_title;
	public string  $meta_description;
	public string  $focus_keyword;
	public ?int    $plugin_score;
	public array   $plugin_issues;

	public function __construct(
		string $plugin_name,
		string $meta_title,
		string $meta_description,
		string $focus_keyword,
		?int $plugin_score,
		array $plugin_issues
	) {
		$this->plugin_name      = $plugin_name;
		$this->meta_title       = $meta_title;
		$this->meta_description = $meta_description;
		$this->focus_keyword    = $focus_keyword;
		$this->plugin_score     = $plugin_score;
		$this->plugin_issues    = $plugin_issues;
	}
}
