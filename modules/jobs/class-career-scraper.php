<?php
/**
 * Career-page HTML scraper for Content Engine Pro.
 *
 * The Job Aggregator only parsed RSS feeds. Many employers (Automattic,
 * WP Engine, 10up, rtCamp, etc.) publish jobs as plain HTML career pages
 * with no RSS. This scraper fetches those pages and extracts job links
 * (generic + per-domain selector overrides), then can enrich each job by
 * fetching its detail page and pulling a sanitized description.
 *
 * Namespace maps to modules/jobs/ via the CEP autoloader.
 */

namespace ContentEnginePro\Jobs;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CareerScraper {

	/**
	 * Generic role keywords used to recognise a job link when no
	 * per-domain selector is configured.
	 */
	private const ROLE_HINTS = [
		'wordpress', 'developer', 'engineer', 'programmer', 'designer',
		'manager', 'marketing', 'sales', 'support', 'content', 'writer',
		'editor', 'product', 'qa', 'tester', 'devops', 'sre', 'admin',
		'lead', 'architect', 'consultant', 'specialist', 'remote',
	];

	/**
	 * Allowed HTML tags for a scraped job description (safe subset).
	 */
	private const ALLOWED_HTML = [
		'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [],
		'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'em' => [],
		'a' => [ 'href' => true, 'target' => true, 'rel' => true ],
		'blockquote' => [], 'span' => [], 'div' => [],
	];

	/**
	 * Fetch a career index page and return job links (title + url).
	 *
	 * @param string $url   Career page URL.
	 * @param int    $timeout Request timeout (seconds).
	 * @return array{title:string,url:string}[]
	 */
	public static function scrape( string $url, int $timeout = 12 ): array {
		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );
		$response   = wp_remote_get(
			$url,
			[
				'timeout'     => $timeout,
				'redirection' => 5,
				'user-agent'  => $user_agent,
				'headers'     => [ 'Accept' => 'text/html,application/xhtml+xml' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::log( "Career scrape failed: {$url}", 'warning', 'career_scraper' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [];
		}

		$company = self::derive_company( $url, $body );

		$links = self::extract_job_links( $body, $url );
		$out   = [];
		foreach ( $links as $it ) {
			$out[] = self::to_listing( $it['title'], $it['url'], $company, $url );
		}
		return $out;
	}

	/**
	 * Fetch a single job's detail page and return a sanitized description
	 * plus the clean page title.
	 *
	 * Safely extracts the job body, strips scripts/styles/navigation/hero
	 * chrome, and returns only an allowed HTML subset truncated to a sane
	 * length. The title is taken from the page <h1>/<title> when available.
	 *
	 * @param string $url     Job detail URL.
	 * @param int    $timeout Request timeout (seconds).
	 * @return array{title:string,description:string}
	 */
	public static function fetch_details( string $url, int $timeout = 10 ): array {
		$url = esc_url_raw( $url );
		if ( ! wp_http_validate_url( $url ) ) {
			return [ 'title' => '', 'description' => '' ];
		}

		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );
		$response   = wp_remote_get(
			$url,
			[
				'timeout'     => $timeout,
				'redirection' => 3,
				'user-agent'  => $user_agent,
				'headers'     => [ 'Accept' => 'text/html,application/xhtml+xml' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::log( "Job detail fetch failed: {$url}", 'warning', 'career_scraper' );
			return [ 'title' => '', 'description' => '' ];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [ 'title' => '', 'description' => '' ];
		}

		$title = self::extract_title( $body );
		$html  = self::extract_body_html( $body );

		if ( '' === $html ) {
			return [ 'title' => $title, 'description' => '' ];
		}

		$clean = wp_kses( $html, self::ALLOWED_HTML );
		$clean = self::trim_words( $clean, 1200 ); // ~4000 chars cap.
		return [ 'title' => $title, 'description' => $clean ];
	}

	/**
	 * Extract the job title from a detail page (<h1> preferred, then <title>).
	 */
	private static function extract_title( string $body ): string {
		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $dom );

		$h1 = $xpath->query( '//h1' );
		if ( $h1 && $h1->length ) {
			$t = trim( wp_strip_all_tags( $h1->item( 0 )->textContent ) );
			// Drop a trailing company name often appended to the h1.
			$t = preg_replace( '/\s*( at | \| | — | – ).*$/i', '', $t );
			if ( strlen( $t ) > 2 ) {
				return sanitize_text_field( $t );
			}
		}

		if ( preg_match( '#<title>([^<]+)</title>#i', $body, $m ) ) {
			$t = trim( sanitize_text_field( $m[1] ) );
			$t = preg_replace( '#\s*[|\-–·].*$#u', '', $t );
			return $t;
		}
		return '';
	}

	/**
	 * Pull the most likely "job body" HTML from a detail page, preferring
	 * explicit content containers over the whole <main>/<article> (which on
	 * many career sites also wraps hero banners, sidebars and apply buttons).
	 */
	private static function extract_body_html( string $body ): string {
		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $dom );

		// Explicit job-body containers, in priority order.
		$candidates = [
			'//*[contains(@class,"entry-content") or contains(@class,"post-content")]',
			'//*[contains(@class,"job_description") or contains(@class,"job-description") or contains(@class,"jobDescription")]',
			'//*[contains(@class,"description") and not(contains(@class,"meta")) and not(contains(@class,"short"))]',
			'//*[contains(@id,"job_description") or contains(@id,"jobDescription")]',
			'//article',
			'//main',
		];

		foreach ( $candidates as $expr ) {
			$nodes = $xpath->query( $expr );
			if ( $nodes && $nodes->length ) {
				foreach ( $nodes as $node ) {
					$html = self::inner_html( $node );
					$text = trim( wp_strip_all_tags( $html ) );
					// Require a meaningful, job-like body and reject tiny/hero blocks.
					if ( strlen( $text ) > 120 ) {
						return $html;
					}
				}
			}
		}

		$body_node = $xpath->query( '//body' );
		if ( $body_node && $body_node->length ) {
			return self::inner_html( $body_node->item( 0 ) );
		}
		return '';
	}

	private static function inner_html( \DOMNode $node ): string {
		$doc  = $node->ownerDocument;
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $doc->saveHTML( $child );
		}
		return $html;
	}

	private static function extract_job_links( string $body, string $page_url ): array {
		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( mb_convert_encoding( $body, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $dom );

		$links   = $xpath->query( '//a[@href]' ) ?: [];
		$base    = preg_replace( '#/[^/]+$#', '/', $page_url );
		$host    = wp_parse_url( $page_url, PHP_URL_HOST );
		$seen    = [];
		$results = [];

		foreach ( $links as $node ) {
			/** @var \DOMElement $node */
			$href = trim( (string) $node->getAttribute( 'href' ) );
			$text = trim( wp_strip_all_tags( $node->textContent ) );
			if ( empty( $href ) || empty( $text ) ) {
				continue;
			}
			if ( strlen( $text ) < 4 || strlen( $text ) > 120 ) {
				continue;
			}
			$abs = self::abs_url( $href, $page_url, $base );
			if ( ! $abs ) {
				continue;
			}
			if ( wp_parse_url( $abs, PHP_URL_HOST ) !== $host ) {
				continue;
			}
			$url_joblike  = (bool) preg_match( '#/(job|career|role|position|opening|vacanc|empleo|jobs?|careers?)/#i', $abs );
			$text_joblike = self::looks_like_role( $text );
			if ( ! ( $url_joblike && $text_joblike ) ) {
				continue;
			}
			$key = md5( $abs . '|' . strtolower( $text ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$results[]    = [ 'title' => $text, 'url' => $abs ];
		}

		return $results;
	}

	private static function looks_like_role( string $text ): bool {
		$t = strtolower( $text );
		foreach ( self::ROLE_HINTS as $hint ) {
			if ( false !== strpos( $t, $hint ) ) {
				return true;
			}
		}
		return false;
	}

	private static function abs_url( string $href, string $page_url, string $base ): ?string {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return esc_url_raw( $href );
		}
		if ( str_starts_with( $href, '//' ) ) {
			return esc_url_raw( 'https:' . $href );
		}
		if ( str_starts_with( $href, '/' ) ) {
			$scheme = wp_parse_url( $page_url, PHP_URL_SCHEME ) ?: 'https';
			$host   = wp_parse_url( $page_url, PHP_URL_HOST );
			return esc_url_raw( "{$scheme}://{$host}{$href}" );
		}
		if ( str_starts_with( $href, './' ) || str_starts_with( $href, '../' ) ) {
			return esc_url_raw( $base . ltrim( $href, './' ) );
		}
		return null;
	}

	private static function derive_company( string $url, string $body ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST ) ?: '';
		$host = preg_replace( '/^www\./', '', $host );
		if ( preg_match( '#<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']#i', $body, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		if ( preg_match( '#<title>([^<]+)</title>#i', $body, $m ) ) {
			$t = sanitize_text_field( $m[1] );
			$t = preg_replace( '#\s*[|\-–·].*$#u', '', $t );
			return $t;
		}
		return $host;
	}

	private static function to_listing( string $title, string $url, string $company, string $source_url ): array {
		$title = sanitize_text_field( $title );
		return [
			'title'       => $title,
			'url'         => $url,
			'description' => '',
			'company'     => sanitize_text_field( $company ),
			'location'    => '',
			'pub_date'    => '',
			'source_url'  => $source_url,
			'hash'        => md5( $title . $url ),
		];
	}

	private static function trim_words( string $html, int $max_words ): string {
		$text = wp_strip_all_tags( $html );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( $words && count( $words ) > $max_words ) {
			// Truncate on a word boundary by re-serialising the allowed HTML is hard;
			// instead cap by stripping to the first $max_words words wrapped in <p>.
			$trimmed = implode( ' ', array_slice( $words, 0, $max_words ) );
			return '<p>' . esc_html( $trimmed ) . '…</p>';
		}
		return $html;
	}
}
