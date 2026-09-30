<?php
/**
 * Business taxonomy term archive — category (/business-category/<slug>/) and
 * location (/location/<slug>/).
 * Override: wp-content/themes/<theme>/community-business-directory/taxonomy-business.php
 */
defined( 'ABSPATH' ) || exit;
get_header();
$term = get_queried_object();
$slug = ( $term && ! is_wp_error( $term ) && isset( $term->slug ) ) ? $term->slug : '';
$tax  = ( $term && isset( $term->taxonomy ) ) ? $term->taxonomy : '';

// The directory grid can pre-filter by business category; location has no
// shortcode attribute, so those term pages show the full directory (whose own
// location filter the visitor can then use).
$shortcode = ( 'cbd_category' === $tax && $slug )
	? '[cbd_directory category="' . esc_attr( $slug ) . '"]'
	: '[cbd_directory]';
?>
<div class="cbd-scope cbd-archive-page">
	<?php if ( $term && isset( $term->name ) ) : ?>
	<div class="cbd-wrap cbd-archive-head">
		<h1 class="cbd-archive-title"><?php echo esc_html( $term->name ); ?></h1>
		<?php if ( ! empty( $term->description ) ) : ?>
		<p class="cbd-archive-desc"><?php echo esc_html( $term->description ); ?></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<?php echo do_shortcode( $shortcode ); ?>
</div>
<?php
get_footer();
