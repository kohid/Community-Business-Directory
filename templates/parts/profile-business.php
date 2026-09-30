<?php
defined( 'ABSPATH' ) || exit;
global $post, $wpdb;

$post_id  = get_the_ID();
$meta     = $wpdb->get_row(
	$wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d", $post_id),
	ARRAY_A
) ?? [];

$categories   = get_the_terms($post_id, 'cbd_category') ?: [];
$locations    = get_the_terms($post_id, 'cbd_location') ?: [];
// Cover photo: dedicated cover image first, then the logo, then the CSS gradient.
$cover_id     = (int) get_post_meta($post_id, '_cbd_cover_id', true);
$cover_url    = $cover_id ? (wp_get_attachment_image_url($cover_id, 'full') ?: '') : '';
if (! $cover_url) {
	$cover_url = get_the_post_thumbnail_url($post_id, 'full') ?: '';
}
$social_links = ! empty($meta['social_links']) ? json_decode($meta['social_links'],  true) : [];
$hours        = ! empty($meta['opening_hours']) ? json_decode($meta['opening_hours'], true) : [];

$user_id      = get_current_user_id();
$is_following = $user_id && $wpdb->get_var(
	$wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}cbd_follows WHERE user_id = %d AND business_id = %d",
		$user_id,
		$post_id
	)
);

// Track view — but only on a genuine front-end visit to this business. This
// partial is also rendered by [cbd_business_profile] (e.g. inside an Elementor
// Theme Builder single template), so guard against inflating the count or
// writing analytics rows when we're not looking at a real cbd_business hit:
//   • skip wp-admin / REST,
//   • skip Elementor's editor + preview iframe (?elementor-preview=…),
//   • require the current post to actually be a business row.
$cbd_track_view = isset($meta['post_id'])
	&& 'cbd_business' === get_post_type($post_id)
	&& ! is_admin()
	&& ! ( defined('REST_REQUEST') && REST_REQUEST )
	&& ! isset($_GET['elementor-preview']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ($cbd_track_view) {
	$today = gmdate('Y-m-d');
	$wpdb->query($wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}cbd_analytics (business_id, date, views)
		 VALUES (%d, %s, 1)
		 ON DUPLICATE KEY UPDATE views = views + 1",
		$post_id,
		$today
	));
	$wpdb->query($wpdb->prepare(
		"UPDATE {$wpdb->prefix}cbd_businesses SET view_count = view_count + 1 WHERE post_id = %d",
		$post_id
	));
}

$active_tab = sanitize_key($_GET['biz_tab'] ?? 'posts');
$days_order = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
$today_key  = strtolower(gmdate('l'));

// Owner / delegate / admin — gates the Settings menu, "add" buttons and forms.
$can_manage = \cbd_user_can_manage_business($post_id);
?>
<div class="cbd-scope cbd-profile-wrap">

	<!-- ── Cover ──────────────────────────────────────────── -->
	<div class="cbd-profile-cover" style="<?php echo $cover_url ? 'background-image:url(' . esc_url($cover_url) . ')' : ''; ?>">
		<div class="cbd-profile-cover-overlay"></div>

		<div class="cbd-profile-business-category">

			<div class="cbd-profile-cats">
				<?php foreach ($categories as $cat) : ?>
					<a href="<?php echo esc_url(get_term_link($cat)); ?>" class="cbd-profile-cat-tag">
						<?php echo esc_html($cat->name); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<?php
			$cover_plan      = $meta['plan'] ?? 'free';
			$cover_plan_name = $wpdb->get_var($wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}cbd_membership_plans WHERE slug = %s",
				$cover_plan
			));
			$cover_plan_name = $cover_plan_name ?: ucfirst($cover_plan);
			?>
			<?php
			$cover_plan_label = sprintf(esc_html__('%s Plan', 'community-business-directory'), esc_html($cover_plan_name));
			if ($can_manage) : // Owners can click the badge to manage their plan. 
			?>
				<a href="<?php echo esc_url(\cbd_plans_url()); ?>" class="cbd-cover-plan cbd-cover-plan-link cbd-plan-tier-<?php echo esc_attr($cover_plan); ?>" title="<?php esc_attr_e('View membership plans', 'community-business-directory'); ?>">
					💳 <?php echo $cover_plan_label; // phpcs:ignore — already escaped above 
						?>
				</a>
			<?php else : ?>
				<span class="cbd-cover-plan cbd-plan-tier-<?php echo esc_attr($cover_plan); ?>">
					💳 <?php echo $cover_plan_label; // phpcs:ignore — already escaped above 
						?>
				</span>
			<?php endif; ?>

		</div>

	</div>

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="cbd-profile-header-wrap">
		<div class="cbd-profile-header">
			<div class="cbd-profile-logo-wrap">
				<?php if (has_post_thumbnail()) : ?>
					<?php the_post_thumbnail('thumbnail', ['class' => 'cbd-profile-logo']); ?>
				<?php else : ?>
					<div class="cbd-profile-logo cbd-profile-logo-ph">
						<?php echo esc_html(mb_strtoupper(mb_substr(get_the_title(), 0, 1))); ?>
					</div>
				<?php endif; ?>
				<?php if (! empty($meta['is_verified'])) : ?>
					<span class="cbd-verified-badge" title="<?php esc_attr_e('Verified Business', 'community-business-directory'); ?>">✓</span>
				<?php endif; ?>
			</div>

			<div class="cbd-profile-headline">
				<h1 class="cbd-profile-name"><?php the_title(); ?></h1>
				<?php if ($categories) : ?>

				<?php endif; ?>
				<?php
				// Full address: street, city, postcode — joined with what's present.
				$addr_parts = array_filter([
					trim((string) ($meta['address_line1'] ?? '')),
					trim((string) ($meta['city'] ?? '')),
					trim((string) ($meta['postal_code'] ?? '')),
				]);
				if ($addr_parts) : ?>
					<p class="cbd-profile-location">
						<span class="cbd-profile-location-pin">
							<?php echo \cbd_icon('map-marker'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?>
						</span>
						<span><?php echo esc_html(implode(', ', $addr_parts)); ?></span>
					</p>
				<?php endif; ?>
				<?php if (! empty($meta['rating_avg']) && (float) $meta['rating_avg'] > 0) : ?>
					<div class="cbd-profile-rating">
						<span class="cbd-stars"><?php echo str_repeat('★', (int) round((float) $meta['rating_avg'])); ?><?php echo str_repeat('☆', 5 - (int) round((float) $meta['rating_avg'])); ?></span>
						<strong><?php echo esc_html(number_format((float) $meta['rating_avg'], 1)); ?></strong>
						<span>(<?php printf(esc_html(_n('%d review', '%d reviews', (int) $meta['review_count'], 'community-business-directory')), (int) $meta['review_count']); ?>)</span>
					</div>
				<?php endif; ?>
			</div>

			<div class="cbd-profile-actions">
				<?php if (! empty($meta['website'])) : ?>
					<a href="<?php echo esc_url($meta['website']); ?>" class="cbd-btn cbd-btn-primary" target="_blank" rel="noopener">
						<?php echo \cbd_icon('web'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?> <?php esc_html_e('Visit Website', 'community-business-directory'); ?>
					</a>
				<?php endif; ?>
				<?php if (! empty($meta['phone'])) : ?>
					<a href="tel:<?php echo esc_attr(preg_replace('/[^+\d]/', '', $meta['phone'])); ?>" class="cbd-btn cbd-btn-outline">
						📞 <?php echo esc_html($meta['phone']); ?>
					</a>
				<?php endif; ?>
				<?php if (is_user_logged_in()) : ?>
					<button class="cbd-btn cbd-btn-outline cbd-follow-btn <?php echo $is_following ? 'is-following' : ''; ?>"
						data-business-id="<?php echo esc_attr($post_id); ?>">
						<?php echo $is_following
							? esc_html__('Following', 'community-business-directory')
							: esc_html__('Follow', 'community-business-directory'); ?>
					</button>
				<?php endif; ?>
				<div class="cbd-profile-followers">
					<strong><?php echo esc_html(number_format((int) ($meta['follower_count'] ?? 0))); ?></strong>
					<?php esc_html_e('followers', 'community-business-directory'); ?>
				</div>
			</div>
		</div>
	</div>

	<!-- ── Tabs ───────────────────────────────────────────── -->
	<div class="cbd-profile-tabs-wrap">
		<div class="cbd-profile-tabs">
			<?php
			$biz_tabs = [
				'posts'      => __('News Feed',   'community-business-directory'),
				'about'      => __('About',      'community-business-directory'),
				'gallery'    => __('Gallery',     'community-business-directory'),
				'events'     => __('Events',      'community-business-directory'),
				'promotions' => __('Promotions',  'community-business-directory'),
				'reviews'    => __('Reviews',     'community-business-directory'),
			];
			// "Page Profile" (edit, delegate-with-edit) and "Delegate Access"
			// (owner-only) moved out of the public tab strip into the settings gear
			// below — see the cbd-profile-settings menu.
			foreach ($biz_tabs as $slug => $label) :
			?>
				<a href="<?php echo esc_url(add_query_arg('biz_tab', $slug, get_permalink($post_id))); ?>"
					class="cbd-profile-tab <?php echo $active_tab === $slug ? 'active' : ''; ?>">
					<?php echo esc_html($label); ?>
				</a>
			<?php endforeach; ?>
		</div>

		<?php
		// Owner/delegate settings menu (gear, far right of the tab strip). It
		// gathers the management surfaces that aren't part of the public tab strip:
		//   • Page Profile   — edit form: owner or a delegate with the "edit" cap.
		//   • Delegate Access — owner-only (cbd_is_business_owner).
		//   • Analytics / Plan Usage — any manager (owner / delegate / admin).
		// Each item re-checks its own access; the body branches it links to do too.
		$can_edit_page  = \cbd_user_can($post_id, 'edit');
		$is_biz_owner   = \cbd_is_business_owner($post_id);
		$gear_active    = in_array($active_tab, ['analytics', 'usage', 'edit', 'delegate'], true);
		?>
		<?php if ($can_manage) : ?>
			<div class="cbd-profile-settings" id="cbd-profile-settings">
				<button type="button" class="cbd-settings-toggle <?php echo $gear_active ? 'active' : ''; ?>" id="cbd-settings-toggle" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e('Business settings', 'community-business-directory'); ?>">
					<?php echo \cbd_icon('cog'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?>
				</button>
				<div class="cbd-settings-menu" role="menu">
					<?php if ($can_edit_page) : ?>
						<a class="cbd-settings-item <?php echo $active_tab === 'edit' ? 'is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url(add_query_arg('biz_tab', 'edit', get_permalink($post_id))); ?>">
							<?php echo \cbd_icon('pencil'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?>
							<?php esc_html_e('Page Profile', 'community-business-directory'); ?>
						</a>
					<?php endif; ?>
					<?php if ($is_biz_owner) : ?>
						<a class="cbd-settings-item <?php echo $active_tab === 'delegate' ? 'is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url(add_query_arg('biz_tab', 'delegate', get_permalink($post_id))); ?>">
							<?php echo \cbd_icon('account-group'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?>
							<?php esc_html_e('Delegate Access', 'community-business-directory'); ?>
						</a>
					<?php endif; ?>
					<a class="cbd-settings-item <?php echo $active_tab === 'analytics' ? 'is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url(add_query_arg('biz_tab', 'analytics', get_permalink($post_id))); ?>">
						<?php echo \cbd_icon('chart-bar'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?>
						<?php esc_html_e('Analytics', 'community-business-directory'); ?>
					</a>
					<a class="cbd-settings-item <?php echo $active_tab === 'usage' ? 'is-active' : ''; ?>" role="menuitem" href="<?php echo esc_url(add_query_arg('biz_tab', 'usage', get_permalink($post_id))); ?>">
						<?php echo \cbd_icon('gauge'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?>
						<?php esc_html_e('Plan Usage', 'community-business-directory'); ?>
					</a>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<!-- ── Tab Content ────────────────────────────────────── -->
	<div class="cbd-profile-body">
		<div class="cbd-profile-main">
			<?php if ($active_tab === 'about') : ?>

				<?php if (has_excerpt($post_id)) : ?>
					<!-- Tagline / slogan — shown across every tab. -->
					<p class="cbd-profile-tagline"><?php echo esc_html(\cbd_label(get_the_excerpt())); ?></p>
				<?php endif; ?>

				<!-- About -->
				<div class="cbd-profile-section">
					<div class="cbd-profile-description">
						<?php the_content(); ?>
					</div>
				</div>

				<!-- Opening Hours -->
				<?php if ($hours || $can_manage) : ?>
					<div class="cbd-profile-section">
						<div class="cbd-section-head">
							<h3><?php esc_html_e('Opening Hours', 'community-business-directory'); ?></h3>
							<?php if (\cbd_user_can($post_id, 'edit')) : ?>
								<button type="button" class="cbd-btn cbd-btn-outline cbd-btn-sm" data-cbd-open="#cbd-hours-modal">✎ <?php esc_html_e('Edit Hours', 'community-business-directory'); ?></button>
							<?php endif; ?>
						</div>
						<?php if ($hours) : ?>
							<div class="cbd-hours-table">
								<?php foreach ($days_order as $day) :
									if (! isset($hours[$day])) continue;
									$h       = $hours[$day];
									$is_open = ! empty($h['open']);
									$is_today = $day === $today_key;
								?>
									<div class="cbd-hours-row <?php echo $is_today ? 'today' : ''; ?>">
										<span class="cbd-hours-day"><?php echo esc_html(ucfirst($day)); ?></span>
										<span class="cbd-hours-time">
											<?php if ($is_open) : ?>
												<?php echo esc_html($h['from'] ?? '09:00'); ?> – <?php echo esc_html($h['to'] ?? '17:00'); ?>
											<?php else : ?>
												<em><?php esc_html_e('Closed', 'community-business-directory'); ?></em>
											<?php endif; ?>
										</span>
										<?php if ($is_today) : ?>
											<span class="cbd-hours-today-badge"><?php esc_html_e('Today', 'community-business-directory'); ?></span>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p class="cbd-hours-empty"><?php esc_html_e('No opening hours set yet.', 'community-business-directory'); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

			<?php elseif ($active_tab === 'gallery') :
				$albums = \cbd_gallery_albums($post_id);
			?>
				<div class="cbd-profile-section">
					<div class="cbd-section-head">
						<?php if (\cbd_user_can($post_id, 'gallery')) : ?>
							<button type="button" class="cbd-btn cbd-btn-primary cbd-btn-sm" data-cbd-open="#cbd-album-modal">＋ <?php esc_html_e('Add Album', 'community-business-directory'); ?></button>
						<?php endif; ?>
					</div>
					<?php if ($albums) : ?>
						<?php foreach ($albums as $album_name => $images) : ?>
							<div class="cbd-gallery-album">
								<h3 class="cbd-album-title"><?php echo esc_html($album_name); ?> <span class="cbd-album-count"><?php echo count($images); ?></span></h3>
								<div class="cbd-gallery-grid cbd-gallery-view">
									<?php foreach ($images as $img) :
										$thumb = wp_get_attachment_image_src($img->ID, 'medium');
										$full  = wp_get_attachment_image_url($img->ID, 'full');
										$cap   = \cbd_label($img->post_excerpt ?: $img->post_title);
									?>
										<a class="cbd-gallery-item cbd-lightbox-trigger" href="<?php echo esc_url($full); ?>"
											data-caption="<?php echo esc_attr($cap); ?>">
											<img src="<?php echo esc_url($thumb[0] ?? $full); ?>" alt="<?php echo esc_attr($cap); ?>" loading="lazy">
										</a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="cbd-empty-state"><span class="cbd-empty-icon">🖼️</span>
							<p><?php esc_html_e('No photos in the gallery yet.', 'community-business-directory'); ?></p>
						</div>
					<?php endif; ?>
				</div>

			<?php elseif ($active_tab === 'posts') :
				// Strictly this business's posts only — never other businesses owned
				// by the same person (filtered on the _cbd_business_id meta).
				// The create box shows for owners + delegates with the "posts" cap.
				$is_owner   = \cbd_user_can($post_id, 'posts');
				$feed_posts = get_posts([
					'post_type'      => 'cbd_business_post',
					'post_status'    => 'publish',
					'posts_per_page' => 20,
					'meta_query'     => [['key' => '_cbd_business_id', 'value' => $post_id, 'compare' => '=']],
				]);
			?>
				<div class="cbd-profile-section">
					<?php if ($is_owner) : ?>
						<div class="cbd-post-create-box">
							<h3><?php esc_html_e('Create a Post', 'community-business-directory'); ?></h3>
							<form id="cbd-business-post-form" class="cbd-form" enctype="multipart/form-data">
								<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
								<input type="hidden" name="action" value="cbd_create_business_post">
								<input type="hidden" name="business_id" value="<?php echo esc_attr($post_id); ?>">
								<div class="cbd-form-row">
									<label><?php esc_html_e('Post Type', 'community-business-directory'); ?></label>
									<div class="cbd-post-type-pills">
										<?php foreach (
											[
												'update'      => '📢 ' . __('Update',      'community-business-directory'),
												'news'        => '📰 ' . __('News',        'community-business-directory'),
												'product'     => '🛍 ' .  __('New Product', 'community-business-directory'),
												'event_promo' => '🎉 ' . __('Promotion',  'community-business-directory'),
												'community'   => '🤝 ' . __('Community',  'community-business-directory'),
											] as $value => $label
										) : ?>
											<label class="cbd-type-pill">
												<input type="radio" name="post_type_label" value="<?php echo esc_attr($value); ?>" <?php echo $value === 'update' ? 'checked' : ''; ?>>
												<?php echo esc_html($label); ?>
											</label>
										<?php endforeach; ?>
									</div>
								</div>
								<div class="cbd-form-row">
									<label><?php esc_html_e('Title', 'community-business-directory'); ?> <span class="req">*</span></label>
									<input type="text" name="post_title" required placeholder="<?php esc_attr_e('What\'s the headline?', 'community-business-directory'); ?>">
								</div>
								<div class="cbd-form-row">
									<label><?php esc_html_e('Content', 'community-business-directory'); ?> <span class="req">*</span></label>
									<textarea name="post_content" rows="4" required placeholder="<?php esc_attr_e('Share your update with the community…', 'community-business-directory'); ?>"></textarea>
								</div>
								<div class="cbd-form-row">
									<label><?php esc_html_e('Featured Image', 'community-business-directory'); ?></label>
									<input type="file" name="post_image" accept="image/*" data-crop-aspect="1.7778" data-crop-w="1200" data-crop-h="675">
									<small><?php esc_html_e('Cropped to 16:9 (1200×675).', 'community-business-directory'); ?></small>
								</div>
								<div class="cbd-form-actions">
									<button type="submit" class="cbd-btn cbd-btn-primary">
										<span class="btn-text"><?php esc_html_e('Publish Post', 'community-business-directory'); ?></span>
										<span class="btn-loading" style="display:none;"><?php esc_html_e('Publishing…', 'community-business-directory'); ?></span>
									</button>
								</div>
								<div class="cbd-form-msg" style="display:none;"></div>
							</form>
						</div>
					<?php endif; ?>

					<div class="cbd-feed-list cbd-posts-feed" id="cbd-news-feed-list">
						<?php foreach ($feed_posts as $fp) {
							echo \CBD\Frontend\Shortcodes\FeedShortcode::render_item((int) $fp->ID);
						} ?>
					</div>
					<div class="cbd-empty-state cbd-feed-empty" <?php echo $feed_posts ? ' style="display:none;"' : ''; ?>>
						<span class="cbd-empty-icon">📰</span>
						<p><?php esc_html_e('No posts yet.', 'community-business-directory'); ?></p>
					</div>
				</div>

			<?php elseif ($active_tab === 'events') :
				// Strictly this business's events (joined on the cbd_events table).
				$event_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}cbd_events WHERE business_id = %d", $post_id));
				$events_query = new WP_Query([
					'post_type'      => 'cbd_event',
					'post_status'    => 'publish',
					'posts_per_page' => 12,
					'post__in'       => $event_ids ? array_map('intval', $event_ids) : [0],
					'orderby'        => 'date',
					'order'          => 'DESC',
				]);
			?>
				<div class="cbd-profile-section">
					<div class="cbd-section-head">
						<?php if (\cbd_user_can($post_id, 'events')) : ?>
							<button type="button" class="cbd-btn cbd-btn-primary cbd-btn-sm" id="cbd-new-event-btn">＋ <?php esc_html_e('Add Event', 'community-business-directory'); ?></button>
						<?php endif; ?>
					</div>
					<?php if ($events_query->have_posts()) : ?>
						<div class="cbd-events-grid">
							<?php while ($events_query->have_posts()) : $events_query->the_post();
								$ev = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_events WHERE post_id = %d", get_the_ID()), ARRAY_A) ?? [];
								$start = ! empty($ev['start_date']) ? new DateTime($ev['start_date']) : null;
							?>
								<div class="cbd-event-card" id="cbd-item-<?php echo esc_attr(get_the_ID()); ?>">
									<?php if (has_post_thumbnail()) : ?>
										<a href="<?php the_permalink(); ?>" class="cbd-event-img-wrap">
											<?php the_post_thumbnail('medium', ['class' => 'cbd-event-img', 'loading' => 'lazy']); ?>
										</a>
									<?php else : ?>
										<div class="cbd-event-img cbd-event-img-ph"></div>
									<?php endif; ?>

									<?php if ($start) : ?>
										<div class="cbd-event-date-badge">
											<span class="cbd-event-month"><?php echo esc_html($start->format('M')); ?></span>
											<span class="cbd-event-day"><?php echo esc_html($start->format('d')); ?></span>
										</div>
									<?php endif; ?>

									<?php if (! empty($ev['is_free'])) : ?>
										<span class="cbd-badge cbd-badge-free"><?php esc_html_e('Free', 'community-business-directory'); ?></span>
									<?php elseif (! empty($ev['ticket_price'])) : ?>
										<span class="cbd-badge cbd-badge-paid"><?php echo esc_html(get_option('cbd_currency_symbol', '£') . number_format((float) $ev['ticket_price'], 2)); ?></span>
									<?php endif; ?>

									<?php echo cbd_card_business_info((int) ($ev['business_id'] ?? 0)); ?>

									<div class="cbd-event-body">
										<h3 class="cbd-event-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

										<?php if ($start) : ?>
											<p class="cbd-event-meta">
												<span><?php echo \cbd_icon('clock-outline'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span><span><?php echo esc_html($start->format('D j M Y, g:ia')); ?></span>
											</p>
										<?php endif; ?>

										<?php if (! empty($ev['venue_name'])) : ?>
											<p class="cbd-event-venue"><span><?php echo \cbd_icon('map-marker'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span><span><?php echo esc_html($ev['venue_name']); ?></span></p>
										<?php endif; ?>

										<p class="cbd-event-excerpt"><?php echo esc_html(wp_trim_words(get_the_excerpt(), 15)); ?></p>

										<div class="cbd-event-footer">
											<a href="<?php the_permalink(); ?>" class="cbd-btn cbd-btn-sm"><?php esc_html_e('Learn More', 'community-business-directory'); ?></a>
											<?php if (! empty($ev['ticket_url'])) echo '<a href="' . esc_url($ev['ticket_url']) . '" class="cbd-btn cbd-btn-sm cbd-btn-outline" target="_blank" rel="noopener">' . esc_html__('Get Tickets', 'community-business-directory') . '</a>'; ?>
										</div>
									</div>
								</div>
							<?php endwhile;
							wp_reset_postdata(); ?>
						</div>
					<?php else : ?>
						<div class="cbd-empty-state"><span class="cbd-empty-icon">📅</span>
							<p><?php esc_html_e('No events yet.', 'community-business-directory'); ?></p>
						</div>
					<?php endif; ?>
				</div>

			<?php elseif ($active_tab === 'promotions') :
				// Strictly this business's promotions (joined on the cbd_promotions table).
				$promo_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}cbd_promotions WHERE business_id = %d", $post_id));
				$promo_query = new WP_Query([
					'post_type'      => 'cbd_promotion',
					'post_status'    => 'publish',
					'posts_per_page' => 12,
					'post__in'       => $promo_ids ? array_map('intval', $promo_ids) : [0],
					'orderby'        => 'date',
					'order'          => 'DESC',
				]);
			?>
				<div class="cbd-profile-section">
					<div class="cbd-section-head">
						<?php if (\cbd_user_can($post_id, 'promotions')) : ?>
							<button type="button" class="cbd-btn cbd-btn-primary cbd-btn-sm" id="cbd-new-promo-btn">＋ <?php esc_html_e('Add Promotion', 'community-business-directory'); ?></button>
						<?php endif; ?>
					</div>
					<?php if ($promo_query->have_posts()) : ?>
						<div class="cbd-promos-grid">
							<?php while ($promo_query->have_posts()) : $promo_query->the_post();
								$pr     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_promotions WHERE post_id = %d", get_the_ID()), ARRAY_A) ?? [];
								$today  = gmdate('Y-m-d');
								$expiring = ! empty($pr['expiry_date']) && strtotime($pr['expiry_date']) < strtotime('+7 days') && strtotime($pr['expiry_date']) >= strtotime($today);
							?>
								<div class="cbd-promo-card <?php echo $expiring ? 'is-expiring' : ''; ?>" id="cbd-item-<?php echo esc_attr(get_the_ID()); ?>">
									<?php if ($expiring) : ?>
										<span class="cbd-badge cbd-badge-expiring"><?php esc_html_e('Expiring Soon', 'community-business-directory'); ?></span>
									<?php endif; ?>
									<?php if (! empty($pr['discount_value'])) : ?>
										<div class="cbd-promo-discount">
											<?php echo esc_html($pr['discount_value']); ?><?php echo $pr['discount_type'] === 'percent' ? '%' : esc_html(get_option('cbd_currency_symbol', '£')); ?>
											<span><?php esc_html_e('OFF', 'community-business-directory'); ?></span>
										</div>
									<?php endif; ?>
									<h3 class="cbd-promo-title"><?php the_title(); ?></h3>
									<p class="cbd-promo-desc"><?php echo wp_trim_words(get_the_excerpt(), 15); ?></p>
									<?php if (! empty($pr['coupon_code'])) : ?>
										<div class="cbd-promo-code-wrap">
											<span class="cbd-promo-code"><?php echo esc_html($pr['coupon_code']); ?></span>
											<button class="cbd-copy-code" data-code="<?php echo esc_attr($pr['coupon_code']); ?>"><?php esc_html_e('Copy', 'community-business-directory'); ?></button>
										</div>
									<?php endif; ?>
									<?php if (! empty($pr['expiry_date'])) echo '<p class="cbd-promo-expiry">⏰ ' . esc_html(date_i18n(get_option('date_format'), strtotime($pr['expiry_date']))) . '</p>'; ?>
									<?php if (! empty($pr['cta_url'])) echo '<a href="' . esc_url($pr['cta_url']) . '" class="cbd-btn cbd-btn-primary cbd-btn-full" target="_blank" rel="noopener">' . esc_html($pr['cta_text'] ?: __('Get Offer', 'community-business-directory')) . '</a>'; ?>
									<?php echo cbd_card_business_info((int) ($pr['business_id'] ?? 0)); ?>
								</div>
							<?php endwhile;
							wp_reset_postdata(); ?>
						</div>
					<?php else : ?>
						<div class="cbd-empty-state"><span class="cbd-empty-icon">🏷️</span>
							<p><?php esc_html_e('No active promotions.', 'community-business-directory'); ?></p>
						</div>
					<?php endif; ?>
				</div>

			<?php elseif ($active_tab === 'reviews') :
				$reviews = $wpdb->get_results($wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cbd_reviews WHERE business_id = %d AND status = 'approved' ORDER BY created_at DESC",
					$post_id
				));
			?>
				<div class="cbd-profile-section">
					<div class="cbd-reviews-list">
						<?php foreach ((array) $reviews as $r) echo \CBD\Frontend\Shortcodes\ReviewsShortcode::render_item($r); ?>
					</div>
					<p class="cbd-no-reviews" <?php echo $reviews ? ' style="display:none;"' : ''; ?>><?php esc_html_e('No reviews yet — be the first!', 'community-business-directory'); ?></p>

					<?php if (is_user_logged_in()) : ?>
						<div class="cbd-review-form-wrap">
							<h4><?php esc_html_e('Leave a Review', 'community-business-directory'); ?></h4>
							<form id="cbd-review-form" class="cbd-form">
								<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
								<input type="hidden" name="action" value="cbd_submit_review">
								<input type="hidden" name="business_id" value="<?php echo esc_attr($post_id); ?>">
								<div class="cbd-form-row">
									<label><?php esc_html_e('Rating', 'community-business-directory'); ?> <span class="req">*</span></label>
									<div class="cbd-star-picker" id="cbd-star-picker">
										<?php for ($i = 5; $i >= 1; $i--) : ?>
											<input type="radio" name="review_rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
											<label for="star<?php echo $i; ?>" title="<?php echo $i; ?> stars">★</label>
										<?php endfor; ?>
									</div>
								</div>
								<div class="cbd-form-row">
									<label><?php esc_html_e('Title', 'community-business-directory'); ?></label>
									<input type="text" name="review_title">
								</div>
								<div class="cbd-form-row">
									<label><?php esc_html_e('Your Review', 'community-business-directory'); ?> <span class="req">*</span></label>
									<textarea name="review_content" rows="4" required data-no-wysiwyg></textarea>
								</div>
								<button type="submit" class="cbd-btn cbd-btn-primary">
									<span class="btn-text"><?php esc_html_e('Submit Review', 'community-business-directory'); ?></span>
									<span class="btn-loading" style="display:none;"><?php esc_html_e('Submitting…', 'community-business-directory'); ?></span>
								</button>
								<div class="cbd-form-msg" style="display:none;"></div>
							</form>
						</div>
					<?php else : ?>
						<p><?php printf(wp_kses(__('<a href="%s">Log in</a> to leave a review.', 'community-business-directory'), ['a' => ['href' => []]]), esc_url(wp_login_url(get_permalink()))); ?></p>
					<?php endif; ?>
				</div>

			<?php elseif ($active_tab === 'edit' && \cbd_user_can($post_id, 'edit')) :
				$all_cats = get_terms(['taxonomy' => 'cbd_category', 'hide_empty' => false]);
				$cur_cats = wp_get_post_terms($post_id, 'cbd_category', ['fields' => 'ids']);
				$logo_url = get_the_post_thumbnail_url($post_id, 'thumbnail') ?: '';
				$cover_pv = $cover_id ? (wp_get_attachment_image_url($cover_id, 'large') ?: '') : '';
			?>
				<div class="cbd-profile-section">
					<h2><?php esc_html_e('Page Profile', 'community-business-directory'); ?></h2>
					<form id="cbd-edit-profile-form" class="cbd-form is-locked" data-cbd-lockable enctype="multipart/form-data">
						<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
						<input type="hidden" name="action" value="cbd_update_profile">
						<input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
						<div class="cbd-form-cols cbd-media-cols">
							<div class="cbd-form-row">
								<label><?php esc_html_e('Logo', 'community-business-directory'); ?></label>
								<div class="cbd-media-field">
									<div class="cbd-media-thumb cbd-media-logo" <?php echo $logo_url ? ' style="background-image:url(' . esc_url($logo_url) . ');background-size:cover"' : ''; ?>></div>
									<input type="file" name="biz_logo" accept="image/*" data-crop-aspect="1" data-crop-w="400" data-crop-h="400">
								</div>
							</div>
							<div class="cbd-form-row">
								<label><?php esc_html_e('Cover Photo', 'community-business-directory'); ?></label>
								<div class="cbd-media-field">
									<div class="cbd-media-thumb cbd-media-cover" <?php echo $cover_pv ? ' style="background-image:url(' . esc_url($cover_pv) . ');background-size:cover"' : ''; ?>></div>
									<input type="file" name="biz_cover" accept="image/*" data-crop-aspect="3" data-crop-w="1200" data-crop-h="400">
								</div>
							</div>
						</div>
						<div class="cbd-form-row">
							<label><?php esc_html_e('Business Name', 'community-business-directory'); ?> <span class="req">*</span></label>
							<input type="text" name="biz_name" value="<?php echo esc_attr($post->post_title); ?>" required>
						</div>
						<div class="cbd-form-row">
							<label><?php esc_html_e('Tagline / Slogan', 'community-business-directory'); ?></label>
							<input type="text" name="biz_tagline" value="<?php echo esc_attr($post->post_excerpt); ?>">
						</div>
						<div class="cbd-form-row">
							<label><?php esc_html_e('Category', 'community-business-directory'); ?></label>
							<select name="biz_category">
								<option value=""><?php esc_html_e('— Select —', 'community-business-directory'); ?></option>
								<?php if (! is_wp_error($all_cats)) foreach ($all_cats as $cat) : ?>
									<option value="<?php echo esc_attr($cat->term_id); ?>" <?php echo in_array($cat->term_id, (array) $cur_cats, true) ? 'selected' : ''; ?>><?php echo esc_html(\cbd_label($cat->name)); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="cbd-form-row">
							<label><?php esc_html_e('Description', 'community-business-directory'); ?></label>
							<textarea name="biz_description" rows="4"><?php echo esc_textarea($post->post_content); ?></textarea>
						</div>
						<div class="cbd-form-cols">
							<div class="cbd-form-row"><label><?php esc_html_e('Email', 'community-business-directory'); ?></label><input type="email" name="biz_email" value="<?php echo esc_attr($meta['email'] ?? ''); ?>"></div>
							<div class="cbd-form-row"><label><?php esc_html_e('Phone', 'community-business-directory'); ?></label><input type="tel" name="biz_phone" value="<?php echo esc_attr($meta['phone'] ?? ''); ?>"></div>
						</div>
						<div class="cbd-form-row"><label><?php esc_html_e('Website', 'community-business-directory'); ?></label><input type="url" name="biz_website" value="<?php echo esc_attr($meta['website'] ?? ''); ?>"></div>

						<h4 class="cbd-form-section-title"><?php esc_html_e('Address', 'community-business-directory'); ?></h4>
						<div class="cbd-form-row"><label><?php esc_html_e('Street Address', 'community-business-directory'); ?></label><input type="text" name="biz_address" value="<?php echo esc_attr($meta['address_line1'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g. 103 High Street', 'community-business-directory'); ?>"></div>
						<div class="cbd-form-cols">
							<div class="cbd-form-row"><label><?php esc_html_e('City', 'community-business-directory'); ?></label><input type="text" name="biz_city" value="<?php echo esc_attr($meta['city'] ?? ''); ?>"></div>
							<div class="cbd-form-row"><label><?php esc_html_e('Postcode / ZIP', 'community-business-directory'); ?></label><input type="text" name="biz_postcode" value="<?php echo esc_attr($meta['postal_code'] ?? ''); ?>"></div>
						</div>

						<h4 class="cbd-form-section-title"><?php esc_html_e('Social Media', 'community-business-directory'); ?></h4>
						<div class="cbd-form-cols">
							<div class="cbd-form-row"><label><?php esc_html_e('Facebook', 'community-business-directory'); ?></label><input type="url" name="social_facebook" value="<?php echo esc_attr($social_links['facebook'] ?? ''); ?>" placeholder="https://facebook.com/yourbusiness"></div>
							<div class="cbd-form-row"><label><?php esc_html_e('Instagram', 'community-business-directory'); ?></label><input type="url" name="social_instagram" value="<?php echo esc_attr($social_links['instagram'] ?? ''); ?>" placeholder="https://instagram.com/yourbusiness"></div>
						</div>
						<div class="cbd-form-cols">
							<div class="cbd-form-row"><label><?php esc_html_e('X / Twitter', 'community-business-directory'); ?></label><input type="url" name="social_twitter" value="<?php echo esc_attr($social_links['twitter'] ?? ''); ?>" placeholder="https://x.com/yourbusiness"></div>
							<div class="cbd-form-row"><label><?php esc_html_e('LinkedIn', 'community-business-directory'); ?></label><input type="url" name="social_linkedin" value="<?php echo esc_attr($social_links['linkedin'] ?? ''); ?>" placeholder="https://linkedin.com/company/yourbusiness"></div>
						</div>

						<div class="cbd-form-actions">
							<button type="button" class="cbd-btn cbd-btn-primary" data-cbd-edit-toggle>✎ <?php esc_html_e('Edit Page', 'community-business-directory'); ?></button>
							<button type="submit" class="cbd-btn cbd-btn-primary" data-cbd-edit-save style="display:none;"><span class="btn-text"><?php esc_html_e('Save Changes', 'community-business-directory'); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e('Saving…', 'community-business-directory'); ?></span></button>
							<button type="button" class="cbd-btn cbd-btn-outline" data-cbd-edit-cancel style="display:none;"><?php esc_html_e('Cancel', 'community-business-directory'); ?></button>
						</div>
						<div class="cbd-form-msg" style="display:none;"></div>
					</form>
				</div>

			<?php elseif ($active_tab === 'delegate' && \cbd_is_business_owner($post_id)) : ?>
				<div class="cbd-profile-section">
					<h2><?php esc_html_e('Delegate Access', 'community-business-directory'); ?></h2>
					<p class="cbd-section-intro"><?php esc_html_e('Invite trusted team members to help manage this business. Enter their email and we\'ll send an invitation — they get access once they accept (a new account is created automatically if they don\'t have one). Then choose exactly what each delegate can do. Only you, the owner, can manage delegates.', 'community-business-directory'); ?></p>
					<form id="cbd-delegate-form" class="cbd-form" data-business-id="<?php echo esc_attr($post_id); ?>">
						<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
						<div class="cbd-form-row cbd-delegate-add">
							<input type="email" name="user" placeholder="<?php esc_attr_e('Email address…', 'community-business-directory'); ?>" autocomplete="off">
							<button type="submit" class="cbd-btn cbd-btn-primary"><?php esc_html_e('Send Invitation', 'community-business-directory'); ?></button>
						</div>
						<div class="cbd-form-msg" style="display:none;"></div>
					</form>
					<div id="cbd-delegate-list-wrap"><?php echo \CBD\Frontend\AjaxHandler::render_delegate_list($post_id); ?></div>
				</div>

			<?php elseif ($active_tab === 'analytics' && $can_manage) :
				// Analytics panel — opened from the tab-strip settings gear.
				$plan  = $meta['plan'] ?? 'free';
				// Build a continuous 7-day window (today back 6 days). Analytics rows
				// only exist for days that had activity, so we index the DB results by
				// date and fill the gaps with zero rows — otherwise "Last 7 Days" shows
				// a ragged list that skips quiet days and omits today. Anchor on the UTC
				// gmdate the view tracker writes (see top of file) so keys line up.
				$today_utc = gmdate('Y-m-d');
				$window_start = gmdate('Y-m-d', strtotime($today_utc . ' -6 days'));
				$rows  = $wpdb->get_results($wpdb->prepare(
					"SELECT date, views, new_followers, new_reviews FROM {$wpdb->prefix}cbd_analytics
					 WHERE business_id = %d AND date >= %s ORDER BY date ASC",
					$post_id,
					$window_start
				));
				$by_date = [];
				foreach ($rows as $r) {
					$by_date[$r->date] = $r;
				}
				$daily = [];
				for ($i = 6; $i >= 0; $i--) {
					$d = gmdate('Y-m-d', strtotime($today_utc . " -{$i} days"));
					$daily[] = $by_date[$d] ?? (object) [
						'date'          => $d,
						'views'         => 0,
						'new_followers' => 0,
						'new_reviews'   => 0,
					];
				}
				// Metronic-8 stat tiles: icon + accent per metric, scaled below.
				$max_views   = 0;
				foreach ($daily as $dd) {
					$max_views = max($max_views, (int) $dd->views);
				}
				$rating_val  = (float) ($meta['rating_avg'] ?? 0);
				$stat_cards  = [
					['icon' => 'chart-bar',        'accent' => 'primary', 'value' => number_format((int) ($meta['view_count'] ?? 0)),     'label' => __('Total Profile Views', 'community-business-directory')],
					['icon' => 'account-multiple', 'accent' => 'success', 'value' => number_format((int) ($meta['follower_count'] ?? 0)), 'label' => __('Total Followers', 'community-business-directory')],
					['icon' => 'thumb-up-outline', 'accent' => 'info',    'value' => number_format((int) ($meta['review_count'] ?? 0)),   'label' => __('Total Reviews', 'community-business-directory')],
					['icon' => 'star',             'accent' => 'warning', 'value' => $rating_val > 0 ? number_format($rating_val, 1) : '—', 'label' => __('Average Rating', 'community-business-directory')],
				];
			?>
				<div class="cbd-profile-section cbd-mt-analytics">
					<div class="cbd-mt-head">
						<h2 class="cbd-mt-title"><?php esc_html_e('Analytics', 'community-business-directory'); ?></h2>
						<span class="cbd-mt-sub"><?php esc_html_e('Performance overview for your business profile', 'community-business-directory'); ?></span>
					</div>
					<div class="cbd-mt-stats">
						<?php foreach ($stat_cards as $sc) : ?>
							<div class="cbd-mt-stat cbd-mt-accent-<?php echo esc_attr($sc['accent']); ?>">
								<span class="cbd-mt-stat-icon"><?php echo \cbd_icon($sc['icon']); ?></span>
								<span class="cbd-mt-stat-body">
									<span class="cbd-mt-stat-val"><?php echo esc_html($sc['value']); ?></span>
									<span class="cbd-mt-stat-label"><?php echo esc_html($sc['label']); ?></span>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ($daily) : ?>
						<div class="cbd-mt-card">
							<div class="cbd-mt-card-head">
								<div>
									<h3 class="cbd-mt-card-title"><?php esc_html_e('Last 7 Days', 'community-business-directory'); ?></h3>
									<span class="cbd-mt-card-sub"><?php esc_html_e('Daily activity breakdown', 'community-business-directory'); ?></span>
								</div>
								<span class="cbd-mt-chip"><?php echo \cbd_icon('calendar-month'); ?><?php esc_html_e('Weekly', 'community-business-directory'); ?></span>
							</div>
							<div class="cbd-mt-table-wrap">
								<table class="cbd-mt-table">
									<thead>
										<tr>
											<th><?php esc_html_e('Date', 'community-business-directory'); ?></th>
											<th><?php esc_html_e('Views', 'community-business-directory'); ?></th>
											<th><?php esc_html_e('New Followers', 'community-business-directory'); ?></th>
											<th><?php esc_html_e('New Reviews', 'community-business-directory'); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($daily as $d) :
											$d_views = (int) $d->views;
											$d_nf    = (int) $d->new_followers;
											$d_nr    = (int) $d->new_reviews;
											$d_pct   = $max_views > 0 ? round(($d_views / $max_views) * 100) : 0;
											$is_today = ($d->date === $today_utc);
										?>
											<tr<?php echo $is_today ? ' class="cbd-mt-row-today"' : ''; ?>>
												<td>
													<span class="cbd-mt-date"><?php echo esc_html(gmdate('D j M', strtotime($d->date))); ?></span>
													<?php if ($is_today) : ?><span class="cbd-mt-badge cbd-mt-badge-today"><?php esc_html_e('Today', 'community-business-directory'); ?></span><?php endif; ?>
												</td>
												<td>
													<div class="cbd-mt-views">
														<span class="cbd-mt-views-num"><?php echo esc_html(number_format($d_views)); ?></span>
														<span class="cbd-mt-bar"><span class="cbd-mt-bar-fill" style="width:<?php echo esc_attr($d_pct); ?>%"></span></span>
													</div>
												</td>
												<td>
													<?php if ($d_nf > 0) : ?>
														<span class="cbd-mt-badge cbd-mt-badge-success">+<?php echo esc_html($d_nf); ?></span>
													<?php else : ?>
														<span class="cbd-mt-badge cbd-mt-badge-muted">0</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ($d_nr > 0) : ?>
														<span class="cbd-mt-badge cbd-mt-badge-info">+<?php echo esc_html($d_nr); ?></span>
													<?php else : ?>
														<span class="cbd-mt-badge cbd-mt-badge-muted">0</span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					<?php endif; ?>
					<?php if ($plan === 'free' || $plan === 'basic') : ?>
						<div class="cbd-mt-upsell">
							<span class="cbd-mt-upsell-icon"><?php echo \cbd_icon('chart-bar'); ?></span>
							<div class="cbd-mt-upsell-text">
								<strong><?php esc_html_e('Unlock detailed analytics', 'community-business-directory'); ?></strong>
								<span><?php esc_html_e('Upgrade to Premium or Elite for detailed charts and click tracking analytics.', 'community-business-directory'); ?></span>
							</div>
							<a href="<?php echo esc_url(\cbd_plans_url()); ?>" class="cbd-mt-upsell-btn"><?php esc_html_e('View Plans', 'community-business-directory'); ?></a>
						</div>
					<?php endif; ?>
				</div>

			<?php elseif ($active_tab === 'usage' && $can_manage) :
				// Plan Usage panel — opened from the tab-strip settings gear.
				$plan        = $meta['plan'] ?? 'free';
				$plan_row    = $wpdb->get_row($wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cbd_membership_plans WHERE slug = %s",
					$plan
				));
				$plan_label  = $plan_row ? $plan_row->name : ucfirst($plan);
				$photos_used = array_sum(array_map('count', \cbd_gallery_albums($post_id)));
				$month_start = date('Y-m-01 00:00:00', current_time('timestamp'));
				$events_used = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}cbd_events e
					 INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
					 WHERE e.business_id = %d AND p.post_status = 'publish' AND p.post_date >= %s",
					$post_id,
					$month_start
				));
				$promos_used = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}cbd_promotions pr
					 INNER JOIN {$wpdb->posts} p ON p.ID = pr.post_id
					 WHERE pr.business_id = %d AND p.post_status = 'publish'",
					$post_id
				));
				$gauges = [
					['label' => __('Photos',            'community-business-directory'), 'used' => (int) $photos_used, 'limit' => (int) ($plan_row->image_limit ?? 0)],
					['label' => __('Events this month', 'community-business-directory'), 'used' => $events_used,        'limit' => (int) ($plan_row->event_limit ?? 0)],
					['label' => __('Active promotions', 'community-business-directory'), 'used' => $promos_used,        'limit' => (int) ($plan_row->promo_limit ?? 0)],
				];
				$plan_features = [
					[__('Advanced analytics',    'community-business-directory'), (int) ($plan_row->show_analytics ?? 0)],
					[__('Featured listing',      'community-business-directory'), (int) ($plan_row->is_featured    ?? 0)],
					[__('Promotions & coupons',  'community-business-directory'), (int) ($plan_row->can_promote    ?? 0)],
				];
				$is_top_plan = ($plan === 'elite');
			?>
				<div class="cbd-profile-section cbd-mt-analytics cbd-mt-usage">
					<div class="cbd-mt-head cbd-mt-head-row">
						<div>
							<h2 class="cbd-mt-title"><?php esc_html_e('Plan Usage', 'community-business-directory'); ?></h2>
							<span class="cbd-mt-sub"><?php esc_html_e('How much of your allowance you are using', 'community-business-directory'); ?></span>
						</div>
						<span class="cbd-mt-plan-pill cbd-mt-plan-<?php echo esc_attr($plan); ?>">
							<?php echo \cbd_icon($is_top_plan ? 'star' : 'shield-account'); ?>
							<?php
							printf(
								/* translators: %s: plan name */
								esc_html__('%s plan', 'community-business-directory'),
								esc_html($plan_label)
							);
							?>
						</span>
					</div>

					<div class="cbd-mt-gauges">
						<?php foreach ($gauges as $g) :
							$used      = max(0, (int) $g['used']);
							$limit     = (int) $g['limit'];
							$unlimited = (0 === $limit);
							$pct       = $unlimited ? 100 : min(100, (int) round($used / max(1, $limit) * 100));
							$over      = (! $unlimited && $used >= $limit);
							$state     = $unlimited ? 'info' : ($over ? 'danger' : ($pct >= 80 ? 'warning' : 'success'));
						?>
							<div class="cbd-mt-gauge cbd-mt-accent-<?php echo esc_attr($state); ?>">
								<div class="cbd-mt-gauge-head">
									<span class="cbd-mt-gauge-label"><?php echo esc_html($g['label']); ?></span>
									<span class="cbd-mt-gauge-count"><strong><?php echo esc_html(number_format_i18n($used)); ?></strong><?php
										echo $unlimited
											? ' · ' . esc_html__('Unlimited', 'community-business-directory')
											: ' / ' . esc_html(number_format_i18n($limit));
									?></span>
								</div>
								<div class="cbd-mt-gauge-bar">
									<span class="cbd-mt-gauge-fill" style="width:<?php echo (int) $pct; ?>%;"></span>
								</div>
								<?php if ($over) : ?>
									<span class="cbd-mt-gauge-warn"><?php esc_html_e('Limit reached — upgrade to add more.', 'community-business-directory'); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="cbd-mt-card cbd-mt-features-card">
						<div class="cbd-mt-card-head">
							<div>
								<h3 class="cbd-mt-card-title"><?php esc_html_e("What's included", 'community-business-directory'); ?></h3>
								<span class="cbd-mt-card-sub"><?php
									printf(
										/* translators: %s: plan name */
										esc_html__('%s plan features', 'community-business-directory'),
										esc_html($plan_label)
									);
								?></span>
							</div>
						</div>
						<ul class="cbd-mt-features">
							<?php foreach ($plan_features as [$label, $on]) : ?>
								<li class="cbd-mt-feature <?php echo $on ? 'is-on' : 'is-off'; ?>">
									<span class="cbd-mt-feature-icon"><?php echo $on ? '✓' : '✕'; ?></span>
									<span><?php echo esc_html($label); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>

					<?php if (! $is_top_plan) : ?>
						<div class="cbd-mt-upsell">
							<span class="cbd-mt-upsell-icon"><?php echo \cbd_icon('star'); ?></span>
							<div class="cbd-mt-upsell-text">
								<strong><?php esc_html_e('Need more headroom?', 'community-business-directory'); ?></strong>
								<span><?php esc_html_e('Upgrade your plan for higher limits and extra features.', 'community-business-directory'); ?></span>
							</div>
							<a href="<?php echo esc_url(\cbd_plans_url()); ?>" class="cbd-mt-upsell-btn"><?php esc_html_e('View Plans', 'community-business-directory'); ?></a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		</div><!-- /.cbd-profile-main -->

		<!-- ── Sidebar ──────────────────────────────────────── -->
		<aside class="cbd-profile-sidebar">

			<?php if (! empty($meta['email']) || ! empty($meta['phone']) || ! empty($meta['website'])) : ?>
				<div class="cbd-sidebar-card">
					<h4><?php esc_html_e('Contact', 'community-business-directory'); ?></h4>
					<?php if (! empty($meta['email'])) : ?>
						<p><a href="mailto:<?php echo esc_attr($meta['email']); ?>">✉️ <?php echo esc_html($meta['email']); ?></a></p>
					<?php endif; ?>
					<?php if (! empty($meta['phone'])) : ?>
						<p><a href="tel:<?php echo esc_attr(preg_replace('/[^+\d]/', '', $meta['phone'])); ?>">📞 <?php echo esc_html($meta['phone']); ?></a></p>
					<?php endif; ?>
					<?php if (! empty($meta['website'])) : ?>
						<p><a href="<?php echo esc_url($meta['website']); ?>" target="_blank" rel="noopener">🌐 <?php echo esc_html(preg_replace('#^https?://#', '', rtrim($meta['website'], '/'))); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if (! empty($meta['address_line1']) || ! empty($meta['city'])) : ?>
				<div class="cbd-sidebar-card">
					<h4><?php esc_html_e('Address', 'community-business-directory'); ?></h4>
					<address>
						<?php if (! empty($meta['address_line1'])) echo esc_html($meta['address_line1']) . '<br>'; ?>
						<?php if (! empty($meta['city'])) echo esc_html($meta['city']); ?>
						<?php if (! empty($meta['postal_code'])) echo ' ' . esc_html($meta['postal_code']); ?>
					</address>
					<?php
					$maps_key = get_option('cbd_google_maps_api_key', '');
					if (! empty($meta['latitude']) && ! empty($meta['longitude']) && $maps_key) :
						$lat = (float) $meta['latitude'];
						$lng = (float) $meta['longitude'];
					?>
						<div class="cbd-inline-map" id="cbd-biz-map" data-lat="<?php echo esc_attr($lat); ?>" data-lng="<?php echo esc_attr($lng); ?>" data-name="<?php echo esc_attr(get_the_title($post_id)); ?>"></div>
						<script>
							function initCbdBizMap() {
								var el = document.getElementById('cbd-biz-map');
								if (!el || typeof google === 'undefined') return;
								var latlng = {
									lat: <?php echo $lat; ?>,
									lng: <?php echo $lng; ?>
								};
								var map = new google.maps.Map(el, {
									zoom: 15,
									center: latlng,
									disableDefaultUI: true,
									zoomControl: true
								});
								new google.maps.Marker({
									map: map,
									position: latlng,
									title: el.dataset.name
								});
							}
						</script>
						<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($maps_key); ?>&callback=initCbdBizMap"></script>
					<?php else : ?>
						<a href="https://www.google.com/maps/search/?api=1&query=<?php echo esc_attr(urlencode(($meta['address_line1'] ?? '') . ' ' . ($meta['city'] ?? ''))); ?>"
							class="cbd-btn cbd-btn-sm" target="_blank" rel="noopener" style="margin-top:10px;">
							🗺️ <?php esc_html_e('View on Google Maps', 'community-business-directory'); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if (array_filter($social_links ?? [])) : ?>
				<div class="cbd-sidebar-card">
					<h4><?php esc_html_e('Social Media', 'community-business-directory'); ?></h4>
					<div class="cbd-social-links">
						<?php $icons = ['facebook' => '📘 Facebook', 'instagram' => '📷 Instagram', 'twitter' => '🐦 X / Twitter', 'linkedin' => '💼 LinkedIn'];
						foreach ($icons as $key => $label) :
							if (empty($social_links[$key])) continue;
						?>
							<a href="<?php echo esc_url($social_links[$key]); ?>" class="cbd-social-link" target="_blank" rel="noopener">
								<?php echo esc_html($label); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="cbd-sidebar-card">
				<h4><?php esc_html_e('Share', 'community-business-directory'); ?></h4>
				<div class="cbd-share-btns">
					<?php $share_url = urlencode(get_permalink($post_id));
					$share_title = urlencode(get_the_title($post_id)); ?>
					<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $share_url; ?>" class="cbd-share-btn" target="_blank" rel="noopener">Facebook</a>
					<a href="https://x.com/intent/tweet?url=<?php echo $share_url; ?>&text=<?php echo $share_title; ?>" class="cbd-share-btn" target="_blank" rel="noopener">X / Twitter</a>
					<button class="cbd-share-btn" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo esc_js(get_permalink($post_id)); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy Link',2000);">Copy Link</button>
				</div>
			</div>

		</aside><!-- /.cbd-profile-sidebar -->
	</div><!-- /.cbd-profile-body -->

	<?php if ($can_manage) :
		// The business edit form lives in the "Edit Page" profile tab, and
		// Analytics / Plan Usage render inline in the profile body above; these
		// vars feed the management modals (add event / add promotion) below.
		$today    = gmdate('Y-m-d');
		$sym      = get_option('cbd_currency_symbol', '£');
	?>

		<?php // Analytics & Plan Usage now render inline in the profile body (opened
		// from the tab-strip settings gear), so their modals were removed here. ?>

		<!-- ── Add Album modal ────────────────────────────────── -->
		<div id="cbd-album-modal" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal">
				<button type="button" class="cbd-modal-close" aria-label="<?php esc_attr_e('Close', 'community-business-directory'); ?>">✕</button>
				<h3><?php esc_html_e('Add Gallery Album', 'community-business-directory'); ?></h3>
				<form id="cbd-gallery-form" class="cbd-form" enctype="multipart/form-data">
					<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
					<input type="hidden" name="action" value="cbd_upload_gallery">
					<input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
					<div class="cbd-form-row">
						<label><?php esc_html_e('Album name', 'community-business-directory'); ?></label>
						<input type="text" name="album" list="cbd-album-list" autocomplete="off" placeholder="<?php esc_attr_e('e.g. Storefront, Menu, Events…', 'community-business-directory'); ?>">
						<datalist id="cbd-album-list"><?php foreach (array_keys(\cbd_gallery_albums($post_id)) as $an) : ?><option value="<?php echo esc_attr($an); ?>"></option><?php endforeach; ?></datalist>
					</div>
					<div class="cbd-form-row">
						<label><?php esc_html_e('Photos', 'community-business-directory'); ?></label>
						<input type="file" name="gallery_images[]" accept="image/*" multiple>
						<small><?php esc_html_e('JPG, PNG, WebP — up to 5 at once.', 'community-business-directory'); ?></small>
					</div>
					<div class="cbd-form-actions">
						<button type="submit" class="cbd-btn cbd-btn-primary"><span class="btn-text"><?php esc_html_e('Upload to Album', 'community-business-directory'); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e('Uploading…', 'community-business-directory'); ?></span></button>
					</div>
					<div class="cbd-form-msg" style="display:none;"></div>
				</form>
			</div>
		</div>

		<!-- ── Edit Opening Hours modal ───────────────────────── -->
		<div id="cbd-hours-modal" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal">
				<button type="button" class="cbd-modal-close" aria-label="<?php esc_attr_e('Close', 'community-business-directory'); ?>">✕</button>
				<h3><?php esc_html_e('Edit Opening Hours', 'community-business-directory'); ?></h3>
				<form id="cbd-hours-form" class="cbd-form">
					<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
					<input type="hidden" name="action" value="cbd_save_hours">
					<input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
					<div class="cbd-hours-grid">
						<?php foreach ($days_order as $day) :
							$h = $hours[$day] ?? ['open' => true, 'from' => '09:00', 'to' => '17:00'];
						?>
							<div class="cbd-hours-row">
								<label class="cbd-hours-day">
									<input type="checkbox" name="hours[<?php echo esc_attr($day); ?>][open]" value="1" <?php checked(! empty($h['open'])); ?>>
									<?php echo esc_html(ucfirst($day)); ?>
								</label>
								<input type="time" name="hours[<?php echo esc_attr($day); ?>][from]" value="<?php echo esc_attr($h['from'] ?? '09:00'); ?>">
								<span><?php esc_html_e('to', 'community-business-directory'); ?></span>
								<input type="time" name="hours[<?php echo esc_attr($day); ?>][to]" value="<?php echo esc_attr($h['to'] ?? '17:00'); ?>">
							</div>
						<?php endforeach; ?>
					</div>
					<div class="cbd-form-actions">
						<button type="submit" class="cbd-btn cbd-btn-primary"><span class="btn-text"><?php esc_html_e('Save Hours', 'community-business-directory'); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e('Saving…', 'community-business-directory'); ?></span></button>
					</div>
					<div class="cbd-form-msg" style="display:none;"></div>
				</form>
			</div>
		</div>

		<!-- ── Event modal (shared markup with the dashboard) ─── -->
		<div id="cbd-event-form-wrap" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal">
				<button class="cbd-modal-close" id="cbd-event-form-close">✕</button>
				<h3><?php esc_html_e('Create New Event', 'community-business-directory'); ?></h3>
				<form id="cbd-event-form" class="cbd-form" enctype="multipart/form-data">
					<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
					<input type="hidden" name="action" value="cbd_create_event">
					<input type="hidden" name="business_id" value="<?php echo esc_attr($post_id); ?>">
					<div class="cbd-form-row"><label><?php esc_html_e('Event Title', 'community-business-directory'); ?> <span class="req">*</span></label><input type="text" name="event_title" required></div>
					<div class="cbd-form-row"><label><?php esc_html_e('Description', 'community-business-directory'); ?></label><textarea name="event_description" rows="3"></textarea></div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e('Start', 'community-business-directory'); ?> <span class="req">*</span></label><input type="datetime-local" name="event_start" required></div>
						<div class="cbd-form-row"><label><?php esc_html_e('End', 'community-business-directory'); ?> <span class="req">*</span></label><input type="datetime-local" name="event_end" required></div>
					</div>
					<div class="cbd-form-row"><label><?php esc_html_e('Venue', 'community-business-directory'); ?></label><input type="text" name="event_venue"></div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e('Ticket URL', 'community-business-directory'); ?></label><input type="url" name="event_ticket_url"></div>
						<div class="cbd-form-row"><label><?php esc_html_e('Price', 'community-business-directory'); ?></label><input type="number" name="event_ticket_price" min="0" step="0.01" value="0"></div>
					</div>
					<div class="cbd-form-row"><label><input type="checkbox" name="event_is_free" value="1" checked> <?php esc_html_e('Free event', 'community-business-directory'); ?></label></div>
					<div class="cbd-form-row"><label><?php esc_html_e('Image', 'community-business-directory'); ?></label><input type="file" name="event_image" accept="image/*" data-crop-aspect="1.7778" data-crop-w="1200" data-crop-h="675"></div>
					<div class="cbd-form-actions"><button type="submit" class="cbd-btn cbd-btn-primary"><span class="btn-text"><?php esc_html_e('Create Event', 'community-business-directory'); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e('Creating…', 'community-business-directory'); ?></span></button></div>
					<div class="cbd-form-msg" style="display:none;"></div>
				</form>
			</div>
		</div>

		<!-- ── Promotion modal (shared markup with the dashboard) ─── -->
		<div id="cbd-promo-form-wrap" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal">
				<button class="cbd-modal-close" id="cbd-promo-form-close">✕</button>
				<h3><?php esc_html_e('Create Promotion', 'community-business-directory'); ?></h3>
				<form id="cbd-promo-form" class="cbd-form">
					<?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
					<input type="hidden" name="action" value="cbd_create_promotion">
					<input type="hidden" name="business_id" value="<?php echo esc_attr($post_id); ?>">
					<div class="cbd-form-row"><label><?php esc_html_e('Offer Title', 'community-business-directory'); ?> <span class="req">*</span></label><input type="text" name="promo_title" required></div>
					<div class="cbd-form-row"><label><?php esc_html_e('Description', 'community-business-directory'); ?> <span class="req">*</span></label><textarea name="promo_description" rows="3" required></textarea></div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e('Coupon Code', 'community-business-directory'); ?></label><input type="text" name="promo_code" placeholder="SAVE20"></div>
						<div class="cbd-form-row"><label><?php esc_html_e('Discount', 'community-business-directory'); ?></label>
							<div style="display:flex;gap:8px;"><input type="number" name="promo_discount_value" min="0" placeholder="20" style="flex:1;"><select name="promo_discount_type">
									<option value="percent">%</option>
									<option value="fixed"><?php echo esc_html($sym); ?></option>
								</select></div>
						</div>
					</div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e('Start Date', 'community-business-directory'); ?></label><input type="date" name="promo_start" value="<?php echo esc_attr($today); ?>"></div>
						<div class="cbd-form-row"><label><?php esc_html_e('Expiry Date', 'community-business-directory'); ?></label><input type="date" name="promo_expiry"></div>
					</div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e('CTA Text', 'community-business-directory'); ?></label><input type="text" name="promo_cta_text" value="Get Offer"></div>
						<div class="cbd-form-row"><label><?php esc_html_e('CTA URL', 'community-business-directory'); ?></label><input type="url" name="promo_cta_url"></div>
					</div>
					<div class="cbd-form-actions"><button type="submit" class="cbd-btn cbd-btn-primary"><span class="btn-text"><?php esc_html_e('Publish Promotion', 'community-business-directory'); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e('Saving…', 'community-business-directory'); ?></span></button></div>
					<div class="cbd-form-msg" style="display:none;"></div>
				</form>
			</div>
		</div>
	<?php endif; ?>

</div><!-- /.cbd-profile-wrap -->
