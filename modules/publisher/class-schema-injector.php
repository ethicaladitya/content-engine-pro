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

		// Regular posts and pages are handled by the active SEO plugin. Do not
		// fall through to Review schema, which is reserved for the Review CPT.
		return;
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

            // A listing with no location at all is treated as remote: this is a
            // remote-jobs board, and Google needs either a jobLocation with an
            // address or TELECOMMUTE + applicantLocationRequirements.
            $remote  = '' === trim( $loc ) || (bool) preg_match( '/\bremote\b/i', $loc . ' ' . ($type ?: '') . ' ' . get_the_title( $id ) );
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
                'validThrough'    => $this->job_valid_through( $post, $id ),
                'employmentType'  => $this->map_employment_type( $type ),
                'hiringOrganization' => $hiring,
                'directApply'     => $apply ? [ '@type' => 'URL', 'url' => esc_url( $apply ) ] : false,
            ];

            // Google rejects a jobLocation without an address, so only emit it
            // when one can be built; remote roles fall back to TELECOMMUTE.
            $address = $this->build_address( $loc, $country, $remote );
            if ( $address ) {
                $schema['jobLocation'] = [
                    '@type'   => 'Place',
                    'address' => $address,
                ];
            }

            if ( $remote || ! $address ) {
                $schema['jobLocationType'] = 'TELECOMMUTE';
                $schema['applicantLocationRequirements'] = $this->build_applicant_location( $loc, $country );
            }
            $base_salary = $this->build_salary( $salary, $country );
            if ( $base_salary ) {
                $schema['baseSalary'] = $base_salary;
            }
            return $schema;
        }

        /**
         * Resolve the validThrough date for a job. Prefers the stored expiry
         * written at publish time (keeps the schema and the expiry cron in
         * sync); falls back to post date + 30 days for older jobs that predate
         * the stored meta.
         */
        private function job_valid_through( \WP_Post $post, int $id ): string {
            $stored = (string) get_post_meta( $id, '_cep_job_expires_at', true );
            $ts     = $stored ? strtotime( $stored ) : 0;
            if ( ! $ts ) {
                $ts = strtotime( $post->post_date_gmt ) + 30 * DAY_IN_SECONDS;
            }
            return gmdate( 'c', $ts );
        }

        /**
         * Where applicants of a remote role may live. Google needs this (or a
         * jobLocation) on every TELECOMMUTE posting: use the ISO country when
         * known, a named region (Europe, APAC, ...) when given, else
         * "Remote" (e.g. "Remote", "Worldwide", "Anywhere") since the
         * listing states no restriction.
         */
        private function build_applicant_location( string $loc, string $country ): array {
            if ( $country ) {
                $node = [ '@type' => 'Country', 'name' => $country ];
            } elseif ( preg_match( '/\b(EMEA|APAC|LATAM|EU|Europe|Asia|Americas|North America|South America|Africa|Middle East)\b/i', $loc, $m ) ) {
                $node = [ '@type' => 'AdministrativeArea', 'name' => $m[1] ];
            } else {
                $node = [ '@type' => 'AdministrativeArea', 'name' => 'Remote' ];
            }
            return apply_filters( 'cep_job_applicant_location', $node, $loc, $country );
        }

        /**
         * Build the PostalAddress for jobLocation, or [] when there is none.
         *
         * Remote roles only get an address when the country is known (it then
         * doubles as applicantLocationRequirements). On-site roles with an
         * unrecognised country still get the raw location as addressLocality,
         * because Google requires an address on every jobLocation.
         */
        private function build_address( string $loc, string $country, bool $remote ): array {
            $parts = array_values( array_filter(
                preg_split( '/\s*[,\/|]\s*/', trim( $loc ) ),
                static fn( $p ) => '' !== $p && ! preg_match( '/^(remote|anywhere|worldwide|hybrid)$/i', $p )
            ) );
            $city  = $parts[0] ?? '';
            // Drop a "city" that is only the country name itself ("India").
            if ( '' !== $city && $country && $this->derive_country( $city ) === $country && count( $parts ) === 1 ) {
                $city = '';
            }

            if ( '' === $country && ( $remote || '' === $city ) ) {
                return [];
            }

            $address = [ '@type' => 'PostalAddress' ];
            if ( '' !== $city ) {
                $address['addressLocality'] = $city;
            }
            if ( '' !== $country ) {
                $address['addressCountry'] = $country;
            }
            return $address;
        }

        /**
         * Parse a free-text salary ("$120k - $150k", "₹18,00,000 / yr",
         * "€45/hour") into a MonetaryAmount. Returns [] when no amount can be
         * read, so we never publish a zero or garbled salary.
         */
        private function build_salary( string $salary, string $country = '' ): array {
            $salary = trim( $salary );
            if ( '' === $salary ) {
                return [];
            }

            preg_match_all( '/(\d[\d,]*(?:\.\d+)?)\s*([km])?\b/i', $salary, $m, PREG_SET_ORDER );
            $values = [];
            foreach ( $m as $match ) {
                $num = (float) str_replace( ',', '', $match[1] );
                $mul = strtolower( $match[2] ?? '' );
                if ( 'k' === $mul ) {
                    $num *= 1000;
                } elseif ( 'm' === $mul ) {
                    $num *= 1000000;
                }
                if ( $num > 0 ) {
                    $values[] = $num;
                }
            }
            if ( ! $values ) {
                return [];
            }
            $values = array_slice( $values, 0, 2 );

            // Indian shorthand: "18-25 LPA" / "18 lakh" / "1.2 crore".
            $scale = 1;
            if ( preg_match( '/\bcrores?\b|\bcr\b/i', $salary ) ) {
                $scale = 10000000;
            } elseif ( preg_match( '/\b(lpa|lakhs?|lacs?)\b/i', $salary ) ) {
                $scale = 100000;
            }
            if ( $scale > 1 ) {
                $values = array_map( static fn( $v ) => $v < 1000 ? $v * $scale : $v, $values );
            }

            $currency = $this->detect_currency( $salary, $country );

            $unit = 'YEAR';
            if ( preg_match( '/\b(hour|hr|hourly)\b|\/\s*h\b/i', $salary ) ) {
                $unit = 'HOUR';
            } elseif ( preg_match( '/\b(day|daily)\b/i', $salary ) ) {
                $unit = 'DAY';
            } elseif ( preg_match( '/\b(week|weekly|wk)\b/i', $salary ) ) {
                $unit = 'WEEK';
            } elseif ( preg_match( '/\b(month|monthly|mo)\b/i', $salary ) ) {
                $unit = 'MONTH';
            }

            $value = [ '@type' => 'QuantitativeValue', 'unitText' => $unit ];
            if ( count( $values ) === 2 && $values[0] !== $values[1] ) {
                $value['minValue'] = min( $values );
                $value['maxValue'] = max( $values );
            } else {
                $value['value'] = $values[0];
            }

            return [
                '@type'    => 'MonetaryAmount',
                'currency' => $currency,
                'value'    => $value,
            ];
        }

        private function detect_currency( string $salary, string $country ): string {
            if ( preg_match( '/\b(USD|EUR|GBP|INR|CAD|AUD)\b/i', $salary, $m ) ) {
                return strtoupper( $m[1] );
            }
            $symbols = [ '£' => 'GBP', '€' => 'EUR', '₹' => 'INR', 'C$' => 'CAD', 'CA$' => 'CAD', 'A$' => 'AUD', 'AU$' => 'AUD' ];
            foreach ( $symbols as $sym => $code ) {
                if ( false !== strpos( $salary, $sym ) ) {
                    return $code;
                }
            }
            if ( preg_match( '/\b(lpa|lakh|lakhs|crore)\b/i', $salary ) ) {
                return 'INR';
            }
            $by_country = [ 'GB' => 'GBP', 'IN' => 'INR', 'CA' => 'CAD', 'AU' => 'AUD', 'DE' => 'EUR' ];
            return $by_country[ $country ] ?? 'USD';
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

        /**
         * Map a free-text location onto an ISO 3166-1 alpha-2 country code.
         * Region names (EU, APAC) are not countries, so they return ''.
         */
        private function derive_country( string $loc ): string {
            $map = [
                'united states' => 'US', 'usa' => 'US', 'u\.s\.a?\.?' => 'US', 'us' => 'US',
                'united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB', 'scotland' => 'GB',
                'india' => 'IN', 'canada' => 'CA', 'germany' => 'DE', 'australia' => 'AU',
            ];
            foreach ( $map as $pattern => $code ) {
                // US/UK must be uppercase to avoid matching the English word "us".
                $flags = in_array( $pattern, [ 'us', 'uk' ], true ) ? '' : 'i';
                $re    = in_array( $pattern, [ 'us', 'uk' ], true ) ? strtoupper( $pattern ) : $pattern;
                if ( preg_match( '/(?<![\p{L}])' . $re . '(?![\p{L}])/u' . $flags, $loc ) ) {
                    return $code;
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
