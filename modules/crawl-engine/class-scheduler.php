<?php
namespace ContentEnginePro\Crawl;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scheduler {

	public static function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => 'Once Weekly',
			];
		}
		if ( ! isset( $schedules['twicedaily'] ) ) {
			$schedules['twicedaily'] = [
				'interval' => 12 * HOUR_IN_SECONDS,
				'display'  => 'Twice Daily',
			];
		}
		if ( ! isset( $schedules['every_three_days'] ) ) {
			$schedules['every_three_days'] = [
				'interval' => 3 * DAY_IN_SECONDS,
				'display'  => 'Every 3 Days',
			];
		}
		return $schedules;
	}

	public static function schedule_all(): void {
		if ( ! Settings::is_enabled( 'enable_crawling' ) ) {
			return;
		}

		$events = [
			[ 'hook' => 'cep_crawl_morning',  'recurrence' => 'daily',      'start_offset' => 7 * HOUR_IN_SECONDS ],
			[ 'hook' => 'cep_crawl_midday',   'recurrence' => 'daily',      'start_offset' => 12 * HOUR_IN_SECONDS ],
			[ 'hook' => 'cep_crawl_evening',  'recurrence' => 'daily',      'start_offset' => 19 * HOUR_IN_SECONDS ],
			[ 'hook' => 'cep_crawl_weekly',   'recurrence' => 'weekly',     'start_offset' => 24 * HOUR_IN_SECONDS ],
			[ 'hook' => 'cep_log_prune',      'recurrence' => 'daily',      'start_offset' => 3 * HOUR_IN_SECONDS ],
		];

		// Article autopilot — runs once daily after crawl has fresh content
		if ( Settings::is_enabled( 'article_autopilot_enabled' ) ) {
			$events[] = [ 'hook' => 'cep_article_autopilot', 'recurrence' => 'daily', 'start_offset' => 9 * HOUR_IN_SECONDS ];
		}

		// Review autopilot
		if ( Settings::is_enabled( 'enable_reviews' ) && Settings::is_enabled( 'review_autopilot_enabled' ) ) {
			$events[] = [ 'hook' => 'cep_reviews_discover', 'recurrence' => 'daily',  'start_offset' => 2 * HOUR_IN_SECONDS ];
			$events[] = [ 'hook' => 'cep_reviews_generate', 'recurrence' => 'daily',  'start_offset' => 5 * HOUR_IN_SECONDS ];
			$events[] = [ 'hook' => 'cep_reviews_update',   'recurrence' => 'weekly', 'start_offset' => 4 * HOUR_IN_SECONDS ];
		}

		// Jobs autopilot
		if ( Settings::is_enabled( 'jobs_cpt_enabled' ) && Settings::is_enabled( 'jobs_autopilot_enabled' ) ) {
			$events[] = [ 'hook' => 'cep_jobs_aggregate', 'recurrence' => 'twicedaily', 'start_offset' => 1 * HOUR_IN_SECONDS ];
		}

		// Trending topics autopilot — runs twice daily, one hour before article autopilot
		if ( Settings::is_enabled( 'trending_autopilot_enabled' ) ) {
			$events[] = [ 'hook' => 'cep_trending_autopilot', 'recurrence' => 'twicedaily', 'start_offset' => 8 * HOUR_IN_SECONDS ];
		}

		// SEO Agent — runs every 3 days
		if ( Settings::is_enabled( 'enable_seo_agent' ) ) {
			$events[] = [ 'hook' => 'cep_seo_analysis', 'recurrence' => 'every_three_days', 'start_offset' => 6 * HOUR_IN_SECONDS ];
		}

		foreach ( $events as $event ) {
			if ( ! wp_next_scheduled( $event['hook'] ) ) {
				wp_schedule_event(
					time() + $event['start_offset'],
					$event['recurrence'],
					$event['hook']
				);
			}
		}
	}
}
