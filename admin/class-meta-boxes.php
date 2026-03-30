<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta boxes for CPT post editing screens.
 */
class MetaBoxes {

	public static function register(): void {
		$primary_cpt  = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews_cpt  = Settings::get( 'reviews_cpt_slug', 'review' );
		$providers_cpt = Settings::get( 'providers_cpt_slug', 'provider' );

		// Primary content meta box
		add_meta_box( 'cep_content_meta', 'Content Engine Data', [ self::class, 'render_content_meta' ], $primary_cpt, 'side', 'default' );

		// Review meta box
		if ( Settings::is_enabled( 'reviews_cpt_enabled' ) ) {
			add_meta_box( 'cep_review_meta', 'Review Details', [ self::class, 'render_review_meta' ], $reviews_cpt, 'normal', 'high' );
		}

		// Provider meta box
		if ( Settings::is_enabled( 'providers_cpt_enabled' ) ) {
			add_meta_box( 'cep_provider_meta', 'Provider Details', [ self::class, 'render_provider_meta' ], $providers_cpt, 'normal', 'high' );
		}
	}

	public static function render_content_meta( \WP_Post $post ): void {
		wp_nonce_field( 'cep_content_meta_save', 'cep_content_meta_nonce' );
		$signal_score  = get_post_meta( $post->ID, '_cep_signal_score', true );
		$source_name   = get_post_meta( $post->ID, '_cep_source_name', true );
		$source_url    = get_post_meta( $post->ID, '_cep_source_url', true );
		$ai_model      = get_post_meta( $post->ID, '_cep_ai_model', true );
		$word_count    = get_post_meta( $post->ID, '_cep_word_count', true );
		$article_type  = get_post_meta( $post->ID, '_cep_article_type', true );
		?>
		<div class="cep-meta-box">
			<p><label><strong>Signal Score</strong><br/><input type="number" name="cep_signal_score" value="<?php echo esc_attr( $signal_score ); ?>" min="0" max="100" class="widefat" /></label></p>
			<p><label><strong>Source Name</strong><br/><input type="text" name="cep_source_name" value="<?php echo esc_attr( $source_name ); ?>" class="widefat" /></label></p>
			<p><label><strong>Source URL</strong><br/><input type="url" name="cep_source_url" value="<?php echo esc_attr( $source_url ); ?>" class="widefat" /></label></p>
			<p><label><strong>AI Model</strong><br/><input type="text" name="cep_ai_model" value="<?php echo esc_attr( $ai_model ); ?>" class="widefat" /></label></p>
			<p><label><strong>Word Count</strong><br/><input type="number" name="cep_word_count" value="<?php echo esc_attr( $word_count ); ?>" class="widefat" /></label></p>
			<p><label><strong>Article Type</strong><br/>
				<select name="cep_article_type" class="widefat">
					<option value="">-- Select --</option>
					<?php foreach ( ['NewsArticle','Article','BlogPosting','TechArticle'] as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $article_type, $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
			</label></p>
		</div>
		<?php
	}

	public static function render_review_meta( \WP_Post $post ): void {
		wp_nonce_field( 'cep_review_meta_save', 'cep_review_meta_nonce' );
		$fields = [
			'star_rating'    => [ 'label' => 'Star Rating (1-5)', 'type' => 'number', 'attrs' => 'min="0" max="5" step="0.1"' ],
			'price_from'     => [ 'label' => 'Price From', 'type' => 'text' ],
			'affiliate_slug' => [ 'label' => 'Affiliate Slug', 'type' => 'text' ],
			'product_url'    => [ 'label' => 'Product URL', 'type' => 'url' ],
			'product_type'   => [ 'label' => 'Product Type', 'type' => 'text' ],
		];
		foreach ( $fields as $key => $config ) :
			$value = get_post_meta( $post->ID, '_cep_review_' . $key, true );
			?>
			<p><label><strong><?php echo esc_html( $config['label'] ); ?></strong><br/>
				<input type="<?php echo esc_attr( $config['type'] ); ?>" name="cep_review_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo isset( $config['attrs'] ) ? $config['attrs'] : ''; ?> class="widefat" />
			</label></p>
		<?php endforeach;

		// Textarea fields
		foreach ( [ 'pros' => 'Pros (one per line)', 'cons' => 'Cons (one per line)', 'verdict' => 'Verdict' ] as $key => $label ) :
			$value = get_post_meta( $post->ID, '_cep_review_' . $key, true );
			?>
			<p><label><strong><?php echo esc_html( $label ); ?></strong><br/>
				<textarea name="cep_review_<?php echo esc_attr( $key ); ?>" rows="3" class="widefat"><?php echo esc_textarea( $value ); ?></textarea>
			</label></p>
		<?php endforeach;
	}

	public static function render_provider_meta( \WP_Post $post ): void {
		wp_nonce_field( 'cep_provider_meta_save', 'cep_provider_meta_nonce' );
		$url_enc      = get_post_meta( $post->ID, '_cep_affiliate_url_enc', true );
		$is_active    = get_post_meta( $post->ID, '_cep_is_active', true );
		$display_price = get_post_meta( $post->ID, '_cep_display_price', true );
		$homepage_url = get_post_meta( $post->ID, '_cep_homepage_url', true );
		$star_rating  = get_post_meta( $post->ID, '_cep_star_rating', true );
		$infra_type   = get_post_meta( $post->ID, '_cep_infra_type', true );
		$target_audience = get_post_meta( $post->ID, '_cep_target_audience', true );
		$match_terms  = get_post_meta( $post->ID, '_cep_match_terms', true );
		?>
		<div class="cep-meta-box">
			<p><label><strong>Affiliate URL</strong> (enter to encrypt, leave blank to keep existing)<br/>
				<input type="url" name="cep_affiliate_url_raw" value="" placeholder="https://..." class="widefat" />
			</label></p>
			<?php if ( $url_enc ) : ?>
				<p class="description">Encrypted URL is stored. Enter a new URL to replace it.</p>
			<?php endif; ?>
			<p><label><strong>Homepage URL</strong><br/>
				<input type="url" name="cep_homepage_url" value="<?php echo esc_attr( $homepage_url ); ?>" class="widefat" />
			</label></p>
			<p><label><strong>Starting Price</strong><br/>
				<input type="text" name="cep_display_price" value="<?php echo esc_attr( $display_price ); ?>" class="widefat" placeholder="e.g. $3.99/mo" />
			</label></p>
			<p><label><strong>Star Rating</strong><br/>
				<input type="number" name="cep_star_rating" value="<?php echo esc_attr( $star_rating ); ?>" min="0" max="5" step="0.1" class="widefat" />
			</label></p>
			<p><label><strong>Infrastructure Type</strong><br/>
				<input type="text" name="cep_infra_type" value="<?php echo esc_attr( $infra_type ); ?>" class="widefat" placeholder="e.g. Shared, VPS, Cloud" />
			</label></p>
			<p><label><strong>Target Audience</strong><br/>
				<input type="text" name="cep_target_audience" value="<?php echo esc_attr( $target_audience ); ?>" class="widefat" />
			</label></p>
			<p><label><strong>Match Terms</strong> (comma-separated)<br/>
				<input type="text" name="cep_match_terms" value="<?php echo esc_attr( is_array( $match_terms ) ? implode( ', ', $match_terms ) : $match_terms ); ?>" class="widefat" />
			</label></p>
			<p><label>
				<input type="checkbox" name="cep_is_active" value="1" <?php checked( '1', $is_active ); ?> />
				<strong>Active</strong> (include in affiliate link matching)
			</label></p>
		</div>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$primary_cpt   = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews_cpt   = Settings::get( 'reviews_cpt_slug', 'review' );
		$providers_cpt = Settings::get( 'providers_cpt_slug', 'provider' );

		if ( $post->post_type === $primary_cpt && isset( $_POST['cep_content_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_content_meta_nonce'] ) ), 'cep_content_meta_save' ) ) {
			update_post_meta( $post_id, '_cep_signal_score', absint( wp_unslash( $_POST['cep_signal_score'] ?? 0 ) ) );
			update_post_meta( $post_id, '_cep_source_name', sanitize_text_field( wp_unslash( $_POST['cep_source_name'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_source_url', esc_url_raw( wp_unslash( $_POST['cep_source_url'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_ai_model', sanitize_text_field( wp_unslash( $_POST['cep_ai_model'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_word_count', absint( wp_unslash( $_POST['cep_word_count'] ?? 0 ) ) );
			update_post_meta( $post_id, '_cep_article_type', sanitize_text_field( wp_unslash( $_POST['cep_article_type'] ?? '' ) ) );
		}

		if ( $post->post_type === $reviews_cpt && isset( $_POST['cep_review_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_review_meta_nonce'] ) ), 'cep_review_meta_save' ) ) {
			$text_fields = [ 'price_from','affiliate_slug','product_url','product_type' ];
			foreach ( $text_fields as $field ) {
				update_post_meta( $post_id, '_cep_review_' . $field, sanitize_text_field( wp_unslash( $_POST[ 'cep_review_' . $field ] ?? '' ) ) );
			}
			update_post_meta( $post_id, '_cep_review_star_rating', (float) sanitize_text_field( wp_unslash( $_POST['cep_review_star_rating'] ?? 0 ) ) );
			update_post_meta( $post_id, '_cep_review_pros', sanitize_textarea_field( wp_unslash( $_POST['cep_review_pros'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_review_cons', sanitize_textarea_field( wp_unslash( $_POST['cep_review_cons'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_review_verdict', sanitize_textarea_field( wp_unslash( $_POST['cep_review_verdict'] ?? '' ) ) );
		}

		if ( $post->post_type === $providers_cpt && isset( $_POST['cep_provider_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_provider_meta_nonce'] ) ), 'cep_provider_meta_save' ) ) {
			$raw_url = esc_url_raw( wp_unslash( $_POST['cep_affiliate_url_raw'] ?? '' ) );
			if ( ! empty( $raw_url ) ) {
				$encrypted = cep_encrypt_affiliate_url( $raw_url );
				if ( $encrypted ) {
					update_post_meta( $post_id, '_cep_affiliate_url_enc', $encrypted );
				}
			}
			update_post_meta( $post_id, '_cep_homepage_url', esc_url_raw( wp_unslash( $_POST['cep_homepage_url'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_display_price', sanitize_text_field( wp_unslash( $_POST['cep_display_price'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_star_rating', (float) sanitize_text_field( wp_unslash( $_POST['cep_star_rating'] ?? 0 ) ) );
			update_post_meta( $post_id, '_cep_infra_type', sanitize_text_field( wp_unslash( $_POST['cep_infra_type'] ?? '' ) ) );
			update_post_meta( $post_id, '_cep_target_audience', sanitize_text_field( wp_unslash( $_POST['cep_target_audience'] ?? '' ) ) );
			$match_terms = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', wp_unslash( $_POST['cep_match_terms'] ?? '' ) ) ) );
			update_post_meta( $post_id, '_cep_match_terms', array_filter( $match_terms ) );
			update_post_meta( $post_id, '_cep_is_active', isset( $_POST['cep_is_active'] ) ? '1' : '0' );
		}
	}
}
