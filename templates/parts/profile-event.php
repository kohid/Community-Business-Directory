<?php
defined( 'ABSPATH' ) || exit;
global $wpdb;
$post_id = get_the_ID();
$ev = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_events WHERE post_id = %d", $post_id), ARRAY_A) ?? [];
$start = ! empty($ev['start_date']) ? new DateTime($ev['start_date']) : null;
$end   = ! empty($ev['end_date']) ? new DateTime($ev['end_date']) : null;
$cats  = get_the_terms($post_id, 'cbd_event_cat') ?: [];
?>
<div class="cbd-scope cbd-profile-wrap cbd-event-detail">
	<?php if (has_post_thumbnail()) : ?>
		<div class="cbd-profile-cover" style="background-image:url(<?php echo esc_url(get_the_post_thumbnail_url($post_id, 'full')); ?>)">
			<div class="cbd-profile-cover-overlay"></div>
		</div>
	<?php endif; ?>

	<div class="cbd-profile-header-wrap">
		<div class="cbd-profile-header">
			<?php if ($start) : ?>
				<div class="cbd-event-hero-date">
					<span class="cbd-event-month"><?php echo esc_html($start->format('M')); ?></span>
					<span class="cbd-event-day"><?php echo esc_html($start->format('d')); ?></span>
				</div>
			<?php endif; ?>
			<div class="cbd-profile-headline">
				<?php if (! empty($ev['business_id'])) echo cbd_card_business_info((int) $ev['business_id']); ?>
				<h1 class="cbd-profile-name"><?php the_title(); ?></h1>
				<?php if ($cats) echo '<p class="cbd-profile-cats">' . esc_html(implode(', ', wp_list_pluck($cats, 'name'))) . '</p>'; ?>

				<?php if (! empty($ev['is_free'])) echo '<span class="cbd-badge cbd-badge-free" style="display:inline-block;margin-top:8px;">Free Event</span>';
				elseif (! empty($ev['ticket_price'])) echo '<span class="cbd-badge cbd-badge-paid" style="display:inline-block;margin-top:8px;">' . esc_html(get_option('cbd_currency_symbol', '£') . number_format((float) $ev['ticket_price'], 2)) . '</span>'; ?>
			</div>
			<div class="cbd-profile-actions">
				<?php if (! empty($ev['ticket_url'])) echo '<a href="' . esc_url($ev['ticket_url']) . '" class="cbd-btn cbd-btn-primary" target="_blank" rel="noopener"> ' . \cbd_icon('ticket-outline') . ' ' . esc_html__('Get Tickets', 'community-business-directory') . '</a>'; ?>
				<a href="<?php echo esc_url(cbd_listing_page_url('cbd_events_page_id', '/events/')); ?>" class="cbd-btn cbd-btn-outline"> <?php echo \cbd_icon('arrow-left'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?> <?php esc_html_e('All Events', 'community-business-directory'); ?></a>
			</div>
		</div>
	</div>

	<div class="cbd-profile-body">
		<div class="cbd-profile-main">
			<div class="cbd-profile-section">
				<h2><?php esc_html_e('About This Event', 'community-business-directory'); ?></h2>
				<div class="cbd-profile-description"><?php the_content(); ?></div>
			</div>
		</div>
		<aside class="cbd-profile-sidebar">
			<div class="cbd-sidebar-card">
				<h4><?php esc_html_e('Event Details', 'community-business-directory'); ?></h4>
				<?php if ($start) echo '<p> ' . \cbd_icon('calendar-blank-outline') . ' ' . esc_html($start->format('D j M Y')) . '</p>'; ?>
				<?php if ($start && $end) echo '<p> ' . \cbd_icon('clock-outline') . ' ' . esc_html($start->format('g:ia') . ' – ' . $end->format('g:ia')) . '</p>'; ?>
				<?php if (! empty($ev['venue_name'])) echo '<p> ' . \cbd_icon('map-marker') . ' ' . esc_html($ev['venue_name']) . '</p>'; ?>
				<?php if (! empty($ev['capacity']) && $ev['capacity'] > 0) echo '<p>👥 ' . sprintf(esc_html__('Capacity: %d', 'community-business-directory'), (int) $ev['capacity']) . '</p>'; ?>
			</div>
		</aside>
	</div>
</div>
