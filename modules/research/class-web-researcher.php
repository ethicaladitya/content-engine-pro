<?php
namespace ContentEnginePro\Research;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web researcher: fetches a URL, extracts clean text, optionally summarizes with AI.
 *
 * Used by all autopilot pipelines to gather real-world data before writing.
 */
class WebResearcher {

	/**
	 * Fetch a URL and return extracted content.
	 *
	 * @param string $url
	 * @return array|null  [ 'url', 'title', 'text', 'meta_description', 'og_image', 'og_description' ] or null on failure.
	 */
	public static function fetch( string $url ): ?array {
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return null;
		}

		$timeout    = (int) Settings::get( 'research_timeout', 20 );
		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );

		$response = wp_remote_get( $url, [
			'timeout'    => $timeout,
			'user-agent' => $user_agent,
			'headers'    => [
				'Accept'          => 'text/html,application/xhtml+xml',
				'Accept-Language' => 'en-US,en;q=0.9',
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::log( "Research fetch failed: {$url} — " . $response->get_error_message(), 'warning', 'research' );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( (int) $code !== 200 ) {
			return null;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return null;
		}

		return self::parse_html( $html, $url );
	}

	/**
	 * Research multiple URLs and return aggregated text.
	 *
	 * @param string[] $urls
	 * @param int      $max_chars  Max total chars to return (sent to AI).
	 * @return string  Aggregated research text.
	 */
	public static function research_urls( array $urls, int $max_chars = 12000 ): string {
		$chunks    = [];
		$total     = 0;
		$max_depth = (int) Settings::get( 'article_research_depth', 3 );
		$urls      = array_slice( $urls, 0, $max_depth );

		foreach ( $urls as $url ) {
			$data = self::fetch( $url );
			if ( ! $data || empty( $data['text'] ) ) {
				continue;
			}

			$chunk = "### Source: {$data['url']}\n**Title:** {$data['title']}\n\n{$data['text']}";
			$len   = strlen( $chunk );

			if ( $total + $len > $max_chars ) {
				// Include a truncated version
				$remaining = $max_chars - $total;
				if ( $remaining > 500 ) {
					$chunks[] = substr( $chunk, 0, $remaining );
				}
				break;
			}

			$chunks[] = $chunk;
			$total   += $len;
		}

		return implode( "\n\n---\n\n", $chunks );
	}

	/**
	 * Use AI to summarize research into structured data.
	 *
	 * @param string $research_text  Raw aggregated research text.
	 * @param string $task           What to extract (e.g. "product info for review", "article summary").
	 * @param string $format_prompt  JSON format instruction.
	 * @return array|null  Decoded JSON or null.
	 */
	public static function ai_extract( string $research_text, string $task, string $format_prompt ): ?array {
		if ( empty( $research_text ) ) {
			return null;
		}

		$ai = AiClient::get_instance();

		$prompt = "You are a research assistant. Your task: {$task}\n\n"
			. "Based on the following research data, extract the requested information.\n\n"
			. $format_prompt . "\n\n"
			. "Research Data:\n" . $research_text;

		$result = $ai->complete( $prompt, '', [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => 0.3,
			'mini'            => true,
		] );

		if ( is_wp_error( $result ) ) {
			Logger::log( 'AI research extraction failed: ' . $result->get_error_message(), 'warning', 'research' );
			return null;
		}

		return json_decode( $result['content'], true );
	}

	// ─── HTML Parsing ─────────────────────────────────────────────────────────

	private static function parse_html( string $html, string $url ): array {
		$title           = '';
		$meta_description = '';
		$og_description  = '';
		$og_image        = '';

		// Extract title
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
			$title = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract meta description
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			$meta_description = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract OG tags
		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			$og_description = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			$og_image = trim( $m[1] );
		}

		// Extract clean text
		$text = self::extract_text( $html );

		return [
			'url'              => $url,
			'title'            => $title,
			'text'             => $text,
			'meta_description' => $meta_description,
			'og_description'   => $og_description,
			'og_image'         => $og_image,
		];
	}

	private static function extract_text( string $html ): string {
		// Remove scripts, styles, nav, header, footer, ads
		$html = preg_replace( '/<(script|style|nav|header|footer|aside|noscript|iframe|form)[^>]*>.*?<\/\1>/si', '', $html );

		// Remove all HTML tags
		$text = wp_strip_all_tags( $html );

		// Normalize whitespace
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Limit to configured max chars
		$max = (int) Settings::get( 'research_max_text_chars', 8000 );
		if ( strlen( $text ) > $max ) {
			$text = substr( $text, 0, $max ) . '...';
		}

		return $text;
	}
}
