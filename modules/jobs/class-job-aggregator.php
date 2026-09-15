<?php
namespace ContentEnginePro\Jobs;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;
use ContentEnginePro\NicheManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job Aggregator Autopilot.
 *
 * Pipeline:
 * 1. Pull configured job RSS feeds (from niche presets + admin-configured sources)
 * 2. Parse job listings (title, company, location, url, description)
 * 3. Deduplicate against recently published jobs
 * 4. Use AI to format/enrich the job listing for publishing
 * 5. Publish to the jobs CPT
 *
 * Also creates a weekly "roundup" post consolidating top jobs for SEO.
 */
class JobAggregator {

	public static function run(): void {
		if ( ! Settings::is_enabled( 'jobs_autopilot_enabled' ) ) {
			return;
		}

		if ( ! Settings::is_enabled( 'enable_jobs' ) ) {
			return;
		}

		if ( ! Settings::is_enabled( 'jobs_cpt_enabled' ) ) {
			return;
		}

		$niche      = NicheManager::get_active();
		$sources    = $niche['job_sources'];
		$max        = (int) Settings::get( 'jobs_max_per_run', 10 );
		$dedup_days = (int) Settings::get( 'jobs_dedup_days', 30 );
		$jobs_cpt   = Settings::get( 'jobs_cpt_slug', 'job' );

		Logger::log(
			'Job aggregator run started',
			'info',
			'job_aggregator',
			[
				'post_type'    => $jobs_cpt,
				'max_per_run'  => $max,
				'dedup_days'   => $dedup_days,
				'source_count' => count( $sources ),
			]
		);

		if ( empty( $sources ) ) {
			Logger::log( 'Job aggregator: no job sources configured', 'info', 'job_aggregator' );
			return;
		}

		do_action( 'cep_before_job_aggregation' );

		$all_listings = [];
		// Career pages (HTML) — scrape a rotating subset per run to stay fast
		// and avoid hammering every employer on every cron tick.
		$career_pages = Settings::get( 'career_pages', '' );
		if ( $career_pages ) {
			$career_list  = array_values( array_filter( array_map( 'trim', explode( "\n", $career_pages ) ) ) );
			$career_cap   = (int) Settings::get( 'career_pages_per_run', 8 );
			$offset       = (int) get_option( 'cep_career_offset', 0 );
			$total        = count( $career_list );
			$batch        = [];
			for ( $i = 0; $i < $career_cap; $i++ ) {
				$idx    = ( $offset + $i ) % $total;
				$batch[] = $career_list[ $idx ];
			}
			update_option( 'cep_career_offset', ( $offset + $career_cap ) % $total );
			foreach ( $batch as $page_url ) {
				$scraped = \ContentEnginePro\Jobs\CareerScraper::scrape( $page_url );
				foreach ( $scraped as $item ) {
					$all_listings[] = $item;
				}
			}
		}

		foreach ( $sources as $feed_url ) {
			$items = self::parse_feed( $feed_url );
			foreach ( $items as $item ) {
				$all_listings[] = $item;
			}
			// Polite delay between feed fetches so the server IP is not throttled/blocked.
			sleep( rand( 2, 4 ) );
		}

		if ( empty( $all_listings ) ) {
			Logger::log( 'Job aggregator: no listings found in feeds', 'info', 'job_aggregator' );
			return;
		}

		// Deduplicate and filter
		$new_listings = self::filter_new( $all_listings, $dedup_days );

		if ( empty( $new_listings ) ) {
			Logger::log( 'Job aggregator: all listings already published', 'info', 'job_aggregator' );
			return;
		}

		$per_company_cap = (int) apply_filters( 'cep_jobs_per_company_cap', 3 );
		if ( $per_company_cap > 0 ) {
			$seen = [];
			$capped = [];
			foreach ( $new_listings as $listing ) {
				$key = strtolower( trim( (string) $listing['company'] ) );
				if ( '' === $key ) {
					$key = '__unknown__';
				}
				if ( ( $seen[ $key ] ?? 0 ) >= $per_company_cap ) {
					continue;
				}
				$capped[] = $listing;
				$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			}
			$new_listings = $capped;
		}

		// Limit to max per run
		$to_process = array_slice( $new_listings, 0, $max );

		$published = 0;
		foreach ( $to_process as $listing ) {
			$result = self::process_listing( $listing, $niche );
			if ( $result && ! is_wp_error( $result ) ) {
				$published++;
			}
			if ( $published < count( $to_process ) ) {
				sleep( 1 );
			}
		}

		Logger::log( "Job aggregator: published {$published} job listings", 'info', 'job_aggregator' );
		do_action( 'cep_after_job_aggregation', $published );
	}

	/**
	 * Parse a job feed URL and return structured listings.
	 */
	private static function parse_feed( string $url ): array {
		// Use a realistic browser UA by default so public RSS/career pages
		// don't silently block the crawler. Rotate slightly per request.
		$ua_pool = [
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
		];
		$user_agent = Settings::get( 'crawl_user_agent', $ua_pool[ array_rand( $ua_pool ) ] );
		$response   = wp_remote_get( $url, [
			'timeout'    => 20,
			'user-agent' => $user_agent,
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::log( "Job feed failed: {$url}", 'warning', 'job_aggregator' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		return self::parse_rss( $body, $url );
	}

	/**
	 * Parse RSS/Atom feed body into job listing array.
	 */
	private static function parse_rss( string $body, string $feed_url ): array {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		if ( false === $xml ) {
			return [];
		}

		$items = [];

		// RSS
		foreach ( $xml->channel->item ?? [] as $item ) {
			$title    = html_entity_decode( (string) $item->title, ENT_QUOTES, 'UTF-8' );
			$link     = (string) $item->link;
			$desc     = self::clean_description( (string) $item->description );
			$pub_date = (string) $item->pubDate;

			if ( empty( $title ) || empty( $link ) ) {
				continue;
			}

			// Try to extract company/location from common RSS job formats
			$company  = self::extract_company( $title, $desc, $item );
			if ( trim( (string) $company ) === '' ) {
				$company = self::source_label( $feed_url );
			}
			$location = self::extract_location( $title, $desc, $item );

			$items[] = [
				'title'       => sanitize_text_field( $title ),
				'url'         => esc_url_raw( $link ),
				'description' => sanitize_textarea_field( $desc ),
				'company'     => sanitize_text_field( $company ),
				'location'    => sanitize_text_field( $location ),
				'pub_date'    => sanitize_text_field( $pub_date ),
				'source_url'  => $feed_url,
				'hash'        => md5( $title . $link ),
			];
		}

		// Atom
		if ( empty( $items ) ) {
			foreach ( $xml->entry ?? [] as $entry ) {
				$title = html_entity_decode( (string) $entry->title, ENT_QUOTES, 'UTF-8' );
				$link  = '';
				foreach ( $entry->link as $l ) {
					if ( in_array( (string) $l['rel'], [ 'alternate', '' ], true ) ) {
						$link = (string) $l['href'];
						break;
					}
				}

				if ( empty( $title ) || empty( $link ) ) {
					continue;
				}

				$desc    = self::clean_description( (string) $entry->summary );
				$company = self::extract_company( $title, $desc, $entry );
				$location = self::extract_location( $title, $desc, $entry );

				$items[] = [
					'title'       => sanitize_text_field( $title ),
					'url'         => esc_url_raw( $link ),
					'description' => sanitize_textarea_field( $desc ),
					'company'     => sanitize_text_field( $company ),
					'location'    => sanitize_text_field( $location ),
					'pub_date'    => sanitize_text_field( (string) $entry->updated ),
					'source_url'  => $feed_url,
					'hash'        => md5( $title . $link ),
				];
			}
		}

		return $items;
	}

	/**
	 * Filter out listings that look like jobs we already have.
	 *
	 * Consolidates three dedup layers so the board isn't flooded by the same
	 * opening re-fed with slightly different URLs/titles:
	 *
	 *  1. Raw-table content hash within the window (fast, exact title+url).
	 *  2. Exact apply URL already present on a published job within the window
	 *     (catches the same listing re-posted with a stripped/changed URL).
	 *  3. Fuzzy title+company match on a published job within the window —
	 *     the company must match exactly (normalized) AND the title must be
	 *     highly similar, so distinct roles at the same employer are kept.
	 *
	 * Checking against ALL published `job` posts (not just raw rows) also
	 * survives manual edits, DB resets, or raw rows that were lost.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private static function filter_new( array $listings, int $dedup_days ): array {
		global $wpdb;
		$table    = $wpdb->prefix . 'cep_jobs_raw';
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$dedup_days} days" ) );
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );

		// Load recently-published jobs once: title, company, apply URL.
		// This is the source of truth for the URL + fuzzy checks.
		$recent = get_posts( [
			'post_type'      => $jobs_cpt,
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'posts_per_page' => 500,
			'date_query'     => [ [ 'after' => "-{$dedup_days} days", 'inclusive' => true ] ],
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$known_titles = [];
		$known_urls   = [];
		foreach ( $recent as $post_id ) {
			$known_urls[] = (string) get_post_meta( $post_id, '_cep_job_url', true );
			$company = (string) get_post_meta( $post_id, '_cep_job_company', true );
			if ( '' !== $company ) {
				$known_titles[] = [
					'company' => self::normalize( $company ),
					'title'   => self::normalize( (string) get_the_title( $post_id ) ),
				];
			}
		}
		// Many raw/published rows may share a URL — de-dupe to keep lookups cheap.
		$known_urls = array_values( array_unique( array_filter( $known_urls ) ) );

		$new = [];
		foreach ( $listings as $listing ) {
			// Layer 1 — raw-table content hash seen within the dedup window.
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE content_hash = %s AND discovered_at > %s",
				$listing['hash'],
				$cutoff
			) );
			if ( $exists ) {
				continue;
			}

			// Layer 2 — exact resolve/apply URL already on a recent published job.
			$url = (string) $listing['url'];
			if ( $url && in_array( $url, $known_urls, true ) ) {
				continue;
			}

			// Layer 3 — fuzzy title + exact company against recent published jobs.
			$listing_title   = self::normalize( (string) $listing['title'] );
			$listing_company = self::normalize( (string) $listing['company'] );
			if ( '' !== $listing_company && '' !== $listing_title ) {
				foreach ( $known_titles as $known ) {
					if ( $known['company'] !== $listing_company ) {
						continue;
					}
					similar_text( $known['title'], $listing_title, $pct );
					if ( $pct >= 80 ) {
						continue 2;
					}
				}
			}

			$new[] = $listing;
		}

		return $new;
	}

	/**
	 * Normalise a human label for fuzzy comparison: lowercase, collapse
	 * whitespace, and strip common filler/punctuation so "Base.com: DevOps
	 * Engineer" and "DevOps Engineer at Base.com" compare closely.
	 */
	private static function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Detect whether a URL points at a company careers/landing index page
	 * rather than an individual job role. Some RSS feeds emit the bare
	 * "/careers/" or "/jobs/" index as a listing item; publishing it produces
	 * a "job" post that only shows the employer's generic intro blurb.
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function is_careers_index_url( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = rtrim( strtolower( trim( $path ) ), '/' );

		if ( '' === $path ) {
			return false;
		}

		// Bare index paths: /careers, /jobs, /careers/, /jobs/, /#open-roles, etc.
		if ( preg_match( '#/(careers?|jobs?|vacancies|open-roles|openings|empleos)$#i', $path ) ) {
			return true;
		}

		// Index with only a fragment and no deeper slug: /careers/#open-roles
		if ( preg_match( '#/(careers?|jobs?)/?$#i', $path ) && '' !== (string) wp_parse_url( $url, PHP_URL_FRAGMENT ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Process one job listing: AI-format and publish.
	 */
	private static function process_listing( array $listing, array $niche ) {
		global $wpdb;

		// Skip careers/landing index URLs that some feeds emit as a "job"
		// item (e.g. https://10up.com/careers/). These are company pages, not
		// individual roles — the dedicated company_careers_map / career scraper
		// already covers them separately. Publishing the index as a job shows
		// the company's generic careers blurb instead of real openings.
		if ( self::is_careers_index_url( $listing['url'] ) ) {
			Logger::log( "Skipping careers index URL (not an individual role): {$listing['url']}", 'info', 'job_aggregator' );
			return 0;
		}

		// Store in raw table to prevent duplicates on next run
		$wpdb->insert(
			$wpdb->prefix . 'cep_jobs_raw',
			[
				'title'        => sanitize_text_field( $listing['title'] ),
				'content_hash' => $listing['hash'],
				'source_url'   => esc_url_raw( $listing['source_url'] ),
				'job_url'      => esc_url_raw( $listing['url'] ),
				'status'       => 'processing',
				'discovered_at'=> current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		$raw_id = $wpdb->insert_id;

		// Use AI to format/enrich the listing
		$formatted = self::format_listing( $listing, $niche );

		if ( is_wp_error( $formatted ) || empty( $formatted['title'] ) ) {
			// Fallback: publish with minimal formatting
			$formatted = self::minimal_format( $listing );
		}

		// Publish job post
		$post_id = self::publish_job( $listing, $formatted );

		if ( is_wp_error( $post_id ) ) {
			$wpdb->update( $wpdb->prefix . 'cep_jobs_raw', [ 'status' => 'failed' ], [ 'id' => $raw_id ], [ '%s' ], [ '%d' ] );
			return $post_id;
		}

		$wpdb->update(
			$wpdb->prefix . 'cep_jobs_raw',
			[ 'status' => 'published', 'wp_post_id' => $post_id ],
			[ 'id' => $raw_id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);

		return $post_id;
	}

	/**
	 * Use AI to format a job listing with proper structure.
	 */
	private static function format_listing( array $listing, array $niche ) {
		$ai    = AiClient::get_instance();
		$brand = Settings::get( 'brand_name', 'our publication' );

		$prompt = apply_filters( 'cep_job_format_prompt', <<<PROMPT
You are a job listings editor for "{$brand}", a {$niche['label']} publication.

Format the following job listing into a clean, well-structured post.

Job Title: {$listing['title']}
Company: {$listing['company']}
Location: {$listing['location']}
Original URL: {$listing['url']}
Description:
{$listing['description']}

Requirements:
- Clean up the job title (remove duplicated company names, clean formatting)
- Structure the content with HTML: <h2> sections for responsibilities/requirements if present
- Highlight salary range if mentioned
- Note whether it's remote/hybrid/on-site
- Keep it concise but informative
- Add a clear CTA linking to the original listing

Return JSON:
{
  "title": "clean job title (optionally include company)",
  "content": "HTML-formatted job description with apply CTA",
  "excerpt": "1-2 sentence summary",
  "company": "company name",
  "location": "location (Remote / City, Country / Hybrid)",
  "job_type": "full-time / part-time / contract / freelance",
  "salary": "salary range or empty string",
  "tags": ["tag1", "tag2"]
}
PROMPT
		, $listing, $niche );

		$result = $ai->complete( $prompt, '', [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => 0.4,
			'mini'            => true,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['content'], true );
		return is_array( $data ) ? $data : new \WP_Error( 'bad_json', 'Invalid AI response for job' );
	}

	/**
	 * Build minimal formatted job without AI (fallback).
	 */
	private static function minimal_format( array $listing ): array {
		$content = '';

		// Split the cleaned description into real paragraphs (blank-line
		// separated) instead of one run-on <p>, so the fallback output reads
		// like a proper job listing even when the AI formatter is unavailable.
		$paras = preg_split( "/\n\s*\n/u", trim( (string) $listing['description'] ) );
		foreach ( $paras as $para ) {
			$para = trim( (string) $para );
			if ( '' !== $para ) {
				$content .= '<p>' . esc_html( $para ) . '</p>' . "\n";
			}
		}

		if ( '' !== trim( (string) $listing['url'] ) ) {
			$content .= '<p><a href="' . esc_url( $listing['url'] ) . '" target="_blank" rel="noopener noreferrer">Apply for this position →</a></p>';
		}

		return [
			'title'    => $listing['title'],
			'content'  => $content,
			'excerpt'  => self::safe_truncate( (string) $listing['description'], 200 ),
			'company'  => $listing['company'],
			'location' => $listing['location'],
			'job_type' => '',
			'salary'   => '',
			'tags'     => [],
		];
	}

	/**
	 * Publish the formatted job to WordPress.
	 */
	private static function publish_job( array $listing, array $formatted ): int|\WP_Error {
		$cpt       = Settings::get( 'jobs_cpt_slug', 'job' );
		$author_id = (int) Settings::get( 'default_author_id', 1 );
		$status    = Settings::is_enabled( 'auto_publish' ) ? 'publish' : 'draft';

		$post_id = wp_insert_post( apply_filters( 'cep_job_post_args', [
			'post_title'   => sanitize_text_field( $formatted['title'] ),
			// Slug: prefix the company name so URLs read /jobs/{company}-{role}/.
			'post_name'    => self::build_job_slug( $formatted['company'] ?? ( $listing['company'] ?? '' ), $formatted['title'] ),
			'post_content' => wp_kses_post( $formatted['content'] ),
			'post_excerpt' => sanitize_textarea_field( $formatted['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => $cpt,
			'post_author'  => $author_id,
		], $listing, $formatted ) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save job meta
		update_post_meta( $post_id, '_cep_job_company', sanitize_text_field( $formatted['company'] ?? $listing['company'] ) );
		update_post_meta( $post_id, '_cep_job_location', sanitize_text_field( $formatted['location'] ?? $listing['location'] ) );
		update_post_meta( $post_id, '_cep_job_type', sanitize_text_field( $formatted['job_type'] ?? '' ) );
		update_post_meta( $post_id, '_cep_job_salary', sanitize_text_field( $formatted['salary'] ?? '' ) );
		update_post_meta( $post_id, '_cep_job_url', esc_url_raw( $listing['url'] ) );
		// Flag scraped (HTML) jobs so the enrich cron can fetch real descriptions.
		if ( empty( $formatted['content'] ) || '' === trim( wp_strip_all_tags( $formatted['content'] ) ) ) {
			update_post_meta( $post_id, '_cep_needs_enrich', 1 );
		}
		update_post_meta( $post_id, '_cep_job_source', esc_url_raw( $listing['source_url'] ) );
		update_post_meta( $post_id, '_cep_job_pub_date', sanitize_text_field( $listing['pub_date'] ) );
		// Store the exact expiry so the schema's validThrough and the expiry
		// cleanup always agree. Falls back to post date + default window.
		$expiry_days = self::job_expiry_days();
		update_post_meta( $post_id, '_cep_job_expires_at', gmdate( 'Y-m-d H:i:s', strtotime( get_post_field( 'post_date_gmt', $post_id ) ) + $expiry_days * DAY_IN_SECONDS ) );

		// Tags
		if ( ! empty( $formatted['tags'] ) && is_array( $formatted['tags'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', $formatted['tags'] ), 'post_tag' );
		}

		do_action( 'cep_job_published', $post_id, $listing, $formatted );

		return $post_id;
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Normalise a raw RSS/Atom job description into clean, well-separated
	 * plain text. wp_strip_all_tags() concatenates adjacent elements with no
	 * separator (e.g. <p>&hellip;experience.</p><p>We are&hellip;</p> becomes
	 * "experience.We are"), so we introduce whitespace at element boundaries
	 * before stripping. The result is also trimmed to a sane length at a word
	 * boundary instead of being hard-cut mid-sentence.
	 *
	 * @param string $raw Raw feed description (may contain HTML).
	 * @return string
	 */
	private static function clean_description( string $raw ): string {
		$raw = (string) $raw;

		// Preserve paragraph/heading/list boundaries as blank-line separators.
		$raw = (string) preg_replace( '#</p>\s*<p[^>]*>#i', "\n\n", $raw );
		$raw = (string) preg_replace( '#<br\s*/?>#i', "\n", $raw );
		$raw = (string) preg_replace( '#</(?:li|h[1-6]|div|dd)>#i', "\n\n", $raw );

		// Insert a space between any remaining adjacent tags so inline text
		// (e.g. <b>Experience</b>Required) does not jam together.
		$raw = (string) preg_replace( '#>\s*<#', '> <', $raw );

		$text = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/[ \t\r\x{00a0}]+/u', ' ', $text ) );
		$text = (string) preg_replace( "/\n{3,}/u", "\n\n", $text );
		$text = (string) preg_replace( "/[ \t]+\n/u", "\n", $text );

		return self::safe_truncate( $text, 1000 );
	}

	/**
	 * Truncate a string at a word boundary within a character budget, leaving
	 * a trailing ellipsis instead of cutting mid-word.
	 *
	 * @param string $text  Text to truncate.
	 * @param int    $limit Max characters (including the ellipsis surrogate).
	 * @return string
	 */
	private static function safe_truncate( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, max( 1, $limit - 1 ) );
		$cut = (string) preg_replace( '/\s+\S*$/u', '', $cut );
		return trim( (string) $cut ) . '…';
	}

	private static function extract_company( string $title, string $desc, $item ): string {
		// Many job feeds use "Job Title at Company" or "Company: Job Title" formats
		if ( preg_match( '/\bat\s+([A-Z][^\|\x{2013}-]{2,40})$/u', $title, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/^([A-Z][^:]{2,40}):\s+/u', $title, $m ) ) {
			return trim( $m[1] );
		}
		// Try common RSS extensions
		if ( isset( $item->children( 'job', true )->company ) ) {
			return (string) $item->children( 'job', true )->company;
		}
		// dc:creator / author often carry the employer on WordPress job feeds
		foreach ( [ 'creator', 'author' ] as $tag ) {
			if ( isset( $item->children( 'dc', true )->$tag ) ) {
				$v = (string) $item->children( 'dc', true )->$tag;
				if ( trim( $v ) ) {
					return trim( strip_tags( $v ) );
				}
			}
		}
		return '';
	}

	private static function extract_location( string $title, string $desc, $item ): string {
		// Check for explicit "Remote" mentions first
		if ( stripos( $title . ' ' . $desc, 'remote' ) !== false ) {
			return 'Remote';
		}
		// Try common patterns like "(New York, NY)" or "- London, UK"
		if ( preg_match( '/[\(\-]\s*([A-Z][a-zA-Z\s,]+(?:,\s*[A-Z]{2,3})?)\s*[\)]/u', $title, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Enrich scraped jobs that have no description yet by fetching each
	 * job's detail page. Runs on a separate, slower cron so the main
	 * aggregation stays fast. Caps work per run.
	 *
	 * @return int Number of jobs enriched this run.
	 */
	public static function enrich_pending(): int {
		if ( ! Settings::is_enabled( 'jobs_autopilot_enabled' ) ) {
			return 0;
		}

		$per_run = (int) Settings::get( 'jobs_enrich_per_run', 6 );
		if ( $per_run < 1 ) {
			$per_run = 6;
		}

		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		$posts    = get_posts( [
			'post_type'      => $jobs_cpt,
			'post_status'    => 'publish',
			'posts_per_page' => $per_run,
			'meta_query'     => [ [ 'key' => '_cep_needs_enrich', 'value' => 1, 'compare' => '=' ] ],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$enriched = 0;
		foreach ( $posts as $post_id ) {
			$detail = get_post_meta( $post_id, '_cep_job_url', true );
			$listing_company = (string) get_post_meta( $post_id, '_cep_job_company', true );
			if ( empty( $detail ) ) {
				delete_post_meta( $post_id, '_cep_needs_enrich' );
				continue;
			}

			$details = \ContentEnginePro\Jobs\CareerScraper::fetch_details( $detail );
			$desc  = $details['description'] ?? '';
			$title = $details['title'] ?? '';
			if ( '' !== $desc && strlen( trim( wp_strip_all_tags( $desc ) ) ) > 40 ) {
				// Build a clean excerpt for SEO (meta description + OG) — SmartCrawl
				// uses %%excerpt%%, which is empty unless we set it here.
				$excerpt = self::make_excerpt( $desc, $title, $listing_company );
				$args = [
					'ID'           => $post_id,
					'post_content' => $desc,
					'post_excerpt' => $excerpt,
				];
				// Rewrite the title from the detail page's clean <h1> when available.
				if ( '' !== $title && strlen( $title ) > 2 ) {
					$args['post_title'] = sanitize_text_field( $title );
					// Regenerate a clean slug from {company}-{clean title}.
					$co   = get_post_meta( $post_id, '_cep_job_company', true );
					$slug = self::build_job_slug( $co, $title );
					$args['post_name'] = wp_unique_post_slug( $slug, $post_id, get_post_status( $post_id ), 'job', 0 );
				}
				wp_update_post( $args );
				delete_post_meta( $post_id, '_cep_needs_enrich' );
				$enriched++;
			} else {
				// Could not fetch a useful description; stop retrying to avoid loops.
				delete_post_meta( $post_id, '_cep_needs_enrich' );
			}
			sleep( 1 );
		}

		if ( $enriched ) {
			Logger::log( "Job enrich: filled {$enriched} descriptions", 'info', 'job_aggregator' );
		}
		return $enriched;
	}

	/**
	 * Build a URL slug of the form {company}-{role}, sanitised.
	 *
	 * @param string $company
	 * @param string $title
	 * @return string
	 */
	public static function build_job_slug( string $company, string $title ): string {
		$role = sanitize_title( $title );
		$co   = sanitize_title( $company );
		$base = $co ? $co . '-' . $role : $role;
		if ( '' === $base ) {
			$base = 'job-' . uniqid();
		}
		return $base;
	}

	/**
	 * Friendly label for a feed/source URL, used when a listing carries no
	 * parseable company name. Keeps the board varied and honest.
	 */
	private static function source_label( string $url ): string {
		$map = [
			'jobs.wordpress.net'        => 'WordPress Jobs',
			'wpremotework.com'          => 'WP Remote Work',
			'remoteok.com'              => 'Remote OK',
			'weworkremotely.com'        => 'We Work Remotely',
			'workingnomads.co'          => 'Working Nomads',
			'jobspresso.co'             => 'Jobspresso',
			'remotive.com'              => 'Remotive',
			'authenticjobs.com'         => 'Authentic Jobs',
			'wphired.com'               => 'WP Hired',
		];
		foreach ( $map as $host => $label ) {
			if ( strpos( $url, $host ) !== false ) {
				return $label;
			}
		}
		return 'Curated';
	}

	/**
	 * Curated map of employer -> direct careers/apply URL.
	 * Lets the job board link straight to the recruiter (with our UTM)
	 * instead of the intermediary listing (WeWorkRemotely, RemoteOK, ...).
	 * Keys are lower-cased company names. URLs verified reachable.
	 */
	private static function company_careers_map(): array {
		return [
			'rtcamp'                => 'https://careers.rtcamp.com/',
			'wp engine'             => 'https://wpengine.com/careers/',
			'automattic'            => 'https://automattic.com/work-with-us/',
			'10up'                  => 'https://10up.com/careers/',
			'kinsta'                => 'https://kinsta.com/careers/',
			'happy cog'             => 'https://www.happycog.com/careers/',
			'lemon.io'              => 'https://lemon.io/',
			'customer.io'           => 'https://customer.io/careers',
			'workleap'              => 'https://workleap.com/careers',
			'sharegate migrate chezworkleap' => 'https://workleap.com/careers',
			'mediafly'              => 'https://www.mediafly.com/careers/',
			'lattice'               => 'https://lattice.com/careers',
			'fueled'                => 'https://fueled.com/careers/',
			'rocketgenius'          => 'https://rocketgenius.com/careers/',
			'tractian'              => 'https://www.tractian.com/',
			'galileo'               => 'https://www.galileo.ai/',
			'toptal'                => 'https://www.toptal.com/careers',
			'offerup'               => 'https://www.offerup.com/careers',
			'tiger analytics inc.'  => 'https://www.tigeranalytics.com/careers/',
			'awesome motive'        => 'https://awesomemotive.com/careers/',
			'awesomemotive'         => 'https://awesomemotive.com/careers/',
		];
	}

	/**
	 * Resolve the apply URL for a job: prefer the employer's own careers
	 * page (with our UTM) and fall back to the stored (intermediary) URL
	 * plus UTM. Never returns a broken link.
	 */
	public static function apply_url( int $id ): string {
		$company = (string) get_post_meta( $id, '_cep_job_company', true );
		$stored  = (string) get_post_meta( $id, '_cep_job_url', true );
		$map     = self::company_careers_map();
		$key     = strtolower( trim( $company ) );
		$direct  = $map[ $key ] ?? '';
		$base    = $direct ?: $stored;
		if ( '' === $base ) {
			return '';
		}
		return self::with_utm( $base, $id );
	}

	/**
	 * Add outbound UTM parameters to a job link.
	 *
	 * Keeps the URL's existing query and #fragment, never overrides UTM values
	 * the employer already set, and tags each listing with its slug so clicks
	 * can be attributed per role. Filter `cep_job_utm_params` to change them.
	 *
	 * @param string $url Outbound URL.
	 * @param int    $id  Job post ID (optional; adds utm_content).
	 * @return string
	 */
	public static function with_utm( string $url, int $id = 0 ): string {
		$url = trim( $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return $url;
		}

		$params = [
			'utm_source'   => 'adityashah.blog',
			'utm_medium'   => 'job_board',
			'utm_campaign' => 'jobs',
		];
		if ( $id ) {
			$slug = (string) get_post_field( 'post_name', $id );
			if ( '' !== $slug ) {
				$params['utm_content'] = $slug;
			}
		}
		$params = (array) apply_filters( 'cep_job_utm_params', $params, $url, $id );

		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$existing = [];
		$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' !== $query ) {
			wp_parse_str( $query, $existing );
		}
		$missing = array_diff_key( array_filter( $params, 'strlen' ), $existing );

		return ( $missing ? add_query_arg( array_map( 'rawurlencode', $missing ), $url ) : $url ) . $fragment;
	}

	/**
	 * Build a clean 1-2 sentence excerpt for SEO from a job description.
	 */
	private static function make_excerpt( string $html, string $title, string $company ): string {
		$text = trim( wp_strip_all_tags( $html ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		// Drop common scraped intro fragments that make ugly descriptions.
		$text = preg_replace( '/^[^.!?]*\b(Headquarters|Who we are|About us|About the role|Job brief|Summary):?/i', '', $text );
		$text = trim( $text );
		if ( '' === $text ) {
			$text = $title;
		}
		$sentences = preg_split( '/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$body = '';
		foreach ( $sentences as $s ) {
			$body .= $s . ' ';
			if ( strlen( trim( $body ) ) >= 130 ) {
				break;
			}
		}
		$body = trim( $body );
		$lead = '';
		if ( $company ) {
			$lead = $company . ' is hiring' . ( $title ? ( ' for ' . $title ) : '' ) . '. ';
		} elseif ( $title ) {
			$lead = $title . '. ';
		}
		$out = $lead . $body;
		return wp_kses_post( substr( $out, 0, 200 ) );
	}

	/**
	 * Ping Google + Bing with the job sitemap when a new job is published.
	 * Throttled to at most one ping per 5 minutes so a bulk publish run
	 * never hammers the search engines (avoiding blocks/penalties).
	 *
	 * @param int $post_id The newly published job post ID.
	 */
	public static function ping_search_engines( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'job' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		// Throttle: only ping every 5 minutes regardless of how many jobs publish.
		$last = (int) get_transient( 'cep_se_ping_lock' );
		if ( $last && ( time() - $last ) < 300 ) {
			return;
		}
		set_transient( 'cep_se_ping_lock', time(), 300 );

		$sitemap = home_url( '/job-sitemap1.xml' );
		$ping_urls = [
			'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap ),
			'https://www.bing.com/ping?sitemap=' . rawurlencode( $sitemap ),
		];
		foreach ( $ping_urls as $ping ) {
			wp_remote_get( $ping, [ 'timeout' => 10, 'blocking' => false, 'user-agent' => 'Mozilla/5.0' ] );
		}
		Logger::log( "Pinged search engines with sitemap {$sitemap}", 'info', 'job_aggregator' );
	}

	/**
	 * Number of days a job stays published before it is expired. Defaults to
	 * 30 so it matches the schema's validThrough window, keeping the sitemap
	 * and JobPosting markup honest (Google drops sites that leave expired
	 * jobs indexed). Overridable via the cep_job_expiry_days filter.
	 *
	 * @return int
	 */
	private static function job_expiry_days(): int {
		$days = (int) apply_filters( 'cep_job_expiry_days', (int) Settings::get( 'jobs_expire_days', 30 ) );
		return $days < 1 ? 30 : $days;
	}

	/**
	 * Expire jobs that have been published for longer than the expiry window.
	 * Unpublishing removes them from the archive, the sitemap and the single
	 * page (404), which is exactly what Google's JobPosting policy requires —
	 * stale listings are the leading cause of lost Jobs rich results.
	 *
	 * @return int Number of jobs expired this run.
	 */
	public static function expire_expired(): int {
		if ( ! Settings::is_enabled( 'jobs_autopilot_enabled' ) ) {
			return 0;
		}

		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		$days     = self::job_expiry_days();
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$ids = get_posts( [
			'post_type'      => $jobs_cpt,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'date_query'     => [ [ 'before' => $cutoff, 'inclusive' => true ] ],
		] );

		$expired = 0;
		foreach ( $ids as $id ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
			update_post_meta( $id, '_cep_job_expired', time() );
			$expired++;
		}

		if ( $expired ) {
			Logger::log( "Job expiry: unpublished {$expired} job(s) older than {$days} days", 'info', 'job_aggregator' );
		}
		return $expired;
	}

	/**
	 * Regenerate the formatted content/meta of an already-published job.
	 *
	 * Used to repair posts created before the description-cleaning fixes: it
	 * re-fetches the full listing from the source URL, then runs the normal
	 * AI formatting pipeline (falling back to clean paragraphs) and writes
	 * the result back. Returns the post ID on success, else a WP_Error.
	 *
	 * @param int $post_id
	 * @return int|\WP_Error
	 */
	public static function reformat( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || Settings::get( 'jobs_cpt_slug', 'job' ) !== $post->post_type ) {
			return new \WP_Error( 'bad_post', 'Not a job post' );
		}

		$listing = [
			'title'       => $post->post_title,
			'company'     => (string) get_post_meta( $post_id, '_cep_job_company', true ),
			'location'    => (string) get_post_meta( $post_id, '_cep_job_location', true ),
			'url'         => (string) get_post_meta( $post_id, '_cep_job_url', true ),
			'description' => trim( (string) wp_strip_all_tags( $post->post_content ) ),
			'source_url'  => (string) get_post_meta( $post_id, '_cep_job_source', true ),
			'pub_date'    => (string) get_post_meta( $post_id, '_cep_job_pub_date', true ),
		];

		// Prefer the full source description over the (possibly truncated)
		// stored content so regeneration repairs the cut-off copy too.
		if ( '' !== $listing['url'] ) {
			$details = \ContentEnginePro\Jobs\CareerScraper::fetch_details( $listing['url'] );
			$full    = trim( (string) ( $details['description'] ?? '' ) );
			if ( strlen( trim( (string) wp_strip_all_tags( $full ) ) ) > 40 ) {
				$listing['description'] = self::clean_description( $full );
			}
		}

		$formatted = self::format_listing( $listing, NicheManager::get_active() );
		if ( is_wp_error( $formatted ) || empty( $formatted['title'] ) ) {
			$formatted = self::minimal_format( $listing );
		}

		$update = [
			'ID'           => $post_id,
			'post_title'   => sanitize_text_field( $formatted['title'] ?? $listing['title'] ),
			'post_content' => wp_kses_post( $formatted['content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $formatted['excerpt'] ?? '' ),
		];
		// Regenerate the slug when the cleaned title/company produce a better one.
		$company = $formatted['company'] ?? $listing['company'];
		$slug    = self::build_job_slug( $company, $formatted['title'] ?? $listing['title'] );
		if ( $slug && get_post_field( 'post_name', $post_id ) !== $slug ) {
			$update['post_name'] = wp_unique_post_slug( $slug, $post_id, get_post_status( $post_id ), $post->post_type, 0 );
		}
		wp_update_post( $update );

		update_post_meta( $post_id, '_cep_job_company', sanitize_text_field( $company ) );
		update_post_meta( $post_id, '_cep_job_location', sanitize_text_field( $formatted['location'] ?? $listing['location'] ) );
		update_post_meta( $post_id, '_cep_job_type', sanitize_text_field( $formatted['job_type'] ?? '' ) );
		update_post_meta( $post_id, '_cep_job_salary', sanitize_text_field( $formatted['salary'] ?? '' ) );
		update_post_meta( $post_id, '_cep_job_expires_at', gmdate( 'Y-m-d H:i:s', strtotime( get_post_field( 'post_date_gmt', $post_id ) ) + self::job_expiry_days() * DAY_IN_SECONDS ) );

		do_action( 'cep_job_reformatted', $post_id, $listing, $formatted );
		return $post_id;
	}

	/**
	 * Weekly self-audit of the job CPT: ensures every published job has a
	 * clean excerpt (for meta description/OG), indexable robots, and a valid
	 * clean slug. Re-applies fixes to any that drift. Reports counts to the log.
	 */
	public static function seo_audit(): void {
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		$ids      = get_posts( [
			'post_type'      => $jobs_cpt,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		$fixed_excerpt = 0;
		$fixed_slug    = 0;
		foreach ( $ids as $id ) {
			// 1) Excerpt (drives meta description + OG tags).
			if ( '' === trim( (string) get_post_field( 'post_excerpt', $id ) ) ) {
				$desc    = get_post_field( 'post_content', $id );
				$title   = get_post_field( 'post_title', $id );
				$company = (string) get_post_meta( $id, '_cep_job_company', true );
				wp_update_post( [ 'ID' => $id, 'post_excerpt' => self::make_excerpt( $desc, $title, $company ) ] );
				$fixed_excerpt++;
			}
			// 2) Clean slug (company-role) if it drifted.
			$co   = get_post_meta( $id, '_cep_job_company', true );
			$title = get_post_field( 'post_title', $id );
			$expected = self::build_job_slug( $co, $title );
			if ( $expected && get_post_field( 'post_name', $id ) !== $expected ) {
				wp_update_post( [ 'ID' => $id, 'post_name' => wp_unique_post_slug( $expected, $id, 'publish', 'job', 0 ) ] );
				$fixed_slug++;
			}
		}
		Logger::log( "Job SEO audit: {$fixed_excerpt} excerpts fixed, {$fixed_slug} slugs fixed (of " . count( $ids ) . ' jobs)', 'info', 'job_aggregator' );
	}

}
