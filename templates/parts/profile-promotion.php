<?php
defined( 'ABSPATH' ) || exit;
global $wpdb;
$post_id = get_the_ID();
$pr = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_promotions WHERE post_id = %d", $post_id), ARRAY_A) ?? [];
$sym = get_option('cbd_currency_symbol', '£');
?>
<div class="cbd-scope cbd-profile-wrap cbd-promo-detail">

	<div class="cbd-profile-header-wrap">
		<div class="cbd-profile-header">
			<?php if (! empty($pr['discount_value'])) : ?>
				<div class="cbd-promo-hero-discount">
					<span class="cbd-promo-big-val"><?php echo esc_html($pr['discount_value']); ?><?php echo $pr['discount_type'] === 'percent' ? '%' : esc_html($sym); ?></span>
					<span><?php esc_html_e('OFF', 'community-business-directory'); ?></span>
				</div>
			<?php endif; ?>
			<div class="cbd-profile-headline">
				<?php if (! empty($pr['business_id'])) echo cbd_card_business_info((int) $pr['business_id']); ?>
				<h1 class="cbd-profile-name"><?php the_title(); ?></h1>
			</div>
			<div class="cbd-profile-actions">

				<?php if (! empty($pr['cta_url'])) echo '<a href="' . esc_url($pr['cta_url']) . '" class="cbd-btn cbd-btn-primary" target="_blank" rel="noopener">' . esc_html($pr['cta_text'] ?: __('Get Offer', 'community-business-directory')) . '</a>'; ?>
				<a href="<?php echo esc_url(cbd_listing_page_url('cbd_promotions_page_id', '/offers/')); ?>" class="cbd-btn cbd-btn-outline"> <?php echo \cbd_icon('arrow-left'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG
																																							?> <?php esc_html_e('All Offers', 'community-business-directory'); ?></a>
			</div>
		</div>
	</div>
	<div class="cbd-profile-body">
		<div class="cbd-profile-main">
			<div class="cbd-profile-section">
				<h2><?php esc_html_e('Offer Details', 'community-business-directory'); ?></h2>
				<div class="cbd-profile-description">
					<?php if (! empty($pr['coupon_code'])) : ?>
						<div class="cbd-promo-code-wrap" style="font-size:18px;">
							<span class="cbd-promo-code"><?php echo esc_html($pr['coupon_code']); ?></span>
							<button class="cbd-copy-code cbd-btn cbd-btn-outline" data-code="<?php echo esc_attr($pr['coupon_code']); ?>"><?php esc_html_e('Copy Code', 'community-business-directory'); ?></button>
							<?php if (! empty($pr['expiry_date'])) echo '<p class="cbd-promo-expiry">⏰ ' . sprintf(esc_html__('Offer expires %s', 'community-business-directory'), esc_html(date_i18n(get_option('date_format'), strtotime($pr['expiry_date'])))) . '</p>'; ?>
						</div>
					<?php endif; ?>
					<?php the_content(); ?>
				</div>
			</div>
		</div>
		<aside class="cbd-profile-sidebar">
			<div class="cbd-sidebar-card">
				<h4><?php esc_html_e('Offer Summary', 'community-business-directory'); ?></h4>
				<?php if (! empty($pr['start_date'])) echo '<p>' . esc_html__('Valid from:', 'community-business-directory') . ' ' . esc_html(date_i18n(get_option('date_format'), strtotime($pr['start_date']))) . '</p>'; ?>
				<?php if (! empty($pr['expiry_date'])) echo '<p>' . esc_html__('Expires:', 'community-business-directory') . ' ' . esc_html(date_i18n(get_option('date_format'), strtotime($pr['expiry_date']))) . '</p>'; ?>
				<?php if (! empty($pr['cta_url'])) echo '<a href="' . esc_url($pr['cta_url']) . '" class="cbd-btn cbd-btn-primary cbd-btn-full" style="margin-top:12px;" target="_blank" rel="noopener">' . esc_html($pr['cta_text'] ?: __('Get Offer', 'community-business-directory')) . '</a>'; ?>
			</div>
		</aside>
	</div>
</div>
