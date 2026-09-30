<?php

/**
 * [cbd_account_menu] — Header avatar + dropdown menu.
 *
 * Ported from the WorkSpace theme's profile dropdown. For guests it
 * renders a single "Log In" button pointing to the configured login page.
 * For logged-in users it renders the avatar, a hover/click menu with
 * user info, navigation links, an (admin-only) wp-admin link, a
 * dark-mode toggle, and Log Out.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class AccountMenuShortcode
{

	public function render($atts): string
	{
		$atts = shortcode_atts(
			[
				'login_url'     => '',
				'profile_url'   => '', // "My Profile" target — defaults to the user's own business listing.
				'dashboard_url' => '',
				'directory_url' => '', // "Directory" item only shows when a URL is supplied (or left default below).
				'events_url'    => '',
				'show_directory' => '1',
				'show_dark'      => '1',
			],
			(array) $atts,
			'cbd_account_menu'
		);

		if (! is_user_logged_in()) {
			$login = $atts['login_url'] ?: (get_option('cbd_login_page_id') ? get_permalink((int) get_option('cbd_login_page_id')) : wp_login_url());
			return sprintf(
				'<a class="cbd-account-login" href="%s">%s</a>',
				esc_url($login),
				esc_html__('Sign In', 'community-business-directory')
			);
		}

		$user     = wp_get_current_user();
		$avatar   = get_avatar_url($user->ID, ['size' => 96]);
		$name     = $user->display_name ?: $user->user_login;
		$email    = $user->user_email;
		$provider = (string) get_user_meta($user->ID, 'cbd_social_provider', true);
		$admin_em = (string) get_option('admin_email');
		// Admin Panel link only for users who can actually reach wp-admin (AdminGuard
		// blocks everyone but the site admin email).
		$is_admin = current_user_can('manage_options') && strcasecmp($email, $admin_em) === 0;

		// Role-aware menu. Business owners self-manage their listing through the
		// modals on their own business page (the items below deep-link to it via
		// ?cbd_panel=…); subscribers just get a personal profile editor. The
		// standalone dashboard was removed.
		$is_owner     = in_array('cbd_business_owner', (array) $user->roles, true);
		// Every published business this user owns. Anyone who owns at least one
		// (including the site "super admin" who owns the Website listing) gets the
		// full owner menu; the primary one drives the deep-linked management items.
		$businesses   = function_exists('cbd_user_businesses') ? \cbd_user_businesses($user->ID) : [];
		$has_business = ! empty($businesses);
		// Which of the user's business pages we're currently viewing (if any), so
		// the flyout can highlight it as active.
		$current_biz  = is_singular('cbd_business') ? (int) get_queried_object_id() : 0;
		$biz_base     = $has_business ? $businesses[0]['url'] : (\cbd_user_business_url($user->ID) ?: home_url('/'));
		$logout       = wp_logout_url(home_url('/'));

		// Icon set — inline Material Design Icons via the cbd_icon() helper
		// (assets/icons/, Apache-2.0). currentColor + CSS sizing carry over.
		$icon = [
			'profile'    => \cbd_icon('account'),
			'directory'  => \cbd_icon('account-group'),
			'dashboard'  => \cbd_icon('view-dashboard'),
			'events'     => \cbd_icon('calendar-month'),
			'admin'      => \cbd_icon('cog'),
			'dark'       => \cbd_icon('weather-night'),
			'logout'     => \cbd_icon('logout'),
			'settings'   => \cbd_icon('cog'),
			'page'       => \cbd_icon('file-document-outline'),
			'edit'       => \cbd_icon('pencil'),
			'analytics'  => \cbd_icon('chart-bar'),
			'usage'      => \cbd_icon('gauge'),
			'activities' => \cbd_icon('clipboard-text-outline'),
		];

		// Build the navigation list per role.
		if ($is_owner || $has_business) {
			// Owner: page + the four management actions (each deep-links to a
			// modal on the business page) + Admin Panel for the site admin.
			$my_page = ['url' => $biz_base, 'label' => __('My Page', 'community-business-directory'), 'icon' => $icon['page']];
			// Own more than one business? Hang a submenu off "My Page" listing
			// each, so the owner can jump straight to any of their pages.
			if (count($businesses) > 1) {
				$my_page['children'] = $businesses;
			}
			$items = [
				$my_page,
				// "Edit Profile" = the user's own account settings (display name,
				// avatar, …) — opens the profile modal, NOT the business edit form.
				// Business owners edit their listing from the "Edit Page" profile tab.
				['action' => 'profile', 'label' => __('Edit Profile', 'community-business-directory'), 'icon' => $icon['edit']],
			];
			// Analytics + Plan Usage moved to the on-page profile-tabs settings menu
			// (the gear at the right of the tab strip); Delegate Access lives on its
			// own owner-only "Delegate Access" profile tab.
		} else {
			// Subscriber: a single "My Profile" item that opens the profile modal.
			$items = [
				['action' => 'profile', 'label' => __('My Profile', 'community-business-directory'), 'icon' => $icon['profile']],
			];
		}

		// Everyone gets an "Activities" item — their reviews, follows,
		// favourites, reactions and authored content, loaded into a modal.
		$items[] = ['action' => 'activities', 'label' => __('Activities', 'community-business-directory'), 'icon' => $icon['activities']];

		// Admin Panel link for the site administrator (the only user AdminGuard
		// lets into wp-admin), regardless of their directory role.
		if ($is_admin) {
			$items[] = ['url' => admin_url(), 'label' => __('Admin Panel', 'community-business-directory'), 'icon' => $icon['admin']];
		}

		$menu_id = 'cbd-account-menu-' . (int) $user->ID;

		ob_start(); ?>
		<div class="cbd-account" data-cbd-account>
			<button type="button" class="cbd-account-avatar" aria-haspopup="true" aria-expanded="false" aria-controls="<?php echo esc_attr($menu_id); ?>" aria-label="<?php esc_attr_e('Account menu', 'community-business-directory'); ?>">
				<img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($name); ?>">
			</button>
			<div class="cbd-account-menu" id="<?php echo esc_attr($menu_id); ?>" role="menu" hidden>
				<div class="cbd-account-user">
					<img src="<?php echo esc_url($avatar); ?>" alt="">
					<div>
						<div class="cbd-account-name"><?php echo esc_html($name); ?></div>
						<div class="cbd-account-email"><?php echo esc_html($email); ?></div>
						<?php echo self::provider_badge($provider); // phpcs:ignore — trusted inline SVG + escaped label 
						?>
					</div>
				</div>

				<?php foreach ($items as $item) : ?>
					<?php if (! empty($item['children'])) : ?>
						<div class="cbd-menu-parent">
							<a href="<?php echo esc_url($item['url']); ?>" class="cbd-menu-row" role="menuitem">
								<?php echo $item['icon']; // phpcs:ignore — trusted static inline SVG
								?>
								<?php echo esc_html($item['label']); ?>
							</a>
							<button type="button" class="cbd-submenu-toggle" data-cbd-submenu-toggle aria-expanded="false" aria-label="<?php esc_attr_e('Show your businesses', 'community-business-directory'); ?>">›</button>
							<div class="cbd-account-submenu" role="menu">
								<div class="cbd-submenu-head"><?php esc_html_e('Your Businesses', 'community-business-directory'); ?></div>
								<?php foreach ($item['children'] as $child) :
									$active = ($current_biz && (int) $child['id'] === $current_biz); ?>
									<a href="<?php echo esc_url($child['url']); ?>" role="menuitem" class="<?php echo $active ? 'is-active' : ''; ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>><?php echo esc_html($child['title']); ?></a>
								<?php endforeach; ?>
							</div>
						</div>
					<?php elseif (! empty($item['action'])) : ?>
						<button type="button" class="cbd-menu-item" role="menuitem" data-cbd-<?php echo esc_attr($item['action']); ?>-open>
							<?php echo $item['icon']; // phpcs:ignore — trusted static inline SVG
							?>
							<?php echo esc_html($item['label']); ?>
						</button>
					<?php else : ?>
						<a href="<?php echo esc_url($item['url']); ?>" role="menuitem">
							<?php echo $item['icon']; // phpcs:ignore — trusted static inline SVG
							?>
							<?php echo esc_html($item['label']); ?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if ('1' === (string) $atts['show_dark']) : ?>
					<div class="cbd-account-divider"></div>
					<div class="cbd-account-toggle-row" data-cbd-dark-toggle role="button" tabindex="0" aria-pressed="false">
						<span style="display:flex;align-items:center;gap:10px;">
							<?php echo $icon['dark']; // phpcs:ignore — trusted static inline SVG 
							?>
							<?php esc_html_e('Dark Mode', 'community-business-directory'); ?>
						</span>
						<div class="cbd-dark-switch" data-cbd-dark-switch></div>
					</div>
				<?php endif; ?>

				<div class="cbd-account-divider"></div>

				<a href="<?php echo esc_url($logout); ?>" role="menuitem">
					<?php echo $icon['logout']; // phpcs:ignore — trusted static inline SVG 
					?>
					<?php esc_html_e('Log Out', 'community-business-directory'); ?>
				</a>
			</div>
		</div>

		<div id="cbd-activities-modal" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal cbd-activities-modal-inner">
				<button type="button" class="cbd-modal-close" data-cbd-activities-close aria-label="<?php esc_attr_e('Close', 'community-business-directory'); ?>">✕</button>
				<h3><?php esc_html_e('My Activities', 'community-business-directory'); ?></h3>
				<div class="cbd-activities-body" data-cbd-activities-body>
					<div class="cbd-spinner"></div>
				</div>
			</div>
		</div>

		<?php // User account-settings modal — available to every logged-in user
		// ("My Profile" for subscribers, "Edit Profile" for owners/super admin).
		?>
			<div id="cbd-profile-modal" class="cbd-modal-wrap" style="display:none;">
				<div class="cbd-modal cbd-profile-modal-inner">
					<button type="button" class="cbd-modal-close" data-cbd-profile-close aria-label="<?php esc_attr_e('Close', 'community-business-directory'); ?>">✕</button>
					<h3 class="cbd-profile-title"><?php esc_html_e('Account Settings', 'community-business-directory'); ?></h3>

					<div class="cbd-profile-head">
						<img class="cbd-profile-avatar" src="<?php echo esc_url($avatar); ?>" alt="" data-cbd-avatar-preview>
						<?php echo self::provider_badge($provider); // phpcs:ignore — trusted inline SVG + escaped label 
						?>
					</div>

					<form id="cbd-account-form" class="cbd-form" enctype="multipart/form-data">
						<input type="hidden" name="action" value="cbd_update_account">
						<input type="hidden" name="cbd_nonce" value="<?php echo esc_attr(wp_create_nonce('cbd_nonce')); ?>">

						<label class="cbd-field">
							<span><?php esc_html_e('Profile photo', 'community-business-directory'); ?></span>
							<input type="file" name="account_avatar" accept="image/*" data-cbd-avatar-input data-crop-aspect="1" data-crop-w="400" data-crop-h="400">
							<small><?php esc_html_e('Square photo — reposition and zoom before saving. Exports 400×400px.', 'community-business-directory'); ?></small>
						</label>

						<label class="cbd-field">
							<span><?php esc_html_e('Display name', 'community-business-directory'); ?></span>
							<input type="text" name="display_name" value="<?php echo esc_attr($name); ?>" maxlength="100">
						</label>

						<div class="cbd-field-row">
							<label class="cbd-field">
								<span><?php esc_html_e('First name', 'community-business-directory'); ?></span>
								<input type="text" name="first_name" value="<?php echo esc_attr($user->first_name); ?>" maxlength="60">
							</label>
							<label class="cbd-field">
								<span><?php esc_html_e('Last name', 'community-business-directory'); ?></span>
								<input type="text" name="last_name" value="<?php echo esc_attr($user->last_name); ?>" maxlength="60">
							</label>
						</div>

						<label class="cbd-field">
							<span><?php esc_html_e('Email address', 'community-business-directory'); ?></span>
							<input type="email" value="<?php echo esc_attr($email); ?>" readonly disabled>
							<small class="cbd-field-hint"><?php esc_html_e('Your email is linked to your sign-in and cannot be changed here.', 'community-business-directory'); ?></small>
						</label>

						<div class="cbd-field-section">
							<span class="cbd-field-section-title"><?php esc_html_e('Change password', 'community-business-directory'); ?></span>
							<small class="cbd-field-hint"><?php esc_html_e('Leave blank to keep your current password.', 'community-business-directory'); ?></small>
						</div>
						<label class="cbd-field">
							<span><?php esc_html_e('Current password', 'community-business-directory'); ?></span>
							<input type="password" name="current_password" autocomplete="current-password" placeholder="••••••••">
						</label>
						<div class="cbd-field-row">
							<label class="cbd-field">
								<span><?php esc_html_e('New password', 'community-business-directory'); ?></span>
								<input type="password" name="new_password" autocomplete="new-password" minlength="8" placeholder="••••••••">
							</label>
							<label class="cbd-field">
								<span><?php esc_html_e('Confirm password', 'community-business-directory'); ?></span>
								<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" placeholder="••••••••">
							</label>
						</div>

						<div class="cbd-form-msg" style="display:none;"></div>

						<button type="submit" class="cbd-btn cbd-btn-primary">
							<span class="btn-text"><?php esc_html_e('Save Changes', 'community-business-directory'); ?></span>
							<span class="btn-loading" style="display:none;"><?php esc_html_e('Saving…', 'community-business-directory'); ?></span>
						</button>
					</form>
				</div>
			</div>
<?php
		return (string) ob_get_clean();
	}

	/**
	 * Small "Signed in with <provider>" pill showing the brand logo of the
	 * social network the account was created/last linked with. Falls back to
	 * an email badge for plain email/password accounts.
	 *
	 * Public + static so other surfaces (reactors modal, reviews list) can
	 * render the same pill — see also the cbd_provider_badge() global helper.
	 */
	public static function provider_badge(string $provider): string
	{
		$labels = [
			'google'    => 'Google',
			'facebook'  => 'Facebook',
			'twitter'   => 'Twitter / X',
			'instagram' => 'Instagram',
		];
		$label = $labels[$provider] ?? __('Email', 'community-business-directory');
		/* translators: %s: sign-in provider name (Google, Facebook, …) or "Email". */
		$text = sprintf(__('Signed in with %s', 'community-business-directory'), $label);

		return '<span class="cbd-provider-badge cbd-provider-' . esc_attr($provider ?: 'email') . '">'
			. self::provider_logo($provider)
			. '<span>' . esc_html($text) . '</span></span>';
	}

	/** Inline brand mark for a provider (or an envelope for email accounts). */
	private static function provider_logo(string $provider): string
	{
		switch ($provider) {
			case 'google':
				return '<svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.5 26.7 36.5 24 36.5c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.6 39.7 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C41.4 36.3 44 30.7 44 24c0-1.3-.1-2.3-.4-3.5z"/></svg>';
			case 'facebook':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.875 11.854v-8.385H7.078V12h3.047V9.356c0-3.007 1.792-4.669 4.533-4.669 1.313 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874V12h3.328l-.532 3.469h-2.796v8.385A12.002 12.002 0 0 0 24 12z"/></svg>';
			case 'twitter':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.451-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
			case 'instagram':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#E4405F" d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.43.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.43.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.43-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.43-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63a5.9 5.9 0 0 0-2.12 1.38A5.9 5.9 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.8.72 1.47 1.38 2.13.66.66 1.33 1.07 2.12 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.8-.31 1.47-.72 2.13-1.38.66-.66 1.07-1.33 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.9 5.9 0 0 0-1.38-2.12A5.9 5.9 0 0 0 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84zM12 16a4 4 0 1 1 4-4 4 4 0 0 1-4 4zm6.41-10.85a1.44 1.44 0 1 0 1.44 1.44 1.44 1.44 0 0 0-1.44-1.44z"/></svg>';
			default:
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>';
		}
	}

	/**
	 * Permalink of the current user's own (first published) business listing, so
	 * "My Profile" points at their public profile page. '' when they own none.
	 */
	private function own_business_url(int $user_id): string
	{
		$ids = get_posts([
			'post_type'      => 'cbd_business',
			'author'         => $user_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);
		return $ids ? (string) get_permalink((int) $ids[0]) : '';
	}
}
