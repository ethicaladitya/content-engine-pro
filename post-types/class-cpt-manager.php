<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamically registers CPTs and taxonomies from plugin settings.
 * If primary_cpt_slug === 'post', no new CPT is created; taxonomies
 * are attached to the built-in posts type instead.
 */
class CptManager {

	public static function register_all(): void {
		self::register_primary_taxonomy();
		self::register_secondary_taxonomy();

		$primary_slug = Settings::get( 'primary_cpt_slug', 'post' );
		if ( 'post' !== $primary_slug ) {
			self::register_primary_cpt();
			Logger::log( "Registered primary post type: {$primary_slug}", 'debug', 'cpt_manager', [ 'post_type' => $primary_slug ] );
		} else {
			Logger::log( 'Primary post type uses built-in post', 'debug', 'cpt_manager', [ 'post_type' => 'post' ] );
		}

		if ( Settings::is_enabled( 'reviews_cpt_enabled' ) ) {
			self::register_reviews_cpt();
			self::register_reviews_taxonomy();
			Logger::log( 'Registered reviews post type', 'debug', 'cpt_manager', [ 'post_type' => Settings::get( 'reviews_cpt_slug', 'review' ) ] );
		}

		// Provider CPT is always registered — the affiliate system depends on it for data storage.
		// The providers_cpt_enabled setting may be off, but the CPT must exist or the admin
		// "Manage Providers" link returns "Invalid post type."
		self::register_providers_cpt();
		Logger::log( 'Registered providers post type', 'debug', 'cpt_manager', [ 'post_type' => Settings::get( 'providers_cpt_slug', 'provider' ) ] );

		if ( Settings::is_enabled( 'jobs_cpt_enabled' ) ) {
			self::register_jobs_cpt();
			Logger::log( 'Registered jobs post type', 'debug', 'cpt_manager', [ 'post_type' => Settings::get( 'jobs_cpt_slug', 'job' ) ] );
		}

		do_action( 'cep_register_post_types' );
	}

	// ─── Primary CPT (only when not using built-in posts) ───────────────────

	private static function register_primary_cpt(): void {
		$slug     = Settings::get( 'primary_cpt_slug', 'article' );
		$singular = Settings::get( 'primary_cpt_singular', 'Article' );
		$plural   = Settings::get( 'primary_cpt_plural', 'Articles' );
		$archive  = Settings::get( 'primary_cpt_archive_slug', 'news' );
		$icon     = Settings::get( 'primary_cpt_icon', 'dashicons-media-document' );

		$labels = [
			'name'               => $plural,
			'singular_name'      => $singular,
			'add_new'            => "Add New {$singular}",
			'add_new_item'       => "Add New {$singular}",
			'edit_item'          => "Edit {$singular}",
			'view_item'          => "View {$singular}",
			'all_items'          => "All {$plural}",
			'search_items'       => "Search {$plural}",
			'not_found'          => "No {$plural} found.",
			'not_found_in_trash' => "No {$plural} found in Trash.",
		];

		$tax_slug  = Settings::get( 'primary_tax_slug', 'article-category' );
		$tax2_slug = Settings::get( 'secondary_tax_slug', 'article-tag' );

		register_post_type(
			$slug,
			apply_filters(
				'cep_primary_cpt_args',
				[
					'labels'              => apply_filters( 'cep_primary_cpt_labels', $labels ),
					'public'              => true,
					'publicly_queryable'  => true,
					'show_ui'             => true,
					'show_in_rest'        => true,
					'has_archive'         => $archive,
					'rewrite'             => [ 'slug' => $archive, 'with_front' => false, 'feeds' => true ],
					'supports'            => [ 'title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions', 'custom-fields' ],
					'taxonomies'          => [ $tax_slug, $tax2_slug ],
					'menu_icon'           => $icon,
					'menu_position'       => 5,
					'capability_type'     => 'post',
				]
			)
		);
	}

	// ─── Primary Taxonomy (categories) ──────────────────────────────────────

	private static function register_primary_taxonomy(): void {
		$slug     = Settings::get( 'primary_tax_slug', 'category' );

		// Built-in WordPress taxonomies are already registered — nothing to do.
		if ( in_array( $slug, [ 'category', 'post_tag', 'post_format' ], true ) ) {
			// Still ensure the configured terms exist (e.g. create "Celebrity Gossip" in WP categories).
			self::ensure_terms( $slug, Settings::get( 'primary_tax_terms', '' ) );
			return;
		}
		$singular = Settings::get( 'primary_tax_singular', 'Category' );
		$plural   = Settings::get( 'primary_tax_plural', 'Categories' );
		$archive  = Settings::get( 'primary_tax_archive_slug', 'news' );

		$primary_cpt = Settings::get( 'primary_cpt_slug', 'post' );

		$labels = [
			'name'              => $plural,
			'singular_name'     => $singular,
			'search_items'      => "Search {$plural}",
			'all_items'         => "All {$plural}",
			'edit_item'         => "Edit {$singular}",
			'update_item'       => "Update {$singular}",
			'add_new_item'      => "Add New {$singular}",
			'new_item_name'     => "New {$singular} Name",
			'menu_name'         => $plural,
		];

		register_taxonomy(
			$slug,
			$primary_cpt,
			apply_filters(
				'cep_primary_tax_args',
				[
					'labels'            => apply_filters( 'cep_primary_tax_labels', $labels ),
					'hierarchical'      => true,
					'public'            => true,
					'show_ui'           => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'rewrite'           => [ 'slug' => $archive, 'with_front' => false, 'hierarchical' => false ],
				]
			)
		);

		// Auto-create default terms
		self::ensure_terms( $slug, Settings::get( 'primary_tax_terms', '' ) );
	}

	// ─── Secondary Taxonomy (tags) ───────────────────────────────────────────

	private static function register_secondary_taxonomy(): void {
		$slug     = Settings::get( 'secondary_tax_slug', 'post_tag' );

		// Built-in WordPress taxonomies are already registered — nothing to do.
		if ( in_array( $slug, [ 'category', 'post_tag', 'post_format' ], true ) ) {
			return;
		}
		$singular = Settings::get( 'secondary_tax_singular', 'Tag' );
		$plural   = Settings::get( 'secondary_tax_plural', 'Tags' );
		$archive  = Settings::get( 'secondary_tax_archive_slug', 'topic' );

		$primary_cpt = Settings::get( 'primary_cpt_slug', 'post' );

		$labels = [
			'name'              => $plural,
			'singular_name'     => $singular,
			'search_items'      => "Search {$plural}",
			'all_items'         => "All {$plural}",
			'edit_item'         => "Edit {$singular}",
			'update_item'       => "Update {$singular}",
			'add_new_item'      => "Add New {$singular}",
			'new_item_name'     => "New {$singular} Name",
			'menu_name'         => $plural,
		];

		register_taxonomy(
			$slug,
			$primary_cpt,
			apply_filters(
				'cep_secondary_tax_args',
				[
					'labels'            => apply_filters( 'cep_secondary_tax_labels', $labels ),
					'hierarchical'      => false,
					'public'            => true,
					'show_ui'           => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'rewrite'           => [ 'slug' => $archive, 'with_front' => false ],
				]
			)
		);
	}

	// ─── Reviews CPT ────────────────────────────────────────────────────────

	private static function register_reviews_cpt(): void {
		$slug     = Settings::get( 'reviews_cpt_slug', 'review' );
		$singular = Settings::get( 'reviews_cpt_singular', 'Review' );
		$plural   = Settings::get( 'reviews_cpt_plural', 'Reviews' );
		$archive  = Settings::get( 'reviews_cpt_archive_slug', 'reviews' );

		$labels = [
			'name'               => $plural,
			'singular_name'      => $singular,
			'add_new_item'       => "Add New {$singular}",
			'edit_item'          => "Edit {$singular}",
			'view_item'          => "View {$singular}",
			'all_items'          => "All {$plural}",
			'search_items'       => "Search {$plural}",
			'not_found'          => "No {$plural} found.",
			'not_found_in_trash' => "No {$plural} found in Trash.",
		];

		register_post_type(
			$slug,
			apply_filters(
				'cep_reviews_cpt_args',
				[
					'labels'              => $labels,
					'public'              => true,
					'publicly_queryable'  => true,
					'show_ui'             => true,
					'show_in_rest'        => true,
					'has_archive'         => $archive,
					'rewrite'             => [ 'slug' => $archive, 'with_front' => false ],
					'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ],
					'taxonomies'          => [ Settings::get( 'reviews_tax_slug', 'review-type' ) ],
					'menu_icon'           => 'dashicons-star-filled',
					'capability_type'     => 'post',
				]
			)
		);
	}

	// ─── Reviews Taxonomy ────────────────────────────────────────────────────

	private static function register_reviews_taxonomy(): void {
		$slug     = Settings::get( 'reviews_tax_slug', 'review-type' );
		$cpt_slug = Settings::get( 'reviews_cpt_slug', 'review' );

		register_taxonomy(
			$slug,
			$cpt_slug,
			[
				'labels'            => [
					'name'          => 'Review Types',
					'singular_name' => 'Review Type',
				],
				'hierarchical'      => true,
				'public'            => true,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => [ 'slug' => Settings::get( 'reviews_cpt_archive_slug', 'reviews' ) . '/type', 'with_front' => false ],
			]
		);

		self::ensure_terms( $slug, Settings::get( 'reviews_tax_terms', 'plugin,hosting,theme,service,tool' ) );
	}

	// ─── Providers CPT (private — admin only) ────────────────────────────────

	private static function register_providers_cpt(): void {
		$slug     = Settings::get( 'providers_cpt_slug', 'provider' );
		$singular = Settings::get( 'providers_cpt_singular', 'Provider' );
		$plural   = Settings::get( 'providers_cpt_plural', 'Providers' );

		register_post_type(
			$slug,
			apply_filters(
				'cep_providers_cpt_args',
				[
					'labels'          => [
						'name'          => $plural,
						'singular_name' => $singular,
						'add_new_item'  => "Add New {$singular}",
						'edit_item'     => "Edit {$singular}",
						'all_items'     => "All {$plural}",
					],
					'public'          => false,
					'show_ui'         => true,
					'show_in_rest'    => false,
					'supports'        => [ 'title', 'custom-fields' ],
					'menu_icon'       => 'dashicons-store',
					'capability_type' => 'post',
					'show_in_menu'    => 'cep-dashboard',
				]
			)
		);
	}

	// ─── Jobs CPT ───────────────────────────────────────────────────────────

	private static function register_jobs_cpt(): void {
		$slug     = Settings::get( 'jobs_cpt_slug', 'job' );
		$singular = Settings::get( 'jobs_cpt_singular', 'Job' );
		$plural   = Settings::get( 'jobs_cpt_plural', 'Jobs' );
		$archive  = Settings::get( 'jobs_cpt_archive_slug', 'jobs' );

		register_post_type(
			$slug,
			apply_filters(
				'cep_jobs_cpt_args',
				[
					'labels'             => [
						'name'               => $plural,
						'singular_name'      => $singular,
						'add_new_item'       => "Add New {$singular}",
						'edit_item'          => "Edit {$singular}",
						'all_items'          => "All {$plural}",
						'not_found'          => "No {$plural} found.",
					],
					'public'             => true,
					'publicly_queryable' => true,
					'show_ui'            => true,
					'show_in_rest'       => true,
					'has_archive'        => $archive,
					'rewrite'            => [ 'slug' => $archive, 'with_front' => false ],
					'supports'           => [ 'title', 'editor', 'excerpt', 'author', 'custom-fields' ],
					'menu_icon'          => 'dashicons-businessman',
					'capability_type'    => 'post',
				]
			)
		);
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Ensure comma-separated terms exist in a given taxonomy.
	 */
	private static function ensure_terms( string $taxonomy, string $terms_csv ): void {
		if ( empty( $terms_csv ) ) {
			return;
		}
		$terms = array_map( 'trim', explode( ',', $terms_csv ) );
		foreach ( $terms as $term ) {
			if ( $term && ! term_exists( $term, $taxonomy ) ) {
				wp_insert_term( $term, $taxonomy );
			}
		}
	}
}
