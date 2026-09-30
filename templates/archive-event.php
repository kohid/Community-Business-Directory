<?php
/**
 * Events archive (/events/).
 * Override: wp-content/themes/<theme>/community-business-directory/archive-event.php
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="cbd-scope cbd-archive-page">
	<?php echo do_shortcode( '[cbd_events count="9"]' ); ?>
</div>
<?php
get_footer();
