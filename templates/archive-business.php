<?php
/**
 * Business directory archive (/directory/).
 * Override: wp-content/themes/<theme>/community-business-directory/archive-business.php
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<div class="cbd-scope cbd-archive-page">
	<?php echo do_shortcode( '[cbd_directory]' ); ?>
</div>
<?php
get_footer();
