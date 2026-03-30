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

		if ( ! Settings::is_enabled( 'jobs_cpt_enabled' ) ) {
			return;
		}

		$niche      = NicheManager::get_active();
		$sources    = $niche['job_sources'];
		$max        = (int) Settings::get( 'jobs_max_per_run', 10 );
		$dedup_days = (int) Settings::get( 'jobs_dedup_days', 30 );

		if ( empty( $sources ) ) {
			Logger::log( 'Job aggregator: no job sources configured', 'info', 'job_aggregator' );
			return;
		}

		do_action( 'cep_before_job_aggregation' );

		$all_listings = [];
		foreach ( $sources as $feed_url ) {
			$items = self::parse_feed( $feed_url );
			foreach ( $items as $item ) {
				$all_listings[] = $item;
			}
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
		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );
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
			$desc     = html_entity_decode( wp_strip_all_tags( (string) $item->description ), ENT_QUOTES, 'UTF-8' );
			$pub_date = (string) $item->pubDate;

			if ( empty( $title ) || empty( $link ) ) {
				continue;
			}

			// Try to extract company/location from common RSS job formats
			$company  = self::extract_company( $title, $desc, $item );
			$location = self::extract_location( $title, $desc, $item );

			$items[] = [
				'title'       => sanitize_text_field( $title ),
				'url'         => esc_url_raw( $link ),
				'description' => sanitize_textarea_field( substr( $desc, 0, 1000 ) ),
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

				$desc    = html_entity_decode( wp_strip_all_tags( (string) $entry->summary ), ENT_QUOTES, 'UTF-8' );
				$company = self::extract_company( $title, $desc, $entry );
				$location = self::extract_location( $title, $desc, $entry );

				$items[] = [
					'title'       => sanitize_text_field( $title ),
					'url'         => esc_url_raw( $link ),
					'description' => sanitize_textarea_field( substr( $desc, 0, 1000 ) ),
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
	 * Filter out listings already in the DB within the dedup window.
	 */
	private static function filter_new( array $listings, int $dedup_days ): array {
		global $wpdb;
		$table     = $wpdb->prefix . 'cep_jobs_raw';
		$cutoff    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$dedup_days} days" ) );
		$jobs_cpt  = Settings::get( 'jobs_cpt_slug', 'job' );

		$new = [];
		foreach ( $listings as $listing ) {
			// Check jobs_raw table
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE content_hash = %s AND discovered_at > %s",
				$listing['hash'],
				$cutoff
			) );
			if ( $exists ) {
				continue;
			}

			// Check if post already exists (by slug)
			$slug = sanitize_title( $listing['title'] );
			$post_exists = get_posts( [
				'post_type'   => $jobs_cpt,
				'name'        => $slug,
				'numberposts' => 1,
				'post_status' => 'any',
			] );
			if ( ! empty( $post_exists ) ) {
				continue;
			}

			$new[] = $listing;
		}

		return $new;
	}

	/**
	 * Process one job listing: AI-format and publish.
	 */
	private static function process_listing( array $listing, array $niche ) {
		global $wpdb;

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
		$content  = '<p>' . esc_html( $listing['description'] ) . '</p>';
		$content .= '<p><a href="' . esc_url( $listing['url'] ) . '" target="_blank" rel="noopener noreferrer">Apply for this position →</a></p>';

		return [
			'title'    => $listing['title'],
			'content'  => $content,
			'excerpt'  => substr( $listing['description'], 0, 200 ),
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
		update_post_meta( $post_id, '_cep_job_source', esc_url_raw( $listing['source_url'] ) );
		update_post_meta( $post_id, '_cep_job_pub_date', sanitize_text_field( $listing['pub_date'] ) );

		// Tags
		if ( ! empty( $formatted['tags'] ) && is_array( $formatted['tags'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', $formatted['tags'] ), 'post_tag' );
		}

		do_action( 'cep_job_published', $post_id, $listing, $formatted );

		return $post_id;
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	private static function extract_company( string $title, string $desc, $item ): string {
		// Many job feeds use "Job Title at Company" or "Company: Job Title" formats
		if ( preg_match( '/\bat\s+([A-Z][^\|–-]{2,40})$/u', $title, $m ) ) {
			return trim( $m[1] );
		}
		if ( preg_match( '/^([A-Z][^:]{2,40}):\s+/u', $title, $m ) ) {
			return trim( $m[1] );
		}
		// Try common RSS extensions
		if ( isset( $item->children( 'job', true )->company ) ) {
			return (string) $item->children( 'job', true )->company;
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
}
