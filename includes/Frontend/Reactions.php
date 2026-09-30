<?php

/**
 * Reactions — Facebook-style emoji reactions for posts.
 *
 * Storage: {prefix}cbd_reactions, one row per (post_id, user_id). Only
 * logged-in users may react; the AJAX layer enforces that.
 *
 * @package CBD\Frontend
 */

namespace CBD\Frontend;

defined('ABSPATH') || exit;

class Reactions
{

	/** Reaction key → emoji + human label. Order = picker order. */
	public const TYPES = [
		'like'  => ['👍', 'Like'],
		'love'  => ['❤️', 'Love'],
		'haha'  => ['😂', 'Haha'],
		'wow'   => ['😮', 'Wow'],
		'sad'   => ['😢', 'Sad'],
		'angry' => ['😡', 'Angry'],
	];

	/** Default (un-reacted) "Like" button icon — kept in sync with CBD_REACT_DEFAULT_ICON in frontend.js. */
	public const DEFAULT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M5 9v12H1V9zm4 12a2 2 0 0 1-2-2V9c0-.55.22-1.05.59-1.41L14.17 1l1.06 1.06c.27.27.44.64.44 1.05l-.03.32L14.69 8H21a2 2 0 0 1 2 2v2c0 .26-.05.5-.14.73l-3.02 7.05C19.54 20.5 18.83 21 18 21zm0-2h9.03L21 12v-2h-8.79l1.13-5.32L9 9.03z"/></svg>';

	public static function is_valid(string $type): bool
	{
		return isset(self::TYPES[$type]);
	}

	public static function emoji(string $type): string
	{
		return self::TYPES[$type][0] ?? '👍';
	}

	public static function label(string $type): string
	{
		return self::TYPES[$type][1] ?? 'Like';
	}

	/** @return array<string,int> reaction key → count (only non-zero) */
	public static function counts(int $post_id): array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT reaction, COUNT(*) AS c FROM {$wpdb->prefix}cbd_reactions WHERE post_id = %d GROUP BY reaction",
			$post_id
		));
		$out = [];
		foreach ((array) $rows as $r) {
			if (self::is_valid($r->reaction)) {
				$out[$r->reaction] = (int) $r->c;
			}
		}
		return $out;
	}

	public static function total(int $post_id): int
	{
		return array_sum(self::counts($post_id));
	}

	public static function user_reaction(int $post_id, int $user_id): string
	{
		if (! $user_id) {
			return '';
		}
		global $wpdb;
		return (string) $wpdb->get_var($wpdb->prepare(
			"SELECT reaction FROM {$wpdb->prefix}cbd_reactions WHERE post_id = %d AND user_id = %d",
			$post_id,
			$user_id
		));
	}

	/**
	 * Apply a reaction toggle for a user. Same type twice removes it; a
	 * different type replaces it. Returns the user's resulting reaction ('' if
	 * removed).
	 */
	public static function toggle(int $post_id, int $user_id, string $type): string
	{
		global $wpdb;
		$table   = $wpdb->prefix . 'cbd_reactions';
		$current = self::user_reaction($post_id, $user_id);

		if ($current === $type) {
			$wpdb->delete($table, ['post_id' => $post_id, 'user_id' => $user_id]);
			return '';
		}

		// replace() upserts on the (post_id, user_id) unique key.
		$wpdb->replace($table, [
			'post_id'    => $post_id,
			'user_id'    => $user_id,
			'reaction'   => $type,
			'created_at' => current_time('mysql'),
		]);
		return $type;
	}

	/** @return array<int,array<string,string>> list of reactors for the modal */
	public static function reactors(int $post_id): array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT user_id, reaction FROM {$wpdb->prefix}cbd_reactions WHERE post_id = %d ORDER BY created_at DESC",
			$post_id
		));
		$out = [];
		foreach ((array) $rows as $r) {
			$u = get_userdata((int) $r->user_id);
			$out[] = [
				'name'     => $u ? $u->display_name : __('Someone', 'community-business-directory'),
				'avatar'   => get_avatar_url((int) $r->user_id, ['size' => 48]),
				'reaction' => $r->reaction,
				'emoji'    => self::emoji($r->reaction),
				// "Signed in with …" pill (empty for guests / missing users).
				'badge'    => \cbd_provider_badge((int) $r->user_id),
			];
		}
		return $out;
	}

	/**
	 * Render the reaction bar (summary + button + picker) for a post.
	 * Safe to output directly; all dynamic values are escaped.
	 *
	 * @param bool $with_readmore Append a "Read More" button that opens the full
	 *                            feed item in a modal (feed cards with long copy).
	 */
	public static function render(int $post_id, bool $with_readmore = false): string
	{
		$counts  = self::counts($post_id);
		$total   = array_sum($counts);
		$user_id = get_current_user_id();
		$mine    = self::user_reaction($post_id, $user_id);

		// Top reactions (by count) for the summary badge.
		arsort($counts);
		$top_emojis = '';
		foreach (array_slice(array_keys($counts), 0, 3) as $type) {
			$top_emojis .= '<span class="cbd-react-emoji">' . self::emoji($type) . '</span>';
		}

		$btn_label = $mine ? self::label($mine) : __('Like', 'community-business-directory');
		// Default (un-reacted) icon is a thumbs-up SVG; a chosen reaction shows its emoji.
		$btn_emoji = $mine ? self::emoji($mine) : self::DEFAULT_ICON;

		ob_start(); ?>
		<div class="cbd-reactions" data-post-id="<?php echo esc_attr($post_id); ?>" data-logged-in="<?php echo $user_id ? '1' : '0'; ?>" data-login-url="<?php echo esc_attr(wp_login_url(get_permalink($post_id))); ?>">
			<div class="cbd-react-summary" <?php echo $total ? '' : ' style="display:none;"'; ?>>
				<span class="cbd-react-summary-emojis"><?php echo $top_emojis; // already escaped 
														?></span>
				<span class="cbd-react-summary-count"><?php echo esc_html((string) $total); ?></span>
			</div>
			<?php if ($user_id) : // reaction picker is logged-in only 
			?>
				<div class="cbd-react-btn-wrap">
					<button type="button" class="cbd-react-btn <?php echo $mine ? 'has-reaction is-' . esc_attr($mine) : ''; ?>" data-current="<?php echo esc_attr($mine); ?>">
						<span class="cbd-react-btn-emoji"><?php echo $btn_emoji; // emoji literal 
															?></span>
						<span class="cbd-react-btn-label"><?php echo esc_html($btn_label); ?></span>
					</button>
					<div class="cbd-react-picker" role="menu" aria-label="<?php esc_attr_e('Pick a reaction', 'community-business-directory'); ?>">
						<?php foreach (self::TYPES as $key => [$emoji, $label]) : ?>
							<button type="button" class="cbd-react-opt" data-reaction="<?php echo esc_attr($key); ?>" title="<?php echo esc_attr($label); ?>" aria-label="<?php echo esc_attr($label); ?>"><?php echo $emoji; ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; // user_id 
			?>
			<?php if ($with_readmore) : ?>
				<button type="button" class="cbd-feed-readmore-btn"><?php esc_html_e('Read More', 'community-business-directory'); ?></button>
			<?php endif; ?>
		</div>
<?php
		return ob_get_clean();
	}
}
