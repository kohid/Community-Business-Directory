<?php
/**
 * Promotions archive (/promotions/).
 * Override: wp-content/themes/<theme>/community-business-directory/archive-promotion.php
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="cbd-scope cbd-archive-page">
	<?php echo do_shortcode( '[cbd_promotions count="9"]' ); ?>
</div>
<?php
get_footer();
