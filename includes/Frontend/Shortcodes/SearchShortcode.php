<?php
namespace CBD\Frontend\Shortcodes;
defined( 'ABSPATH' ) || exit;

class SearchShortcode {
	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'placeholder' => __( 'Search businesses, events, offers…', 'community-business-directory' ),
			'redirect'    => '',
			'show_cats'   => 'true',
			'button_text' => __( 'Search', 'community-business-directory' ),
		], $atts );

		$cats = get_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false ] );

		$dir_page = get_option( 'cbd_directory_page_id' );
		$action   = $atts['redirect'] ?: ( $dir_page ? get_permalink( $dir_page ) : home_url( '/directory/' ) );

		ob_start(); ?>
<div class="cbd-wrap cbd-search-widget">
	<form method="get" action="<?php echo esc_url( $action ); ?>" class="cbd-hero-search-form">
		<div class="cbd-hero-search-inner">
			<div class="cbd-search-field">
				<span class="cbd-search-icon">🔍</span>
				<input type="text" name="cbd_s" id="cbd-live-search"
					   placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					   class="cbd-search-input" autocomplete="off">
				<div id="cbd-autocomplete" class="cbd-autocomplete" style="display:none;"></div>
			</div>
			<?php if ( $atts['show_cats'] === 'true' && ! is_wp_error( $cats ) && $cats ) : ?>
			<select name="cbd_cat" class="cbd-select">
				<option value=""><?php esc_html_e( 'All Categories', 'community-business-directory' ); ?></option>
				<?php foreach ( $cats as $cat ) : ?>
				<option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( \cbd_label( $cat->name ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php endif; ?>
			<button type="submit" class="cbd-btn cbd-btn-primary">
				<?php echo esc_html( $atts['button_text'] ); ?>
			</button>
		</div>
	</form>
</div>
		<?php
		return ob_get_clean();
	}
}
