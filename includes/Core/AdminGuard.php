<?php
/**
 * Restrict wp-admin to the WordPress administration email.
 *
 * Even users with the `administrator` role are bounced to the frontend
 * dashboard unless their `user_email` exactly matches the site's
 * `admin_email` option — or they were explicitly invited as a site-team
 * member (the `cbd_site_team` user-meta set by CBD\Admin\TeamInvite).
 * AJAX, cron, REST and the login screen are exempt so the rest of the
 * plugin keeps working.
 *
 * @package CBD\Core
 */

namespace CBD\Core;

defined( 'ABSPATH' ) || exit;

class AdminGuard {

	public function maybe_block(): void {
		// Don't interfere with AJAX, cron, REST, or the login screen.
		if ( wp_doing_ajax() || wp_doing_cron() ) return;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
		if ( ! is_user_logged_in() ) return;

		$user  = wp_get_current_user();
		$admin = (string) get_option( 'admin_email' );

		if ( $admin && strcasecmp( $user->user_email, $admin ) === 0 ) {
			return; // The one admin allowed in.
		}

		// Invited site-team members with full admin caps (see CBD\Admin\TeamInvite).
		if ( $user->has_cap( 'manage_options' ) && '1' === (string) get_user_meta( $user->ID, 'cbd_site_team', true ) ) {
			return;
		}

		// Send everyone else to their business page (owners) or the home page.
		$dest = \cbd_login_redirect( $user );
		wp_safe_redirect( $dest ?: home_url( '/' ) );
		exit;
	}

	/**
	 * Hide the front-end admin bar for everyone except the site admin_email —
	 * invited team members included. They manage the Facebook/Instagram feeds on
	 * the front-end "/socmed" page and shouldn't be nudged toward wp-admin by a
	 * "Dashboard" link in the toolbar. (wp-admin itself stays reachable by URL as
	 * a fallback — see maybe_block().)
	 */
	public function maybe_hide_admin_bar( bool $show ): bool {
		if ( ! is_user_logged_in() ) return $show;
		$user  = wp_get_current_user();
		$admin = (string) get_option( 'admin_email' );
		if ( $admin && strcasecmp( $user->user_email, $admin ) === 0 ) {
			return $show;
		}
		return false;
	}
}
