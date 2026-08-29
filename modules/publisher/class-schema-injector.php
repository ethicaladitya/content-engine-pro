<?php
namespace ContentEnginePro\Publisher;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects JSON-LD schema markup and, as a fallback, meta/OG tags.
 *
 * Responsibility split:
 *  - inject_schema()   : Review CPT only. All other post types are handled by the
 *                        active SEO plugin (Yoast, RankMath, SmartCrawl, etc.) or
 *                        by seo-agent-ai for BlogPosting / FAQPage.
 *  - inject_seo_meta() : Fallback meta/OG/Twitter output only when no SEO plugin
 *                        is active. When any SEO plugin is present this method
 *                        returns immediately to avoid duplicate tags.
 */
class SchemaInjector {

	public function register(): void {
		add_action( 'wp_head', [ $this, 'inject_schema' ], 5 );

		if ( Settings::is_enabled( 'enable_seo_meta' ) ) {
			add_action( 'wp_head', [ $this, 'inject_seo_meta' ], 2 );
		}
	}

	// -------------------------------------------------------------------
	// SEO plugin detection
	// -------------------------------------------------------------------

	/**
	 * Returns the slug of the first active SEO plugin found, or empty string.
	 *
	 * Covers: Yoast SEO, RankMath, SmartCrawl (WPMU DEV), All in One SEO,
	 * SEOPress, and The SEO Framework.
	 */
	private function active_seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend', false ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			return 'rankmath';
		}
		if (
			defined( 'SMARTCRAWL_VERSION' )
			|| class_exists( 'SmartCrawl_Settings', false )
			|| class_exists( 'Smartcrawl\\Smartcrawl', false )
		) {
			return 'smartcrawl';
		}
		if (
			defined( 'AIOSEO_VERSION' )
			|| class_exists( 'AIOSEO\\Plugin\\AIOSEO', false )
			|| function_exists( 'aioseo' )
		) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || class_exists( 'SeoPress_Admin_Pages', false ) ) {
			return 'seopress';
		}
		if ( function_exists( 'the_seo_framework' ) || class_exists( 'The_SEO_Framework\\Load', false ) ) {
			return 'seoframework';
		}
		return '';
	}

	private function has_active_seo_plugin(): bool {
		return $this->active_seo_plugin() !== '';
	}

	// -------------------------------------------------------------------
	// Schema injection
	// -------------------------------------------------------------------

	public function inject_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$post        = get_post( $post_id );
		$post_type   = get_post_type( $post_id );
		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );

		// Reviews CPT gets Review + ReviewRating schema (SEO plugins don't).
		if ( $post_type === $reviews_cpt ) {
			$schema = $this->build_review_schema( $post );
			$schema = apply_filters( 'cep_schema_data', $schema, $post_id, $post_type );
			if ( ! empty( $schema ) ) {
				echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
			}
			$this->inject_breadcrumbs();
			return;
		}

		// Job CPT gets JobPosting schema for Google Jobs rich results.
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		if ( $post_type === $jobs_cpt ) {
			$schema = $this->build_job_schema( $post );
			$schema = apply_filters( 'cep_schema_data', $schema, $post_id, $post_type );
			if ( ! empty( $schema ) ) {
				echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
			}
			$this->inject_breadcrumbs();
			return;
		}

		$schema = $this->build_review_schema( $post );
		$schema = apply_filters( 'cep_schema_data', $schema, $post_id, $post_type );

		if ( empty( $schema ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	// -------------------------------------------------------------------
	// Schema builders
	// -------------------------------------------------------------------

	private function build_review_schema( \WP_Post $post ): array {
		$author_id   = (int) $post->post_author;
		$author_name = get_the_author_meta( 'display_name', $author_id )
			?: Settings::get( 'brand_name', get_bloginfo( 'name' ) );
		$rating      = (float) get_post_meta( $post->ID, '_cep_review_star_rating', true );
		$product_url = get_post_meta( $post->ID, '_cep_review_product_url', true );

		return [
			'@context'     => 'https://schema.org',
			'@type'        => 'Review',
			'name'         => get_the_title( $post->ID ),
			'url'          => get_permalink( $post->ID ),
			'datePublished'=> get_the_date( 'c', $post->ID ),
			'author'       => [
				'@type' => 'Person',
				'name'  => esc_html( $author_name ),
				'url'   => get_author_posts_url( $author_id ),
			],
			'reviewRating' => [
				'@type'       => 'Rating',
				'ratingValue' => $rating ?: 3.0,
				'bestRating'  => 5,
				'worstRating' => 1,
			],
			'itemReviewed' => [
				'@type' => 'SoftwareApplication',
				'name'  => get_the_title( $post->ID ),
				'url'   => $product_url ?: '',
			],
		];
	}

	// -------------------------------------------------------------------
	// Fallback meta / OG / Twitter (only when no SEO plugin is active)
	// -------------------------------------------------------------------

	public function inject_seo_meta(): void {
		// All major SEO plugins handle meta description, OG, and Twitter Card tags.
		// When any of them is active we return early to avoid duplicate output.
		if ( $this->has_active_seo_plugin() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$description = wp_trim_words( get_the_excerpt( $post_id ), 30 );
		$title       = get_the_title( $post_id );
		$url         = get_permalink( $post_id );
		$image       = '';

		if ( has_post_thumbnail( $post_id ) ) {
			$img   = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			$image = $img ? $img[0] : '';
		}

		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}

		echo '<meta property="og:type"  content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta property="og:url"   content="' . esc_url( $url ) . '" />' . "\n";
		if ( $description ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		echo '<meta name="twitter:card"  content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		}
	}

	/**
	 * Build JobPosting schema.org markup for a single job.
	 *
	 * Maps stored CEP meta onto the Google Jobs schema fields. All values are
	 * escaped/sanitised; the description is truncated to a safe length.
	 *
	 * @param \WP_Post $post
	 * @return array
	 */
        private function build_job_schema( \WP_Post $post ): array {
            $id      = (int) $post->ID;
            $company = (string) get_post_meta( $id, '_cep_job_company', true );
            $loc     = (string) get_post_meta( $id, '_cep_job_location', true );
            $type    = (string) get_post_meta( $id, '_cep_job_type', true );
            $salary  = (string) get_post_meta( $id, '_cep_job_salary', true );
            // Point directApply at the employer's own apply URL (with UTM)
            // when known, else the intermediary listing + UTM.
            $apply   = \ContentEnginePro\Jobs\JobAggregator::apply_url( $id );
            $source  = (string) get_post_meta( $id, '_cep_job_source', true );
            $desc    = wp_kses_post( $post->post_content );
            $desc    = wp_trim_words( wp_strip_all_tags( $desc ), 400 );

            $remote  = (bool) preg_match( '/\bremote\b/i', $loc . ' ' . ($type ?: '') . ' ' . get_the_title( $id ) );
            $country = $this->derive_country( $loc );

            $hiring = [
                '@type' => 'Organization',
                'name'  => $company ?: get_bloginfo( 'name' ),
            ];
            $same   = $this->derive_domain( $source ?: $apply );
            if ( $same ) {
                $hiring['sameAs'] = esc_url( $same );
            }

            $schema = [
                '@context'        => 'https://schema.org',
                '@type'           => 'JobPosting',
                'title'           => get_the_title( $id ),
                'description'     => $desc,
                'datePosted'      => get_the_date( 'c', $id ),
                'validThrough'    => gmdate( 'c', strtotime( $post->post_date_gmt ) + 30 * DAY_IN_SECONDS ),
                'employmentType'  => $this->map_employment_type( $type ),
                'hiringOrganization' => $hiring,
                'jobLocation'     => [
                    '@type' => 'Place',
                    'address' => $this->build_address( $loc, $country ),
                ],
                'directApply'     => $apply ? [ '@type' => 'URL', 'url' => esc_url( $apply ) ] : false,
            ];

            if ( $remote ) {
                $schema['jobLocationType'] = 'TELECOMMUTE';
                if ( $country ) {
                    $schema['applicantLocationRequirements'] = [
                        '@type' => 'Country',
                        'name'  => $country,
                    ];
                }
            }
            if ( $salary ) {
                $schema['baseSalary'] = $this->build_salary( $salary );
            }
            return $schema;
        }

        private function build_address( string $loc, string $country ): array {
            $parts = preg_split( '/\s*,\s*/', trim( $loc ) );
            $city  = $parts[0] ?? '';
            return [
                '@type'           => 'PostalAddress',
                'addressLocality' => $city,
                'addressCountry'  => $country ?: '',
            ];
        }

        private function build_salary( string $salary ): array {
            $num = preg_replace( '/[^0-9.]/', '', $salary );
            return [
                '@type'    => 'MonetaryAmount',
                'currency' => 'USD',
                'value'    => [
                    '@type'    => 'QuantitativeValue',
                    'value'    => $num ? (float) $num : 0,
                    'unitText' => 'YEAR',
                ],
            ];
        }

        private function map_employment_type( string $type ): array {
            $t = strtolower( $type );
            $map = [
                'full'    => 'FULL_TIME',
                'part'    => 'PART_TIME',
                'contract'=> 'CONTRACTOR',
                'temp'    => 'TEMPORARY',
                'intern'  => 'INTERN',
            ];
            foreach ( $map as $k => $v ) {
                if ( strpos( $t, $k ) !== false ) {
                    return [ $v ];
                }
            }
            return [ 'FULL_TIME' ];
        }

        private function derive_country( string $loc ): string {
            $map = [
                'usa' => 'US', 'united states' => 'US', 'us ' => 'US', 'u.s.' => 'US',
                'uk' => 'GB', 'united kingdom' => 'GB', 'england' => 'GB',
                'india' => 'IN', 'remote, india' => 'IN',
                'canada' => 'CA', 'germany' => 'DE', 'eu' => 'EU', 'europe' => 'EU',
                'australia' => 'AU', 'apac' => 'APAC',
            ];
            $l = strtolower( $loc );
            foreach ( $map as $k => $v ) {
                if ( strpos( $l, $k ) !== false ) {
                    return $v;
                }
            }
            return '';
        }

        private function derive_domain( string $url ): string {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            return $host ? 'https://' . preg_replace( '/^www\./', '', $host ) : '';
        }

	/**
	 * Emit BreadcrumbList JSON-LD for the current job or jobs archive.
	 */
        private function inject_breadcrumbs(): void {
            $items   = [];
            $home    = home_url( '/' );
            $items[] = [ '@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo( 'name' ), 'item' => $home ];

            $jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
            $archive  = Settings::get( 'jobs_archive_slug', 'jobs' );
            $jobs_url = $archive ? home_url( '/' . $archive . '/' ) : get_post_type_archive_link( $jobs_cpt );
            if ( $jobs_url ) {
                $items[] = [ '@type' => 'ListItem', 'position' => 2, 'name' => 'Remote Jobs', 'item' => esc_url( $jobs_url ) ];
            }

            if ( is_singular( $jobs_cpt ) ) {
                $items[] = [ '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title( get_the_ID() ), 'item' => esc_url( get_permalink( get_the_ID() ) ) ];
            }

            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => 'BreadcrumbList',
                'itemListElement' => $items,
            ];
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
        }
}