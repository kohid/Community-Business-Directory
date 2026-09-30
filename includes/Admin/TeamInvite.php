<?php
/**
 * Team invitations — grant a colleague access to the WordPress dashboard.
 *
 * The site admin invites someone by email from Community Directory → Team. The
 * invitee gets a branded, single-use tokenised link (mirroring the delegate
 * invite flow in CBD\Frontend\DelegateInvite):
 *
 *   /?cbd_team_invite=<token>
 *
 * On accept the invitee gets (or keeps) a WordPress account, is granted the
 * Administrator role plus the `cbd_site_team` marker user-meta — which is what
 * lets CBD\Core\AdminGuard wave them into wp-admin (normally locked to the site
 * admin_email) — and is signed in. They land on the front-end [cbd_social_sync]
 * page (/socmed) to self-manage the Facebook/Instagram feeds; wp-admin stays a
 * fallback. Brand-new accounts pass through the branded Set Password page first.
 *
 * Invites live in the `cbd_team_invites` option keyed by sha1(token), so a DB
 * leak yields no working links. They expire after EXPIRY and are single-use.
 *
 * @package CBD\Admin
 */

namespace CBD\Admin;

use CBD\Frontend\EmailVerification;

defined( 'ABSPATH' ) || exit;

class TeamInvite {

	/** Option: [ sha1(token) => [ email, role, created, inviter ] ]. */
	public const OPTION = 'cbd_team_invites';

	/** User-meta marking an account as an invited site-team member (AdminGuard reads this). */
	public const META = 'cbd_site_team';

	/** How long an invitation link stays valid. */
	public const EXPIRY = 7 * DAY_IN_SECONDS;

	/**
	 * Roles an invite may grant. Only `administrator` carries `manage_options`,
	 * which every Community Directory admin page (and AdminGuard) requires — so
	 * that is the single supported level for now.
	 */
	public const ROLES = [ 'administrator' ];

	// ── Front controller ─────────────────────────────────────────────

	/** Hooked on `init`. Cheap no-op unless our accept link is present. */
	public function maybe_handle(): void {
		if ( ! isset( $_GET['cbd_team_invite'] ) ) {
			return;
		}
		$token  = sanitize_text_field( wp_unslash( $_GET['cbd_team_invite'] ) );
		$result = $this->accept( $token );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'cbd_login_error', $result->get_error_message(), $this->signin_url() ) );
		} else {
			wp_safe_redirect( $result );
		}
		exit;
	}

	// ── Store ────────────────────────────────────────────────────────

	/** @return array<string,array> All pending invites. */
	public static function all(): array {
		$v = get_option( self::OPTION, [] );
		return is_array( $v ) ? $v : [];
	}

	/**
	 * Create (or replace) an invite for an email and send the invitation.
	 *
	 * @return true|\WP_Error
	 */
	public static function invite( string $email, string $role, int $inviter_id ) {
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return new \WP_Error( 'cbd_team_email', __( 'Enter a valid email address.', 'community-business-directory' ) );
		}
		if ( ! in_array( $role, self::ROLES, true ) ) {
			$role = self::ROLES[0];
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing && strcasecmp( $existing->user_email, (string) get_option( 'admin_email' ) ) === 0 ) {
			return new \WP_Error( 'cbd_team_self', __( 'That address is already the site administrator.', 'community-business-directory' ) );
		}

		$token   = wp_generate_password( 40, false );
		$invites = self::remove_email( self::all(), $email );
		$invites[ sha1( $token ) ] = [
			'email'   => $email,
			'role'    => $role,
			'created' => time(),
			'inviter' => $inviter_id,
		];
		update_option( self::OPTION, $invites, false );

		if ( ! self::send_email( $email, $role, $token, $inviter_id ) ) {
			return new \WP_Error( 'cbd_team_mail', __( 'The invitation could not be emailed — check the site’s email settings under Community Directory → Settings → Email.', 'community-business-directory' ) );
		}
		return true;
	}

	/** Cancel a pending invite by email. */
	public static function cancel( string $email ): void {
		update_option( self::OPTION, self::remove_email( self::all(), sanitize_email( $email ) ), false );
	}

	/**
	 * Revoke an accepted member: drop the marker meta and the Administrator role
	 * we granted. If that leaves them with no role at all, fall back to subscriber
	 * so the account still works.
	 */
	public static function revoke( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || strcasecmp( $user->user_email, (string) get_option( 'admin_email' ) ) === 0 ) {
			return;
		}
		delete_user_meta( $user_id, self::META );
		$user->remove_role( 'administrator' );
		if ( empty( $user->roles ) ) {
			$user->add_role( 'subscriber' );
		}
		do_action( 'cbd_team_member_removed', $user_id );
	}

	/** @return \WP_User[] Accounts flagged as site-team members. */
	public static function members(): array {
		return get_users( [ 'meta_key' => self::META, 'meta_value' => '1', 'orderby' => 'display_name' ] );
	}

	/** Drop any invite rows matching an email (case-insensitive). */
	private static function remove_email( array $invites, string $email ): array {
		$email = strtolower( $email );
		foreach ( $invites as $h => $row ) {
			if ( strtolower( (string) ( $row['email'] ?? '' ) ) === $email ) {
				unset( $invites[ $h ] );
			}
		}
		return $invites;
	}

	// ── Accept ───────────────────────────────────────────────────────

	/**
	 * Validate a token, provision/confirm the account, grant dashboard access,
	 * sign the user in and return the URL to redirect to.
	 *
	 * @return string|\WP_Error Redirect URL on success.
	 */
	public function accept( string $token ) {
		$invalid = new \WP_Error( 'cbd_team_invite', __( 'That invitation link is not valid.', 'community-business-directory' ) );
		if ( '' === $token ) {
			return $invalid;
		}

		$invites = self::all();
		$hash    = sha1( $token );
		if ( ! isset( $invites[ $hash ] ) ) {
			return $invalid;
		}
		$row = $invites[ $hash ];

		if ( ( time() - (int) ( $row['created'] ?? 0 ) ) > self::EXPIRY ) {
			unset( $invites[ $hash ] );
			update_option( self::OPTION, $invites, false );
			return new \WP_Error( 'cbd_team_expired', __( 'That invitation has expired — ask for a new one.', 'community-business-directory' ) );
		}

		$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
		if ( ! $email || ! is_email( $email ) ) {
			return $invalid;
		}
		$role = in_array( $row['role'] ?? '', self::ROLES, true ) ? (string) $row['role'] : self::ROLES[0];

		$is_new = false;
		$user   = get_user_by( 'email', $email );
		if ( ! $user ) {
			$uid = wp_insert_user( [
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => ucfirst( (string) current( explode( '@', $email ) ) ),
				'role'         => $role,
			] );
			if ( is_wp_error( $uid ) ) {
				return $invalid;
			}
			$user   = get_user_by( 'id', (int) $uid );
			$is_new = true;
			/** Fires for a freshly invited + auto-registered account. */
			do_action( 'cbd_account_registered', (int) $uid );
		} else {
			$user->add_role( $role );
			// The email link proves inbox ownership — clear any pending-activation flag.
			if ( EmailVerification::is_pending( (int) $user->ID ) ) {
				delete_user_meta( $user->ID, EmailVerification::META_PENDING );
				delete_user_meta( $user->ID, EmailVerification::META_KEY );
				delete_user_meta( $user->ID, EmailVerification::META_REQUESTED );
				do_action( 'cbd_account_activated', (int) $user->ID );
			}
		}

		if ( ! $user ) {
			return $invalid;
		}

		update_user_meta( (int) $user->ID, self::META, '1' );

		// Consume the single-use invite.
		unset( $invites[ $hash ] );
		update_option( self::OPTION, $invites, false );

		// Sign them in (the emailed token proves they own the inbox).
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );

		do_action( 'cbd_team_member_added', (int) $user->ID, $role );

		// Brand-new account → branded Set Password page first (the auto-generated
		// password is never shown); the tokenised link re-signs them in on save
		// and then routes onward through cbd_login_redirect() — same destination.
		if ( $is_new && function_exists( 'cbd_set_password_url' ) ) {
			$setpw = \cbd_set_password_url( $user );
			if ( $setpw ) {
				return $setpw;
			}
		}
		// Existing account → straight to the front-end sync page (/socmed), with
		// the dashboard as a fallback if that page is missing.
		return function_exists( 'cbd_login_redirect' ) ? \cbd_login_redirect( $user ) : admin_url();
	}

	// ── Email ────────────────────────────────────────────────────────

	/** The accept URL embedded in the invitation email. */
	public static function accept_url( string $token ): string {
		return add_query_arg( 'cbd_team_invite', $token, home_url( '/' ) );
	}

	/** Human label for a grantable role. */
	public static function role_label( string $role ): string {
		$map = [ 'administrator' => __( 'Administrator — full site access', 'community-business-directory' ) ];
		return $map[ $role ] ?? $role;
	}

	private static function send_email( string $email, string $role, string $token, int $inviter_id ): bool {
		$site    = get_bloginfo( 'name' );
		$inviter = get_userdata( $inviter_id );
		$has_name = $inviter && trim( (string) $inviter->display_name ) !== '';
		$by      = $has_name ? $inviter->display_name : $site;
		$url     = self::accept_url( $token );
		$role_l  = self::role_label( $role );

		// Send under the inviter's name ("Jo via Site") with replies to them, so
		// it reads as a personal message. The From address stays the site sender.
		$from_name = $has_name
			? sprintf(
				/* translators: 1: inviter name, 2: site name */
				_x( '%1$s via %2$s', 'email From display name', 'community-business-directory' ),
				$inviter->display_name,
				$site
			)
			: $site;
		$reply_to = ( $inviter && is_email( $inviter->user_email ) ) ? $inviter->user_email : '';

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'You\'re invited to manage %s', 'community-business-directory' ),
			$site
		);

		$listener = static function ( $wp_error ) use ( $email ) {
			if ( is_wp_error( $wp_error ) ) {
				error_log( 'CBD team invite to ' . $email . ' failed: ' . $wp_error->get_error_message() );
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$sent = EmailVerification::send(
			$email,
			$subject,
			self::html_body( $by, $url, $site, $role_l ),
			self::text_body( $by, $url, $site, $role_l ),
			$from_name,
			$reply_to
		);
		remove_action( 'wp_mail_failed', $listener );

		return $sent;
	}

	private static function html_body( string $by, string $url, string $site, string $role_l ): string {
		$logo  = EmailVerification::logo_url();
		$brand = trim( (string) get_option( 'cbd_email_header_color', '' ) ) ?: '#0e2436';
		$header = $logo
			? sprintf( '<img src="%s" alt="%s" style="max-width:200px;max-height:72px;height:auto;width:auto;border:0;display:inline-block;">', esc_url( $logo ), esc_attr( $site ) )
			: sprintf( '<span style="font-size:22px;font-weight:700;color:#fff;">%s</span>', esc_html( $site ) );

		$intro = sprintf(
			/* translators: 1: inviter name, 2: site name */
			__( '%1$s has invited you to help manage the %2$s website. Click the button below to accept — we\'ll set up your access and sign you in. If you don\'t have an account yet we\'ll create one and let you choose a password.', 'community-business-directory' ),
			$by,
			$site
		);
		$expiry = sprintf(
			/* translators: %s: hours valid */
			__( 'This invitation is valid for %s hours. If you weren\'t expecting it, you can safely ignore this email.', 'community-business-directory' ),
			number_format_i18n( self::EXPIRY / HOUR_IN_SECONDS )
		);

		ob_start(); ?>
<div style="background:#f3f4f6;padding:24px 0;">
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;color:#181c32;">
	<div style="text-align:center;background:<?php echo esc_attr( $brand ); ?>;border-radius:10px;padding:22px 16px;margin:0 0 24px;"><?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput — escaped parts ?></div>
	<h2 style="margin:0 0 16px;font-size:22px;color:#181c32;"><?php echo esc_html( sprintf( __( 'Manage %s', 'community-business-directory' ), $site ) ); ?></h2>
	<p style="font-size:15px;line-height:1.6;color:#374151;margin:0 0 20px;"><?php echo esc_html( $intro ); ?></p>
	<p style="font-size:14px;line-height:1.6;color:#374151;margin:0 0 20px;"><strong><?php esc_html_e( 'Access level:', 'community-business-directory' ); ?></strong> <?php echo esc_html( $role_l ); ?></p>
	<p style="text-align:center;margin:0 0 24px;">
		<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#29ABE1;color:#fff;text-decoration:none;font-weight:600;font-size:15px;padding:13px 32px;border-radius:8px;">
			<?php esc_html_e( 'Accept Invitation', 'community-business-directory' ); ?>
		</a>
	</p>
	<p style="font-size:13px;line-height:1.6;color:#6b7280;margin:0 0 8px;"><?php esc_html_e( 'Or paste this link into your browser:', 'community-business-directory' ); ?></p>
	<p style="font-size:13px;line-height:1.5;word-break:break-all;margin:0 0 24px;"><a href="<?php echo esc_url( $url ); ?>" style="color:#29ABE1;"><?php echo esc_html( $url ); ?></a></p>
	<p style="font-size:12px;line-height:1.6;color:#9ca3af;margin:0;border-top:1px solid #e5e7eb;padding-top:16px;"><?php echo esc_html( $expiry ); ?></p>
</div>
</div>
<?php
		return (string) ob_get_clean();
	}

	private static function text_body( string $by, string $url, string $site, string $role_l ): string {
		return sprintf(
			/* translators: 1: inviter, 2: site, 3: access level, 4: URL, 5: hours */
			__( "%1\$s has invited you to help manage the %2\$s website.\n\nAccess level: %3\$s\n\nAccept your invitation:\n%4\$s\n\nIf you don't have an account yet we'll create one and let you choose a password. This link is valid for %5\$s hours; if you weren't expecting it you can ignore this email.\n\n— %2\$s", 'community-business-directory' ),
			$by,
			$site,
			$role_l,
			$url,
			number_format_i18n( self::EXPIRY / HOUR_IN_SECONDS )
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────

	private static function unique_login( string $email ): string {
		$base  = sanitize_user( (string) current( explode( '@', $email ) ), true ) ?: 'user';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		return $login;
	}

	private function signin_url(): string {
		$id = (int) get_option( 'cbd_login_page_id' );
		if ( $id && ( $url = get_permalink( $id ) ) ) {
			return $url;
		}
		return wp_login_url();
	}
}
