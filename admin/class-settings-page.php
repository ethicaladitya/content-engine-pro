<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page with tabbed UI for all plugin configuration.
 */
class SettingsPage {

	private static array $tabs = [
		'general'  => 'General',
		'content'  => 'Content',
		'display'  => 'Display',
		'ai'       => 'AI & APIs',
		'features' => 'Features',
		'niche'    => 'Niche & Autopilot',
		'advanced' => 'Advanced',
		'seo'      => 'SEO Agent',
	];

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-engine-pro' ) );
		}

		// Save handler
		if ( isset( $_POST['cep_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_settings_nonce'] ) ), 'cep_save_settings' ) ) {
			$this->save_settings();
			add_settings_error( 'cep_messages', 'cep_saved', 'Settings saved.', 'updated' );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		if ( ! array_key_exists( $active_tab, self::$tabs ) ) {
			$active_tab = 'general';
		}

		$all = Settings::all();
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title">
					<span class="dashicons dashicons-admin-settings"></span>
					Content Engine Pro — Settings
				</h1>
				<p class="cep-page-subtitle">Configure every aspect of your content engine from this panel.</p>
			</div>

			<?php settings_errors( 'cep_messages' ); ?>

			<nav class="cep-tabs">
				<?php foreach ( self::$tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings&tab=' . $slug ) ); ?>"
					   class="cep-tab <?php echo $active_tab === $slug ? 'cep-tab--active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="" class="cep-settings-form">
				<?php wp_nonce_field( 'cep_save_settings', 'cep_settings_nonce' ); ?>

				<div class="cep-settings-body">
					<?php $this->render_tab( $active_tab, $all ); ?>
				</div>

				<div class="cep-settings-footer">
					<?php submit_button( 'Save Settings', 'primary large', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	private function render_tab( string $tab, array $s ): void {
		switch ( $tab ) {
			case 'general':
				$this->tab_general( $s );
				break;
			case 'content':
				$this->tab_content( $s );
				break;
			case 'display':
				$this->tab_display( $s );
				break;
			case 'ai':
				$this->tab_ai( $s );
				break;
			case 'features':
				$this->tab_features( $s );
				break;
			case 'niche':
				$this->tab_niche( $s );
				break;
			case 'advanced':
				$this->tab_advanced( $s );
				break;
			case 'seo':
				$this->tab_seo( $s );
				break;
		}
	}

	// ─── Tab: General ────────────────────────────────────────────────────────

	private function tab_general( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">Brand & Identity</h2>
			<p class="cep-section-desc">These settings define your publication's identity.</p>

			<?php
			$this->field_text( 'brand_name', 'Brand Name', $s['brand_name'] ?? '', 'Your publication name, used across the plugin.' );
			$this->field_text( 'brand_tagline', 'Tagline', $s['brand_tagline'] ?? '', 'Short tagline for your publication.' );
			$this->field_text( 'contact_email', 'Contact Email', $s['contact_email'] ?? '', 'Primary contact email address.' );
			$this->field_text( 'site_url', 'Site URL', $s['site_url'] ?? get_site_url(), 'Used in crawl user agent and schema markup.' );
			?>
		</div>
		<?php
	}

	// ─── Tab: Content ────────────────────────────────────────────────────────

	private function tab_content( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">Primary Content</h2>
			<p class="cep-section-desc">
				Configure the main content type. Set <code>primary_cpt_slug</code> to <strong><code>post</code></strong> to use WordPress built-in posts (recommended), or any other slug to create a custom post type.
			</p>
			<?php
			$this->field_text( 'primary_cpt_slug', 'Post Type Slug', $s['primary_cpt_slug'] ?? 'post', 'Use "post" for built-in posts or a custom slug like "article". Changing this requires re-saving permalinks.' );
			$this->field_text( 'primary_cpt_singular', 'Singular Label', $s['primary_cpt_singular'] ?? 'Post', '' );
			$this->field_text( 'primary_cpt_plural', 'Plural Label', $s['primary_cpt_plural'] ?? 'Posts', '' );
			$this->field_text( 'primary_cpt_archive_slug', 'Archive Slug', $s['primary_cpt_archive_slug'] ?? 'news', 'URL prefix for the archive page.' );
			$this->field_text( 'primary_cpt_icon', 'Menu Icon (dashicon)', $s['primary_cpt_icon'] ?? 'dashicons-media-document', 'Only used when creating a custom CPT.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Primary Taxonomy (Categories)</h2>
			<?php
			$this->field_text( 'primary_tax_slug', 'Taxonomy Slug', $s['primary_tax_slug'] ?? 'article-category', 'e.g. article-category. Requires re-saving permalinks after change.' );
			$this->field_text( 'primary_tax_singular', 'Singular Label', $s['primary_tax_singular'] ?? 'Category', '' );
			$this->field_text( 'primary_tax_plural', 'Plural Label', $s['primary_tax_plural'] ?? 'Categories', '' );
			$this->field_text( 'primary_tax_archive_slug', 'Archive Base Slug', $s['primary_tax_archive_slug'] ?? 'news', 'URL prefix for category archives.' );
			$this->field_textarea( 'primary_tax_terms', 'Default Terms', $s['primary_tax_terms'] ?? '', 'Comma-separated list of default terms to auto-create. e.g. general,tech,business,security' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Secondary Taxonomy (Tags)</h2>
			<?php
			$this->field_text( 'secondary_tax_slug', 'Taxonomy Slug', $s['secondary_tax_slug'] ?? 'article-tag', '' );
			$this->field_text( 'secondary_tax_singular', 'Singular Label', $s['secondary_tax_singular'] ?? 'Tag', '' );
			$this->field_text( 'secondary_tax_plural', 'Plural Label', $s['secondary_tax_plural'] ?? 'Tags', '' );
			$this->field_text( 'secondary_tax_archive_slug', 'Archive Base Slug', $s['secondary_tax_archive_slug'] ?? 'topic', 'URL prefix for tag archives.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Reviews Post Type</h2>
			<?php
			$this->field_toggle( 'reviews_cpt_enabled', 'Enable Reviews', $s['reviews_cpt_enabled'] ?? '1', '' );
			$this->field_text( 'reviews_cpt_slug', 'Post Type Slug', $s['reviews_cpt_slug'] ?? 'review', '' );
			$this->field_text( 'reviews_cpt_singular', 'Singular Label', $s['reviews_cpt_singular'] ?? 'Review', '' );
			$this->field_text( 'reviews_cpt_plural', 'Plural Label', $s['reviews_cpt_plural'] ?? 'Reviews', '' );
			$this->field_text( 'reviews_cpt_archive_slug', 'Archive Slug', $s['reviews_cpt_archive_slug'] ?? 'reviews', '' );
			$this->field_text( 'reviews_tax_slug', 'Review Type Taxonomy Slug', $s['reviews_tax_slug'] ?? 'review-type', '' );
			$this->field_text( 'reviews_tax_terms', 'Default Review Types', $s['reviews_tax_terms'] ?? 'plugin,hosting,theme,service,tool', 'Comma-separated.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Jobs Post Type</h2>
			<?php
			$this->field_toggle( 'jobs_cpt_enabled', 'Enable Jobs', $s['jobs_cpt_enabled'] ?? '1', '' );
			$this->field_text( 'jobs_cpt_slug', 'Post Type Slug', $s['jobs_cpt_slug'] ?? 'job', '' );
			$this->field_text( 'jobs_cpt_singular', 'Singular Label', $s['jobs_cpt_singular'] ?? 'Job', '' );
			$this->field_text( 'jobs_cpt_plural', 'Plural Label', $s['jobs_cpt_plural'] ?? 'Jobs', '' );
			$this->field_text( 'jobs_cpt_archive_slug', 'Archive Slug', $s['jobs_cpt_archive_slug'] ?? 'jobs', '' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Affiliate Providers</h2>
			<?php
			$this->field_toggle( 'providers_cpt_enabled', 'Enable Providers CPT', $s['providers_cpt_enabled'] ?? '1', 'Enables the private affiliate providers post type.' );
			$this->field_text( 'providers_cpt_slug', 'Post Type Slug', $s['providers_cpt_slug'] ?? 'provider', '' );
			$this->field_text( 'providers_cpt_singular', 'Singular Label', $s['providers_cpt_singular'] ?? 'Provider', '' );
			$this->field_text( 'providers_cpt_plural', 'Plural Label', $s['providers_cpt_plural'] ?? 'Providers', '' );
			?>
		</div>
		<?php
	}

	// ─── Tab: Display ────────────────────────────────────────────────────────

	private function tab_display( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">Layout & Pagination</h2>
			<?php
			$this->field_number( 'posts_per_page', 'Posts Per Page', $s['posts_per_page'] ?? '12', '1', '100' );
			$this->field_number( 'excerpt_length', 'Excerpt Length (words)', $s['excerpt_length'] ?? '30', '10', '200' );
			$this->field_select( 'card_layout', 'Card Layout', $s['card_layout'] ?? 'grid', [
				'grid'    => 'Grid',
				'list'    => 'List',
				'masonry' => 'Masonry',
			] );
			$this->field_select( 'sidebar_position', 'Sidebar Position', $s['sidebar_position'] ?? 'right', [
				'right' => 'Right',
				'left'  => 'Left',
				'none'  => 'No Sidebar',
			] );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Meta Display</h2>
			<?php
			$this->field_toggle( 'show_author', 'Show Author', $s['show_author'] ?? '1', '' );
			$this->field_toggle( 'show_date', 'Show Date', $s['show_date'] ?? '1', '' );
			$this->field_toggle( 'show_category', 'Show Category', $s['show_category'] ?? '1', '' );
			$this->field_toggle( 'show_read_time', 'Show Read Time', $s['show_read_time'] ?? '1', 'Estimated reading time based on word count.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Image Sizes</h2>
			<p class="cep-section-desc">Registered thumbnail sizes. Requires media regeneration after change.</p>
			<div class="cep-row cep-row--inline">
				<?php
				$this->field_number( 'hero_image_width', 'Hero Width', $s['hero_image_width'] ?? '1200', '400', '3000' );
				$this->field_number( 'hero_image_height', 'Hero Height', $s['hero_image_height'] ?? '675', '200', '2000' );
				?>
			</div>
			<div class="cep-row cep-row--inline">
				<?php
				$this->field_number( 'card_image_width', 'Card Width', $s['card_image_width'] ?? '600', '100', '2000' );
				$this->field_number( 'card_image_height', 'Card Height', $s['card_image_height'] ?? '338', '100', '2000' );
				?>
			</div>
			<div class="cep-row cep-row--inline">
				<?php
				$this->field_number( 'thumb_image_width', 'Thumb Width', $s['thumb_image_width'] ?? '160', '60', '600' );
				$this->field_number( 'thumb_image_height', 'Thumb Height', $s['thumb_image_height'] ?? '120', '60', '600' );
				?>
			</div>
		</div>
		<?php
	}

	// ─── Tab: AI & APIs ──────────────────────────────────────────────────────

	private function tab_ai( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">AI Provider</h2>
			<?php
			$this->field_select( 'ai_provider', 'Provider', $s['ai_provider'] ?? 'openai', [
				'openai' => 'OpenAI',
				'azure'  => 'Azure OpenAI',
			] );
			$this->field_text( 'ai_model', 'Default Model', $s['ai_model'] ?? 'gpt-4o', 'e.g. gpt-4o, gpt-4-turbo, gpt-3.5-turbo' );
			$this->field_text( 'ai_model_mini', 'Lightweight Model', $s['ai_model_mini'] ?? 'gpt-4o-mini', 'Used for lower-priority tasks to reduce cost.' );
			$this->field_number( 'ai_temperature', 'Temperature', $s['ai_temperature'] ?? '0.7', '0', '2', '0.1' );
			$this->field_number( 'ai_timeout', 'Request Timeout (s)', $s['ai_timeout'] ?? '90', '10', '300' );
			$this->field_number( 'ai_retries', 'Max Retries', $s['ai_retries'] ?? '3', '0', '10' );
			?>
		</div>

		<div class="cep-section" id="cep-openai-section">
			<h2 class="cep-section-title">OpenAI Settings</h2>
			<?php
			$this->field_password( 'openai_key', 'API Key', $s['openai_key'] ?? '', 'Stored encrypted. You may also define CEP_OPENAI_KEY in wp-config.php.' );
			?>
		</div>

		<div class="cep-section" id="cep-azure-section">
			<h2 class="cep-section-title">Azure OpenAI Settings</h2>
			<?php
			$this->field_password( 'azure_key', 'Azure API Key', $s['azure_key'] ?? '', '' );
			$this->field_text( 'azure_endpoint', 'Azure Endpoint', $s['azure_endpoint'] ?? '', 'e.g. https://myresource.openai.azure.com/' );
			$this->field_text( 'azure_api_version', 'API Version', $s['azure_api_version'] ?? '2024-08-01-preview', '' );
			$this->field_text( 'azure_deployment', 'Primary Deployment', $s['azure_deployment'] ?? '', 'Deployment name for primary model.' );
			$this->field_text( 'azure_deployment_mini', 'Lightweight Deployment', $s['azure_deployment_mini'] ?? '', 'Deployment name for mini model.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Other Integrations</h2>
			<?php
			$this->field_password( 'pexels_key', 'Pexels API Key', $s['pexels_key'] ?? '', 'Used for auto-fetching featured images.' );
			$this->field_textarea( 'ai_system_prompt', 'Custom AI System Prompt', $s['ai_system_prompt'] ?? '', 'Override the default system prompt for content generation. Leave blank to use built-in prompt.' );
			?>
		</div>
		<?php
	}

	// ─── Tab: Features ───────────────────────────────────────────────────────

	private function tab_features( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">Content Automation</h2>
			<?php
			$this->field_toggle( 'enable_crawling', 'Enable Content Crawling', $s['enable_crawling'] ?? '1', 'Schedules cron jobs to crawl configured sources.' );
			$this->field_toggle( 'enable_ai_publishing', 'Enable AI Publishing', $s['enable_ai_publishing'] ?? '1', 'Automatically publish content generated by AI.' );
			$this->field_toggle( 'enable_reviews', 'Enable Reviews Module', $s['enable_reviews'] ?? '1', 'Registers review CPT, shortcodes, and review generation.' );
			$this->field_toggle( 'enable_reviews_autopilot', 'Enable Reviews Autopilot', $s['enable_reviews_autopilot'] ?? '1', 'Auto-generates and publishes product reviews on a schedule.' );
			$this->field_toggle( 'enable_jobs', 'Enable Jobs Module', $s['enable_jobs'] ?? '1', 'Aggregates and publishes job listings.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Monetization</h2>
			<?php
			$this->field_toggle( 'enable_affiliates', 'Enable Affiliates', $s['enable_affiliates'] ?? '1', 'Enables the affiliate provider system.' );
			$this->field_toggle( 'enable_affiliate_links', 'Enable Affiliate Link Insertion', $s['enable_affiliate_links'] ?? '1', 'Automatically inserts contextual affiliate links into post content.' );
			$this->field_toggle( 'enable_click_tracking', 'Enable Click Tracking', $s['enable_click_tracking'] ?? '1', 'Tracks clicks through affiliate redirect URLs.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Content Enhancement</h2>
			<?php
			$this->field_toggle( 'enable_schema_markup', 'Enable Schema Markup', $s['enable_schema_markup'] ?? '1', 'Injects JSON-LD schema for articles, reviews, etc.' );
			$this->field_toggle( 'enable_internal_linking', 'Enable Internal Linking', $s['enable_internal_linking'] ?? '1', 'Inserts related articles block before content end.' );
			$this->field_toggle( 'enable_source_stripping', 'Strip Sources Section', $s['enable_source_stripping'] ?? '1', 'Removes the Sources section from frontend display (kept in database).' );
			$this->field_toggle( 'enable_seo_meta', 'Enable SEO Meta Tags', $s['enable_seo_meta'] ?? '1', 'Outputs meta description, OpenGraph and Twitter Card tags.' );
			$this->field_toggle( 'enable_image_fetching', 'Enable Auto Image Fetching', $s['enable_image_fetching'] ?? '1', 'Auto-fetches featured images from Pexels or Open Graph.' );
			$this->field_toggle( 'enable_rest_api', 'Enable REST API', $s['enable_rest_api'] ?? '1', 'Registers /wp-json/cep/v1/ endpoints.' );
			?>
		</div>
		<?php
	}

	// ─── Tab: Advanced ───────────────────────────────────────────────────────

	private function tab_advanced( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">Signal Scoring</h2>
			<?php
			$this->field_number( 'signal_threshold', 'Default Signal Threshold', $s['signal_threshold'] ?? '50', '0', '100' );
			$this->field_number( 'signal_threshold_security', 'Security Signal Threshold', $s['signal_threshold_security'] ?? '40', '0', '100', '1', 'Lower threshold for security-related content.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Publishing Defaults</h2>
			<?php
			$this->field_toggle( 'auto_publish', 'Auto-Publish Generated Content', $s['auto_publish'] ?? '1', '' );
			$this->field_number( 'default_author_id', 'Default Author ID', $s['default_author_id'] ?? '1', '1', '9999' );
			$this->field_number( 'max_concurrent_jobs', 'Max Concurrent Crawl Jobs', $s['max_concurrent_jobs'] ?? '3', '1', '20' );
			$this->field_text( 'crawl_user_agent', 'Crawl User Agent', $s['crawl_user_agent'] ?? 'Content Engine Pro/1.0', '' );
			$this->field_text( 'wp_path', 'WordPress Path', $s['wp_path'] ?? ABSPATH, 'Absolute server path to WordPress root.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Reviews Engine</h2>
			<?php
			$this->field_number( 'reviews_max_per_run', 'Max Reviews Per Cron Run', $s['reviews_max_per_run'] ?? '3', '1', '50' );
			$this->field_number( 'reviews_ai_temperature', 'Review AI Temperature', $s['reviews_ai_temperature'] ?? '0.7', '0', '2', '0.1' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Affiliate Settings</h2>
			<?php
			$this->field_text( 'affiliate_redirect_base', 'Redirect URL Base', $s['affiliate_redirect_base'] ?? 'go', 'Affiliate links resolve to /{base}/{slug}/. e.g. "go" → /go/rocket/' );
			$this->field_textarea( 'affiliate_disclosure_text', 'Disclosure Text', $s['affiliate_disclosure_text'] ?? '', 'Shown above comparison tables.' );
			$this->field_password( 'affiliate_encrypt_key', 'Affiliate URL Encryption Key', $s['affiliate_encrypt_key'] ?? '', '32-character key for AES-256-CBC encryption. You may also define CEP_AFFILIATE_ENCRYPT_KEY in wp-config.php.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Logs & Maintenance</h2>
			<?php
			$this->field_number( 'log_retention_days', 'Log Retention (days)', $s['log_retention_days'] ?? '90', '7', '365' );
			?>
			<div class="cep-row">
				<div class="cep-field">
					<label class="cep-label">Flush Rewrite Rules</label>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings&tab=advanced&cep_flush=1&_wpnonce=' . wp_create_nonce( 'cep_flush' ) ) ); ?>" class="button button-secondary">Flush Now</a>
					<p class="cep-desc">Run this after changing any slug or post type settings.</p>
				</div>
			</div>
		</div>
		<?php

		// Handle flush action
		if ( isset( $_GET['cep_flush'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cep_flush' ) ) {
			flush_rewrite_rules();
			echo '<div class="notice notice-success is-dismissible"><p>Rewrite rules flushed.</p></div>';
		}
	}

	// ─── Tab: Niche & Autopilot ──────────────────────────────────────────────

	private function tab_niche( array $s ): void {
		$niche_options = [
			'wordpress'  => 'WordPress',
			'ecommerce'  => 'eCommerce',
			'saas'       => 'SaaS / Software',
			'marketing'  => 'Digital Marketing',
			'lifestyle'  => 'Lifestyle & Entertainment',
			'custom'     => 'Custom (configure below)',
		];
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">Niche / Vertical</h2>
			<p class="cep-section-desc">
				Select your site's niche. This configures which RSS feeds, product types, and keywords the autopilot uses.
				Use "Custom" and fill in the override fields below for any niche not listed.
			</p>
			<?php
			$this->field_select( 'niche_vertical', 'Niche Preset', $s['niche_vertical'] ?? 'wordpress', $niche_options, 'Selecting a preset pre-fills sources and keywords. Your override fields below take priority.' );
			$this->field_text( 'niche_name', 'Niche Name Override', $s['niche_name'] ?? '', 'Optional. Overrides the niche label used in AI prompts. Leave blank to use the preset name.' );
			$this->field_textarea( 'niche_keywords', 'Keywords Override', $s['niche_keywords'] ?? '', 'Comma-separated keywords for signal scoring and AI context. Leave blank to use preset keywords.' );
			$this->field_textarea( 'niche_review_types', 'Review Types Override', $s['niche_review_types'] ?? '', 'Comma-separated review types (e.g. plugin,hosting,theme). Leave blank to use preset.' );
			$this->field_textarea( 'niche_article_categories', 'Article Categories Override', $s['niche_article_categories'] ?? '', 'Comma-separated article categories. Leave blank to use preset.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Content Sources</h2>
			<p class="cep-section-desc">Add extra RSS feed URLs for each pipeline. One URL per line. Preset sources are always included.</p>
			<?php
			$this->field_textarea( 'article_sources', 'Extra Article Feed URLs', $s['article_sources'] ?? '', 'Additional RSS/Atom feeds for article content discovery. One URL per line.' );
			$this->field_textarea( 'review_discovery_sources', 'Extra Review Product Feed URLs', $s['review_discovery_sources'] ?? '', 'Additional RSS/Atom feeds for product discovery (plugins, tools, services). One URL per line.' );
			$this->field_textarea( 'job_sources', 'Extra Job Feed URLs', $s['job_sources'] ?? '', 'Additional job RSS feeds. One URL per line. (e.g. remoteok, wphired, jobs.wordpress.net)' );
			$this->field_textarea( 'niche_search_queries', 'Extra Search Queries', $s['niche_search_queries'] ?? '', 'Additional search queries for content discovery. One per line.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Article Autopilot</h2>
			<p class="cep-section-desc">Automatically researches and publishes articles from your content queue.</p>
			<?php
			$this->field_toggle( 'article_autopilot_enabled', 'Enable Article Autopilot', $s['article_autopilot_enabled'] ?? '1', 'Runs daily, picks highest-scored pending content items and publishes them.' );
			$this->field_number( 'article_max_per_run', 'Max Articles per Run', $s['article_max_per_run'] ?? '3', '1', '20', '1', 'Maximum articles to generate per cron run.' );
			$this->field_number( 'article_research_depth', 'Research Depth (URLs)', $s['article_research_depth'] ?? '3', '1', '10', '1', 'Number of URLs to research per article (source + related same-domain pages).' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Review Autopilot</h2>
			<p class="cep-section-desc">Discovers products from RSS feeds and generates AI-powered reviews.</p>
			<?php
			$this->field_toggle( 'review_autopilot_enabled', 'Enable Review Autopilot', $s['review_autopilot_enabled'] ?? '1', 'Runs discovery daily and generation daily. Requires Reviews CPT to be enabled.' );
			$this->field_number( 'review_discovery_max_per_run', 'Max Products to Discover per Run', $s['review_discovery_max_per_run'] ?? '20', '1', '100', '1', 'Number of new products to queue per discovery run.' );
			$this->field_number( 'review_max_per_run', 'Max Reviews to Generate per Run', $s['review_max_per_run'] ?? '3', '1', '20', '1', 'Number of reviews to write per generation run.' );
			$this->field_number( 'review_research_depth', 'Review Research Depth (URLs)', $s['review_research_depth'] ?? '2', '1', '5', '1', 'URLs to research per product before writing the review.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Jobs Autopilot</h2>
			<p class="cep-section-desc">Pulls job listings from configured RSS feeds, deduplicates, and publishes them.</p>
			<?php
			$this->field_toggle( 'jobs_autopilot_enabled', 'Enable Jobs Autopilot', $s['jobs_autopilot_enabled'] ?? '1', 'Runs twice daily. Requires Jobs CPT to be enabled.' );
			$this->field_number( 'jobs_max_per_run', 'Max Jobs per Run', $s['jobs_max_per_run'] ?? '10', '1', '50', '1', 'Maximum job listings to process per cron run.' );
			$this->field_number( 'jobs_dedup_days', 'Deduplication Window (days)', $s['jobs_dedup_days'] ?? '30', '1', '365', '1', 'Jobs with the same hash within this window are skipped as duplicates.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Web Research</h2>
			<p class="cep-section-desc">Settings for the web research engine used by all autopilot pipelines.</p>
			<?php
			$this->field_number( 'research_timeout', 'HTTP Request Timeout (seconds)', $s['research_timeout'] ?? '20', '5', '60', '1', 'Timeout for fetching external URLs during research.' );
			$this->field_number( 'research_max_text_chars', 'Max Text per Page (chars)', $s['research_max_text_chars'] ?? '8000', '1000', '30000', '1000', 'Maximum characters extracted from each page during research. Higher = more context but slower AI calls.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">&#x1F525; Trending Topics Autopilot</h2>
			<p class="cep-section-desc">
				Sourceless autopilot mode &mdash; discovers trending topics from public data sources, validates search demand, and queues them for article generation. No RSS feeds required.
			</p>
			<?php
			$this->field_toggle( 'trending_autopilot_enabled', 'Enable Trending Topics Autopilot', $s['trending_autopilot_enabled'] ?? '0', 'When enabled, runs twice daily to discover trending topics and queue them for the Article Autopilot.' );

			$active_sources = array_filter( array_map( 'trim', explode( ',', $s['trending_sources'] ?? 'google_trends,google_news' ) ) );
			$source_map     = [
				'google_trends' => 'Google Trends Daily (free, no API key)',
				'google_news'   => 'Google News RSS (free, uses niche keywords)',
				'reddit'        => 'Reddit Hot Posts (free, configure subreddits below)',
			];
			?>
			<div class="cep-row">
				<div class="cep-field">
					<label class="cep-label"><?php esc_html_e( 'Data Sources', 'content-engine-pro' ); ?></label>
					<div style="display:flex;flex-direction:column;gap:6px;margin-top:4px">
						<?php foreach ( $source_map as $val => $label ) : ?>
						<label style="display:flex;align-items:center;gap:8px;font-weight:normal">
							<input type="checkbox"
								   name="cep[trending_source_<?php echo esc_attr( $val ); ?>]"
								   value="1"
								   <?php checked( in_array( $val, $active_sources, true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
						<?php endforeach; ?>
					</div>
					<p class="cep-desc"><?php esc_html_e( 'Google Trends and Google News require no API keys.', 'content-engine-pro' ); ?></p>
				</div>
			</div>
			<?php
			$this->field_text( 'trending_region', 'Region (ISO code)', $s['trending_region'] ?? 'US', 'Used for Google Trends. Examples: US, GB, AU, CA, IN.' );
			$this->field_text( 'trending_subreddits', 'Reddit Subreddits', $s['trending_subreddits'] ?? '', 'Comma-separated subreddit names without r/. Example: relationships,dating,selfimprovement. Leave blank to skip Reddit.' );
			$this->field_number( 'trending_max_per_run', 'Max Topics per Run', $s['trending_max_per_run'] ?? '5', '1', '20', '1', 'Maximum number of trending topics queued per cron run.' );
			$this->field_number( 'trending_min_demand_score', 'Minimum Demand Score (0-100)', $s['trending_min_demand_score'] ?? '50', '0', '100', '5', 'Topics below this score are discarded. Score is based on Google Autocomplete + niche relevance bonus.' );
			?>
		</div>
		<?php
	}

	// ─── Tab: SEO Agent ─────────────────────────────────────────────────────

	private function tab_seo( array $s ): void {
		?>
		<div class="cep-section">
			<h2 class="cep-section-title">SEO Agent</h2>
			<p class="cep-section-desc">
				Automatically scans all published posts for SEO issues and applies rule-based fixes.
				Issues that cannot be fixed automatically can be resolved with AI from the
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-seo-agent' ) ); ?>">SEO Agent page</a>.
			</p>
			<?php
			$this->field_toggle( 'enable_seo_agent', 'Enable SEO Agent', $s['enable_seo_agent'] ?? '1', 'Run scheduled SEO analysis every few days across all published posts.' );
			$this->field_toggle( 'seo_auto_fix_enabled', 'Auto-fix After Scan', $s['seo_auto_fix_enabled'] ?? '1', 'Automatically apply rule-based fixes (alt text, slugs, meta descriptions, etc.) immediately after each scan.' );
			$this->field_number( 'seo_agent_interval', 'Scan Interval (days)', $s['seo_agent_interval'] ?? '3', '1', '30', '1', 'How many days between full SEO scans. Default: 3.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Content Thresholds</h2>
			<?php
			$this->field_number( 'seo_min_word_count', 'Minimum Word Count', $s['seo_min_word_count'] ?? '300', '50', '2000', '50', 'Posts below this word count are flagged as thin content.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Title Checks</h2>
			<?php
			$this->field_number( 'seo_title_min_length', 'Minimum Title Length (chars)', $s['seo_title_min_length'] ?? '30', '10', '60', '1', 'SEO titles shorter than this are flagged.' );
			$this->field_number( 'seo_title_max_length', 'Maximum Title Length (chars)', $s['seo_title_max_length'] ?? '60', '40', '100', '1', 'SEO titles longer than this are flagged and auto-truncated.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">Meta Description Checks</h2>
			<?php
			$this->field_number( 'seo_meta_desc_min_length', 'Minimum Meta Description Length (chars)', $s['seo_meta_desc_min_length'] ?? '100', '50', '160', '1', 'Meta descriptions shorter than this are flagged.' );
			$this->field_number( 'seo_meta_desc_max_length', 'Maximum Meta Description Length (chars)', $s['seo_meta_desc_max_length'] ?? '160', '100', '320', '1', 'Meta descriptions longer than this are flagged and auto-truncated.' );
			?>
		</div>

		<div class="cep-section">
			<h2 class="cep-section-title">URL Slug Checks</h2>
			<?php
			$this->field_number( 'seo_slug_max_length', 'Maximum Slug Length (chars)', $s['seo_slug_max_length'] ?? '75', '20', '200', '1', 'Post slugs longer than this are flagged and auto-shortened.' );
			?>
		</div>
		<?php
	}

	// ─── Save ────────────────────────────────────────────────────────────────

	private function save_settings(): void {
		$raw     = wp_unslash( $_POST['cep'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$current = Settings::all();
		$clean   = [];

		/*
		 * Boolean/toggle fields — GROUPED BY TAB.
		 *
		 * Because the settings form is tabbed, only the currently active tab's
		 * fields are submitted with the form.  Unchecked checkboxes on OTHER tabs
		 * are absent from $_POST, so we must NOT treat "missing" as "unchecked"
		 * for those tabs — otherwise every save resets every other tab's toggles.
		 *
		 * Strategy: detect which tab was saved (via $_GET['tab'] — the form
		 * POSTs back to the same URL), then only apply "unchecked = 0" logic
		 * for toggles that belong to the active tab.  Toggles on other tabs
		 * fall back to the value already stored in the DB.
		 */
		$tab_toggles = [
			'general'  => [],
			'content'  => [ 'reviews_cpt_enabled', 'providers_cpt_enabled', 'jobs_cpt_enabled' ],
			'display'  => [ 'show_author', 'show_date', 'show_category', 'show_read_time' ],
			'ai'       => [],
			'features' => [
				'enable_crawling', 'enable_ai_publishing', 'enable_reviews',
				'enable_reviews_autopilot', 'enable_affiliates', 'enable_affiliate_links',
				'enable_click_tracking', 'enable_jobs', 'enable_schema_markup',
				'enable_internal_linking', 'enable_source_stripping', 'enable_seo_meta',
				'enable_image_fetching', 'enable_rest_api',
			],
			'niche'    => [
				'article_autopilot_enabled', 'review_autopilot_enabled',
				'jobs_autopilot_enabled', 'trending_autopilot_enabled',
			],
			'advanced' => [ 'auto_publish' ],
			'seo'      => [ 'enable_seo_agent', 'seo_auto_fix_enabled' ],
		];

		$active_tab        = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$active_toggles    = $tab_toggles[ $active_tab ] ?? [];
		$all_toggle_keys   = array_merge( ...array_values( $tab_toggles ) );

		foreach ( $all_toggle_keys as $key ) {
			if ( in_array( $key, $active_toggles, true ) ) {
				// Field belongs to the active tab — "not submitted" means unchecked.
				$clean[ $key ] = isset( $raw[ $key ] ) && '1' === $raw[ $key ] ? '1' : '0';
			} else {
				// Field is on a different tab — preserve the current stored value.
				$clean[ $key ] = $current[ $key ] ?? '0';
			}
		}

		// Text fields
		$text_fields = [
			'brand_name','brand_tagline','contact_email','site_url',
			'primary_cpt_slug','primary_cpt_singular','primary_cpt_plural','primary_cpt_archive_slug','primary_cpt_icon',
			'primary_tax_slug','primary_tax_singular','primary_tax_plural','primary_tax_archive_slug',
			'secondary_tax_slug','secondary_tax_singular','secondary_tax_plural','secondary_tax_archive_slug',
			'reviews_cpt_slug','reviews_cpt_singular','reviews_cpt_plural','reviews_cpt_archive_slug',
			'reviews_tax_slug','reviews_tax_terms',
			'jobs_cpt_slug','jobs_cpt_singular','jobs_cpt_plural','jobs_cpt_archive_slug',
			'providers_cpt_slug','providers_cpt_singular','providers_cpt_plural',
			'ai_provider','ai_model','ai_model_mini','card_layout','sidebar_position',
			'crawl_user_agent','affiliate_redirect_base','compare_default_title',
			'niche_vertical','niche_name',
			'trending_region','trending_subreddits',
		];

		foreach ( $text_fields as $key ) {
			$clean[ $key ] = sanitize_text_field( $raw[ $key ] ?? $current[ $key ] ?? '' );
		}

		// Slugs (extra sanitization)
		$slug_fields = [
			'primary_cpt_slug','primary_cpt_archive_slug','primary_tax_slug','primary_tax_archive_slug',
			'secondary_tax_slug','secondary_tax_archive_slug',
			'reviews_cpt_slug','reviews_cpt_archive_slug','reviews_tax_slug',
			'jobs_cpt_slug','jobs_cpt_archive_slug','providers_cpt_slug','affiliate_redirect_base',
		];
		foreach ( $slug_fields as $key ) {
			if ( isset( $clean[ $key ] ) && ! empty( $clean[ $key ] ) && $clean[ $key ] !== 'post' ) {
				$clean[ $key ] = sanitize_title( $clean[ $key ] );
			}
		}

		// Textarea fields
		$textarea_fields = [
			'primary_tax_terms','ai_system_prompt','affiliate_disclosure_text',
			'niche_keywords','niche_review_types','niche_article_categories','niche_search_queries',
			'review_discovery_sources','job_sources','article_sources',
		];
		foreach ( $textarea_fields as $key ) {
			$clean[ $key ] = sanitize_textarea_field( $raw[ $key ] ?? $current[ $key ] ?? '' );
		}

		// Number fields
		$number_fields = [
			'posts_per_page','excerpt_length','hero_image_width','hero_image_height',
			'card_image_width','card_image_height','thumb_image_width','thumb_image_height',
			'ai_timeout','ai_retries','signal_threshold','signal_threshold_security',
			'max_concurrent_jobs','default_author_id','log_retention_days',
			'reviews_max_per_run',
			'article_max_per_run','article_research_depth',
			'review_discovery_max_per_run','review_max_per_run','review_research_depth',
			'jobs_max_per_run','jobs_dedup_days',
			'research_timeout','research_max_text_chars',
			'trending_max_per_run','trending_min_demand_score',
			'seo_agent_interval','seo_min_word_count',
			'seo_title_min_length','seo_title_max_length',
			'seo_meta_desc_min_length','seo_meta_desc_max_length',
			'seo_slug_max_length',
		];
		foreach ( $number_fields as $key ) {
			$clean[ $key ] = (string) absint( $raw[ $key ] ?? $current[ $key ] ?? 0 );
		}

		// Trending sources — reconstruct from individual checkboxes
		$trending_source_parts = [];
		foreach ( [ 'google_trends', 'google_news', 'reddit' ] as $src ) {
			if ( ! empty( $raw[ 'trending_source_' . $src ] ) ) {
				$trending_source_parts[] = $src;
			}
		}
		$clean['trending_sources'] = implode( ',', $trending_source_parts ) ?: ( $current['trending_sources'] ?? 'google_trends,google_news' );

		// Float fields
		foreach ( [ 'ai_temperature','reviews_ai_temperature' ] as $key ) {
			$clean[ $key ] = (string) max( 0, min( 2, (float) ( $raw[ $key ] ?? $current[ $key ] ?? 0.7 ) ) );
		}

		// Sensitive fields (only update if non-empty submitted value)
		$sensitive = [ 'openai_key','azure_key','pexels_key','affiliate_encrypt_key' ];
		foreach ( $sensitive as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $raw[ $key ] );
			} else {
				$clean[ $key ] = $current[ $key ] ?? '';
			}
		}

		// Azure text fields
		foreach ( [ 'azure_endpoint','azure_api_version','azure_deployment','azure_deployment_mini' ] as $key ) {
			$clean[ $key ] = esc_url_raw( $raw[ $key ] ?? $current[ $key ] ?? '' );
		}
		foreach ( [ 'azure_api_version','azure_deployment','azure_deployment_mini' ] as $key ) {
			$clean[ $key ] = sanitize_text_field( $raw[ $key ] ?? $current[ $key ] ?? '' );
		}

		// WordPress path
		$clean['wp_path'] = sanitize_text_field( $raw['wp_path'] ?? $current['wp_path'] ?? ABSPATH );

		Settings::save( $clean );
	}

	// ─── Field Renderers ─────────────────────────────────────────────────────

	private function field_text( string $key, string $label, string $value, string $desc = '' ): void {
		?>
		<div class="cep-row">
			<div class="cep-field">
				<label class="cep-label" for="cep_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="text" id="cep_<?php echo esc_attr( $key ); ?>" name="cep[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="cep-input regular-text" />
				<?php if ( $desc ) : ?><p class="cep-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function field_password( string $key, string $label, string $value, string $desc = '' ): void {
		?>
		<div class="cep-row">
			<div class="cep-field">
				<label class="cep-label" for="cep_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<div class="cep-password-wrap">
					<input type="password" id="cep_<?php echo esc_attr( $key ); ?>" name="cep[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="cep-input regular-text" autocomplete="off" />
					<button type="button" class="button cep-toggle-pw" data-target="cep_<?php echo esc_attr( $key ); ?>">Show</button>
				</div>
				<?php if ( $desc ) : ?><p class="cep-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function field_textarea( string $key, string $label, string $value, string $desc = '' ): void {
		?>
		<div class="cep-row">
			<div class="cep-field">
				<label class="cep-label" for="cep_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<textarea id="cep_<?php echo esc_attr( $key ); ?>" name="cep[<?php echo esc_attr( $key ); ?>]" rows="3" class="cep-input large-text"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( $desc ) : ?><p class="cep-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function field_number( string $key, string $label, string $value, string $min = '0', string $max = '9999', string $step = '1', string $desc = '' ): void {
		?>
		<div class="cep-row">
			<div class="cep-field">
				<label class="cep-label" for="cep_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="number" id="cep_<?php echo esc_attr( $key ); ?>" name="cep[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" class="cep-input small-text" />
				<?php if ( $desc ) : ?><p class="cep-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function field_select( string $key, string $label, string $value, array $options, string $desc = '' ): void {
		?>
		<div class="cep-row">
			<div class="cep-field">
				<label class="cep-label" for="cep_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<select id="cep_<?php echo esc_attr( $key ); ?>" name="cep[<?php echo esc_attr( $key ); ?>]" class="cep-input cep-select">
					<?php foreach ( $options as $opt_val => $opt_label ) : ?>
						<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $desc ) : ?><p class="cep-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function field_toggle( string $key, string $label, string $value, string $desc = '' ): void {
		?>
		<div class="cep-row">
			<div class="cep-field cep-field--toggle">
				<label class="cep-toggle">
					<input type="checkbox" name="cep[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( '1', $value ); ?> class="cep-toggle__input" />
					<span class="cep-toggle__switch"></span>
					<span class="cep-toggle__label"><?php echo esc_html( $label ); ?></span>
				</label>
				<?php if ( $desc ) : ?><p class="cep-desc"><?php echo wp_kses_post( $desc ); ?></p><?php endif; ?>
			</div>
		</div>
		<?php
	}
}
