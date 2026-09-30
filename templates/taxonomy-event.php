<?php
/**
 * Event category term archive (/event-category/<slug>/).
 * Override: wp-content/themes/<theme>/community-business-directory/taxonomy-event.php
 */
defined( 'ABSPATH' ) || exit;
get_header();
$term = get_queried_object();
$slug = ( $term && ! is_wp_error( $term ) && isset( $term->slug ) ) ? $term->slug : '';
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
	<?php echo do_shortcode( '[cbd_events count="9" category="' . esc_attr( $slug ) . '"]' ); ?>
</div>
<?php
get_footer();
