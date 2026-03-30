<?php
namespace ContentEnginePro\Api;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints: /wp-json/cep/v1/
 */
class RestApi {

	public function register_routes(): void {
		$namespace = 'cep/v1';

		// Articles
		register_rest_route( $namespace, '/articles', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_articles' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'per_page' => [ 'default' => 10, 'sanitize_callback' => 'absint' ],
				'page'     => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
				'category' => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( $namespace, '/articles/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_article' ],
			'permission_callback' => '__return_true',
		] );

		// Reviews
		register_rest_route( $namespace, '/reviews', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_reviews' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'per_page' => [ 'default' => 10, 'sanitize_callback' => 'absint' ],
				'type'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		// Sources
		register_rest_route( $namespace, '/sources', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_sources' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		// Stats
		register_rest_route( $namespace, '/stats', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => '__return_true',
		] );

		// Trigger crawl (admin only)
		register_rest_route( $namespace, '/crawl/trigger', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'trigger_crawl' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		do_action( 'cep_register_rest_routes', $namespace );
	}

	public function get_articles( \WP_REST_Request $request ): \WP_REST_Response {
		$post_type = Settings::get( 'primary_cpt_slug', 'post' );
		$tax       = Settings::get( 'primary_tax_slug', 'article-category' );
		$category  = $request->get_param( 'category' );

		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => min( 50, $request->get_param( 'per_page' ) ),
			'paged'          => $request->get_param( 'page' ),
		];

		if ( $category ) {
			$args['tax_query'] = [
				[ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $category ],
			];
		}

		$posts = get_posts( $args );
		$data  = array_map( [ $this, 'format_post' ], $posts );

		return new \WP_REST_Response( $data, 200 );
	}

	public function get_article( \WP_REST_Request $request ): \WP_REST_Response {
		$post = get_post( $request['id'] );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		}

		return new \WP_REST_Response( $this->format_post( $post, true ), 200 );
	}

	public function get_reviews( \WP_REST_Request $request ): \WP_REST_Response {
		$post_type = Settings::get( 'reviews_cpt_slug', 'review' );
		$tax       = Settings::get( 'reviews_tax_slug', 'review-type' );
		$type      = $request->get_param( 'type' );

		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => min( 50, $request->get_param( 'per_page' ) ),
		];

		if ( $type ) {
			$args['tax_query'] = [
				[ 'taxonomy' => $tax, 'field' => 'slug', 'terms' => $type ],
			];
		}

		$posts = get_posts( $args );
		$data  = array_map( function( $post ) {
			$item              = $this->format_post( $post );
			$item['rating']    = (float) get_post_meta( $post->ID, '_cep_review_star_rating', true );
			$item['price']     = get_post_meta( $post->ID, '_cep_review_price_from', true );
			$item['verdict']   = get_post_meta( $post->ID, '_cep_review_verdict', true );
			return $item;
		}, $posts );

		return new \WP_REST_Response( $data, 200 );
	}

	public function get_sources( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$sources = $wpdb->get_results(
			"SELECT id, name, feed_url, category, crawl_window, tier, reliability_score, is_active, last_crawled_at FROM {$wpdb->prefix}cep_sources ORDER BY tier ASC, name ASC",
			ARRAY_A
		);
		return new \WP_REST_Response( $sources, 200 );
	}

	public function get_stats( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$primary   = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews   = Settings::get( 'reviews_cpt_slug', 'review' );

		$articles_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$primary
		) );

		$reviews_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$reviews
		) );

		$sources_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_sources WHERE is_active = 1" );
		$queue_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );

		return new \WP_REST_Response( [
			'articles' => $articles_count,
			'reviews'  => $reviews_count,
			'sources'  => $sources_count,
			'queue'    => $queue_count,
		], 200 );
	}

	public function trigger_crawl( \WP_REST_Request $request ): \WP_REST_Response {
		$window = sanitize_key( $request->get_param( 'window' ) ?? '' );
		\ContentEnginePro\Crawl\Crawler::run_window( $window );
		return new \WP_REST_Response( [ 'success' => true, 'message' => 'Crawl triggered.' ], 200 );
	}

	public function check_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	private function format_post( \WP_Post $post, bool $include_content = false ): array {
		$data = [
			'id'        => $post->ID,
			'title'     => get_the_title( $post->ID ),
			'excerpt'   => get_the_excerpt( $post ),
			'url'       => get_permalink( $post->ID ),
			'date'      => get_the_date( 'c', $post->ID ),
			'thumbnail' => get_the_post_thumbnail_url( $post->ID, 'large' ) ?: null,
		];

		if ( $include_content ) {
			$data['content'] = apply_filters( 'the_content', $post->post_content );
		}

		return apply_filters( 'cep_rest_format_post', $data, $post );
	}
}
