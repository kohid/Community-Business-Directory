<?php

namespace CBD\Frontend\Shortcodes;

use CBD\Frontend\Reactions;

defined('ABSPATH') || exit;

class FeedShortcode
{

	private const TYPE_ICONS = ['cbd_business_post' => '📣', 'cbd_event' => '📅', 'cbd_promotion' => '🏷️', 'cbd_business' => '🎉'];

	private const POST_LABELS = ['update' => '📢 Update', 'news' => '📰 News', 'product' => '🛍 New Product', 'event_promo' => '🎉 Promotion', 'community' => '🤝 Community'];

	/**
	 * Resolve the registered business a feed item belongs to.
	 *
	 * - cbd_business_post → `_cbd_business_id` meta (falls back to the author's first business)
	 * - cbd_event / cbd_promotion → the `business_id` (a business post_id) in the custom table
	 * - cbd_business → the post itself (a "newly joined" card)
	 *
	 * The returned array also carries the data the left-hand "business card"
	 * needs (cover image, category, address, rating, and the current user's
	 * follow/favourite state) so a feed item can render the business beside it.
	 *
	 * @return array{id:int,name:string,url:string,logo:string,cover:string,category:string,address:string,rating_avg:float,review_count:int,is_fol:bool,is_fav:bool,logged_in:bool}|null Null when no published business resolves.
	 */
	private static function owning_business(\WP_Post $post): ?array
	{
		global $wpdb;
		$biz_id = 0;

		switch ($post->post_type) {
			case 'cbd_business':
				$biz_id = (int) $post->ID;
				break;

			case 'cbd_business_post':
				$biz_id = (int) get_post_meta($post->ID, '_cbd_business_id', true);
				if (! $biz_id) {
					$biz_id = (int) $wpdb->get_var($wpdb->prepare(
						"SELECT post_id FROM {$wpdb->prefix}cbd_businesses WHERE owner_id = %d ORDER BY id ASC LIMIT 1",
						(int) $post->post_author
					));
				}
				break;

			case 'cbd_event':
				$biz_id = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT business_id FROM {$wpdb->prefix}cbd_events WHERE post_id = %d",
					$post->ID
				));
				break;

			case 'cbd_promotion':
				$biz_id = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT business_id FROM {$wpdb->prefix}cbd_promotions WHERE post_id = %d",
					$post->ID
				));
				break;
		}

		$valid = $biz_id && get_post_type($biz_id) === 'cbd_business' && get_post_status($biz_id) === 'publish';

		// Fallbacks for items with no registered owner (e.g. Love Inverness imports):
		//  - events    → a venue card built from the event's own image + venue,
		//  - promotions → the site's own "Inverness BID" website business listing.
		if (! $valid) {
			if ($post->post_type === 'cbd_event') {
				return self::event_venue_card($post);
			}
			if ($post->post_type === 'cbd_promotion') {
				$biz_id = (int) get_option('cbd_website_business_id');
				$valid  = $biz_id && get_post_type($biz_id) === 'cbd_business' && get_post_status($biz_id) === 'publish';
			}
		}

		if (! $valid) {
			return null;
		}

		$meta = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d",
			$biz_id
		), ARRAY_A) ?: [];

		$cats = get_the_terms($biz_id, 'cbd_category');
		$cat  = ($cats && ! is_wp_error($cats)) ? \cbd_label($cats[0]->name) : '';

		$user_id = get_current_user_id();
		$is_fav  = $user_id ? (bool) $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}cbd_favorites WHERE user_id = %d AND business_id = %d",
			$user_id,
			$biz_id
		)) : false;
		$is_fol  = $user_id ? (bool) $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}cbd_follows WHERE user_id = %d AND business_id = %d",
			$user_id,
			$biz_id
		)) : false;

		return [
			'id'           => $biz_id,
			'name'         => \cbd_label(get_the_title($biz_id)),
			'url'          => (string) get_permalink($biz_id),
			'logo'         => (string) (get_the_post_thumbnail_url($biz_id, 'thumbnail') ?: ''),
			'cover'        => (string) (get_the_post_thumbnail_url($biz_id, 'medium') ?: ''),
			'category'     => $cat,
			'address'      => \CBD\Frontend\AjaxHandler::format_address($meta),
			'rating_avg'   => (float) ($meta['rating_avg'] ?? 0),
			'review_count' => (int) ($meta['review_count'] ?? 0),
			'is_fol'       => $is_fol,
			'is_fav'       => $is_fav,
			'logged_in'    => (bool) $user_id,
		];
	}

	/**
	 * Build a "business card" for an event that has no registered owner (e.g. a
	 * Love Inverness import). The event's own image stands in for the logo/cover
	 * and its venue stands in for the name/address, so the feed item still gets a
	 * left-hand card. No follow/favourite actions (there's no business to follow).
	 *
	 * @return array{id:int,name:string,url:string,logo:string,cover:string,category:string,address:string,rating_avg:float,review_count:int,is_fol:bool,is_fav:bool,logged_in:bool}|null
	 */
	private static function event_venue_card(\WP_Post $post): ?array
	{
		global $wpdb;
		$ev = $wpdb->get_row($wpdb->prepare(
			"SELECT venue_name, venue_addr FROM {$wpdb->prefix}cbd_events WHERE post_id = %d",
			$post->ID
		), ARRAY_A) ?: [];

		$cover = (string) (get_the_post_thumbnail_url($post->ID, 'medium')    ?: '');
		$logo  = (string) (get_the_post_thumbnail_url($post->ID, 'thumbnail') ?: $cover);
		if (! $cover && ! $logo) {
			return null; // nothing to show — leave the item card-less rather than empty.
		}

		$name = trim((string) ($ev['venue_name'] ?? ''));
		if ($name === '') {
			$name = \cbd_label(get_the_title($post->ID));
		}

		$cats = get_the_terms($post->ID, 'cbd_category');
		$cat  = ($cats && ! is_wp_error($cats)) ? \cbd_label($cats[0]->name) : '';

		return [
			'id'           => 0, // not a real business — suppresses follow/fav + profile links
			'name'         => \cbd_label($name),
			'url'          => (string) get_permalink($post->ID),
			'logo'         => $logo,
			'cover'        => $cover,
			'category'     => $cat,
			'address'      => trim((string) ($ev['venue_addr'] ?? '')),
			'rating_avg'   => 0.0,
			'review_count' => 0,
			'is_fol'       => false,
			'is_fav'       => false,
			'logged_in'    => false,
		];
	}

	/**
	 * Render a single feed item (post / event / promotion / new business) including
	 * its reaction bar. Shared by the feed shortcode, the business profile
	 * "News Feed" tab and the AJAX post-create response so markup stays in sync.
	 */
	public static function render_item(int $post_id, bool $with_reactions = true): string
	{
		$post = get_post($post_id);
		if (! $post) {
			return '';
		}
		$pt = $post->post_type;

		if ($pt === 'cbd_business_post') {
			$type_key = get_post_meta($post_id, '_cbd_post_type', true) ?: 'update';
			$type_txt = self::POST_LABELS[$type_key] ?? '📣 Update';
		} elseif ($pt === 'cbd_business') {
			$type_txt = '🎉 ' . __('New Business', 'community-business-directory');
		} else {
			$obj      = get_post_type_object($pt);
			$type_txt = (self::TYPE_ICONS[$pt] ?? '📌') . ' ' . ($obj ? $obj->labels->singular_name : '');
		}

		// Which registered business posted this, and what did they do?
		$biz   = self::owning_business($post);
		$verbs = [
			'cbd_business_post' => __('posted an update',          'community-business-directory'),
			'cbd_event'         => __('created an event',          'community-business-directory'),
			'cbd_promotion'     => __('added a promotion',         'community-business-directory'),
			'cbd_business'      => __('just joined the directory', 'community-business-directory'),
		];
		$verb = $verbs[$pt] ?? '';

		$time_ago = sprintf(
			/* translators: %s: human-readable time difference */
			__('%s ago', 'community-business-directory'),
			human_time_diff(get_post_time('U', false, $post_id), current_time('timestamp'))
		);

		$permalink = get_permalink($post_id);
		// Title + thumbnail point at the owning business profile, open the matching
		// tab and scroll to this item (e.g. /directory/kolab/?biz_tab=events#cbd-item-42)
		// — never the bare /business-posts/ single. A "new business" card just opens
		// the profile; if no business resolves we fall back to the item permalink.
		$tab_map = [
			'cbd_business_post' => 'posts',
			'cbd_event'         => 'events',
			'cbd_promotion'     => 'promotions',
		];
		if ($biz && isset($tab_map[$pt])) {
			$item_url = add_query_arg('biz_tab', $tab_map[$pt], $biz['url']) . '#cbd-item-' . $post_id;
		} elseif ($biz) {
			$item_url = $biz['url'];
		} else {
			$item_url = $permalink;
		}

		$plain     = wp_strip_all_tags($post->post_content);
		$excerpt   = wp_trim_words(has_excerpt($post_id) ? get_the_excerpt($post_id) : $plain, 28);
		$full_html = wp_kses_post(wpautop($post->post_content));

		// Event / promotion items surface their structured details at the bottom
		// of the Read More modal (which renders .cbd-feed-full), and their Visit
		// button deep-links to the item's own public single page rather than the
		// owning-business tab the inline title link uses.
		$details_html = '';
		$visit_url    = $item_url;
		if ($pt === 'cbd_event') {
			$details_html = self::event_details_html($post_id);
			$visit_url    = $permalink;
		} elseif ($pt === 'cbd_promotion') {
			$details_html = self::promotion_details_html($post_id);
			$visit_url    = $permalink;
		}

		ob_start(); ?>
		<div class="cbd-feed-item" id="cbd-item-<?php echo esc_attr($post_id); ?>" data-post-id="<?php echo esc_attr($post_id); ?>" data-cbd-visit="<?php echo esc_url($visit_url); ?>">
			<?php if ($biz) : ?>
				<?php // Left column: a compact profile card for the business that posted. 
				?>
				<aside class="cbd-feed-bizcard">
					<a href="<?php echo esc_url($biz['url']); ?>" class="cbd-feed-bizcard-media">
						<?php if ($biz['cover']) : ?>
							<img src="<?php echo esc_url($biz['cover']); ?>" alt="" loading="lazy">
						<?php else : ?>
							<span class="cbd-feed-bizcard-ph"><?php echo esc_html(mb_strtoupper(mb_substr($biz['name'], 0, 1))); ?></span>
						<?php endif; ?>
						<?php if ($biz['category']) : ?>
							<span class="cbd-feed-bizcard-tag"><?php echo esc_html($biz['category']); ?></span>
						<?php endif; ?>
					</a>
					<div class="cbd-feed-bizcard-info">

						<h4 class="cbd-feed-bizcard-name"><a href="<?php echo esc_url($biz['url']); ?>"><?php echo esc_html($biz['name']); ?></a></h4>
						<?php if ($biz['address']) : ?>
							<p class="cbd-feed-bizcard-loc"><span class="cbd-pin"><?php echo \cbd_icon('map-marker'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span> <span><?php echo esc_html($biz['address']); ?></span></p>
						<?php endif; ?>
						<div class="cbd-feed-bizcard-foot">
							<?php if ($biz['rating_avg'] > 0) :
								$stars = '';
								for ($s = 1; $s <= 5; $s++) {
									$stars .= $s <= round($biz['rating_avg']) ? '★' : '☆';
								} ?>
								<span class="cbd-card-rating"><span class="cbd-stars"><?php echo esc_html($stars); ?></span> <small>(<?php echo esc_html((string) $biz['review_count']); ?>)</small></span>
							<?php else : ?>
								<span class="cbd-card-rating cbd-card-rating-empty"></span>
							<?php endif; ?>
							<?php if ($biz['logged_in']) : ?>
								<div class="cbd-feed-bizcard-actions">
									<button class="cbd-btn cbd-btn-sm cbd-btn-icon cbd-follow-btn <?php echo $biz['is_fol'] ? 'is-following' : ''; ?>" data-business-id="<?php echo esc_attr($biz['id']); ?>"><?php echo $biz['is_fol'] ? esc_html__('✓ Following', 'community-business-directory') : esc_html__('+ Follow', 'community-business-directory'); ?></button>
									<button class="cbd-btn cbd-btn-sm cbd-btn-icon cbd-fav-btn <?php echo $biz['is_fav'] ? 'is-favorited' : ''; ?>" data-business-id="<?php echo esc_attr($biz['id']); ?>" title="<?php echo $biz['is_fav'] ? esc_attr__('Remove from favourites', 'community-business-directory') : esc_attr__('Save to favourites', 'community-business-directory'); ?>"><?php echo $biz['is_fav'] ? '♥' : '♡'; ?></button>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</aside>
			<?php endif; ?>
			<?php // Right column: the post itself. Keep .cbd-feed-body so existing styling + the Read More modal still resolve. 
			?>
			<div class="cbd-feed-body cbd-feed-main">
				<?php // Header: avatar on the left, name + verb on top, timestamp below. ?>
				<div class="cbd-feed-byline">
					<?php if ($biz) : ?>
						<a class="cbd-feed-avatar" href="<?php echo esc_url($biz['url']); ?>">
							<?php if ($biz['logo']) : ?>
								<img class="cbd-feed-biz-logo" src="<?php echo esc_url($biz['logo']); ?>" alt="" width="40" height="40" loading="lazy">
							<?php else : ?>
								<span class="cbd-feed-biz-logo cbd-feed-biz-ph"><?php echo esc_html(mb_substr($biz['name'], 0, 1)); ?></span>
							<?php endif; ?>
						</a>
					<?php endif; ?>
					<div class="cbd-feed-byline-main">
						<div class="cbd-feed-byline-line">
							<?php if ($biz) : ?>
								<a class="cbd-feed-biz-name" href="<?php echo esc_url($biz['url']); ?>"><?php echo esc_html($biz['name']); ?></a>
							<?php endif; ?>
							<?php if ($verb) : ?><span class="cbd-feed-verb"><?php echo esc_html($verb); ?></span><?php endif; ?>
						</div>
						<span class="cbd-feed-time"><?php echo esc_html($time_ago); ?></span>
					</div>
					<span class="cbd-feed-type"><?php echo esc_html($type_txt); ?></span>
				</div>
				<h3 class="cbd-feed-title"><a href="<?php echo esc_url($item_url); ?>"><?php echo esc_html(\cbd_label(get_the_title($post_id))); ?></a></h3>
				<p class="cbd-feed-excerpt"><?php echo esc_html($excerpt); ?></p>
				<?php // Hidden — the post's own featured image, surfaced by the Read More modal. 
				?>
				<?php if (has_post_thumbnail($post_id)) : ?>
					<img class="cbd-feed-img" src="<?php echo esc_url((string) get_the_post_thumbnail_url($post_id, 'medium')); ?>" alt="" hidden aria-hidden="true">
				<?php endif; ?>
				<?php // Full content, hidden — the source the Read More modal reads from. 
				?>
				<div class="cbd-feed-full"><?php
					// phpcs:ignore WordPress.Security.EscapeOutput — $full_html via wp_kses_post(); $details_html built from prepared queries + esc_*.
					echo $full_html . $details_html;
				?></div>
				<?php echo $with_reactions ? Reactions::render($post_id, true) : ''; ?>
			</div>
		</div>
	<?php
		return ob_get_clean();
	}

	/**
	 * Structured "Event details" block (when / where / admission / capacity),
	 * read from the cbd_events custom table. Prepended to .cbd-feed-full so the
	 * Read More modal shows it above the post copy. Returns '' if no row.
	 */
	private static function event_details_html(int $post_id): string
	{
		global $wpdb;
		$ev = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cbd_events WHERE post_id = %d",
			$post_id
		), ARRAY_A);
		if (! $ev) {
			return '';
		}

		$sym   = get_option('cbd_currency_symbol', '£');
		$start = ! empty($ev['start_date']) ? new \DateTime($ev['start_date']) : null;
		$end   = ! empty($ev['end_date'])   ? new \DateTime($ev['end_date'])   : null;

		$rows = [];
		if ($start) {
			$time   = $end ? $start->format('g:ia') . ' – ' . $end->format('g:ia') : $start->format('g:ia');
			$rows[] = ['calendar-month', __('When', 'community-business-directory'), $start->format('D j M Y') . ' · ' . $time];
		}
		if (! empty($ev['venue_name']) || ! empty($ev['venue_addr'])) {
			$venue  = trim($ev['venue_name'] . (! empty($ev['venue_addr']) ? ' — ' . $ev['venue_addr'] : ''), " —\t\n");
			$rows[] = ['map-marker', __('Where', 'community-business-directory'), $venue];
		}
		if (! empty($ev['is_free'])) {
			$rows[] = ['ticket-outline', __('Admission', 'community-business-directory'), __('Free event', 'community-business-directory')];
		} elseif (! empty($ev['ticket_price'])) {
			$rows[] = ['ticket-outline', __('Admission', 'community-business-directory'), $sym . number_format((float) $ev['ticket_price'], 2)];
		}
		if (! empty($ev['capacity']) && (int) $ev['capacity'] > 0) {
			$rows[] = [
				'account-group',
				__('Capacity', 'community-business-directory'),
				sprintf(
					/* translators: %s: number of people */
					__('%s people', 'community-business-directory'),
					number_format_i18n((int) $ev['capacity'])
				),
			];
		}

		return self::details_block(__('Event details', 'community-business-directory'), $rows);
	}

	/**
	 * Structured "Offer details" block (discount / coupon / validity), read from
	 * the cbd_promotions custom table. Prepended to .cbd-feed-full. Returns '' if
	 * no row.
	 */
	private static function promotion_details_html(int $post_id): string
	{
		global $wpdb;
		$pr = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cbd_promotions WHERE post_id = %d",
			$post_id
		), ARRAY_A);
		if (! $pr) {
			return '';
		}

		$sym = get_option('cbd_currency_symbol', '£');
		$df  = get_option('date_format');

		$rows = [];
		if (! empty($pr['discount_value'])) {
			$val = $pr['discount_type'] === 'percent'
				? rtrim(rtrim(number_format((float) $pr['discount_value'], 2), '0'), '.') . '%'
				: $sym . number_format((float) $pr['discount_value'], 2);
			$rows[] = [
				'ticket-outline',
				__('Discount', 'community-business-directory'),
				sprintf(
					/* translators: %s: formatted discount amount, e.g. "20%" or "£10.00" */
					__('%s off', 'community-business-directory'),
					$val
				),
			];
		}
		if (! empty($pr['coupon_code'])) {
			$rows[] = ['__code', __('Coupon code', 'community-business-directory'), $pr['coupon_code']];
		}
		if (! empty($pr['start_date'])) {
			$rows[] = ['calendar-month', __('Valid from', 'community-business-directory'), date_i18n($df, strtotime($pr['start_date']))];
		}
		if (! empty($pr['expiry_date'])) {
			$rows[] = ['clock-outline', __('Expires', 'community-business-directory'), date_i18n($df, strtotime($pr['expiry_date']))];
		}

		return self::details_block(__('Offer details', 'community-business-directory'), $rows);
	}

	/**
	 * Render a titled detail card from `[icon, label, value]` rows. A row whose
	 * icon is the sentinel `__code` renders its value as a copy-styled code chip.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $rows
	 */
	private static function details_block(string $title, array $rows): string
	{
		if (! $rows) {
			return '';
		}
		ob_start(); ?>
		<div class="cbd-feed-details">
			<div class="cbd-feed-details-title"><?php echo esc_html($title); ?></div>
			<ul class="cbd-feed-details-list">
				<?php foreach ($rows as [$icon, $label, $value]) :
					$is_code = ('__code' === $icon); ?>
					<li class="cbd-feed-detail">
						<span class="cbd-feed-detail-ic"><?php echo \cbd_icon($is_code ? 'ticket-outline' : $icon); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span>
						<span class="cbd-feed-detail-text">
							<span class="cbd-feed-detail-label"><?php echo esc_html($label); ?></span>
							<?php if ($is_code) : ?>
								<code class="cbd-feed-detail-code"><?php echo esc_html($value); ?></code>
							<?php else : ?>
								<span class="cbd-feed-detail-val"><?php echo esc_html($value); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	use LoadMore;

	/** Map the `types` att to post types (business → the cbd_business CPT). */
	private function feed_post_types(string $types): array
	{
		$map = [
			'post'      => 'cbd_business_post',
			'event'     => 'cbd_event',
			'promotion' => 'cbd_promotion',
			'business'  => 'cbd_business',
		];
		$out = [];
		foreach (array_map('trim', explode(',', $types)) as $t) {
			if (isset($map[$t])) {
				$out[] = $map[$t];
			}
		}
		return $out ?: array_values($map);
	}

	public function render(array $atts): string
	{
		$atts = shortcode_atts([
			'count'       => 10,
			'business_id' => 0,
			'types'       => 'post,event,promotion,business',
		], $atts);

		$per_page = max(1, (int) $atts['count']);
		$first    = $this->items($atts, 1, $per_page);
		$total    = (int) $first['total'];

		ob_start(); ?>
		<div class="cbd-wrap cbd-business-feed">
			<?php echo $this->loadmore_open('feed', ['count' => $per_page, 'types' => $atts['types'], 'business_id' => (int) $atts['business_id']], $per_page, $total, 'cbd-lm-autoload'); ?>
			<div class="cbd-feed-list cbd-lm-list">
				<?php echo $first['html']; // phpcs:ignore — escaped within items()/render_item() 
				?>
			</div>
			<?php echo $this->loadmore_button(1, $per_page, $total); // phpcs:ignore 
			?>
		</div><!-- /.cbd-lm -->
		</div>
<?php
		return ob_get_clean();
	}

	/**
	 * Render one page of feed items. Posts / events / promotions / new
	 * businesses are pulled in one date-ordered query so paging stays correct
	 * across the merged stream (post_status='publish' already excludes pending
	 * businesses). Scoped to one business's author when `business_id` is set.
	 *
	 * @return array{html:string,total:int}
	 */
	public function items(array $atts, int $page, int $per_page): array
	{
		$types       = $this->feed_post_types((string) ($atts['types'] ?? 'post,event,promotion,business'));
		$business_id = (int) ($atts['business_id'] ?? 0);

		$args = [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];
		if ($business_id) {
			$author = get_post_field('post_author', $business_id);
			if ($author) {
				$args['author'] = $author;
			}
		}
		$query = new \WP_Query($args);

		ob_start();
		if ($query->posts) {
			foreach ($query->posts as $id) {
				echo self::render_item((int) $id); // phpcs:ignore — escaped within render_item()
			}
		} elseif (1 === $page) {
			echo '<div class="cbd-empty-state"><span class="cbd-empty-icon">📰</span><p>'
				. esc_html__('No posts yet.', 'community-business-directory') . '</p></div>';
		}

		return ['html' => (string) ob_get_clean(), 'total' => (int) $query->found_posts];
	}
}
