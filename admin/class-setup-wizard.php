<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\NicheManager;
use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-Run Setup Wizard
 *
 * A 4-step admin wizard that configures a fresh site in under 2 minutes:
 *   Step 1 — Brand Identity  (name, tagline, contact email)
 *   Step 2 — Pick Your Niche (visual card picker, 12 presets)
 *   Step 3 — AI & API Keys  (OpenAI key, Pexels key)
 *   Step 4 — Theme Palette  (6 colour swatches)
 *   Done  — Summary + quick-start buttons
 *
 * The wizard can be re-launched any time via:
 *   WP Admin → Content Engine → Setup Wizard
 */
class SetupWizard {

	private const SLUG  = 'cep-setup-wizard';
	private const STEPS = 4;

	// ─────────────────────────────────────────────────────────────────────────
	// Registration
	// ─────────────────────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'admin_menu',             [ $this, 'add_page' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_cep_wizard',  [ $this, 'handle_save' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'content-engine-pro',
			__( 'Setup Wizard', 'content-engine-pro' ),
			__( '🚀 Setup Wizard', 'content-engine-pro' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'cep-setup-wizard',
			\CEP_URL . 'admin/assets/css/setup-wizard.css',
			[],
			\CEP_VERSION
		);
		wp_enqueue_script(
			'cep-setup-wizard',
			\CEP_URL . 'admin/assets/js/setup-wizard.js',
			[],
			\CEP_VERSION,
			true
		);
		// Pass niche data to JS for palette preview
		wp_localize_script( 'cep-setup-wizard', 'cepWizard', [
			'niches'       => $this->get_niches_for_js(),
			'currentNiche' => Settings::get( 'niche_vertical', 'wordpress' ),
		] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Form handler
	// ─────────────────────────────────────────────────────────────────────────

	public function handle_save(): void {
		check_admin_referer( 'cep_wizard_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'content-engine-pro' ) );
		}

		$step = (int) ( $_POST['cep_wizard_step'] ?? 1 );
		$settings = get_option( 'cep_settings', [] );

		switch ( $step ) {
			case 1:
				$settings['brand_name']    = sanitize_text_field( $_POST['brand_name'] ?? '' );
				$settings['brand_tagline'] = sanitize_text_field( $_POST['brand_tagline'] ?? '' );
				$settings['contact_email'] = sanitize_email( $_POST['contact_email'] ?? '' );
				break;

			case 2:
				$vertical = sanitize_key( $_POST['niche_vertical'] ?? 'custom' );
				$presets  = NicheManager::get_presets();
				if ( ! isset( $presets[ $vertical ] ) ) {
					$vertical = 'custom';
				}
				$settings['niche_vertical'] = $vertical;

				// Apply preset defaults (only if user hasn't customised them yet)
				$preset = $presets[ $vertical ];
				if ( empty( $settings['niche_keywords'] ) ) {
					$settings['niche_keywords'] = $preset['keywords'];
				}
				if ( empty( $settings['niche_review_types'] ) ) {
					$settings['niche_review_types'] = $preset['review_types'];
				}
				if ( empty( $settings['niche_article_categories'] ) ) {
					$settings['niche_article_categories'] = $preset['article_categories'] ?? '';
				}
				// Pre-fill article & review sources (overwrite to match the chosen niche)
				$settings['article_sources']          = implode( "\n", $preset['article_sources'] ?? [] );
				$settings['review_discovery_sources'] = implode( "\n", $preset['review_sources'] ?? [] );
				$settings['job_sources']              = implode( "\n", $preset['job_sources'] ?? [] );
				break;

			case 3:
				if ( ! empty( $_POST['openai_key'] ) ) {
					$settings['openai_key'] = sanitize_text_field( $_POST['openai_key'] );
				}
				if ( ! empty( $_POST['pexels_key'] ) ) {
					$settings['pexels_key'] = sanitize_text_field( $_POST['pexels_key'] );
				}
				$settings['ai_provider'] = sanitize_key( $_POST['ai_provider'] ?? 'openai' );
				break;

			case 4:
				$allowed_palettes = [ 'pink', 'purple', 'emerald', 'crimson', 'midnight', 'gold' ];
				$palette = sanitize_key( $_POST['theme_palette'] ?? 'pink' );
				if ( ! in_array( $palette, $allowed_palettes, true ) ) {
					$palette = 'pink';
				}
				// Store in both the plugin settings and the theme option
				$settings['theme_palette'] = $palette;
				$theme_opts = get_option( 'baetalk_options', [] );
				$theme_opts['palette'] = $palette;
				update_option( 'baetalk_options', $theme_opts );
				break;
		}

		update_option( 'cep_settings', $settings );
		Settings::clear_cache();

		// Mark wizard as complete on the final step
		if ( $step >= self::STEPS ) {
			update_option( 'cep_wizard_complete', true );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&step=done' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&step=' . ( $step + 1 ) ) );
		}
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Render
	// ─────────────────────────────────────────────────────────────────────────

	public function render(): void {
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = 'done' === $step ? 'done' : (int) $step;
		$step = ( 'done' !== $step && ( $step < 1 || $step > self::STEPS ) ) ? 1 : $step;
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Content Engine Pro — Setup Wizard', 'content-engine-pro' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body class="cep-wizard-body">

		<div class="cep-wizard">

			<!-- Header -->
			<div class="cep-wizard__header">
				<div class="cep-wizard__logo">⚡ Content Engine Pro</div>
				<p class="cep-wizard__header-sub">
					<?php esc_html_e( 'New site setup — takes about 2 minutes', 'content-engine-pro' ); ?>
				</p>
			</div>

			<!-- Progress bar -->
			<?php if ( 'done' !== $step ) : ?>
			<div class="cep-wizard__progress">
				<?php for ( $i = 1; $i <= self::STEPS; $i++ ) :
					$state = $i < $step ? 'done' : ( $i === $step ? 'active' : 'pending' );
				?>
				<div class="cep-wizard__step cep-wizard__step--<?php echo esc_attr( $state ); ?>">
					<div class="cep-wizard__step-dot">
						<?php echo 'done' === $state ? '✓' : esc_html( $i ); ?>
					</div>
					<span class="cep-wizard__step-label">
						<?php echo esc_html( $this->step_label( $i ) ); ?>
					</span>
				</div>
				<?php if ( $i < self::STEPS ) : ?>
				<div class="cep-wizard__step-line cep-wizard__step-line--<?php echo $i < $step ? 'done' : 'pending'; ?>"></div>
				<?php endif; ?>
				<?php endfor; ?>
			</div>
			<?php endif; ?>

			<!-- Card -->
			<div class="cep-wizard__card">
				<?php
				if ( 'done' === $step ) {
					$this->render_done();
				} else {
					$method = 'render_step_' . $step;
					if ( method_exists( $this, $method ) ) {
						$this->$method();
					}
				}
				?>
			</div><!-- /.cep-wizard__card -->

		</div><!-- /.cep-wizard -->

		<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Step renderers
	// ─────────────────────────────────────────────────────────────────────────

	private function render_step_1(): void {
		$s = get_option( 'cep_settings', [] );
		?>
		<h2 class="cep-wizard__card-title">
			<span class="cep-wizard__card-icon">🏷️</span>
			<?php esc_html_e( 'Brand Identity', 'content-engine-pro' ); ?>
		</h2>
		<p class="cep-wizard__card-sub">
			<?php esc_html_e( "What's your site called? This shows up in posts, emails, and schema markup.", 'content-engine-pro' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cep_wizard_save' ); ?>
			<input type="hidden" name="action" value="cep_wizard">
			<input type="hidden" name="cep_wizard_step" value="1">

			<div class="cep-wiz-field">
				<label for="cep_brand_name"><?php esc_html_e( 'Brand / Site Name', 'content-engine-pro' ); ?> <span class="cep-wiz-required">*</span></label>
				<input type="text" id="cep_brand_name" name="brand_name" required
				       value="<?php echo esc_attr( $s['brand_name'] ?? get_bloginfo( 'name' ) ); ?>"
				       placeholder="e.g. BaeTalk, TechRadar, NomadNews">
			</div>

			<div class="cep-wiz-field">
				<label for="cep_brand_tagline"><?php esc_html_e( 'Tagline', 'content-engine-pro' ); ?></label>
				<input type="text" id="cep_brand_tagline" name="brand_tagline"
				       value="<?php echo esc_attr( $s['brand_tagline'] ?? get_bloginfo( 'description' ) ); ?>"
				       placeholder="e.g. Love, Life & Trending">
			</div>

			<div class="cep-wiz-field">
				<label for="cep_contact_email"><?php esc_html_e( 'Contact / Publisher Email', 'content-engine-pro' ); ?></label>
				<input type="email" id="cep_contact_email" name="contact_email"
				       value="<?php echo esc_attr( $s['contact_email'] ?? get_option( 'admin_email' ) ); ?>"
				       placeholder="hello@yoursite.com">
			</div>

			<div class="cep-wiz-actions">
				<button type="submit" class="cep-wiz-btn cep-wiz-btn--primary">
					<?php esc_html_e( 'Continue', 'content-engine-pro' ); ?> &rarr;
				</button>
			</div>
		</form>
		<?php
	}

	private function render_step_2(): void {
		$current  = Settings::get( 'niche_vertical', 'wordpress' );
		$presets  = NicheManager::get_presets();
		?>
		<h2 class="cep-wizard__card-title">
			<span class="cep-wizard__card-icon">🎯</span>
			<?php esc_html_e( 'Pick Your Niche', 'content-engine-pro' ); ?>
		</h2>
		<p class="cep-wizard__card-sub">
			<?php esc_html_e( 'This configures what to auto-publish, what to review, and how to score content.', 'content-engine-pro' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cep-niche-form">
			<?php wp_nonce_field( 'cep_wizard_save' ); ?>
			<input type="hidden" name="action" value="cep_wizard">
			<input type="hidden" name="cep_wizard_step" value="2">
			<input type="hidden" name="niche_vertical" id="cep-niche-vertical" value="<?php echo esc_attr( $current ); ?>">

			<div class="cep-niche-grid">
				<?php foreach ( $presets as $key => $preset ) :
					$icon    = $preset['icon']        ?? '📰';
					$desc    = $preset['description'] ?? '';
					$palette = $preset['palette']     ?? 'purple';
					$types   = array_slice( array_map( 'trim', explode( ',', $preset['review_types'] ) ), 0, 4 );
				?>
				<label class="cep-niche-card<?php echo $key === $current ? ' is-selected' : ''; ?>"
				       data-niche="<?php echo esc_attr( $key ); ?>"
				       data-palette="<?php echo esc_attr( $palette ); ?>">
					<input type="radio" name="niche_radio" value="<?php echo esc_attr( $key ); ?>"
					       <?php checked( $key, $current ); ?> class="sr-only">
					<span class="cep-niche-card__icon"><?php echo esc_html( $icon ); ?></span>
					<strong class="cep-niche-card__name"><?php echo esc_html( $preset['label'] ); ?></strong>
					<p class="cep-niche-card__desc"><?php echo esc_html( $desc ); ?></p>
					<div class="cep-niche-card__types">
						<?php foreach ( $types as $t ) : ?>
						<span class="cep-niche-tag"><?php echo esc_html( $t ); ?></span>
						<?php endforeach; ?>
					</div>
					<span class="cep-niche-card__check" aria-hidden="true">✓</span>
				</label>
				<?php endforeach; ?>
			</div>

			<div class="cep-wiz-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&step=1' ) ); ?>"
				   class="cep-wiz-btn cep-wiz-btn--ghost">&larr; <?php esc_html_e( 'Back', 'content-engine-pro' ); ?></a>
				<button type="submit" class="cep-wiz-btn cep-wiz-btn--primary" id="cep-niche-submit">
					<?php esc_html_e( 'Continue', 'content-engine-pro' ); ?> &rarr;
				</button>
			</div>
		</form>
		<?php
	}

	private function render_step_3(): void {
		$s = get_option( 'cep_settings', [] );
		$has_key = ! empty( $s['openai_key'] );
		?>
		<h2 class="cep-wizard__card-title">
			<span class="cep-wizard__card-icon">🤖</span>
			<?php esc_html_e( 'AI & API Keys', 'content-engine-pro' ); ?>
		</h2>
		<p class="cep-wizard__card-sub">
			<?php esc_html_e( 'The autopilot needs an AI model to write content and Pexels for images.', 'content-engine-pro' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cep_wizard_save' ); ?>
			<input type="hidden" name="action" value="cep_wizard">
			<input type="hidden" name="cep_wizard_step" value="3">

			<div class="cep-wiz-field">
				<label><?php esc_html_e( 'AI Provider', 'content-engine-pro' ); ?></label>
				<div class="cep-provider-toggle">
					<label class="cep-provider-option<?php echo ( 'openai' === ( $s['ai_provider'] ?? 'openai' ) ) ? ' is-active' : ''; ?>">
						<input type="radio" name="ai_provider" value="openai"
						       <?php checked( $s['ai_provider'] ?? 'openai', 'openai' ); ?>>
						<span>🔑 OpenAI (ChatGPT)</span>
					</label>
					<label class="cep-provider-option<?php echo ( 'azure' === ( $s['ai_provider'] ?? 'openai' ) ) ? ' is-active' : ''; ?>">
						<input type="radio" name="ai_provider" value="azure"
						       <?php checked( $s['ai_provider'] ?? 'openai', 'azure' ); ?>>
						<span>☁️ Azure OpenAI</span>
					</label>
				</div>
			</div>

			<div class="cep-wiz-field">
				<label for="cep_openai_key">
					<?php esc_html_e( 'OpenAI API Key', 'content-engine-pro' ); ?>
					<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" class="cep-wiz-help-link">
						<?php esc_html_e( 'Get a key →', 'content-engine-pro' ); ?>
					</a>
				</label>
				<input type="password" id="cep_openai_key" name="openai_key" autocomplete="new-password"
				       value="<?php echo esc_attr( $has_key ? str_repeat( '•', 20 ) : '' ); ?>"
				       placeholder="sk-proj-...">
				<?php if ( $has_key ) : ?>
				<p class="cep-wiz-hint cep-wiz-hint--ok">
					✓ <?php esc_html_e( 'API key is saved. Leave blank to keep existing key.', 'content-engine-pro' ); ?>
				</p>
				<?php endif; ?>
			</div>

			<div class="cep-wiz-field">
				<label for="cep_pexels_key">
					<?php esc_html_e( 'Pexels API Key', 'content-engine-pro' ); ?>
					<span class="cep-wiz-optional"><?php esc_html_e( '(optional — for auto images)', 'content-engine-pro' ); ?></span>
					<a href="https://www.pexels.com/api/" target="_blank" rel="noopener" class="cep-wiz-help-link">
						<?php esc_html_e( 'Get a free key →', 'content-engine-pro' ); ?>
					</a>
				</label>
				<input type="password" id="cep_pexels_key" name="pexels_key" autocomplete="new-password"
				       value="<?php echo esc_attr( ! empty( $s['pexels_key'] ) ? str_repeat( '•', 20 ) : '' ); ?>"
				       placeholder="Your Pexels API key">
			</div>

			<div class="cep-wiz-notice">
				💡 <?php esc_html_e( 'Keys are stored in the WordPress database. For extra security, add them as environment variables (CEP_OPENAI_KEY, CEP_PEXELS_KEY) in your server config.', 'content-engine-pro' ); ?>
			</div>

			<div class="cep-wiz-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&step=2' ) ); ?>"
				   class="cep-wiz-btn cep-wiz-btn--ghost">&larr; <?php esc_html_e( 'Back', 'content-engine-pro' ); ?></a>
				<button type="submit" class="cep-wiz-btn cep-wiz-btn--primary">
					<?php esc_html_e( 'Continue', 'content-engine-pro' ); ?> &rarr;
				</button>
			</div>
		</form>
		<?php
	}

	private function render_step_4(): void {
		$s       = get_option( 'cep_settings', [] );
		$current = $s['theme_palette'] ?? 'pink';

		$palettes = [
			'pink'     => [ 'label' => 'BaeTalk Pink',    'from' => '#e879a0', 'to' => '#f9a8d4' ],
			'purple'   => [ 'label' => 'Deep Purple',     'from' => '#7c3aed', 'to' => '#a78bfa' ],
			'emerald'  => [ 'label' => 'Emerald Green',   'from' => '#059669', 'to' => '#6ee7b7' ],
			'crimson'  => [ 'label' => 'Crimson Red',     'from' => '#dc2626', 'to' => '#fca5a5' ],
			'midnight' => [ 'label' => 'Midnight Blue',   'from' => '#1e40af', 'to' => '#93c5fd' ],
			'gold'     => [ 'label' => 'Gold Luxe',       'from' => '#b45309', 'to' => '#fcd34d' ],
		];
		?>
		<h2 class="cep-wizard__card-title">
			<span class="cep-wizard__card-icon">🎨</span>
			<?php esc_html_e( 'Choose a Colour Palette', 'content-engine-pro' ); ?>
		</h2>
		<p class="cep-wizard__card-sub">
			<?php esc_html_e( 'Pick the visual identity for your site. You can change this any time in Theme Settings.', 'content-engine-pro' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cep_wizard_save' ); ?>
			<input type="hidden" name="action" value="cep_wizard">
			<input type="hidden" name="cep_wizard_step" value="4">

			<div class="cep-palette-grid">
				<?php foreach ( $palettes as $key => $palette ) : ?>
				<label class="cep-palette-card<?php echo $key === $current ? ' is-selected' : ''; ?>"
				       data-palette="<?php echo esc_attr( $key ); ?>">
					<input type="radio" name="theme_palette" value="<?php echo esc_attr( $key ); ?>"
					       <?php checked( $key, $current ); ?>>
					<div class="cep-palette-swatch"
					     style="background: linear-gradient(135deg, <?php echo esc_attr( $palette['from'] ); ?> 0%, <?php echo esc_attr( $palette['to'] ); ?> 100%);">
						<span class="cep-palette-check" aria-hidden="true">✓</span>
					</div>
					<span class="cep-palette-label"><?php echo esc_html( $palette['label'] ); ?></span>
				</label>
				<?php endforeach; ?>
			</div>

			<!-- Live preview bar -->
			<div class="cep-palette-preview" id="cep-palette-preview">
				<div class="cep-palette-preview__bar" id="cep-preview-bar"
				     style="background: linear-gradient(135deg, <?php echo esc_attr( $palettes[ $current ]['from'] ); ?> 0%, <?php echo esc_attr( $palettes[ $current ]['to'] ); ?> 100%);">
					<span id="cep-preview-brand"><?php echo esc_html( Settings::get( 'brand_name', get_bloginfo( 'name' ) ) ); ?></span>
					<span id="cep-preview-tagline"><?php echo esc_html( Settings::get( 'brand_tagline', '' ) ); ?></span>
				</div>
			</div>

			<div class="cep-wiz-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&step=3' ) ); ?>"
				   class="cep-wiz-btn cep-wiz-btn--ghost">&larr; <?php esc_html_e( 'Back', 'content-engine-pro' ); ?></a>
				<button type="submit" class="cep-wiz-btn cep-wiz-btn--primary cep-wiz-btn--finish">
					🎉 <?php esc_html_e( 'Finish Setup', 'content-engine-pro' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	private function render_done(): void {
		$s       = get_option( 'cep_settings', [] );
		$niche   = Settings::get( 'niche_vertical', 'custom' );
		$presets = NicheManager::get_presets();
		$preset  = $presets[ $niche ] ?? $presets['custom'];
		?>
		<div class="cep-done">
			<div class="cep-done__fireworks" aria-hidden="true">🎉</div>
			<h2 class="cep-done__title"><?php esc_html_e( "You're all set!", 'content-engine-pro' ); ?></h2>
			<p class="cep-done__sub">
				<?php
				printf(
					/* translators: 1: brand name, 2: niche label */
					esc_html__( '%1$s is configured as a %2$s site and ready to publish on autopilot.', 'content-engine-pro' ),
					'<strong>' . esc_html( $s['brand_name'] ?? get_bloginfo( 'name' ) ) . '</strong>',
					'<strong>' . esc_html( $preset['label'] ) . '</strong>'
				);
				?>
			</p>

			<div class="cep-done__summary">
				<div class="cep-done__row">
					<span class="cep-done__row-icon">🏷️</span>
					<span><strong><?php esc_html_e( 'Brand:', 'content-engine-pro' ); ?></strong> <?php echo esc_html( $s['brand_name'] ?? '' ); ?></span>
				</div>
				<div class="cep-done__row">
					<span class="cep-done__row-icon">🎯</span>
					<span><strong><?php esc_html_e( 'Niche:', 'content-engine-pro' ); ?></strong>
						<?php echo esc_html( $preset['icon'] ?? '' ); ?> <?php echo esc_html( $preset['label'] ); ?></span>
				</div>
				<div class="cep-done__row">
					<span class="cep-done__row-icon">🤖</span>
					<span><strong><?php esc_html_e( 'AI Provider:', 'content-engine-pro' ); ?></strong>
						<?php echo esc_html( 'azure' === ( $s['ai_provider'] ?? 'openai' ) ? 'Azure OpenAI' : 'OpenAI' ); ?></span>
				</div>
				<div class="cep-done__row">
					<span class="cep-done__row-icon">🎨</span>
					<span><strong><?php esc_html_e( 'Palette:', 'content-engine-pro' ); ?></strong>
						<?php echo esc_html( ucfirst( $s['theme_palette'] ?? 'default' ) ); ?></span>
				</div>
				<div class="cep-done__row">
					<span class="cep-done__row-icon">📡</span>
					<span><strong><?php esc_html_e( 'Article sources:', 'content-engine-pro' ); ?></strong>
						<?php echo esc_html( count( array_filter( explode( "\n", $s['article_sources'] ?? '' ) ) ) ); ?>
						<?php esc_html_e( 'feeds pre-loaded', 'content-engine-pro' ); ?></span>
				</div>
				<div class="cep-done__row">
					<span class="cep-done__row-icon">⭐</span>
					<span><strong><?php esc_html_e( 'Review sources:', 'content-engine-pro' ); ?></strong>
						<?php echo esc_html( count( array_filter( explode( "\n", $s['review_discovery_sources'] ?? '' ) ) ) ); ?>
						<?php esc_html_e( 'feeds pre-loaded', 'content-engine-pro' ); ?></span>
				</div>
			</div>

			<div class="cep-done__actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=content-engine-pro' ) ); ?>"
				   class="cep-wiz-btn cep-wiz-btn--primary">
					<?php esc_html_e( '→ Go to Dashboard', 'content-engine-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-autopilot' ) ); ?>"
				   class="cep-wiz-btn cep-wiz-btn--ghost">
					<?php esc_html_e( '▶ Run Autopilot Now', 'content-engine-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings' ) ); ?>"
				   class="cep-wiz-btn cep-wiz-btn--ghost">
					<?php esc_html_e( '⚙ Advanced Settings', 'content-engine-pro' ); ?>
				</a>
			</div>

			<p class="cep-done__rerun">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&step=1' ) ); ?>">
					<?php esc_html_e( '↺ Run the wizard again', 'content-engine-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	private function step_label( int $step ): string {
		$labels = [
			1 => __( 'Brand', 'content-engine-pro' ),
			2 => __( 'Niche', 'content-engine-pro' ),
			3 => __( 'AI Keys', 'content-engine-pro' ),
			4 => __( 'Palette', 'content-engine-pro' ),
		];
		return $labels[ $step ] ?? '';
	}

	private function get_niches_for_js(): array {
		$out = [];
		foreach ( NicheManager::get_presets() as $key => $preset ) {
			$out[ $key ] = [
				'palette' => $preset['palette'] ?? 'purple',
				'icon'    => $preset['icon']    ?? '📰',
				'label'   => $preset['label'],
			];
		}
		return $out;
	}
}
