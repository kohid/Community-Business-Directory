<?php
/**
 * Delegate-access invitations.
 *
 * When a business owner grants delegate access by email, we email the invitee a
 * tokenised accept link instead of adding them silently. The link is a plain,
 * path-agnostic URL handled on `init` (mirroring EmailVerification / SocialAuth):
 *   /?cbd_accept_invite=<token>&cbd_biz=<business_id>
 *
 * On accept:
 *   - No account for that email   → a verified account is auto-created, and the
 *                                   invitee is sent to the branded Set Password
 *                                   page to choose their own password.
 *   - Unverified existing account → confirmed (they proved ownership via email).
 *   - Verified existing account   → used as-is.
 * In every case the invitee is signed in and granted the delegate permissions
 * the owner chose. Existing accounts land on the business page; brand-new ones
 * land on the Set Password page first (which signs them on afterwards).
 *
 * Invites live in per-business post meta keyed by a hash of the token (so a DB
 * leak never yields working links), carrying the invitee email, granted caps,
 * inviter and issue time. They expire after EXPIRY and are single-use.
 *
 * @package CBD\Frontend
 */

namespace CBD\Frontend;

defined( 'ABSPATH' ) || exit;

class DelegateInvite {

	/** Per-business meta: [ sha1(token) => [ email, perms, created, inviter ] ]. */
	public const META = '_cbd_delegate_invites';

	/** How long an invitation link stays valid. */
	public const EXPIRY = 3 * DAY_IN_SECONDS;

	/**
	 * Front controller — hooked on `init`. Cheap no-op on normal requests; only
	 * acts on our accept links.
	 */
	public function maybe_handle(): void {
		if ( ! isset( $_GET['cbd_accept_invite'], $_GET['cbd_biz'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['cbd_accept_invite'] ) );
		$biz   = absint( $_GET['cbd_biz'] );
		$result = $this->accept( $biz, $token );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'cbd_login_error', $result->get_error_message(), $this->signin_url() ) );
		} else {
			// Land them on the business page with a friendly flag the page can read.
			wp_safe_redirect( add_query_arg( 'cbd_invite_accepted', '1', $result ) );
		}
		exit;
	}

	// ── Store ─────────────────────────────────────────────────────────

	/** All pending invites for a business. @return array<string,array> */
	public static function all( int $business_id ): array {
		$v = get_post_meta( $business_id, self::META, true );
		return is_array( $v ) ? $v : [];
	}

	/**
	 * Create (or replace) an invite for an email and send the invitation email.
	 * Returns whether the email was accepted for delivery.
	 */
	public static function invite( int $business_id, string $email, array $perms, int $inviter_id ): bool {
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}

		// No account is provisioned here — the single "Accept Invite" link does it
		// all on click: it auto-creates a verified account if the email is unknown,
		// or signs an existing user straight in. See accept().
		$token   = wp_generate_password( 40, false );
		$invites = self::remove_email( self::all( $business_id ), $email );

		$invites[ sha1( $token ) ] = [
			'email'   => $email,
			'perms'   => array_values( array_map( 'sanitize_key', $perms ) ),
			'created' => time(),
			'inviter' => $inviter_id,
		];
		update_post_meta( $business_id, self::META, $invites );

		return self::send_invite_email( $business_id, $email, $token, $inviter_id );
	}

	/** Cancel a pending invite by email. */
	public static function cancel( int $business_id, string $email ): void {
		update_post_meta( $business_id, self::META, self::remove_email( self::all( $business_id ), sanitize_email( $email ) ) );
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

	// ── Accept ────────────────────────────────────────────────────────

	/**
	 * Validate a token, provision/confirm the account, grant the delegate
	 * permissions, sign the user in and return the business URL to redirect to.
	 *
	 * @return string|\WP_Error Redirect URL on success.
	 */
	public function accept( int $business_id, string $token ) {
		$invalid = new \WP_Error( 'cbd_invite', __( 'That invitation link is not valid.', 'community-business-directory' ) );

		$business = $business_id ? get_post( $business_id ) : null;
		if ( ! $business || 'cbd_business' !== $business->post_type || '' === $token ) {
			return $invalid;
		}

		$invites = self::all( $business_id );
		$hash    = sha1( $token );
		if ( ! isset( $invites[ $hash ] ) ) {
			return $invalid;
		}
		$row = $invites[ $hash ];

		if ( ( time() - (int) ( $row['created'] ?? 0 ) ) > self::EXPIRY ) {
			unset( $invites[ $hash ] );
			update_post_meta( $business_id, self::META, $invites );
			return new \WP_Error( 'cbd_invite_expired', __( 'That invitation has expired — ask the business owner to send a new one.', 'community-business-directory' ) );
		}

		$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
		if ( ! $email || ! is_email( $email ) ) {
			return $invalid;
		}
		$perms = array_map( 'sanitize_key', (array) ( $row['perms'] ?? [] ) );

		$is_new = false;
		$user   = get_user_by( 'email', $email );
		if ( ! $user ) {
			// Auto-register a verified account — they proved email ownership by
			// opening this link, so it never enters the pending-activation state.
			$uid = wp_insert_user( [
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => ucfirst( (string) current( explode( '@', $email ) ) ),
				'role'         => 'subscriber',
			] );
			if ( is_wp_error( $uid ) ) {
				return $invalid;
			}
			$user   = get_user_by( 'id', (int) $uid );
			$is_new = true;
			/** Fires for a freshly invited+auto-registered account. */
			do_action( 'cbd_account_registered', (int) $uid );
		} elseif ( EmailVerification::is_pending( (int) $user->ID ) ) {
			// Existing-but-unverified account — confirm it (the email link is proof).
			delete_user_meta( $user->ID, EmailVerification::META_PENDING );
			delete_user_meta( $user->ID, EmailVerification::META_KEY );
			delete_user_meta( $user->ID, EmailVerification::META_REQUESTED );
			do_action( 'cbd_account_activated', (int) $user->ID );
		}

		if ( ! $user ) {
			return $invalid;
		}

		self::grant( $business_id, (int) $user->ID, $perms );

		// Consume the single-use invite.
		unset( $invites[ $hash ] );
		update_post_meta( $business_id, self::META, $invites );

		// Sign them in (the emailed token proves they own the inbox).
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );

		// Brand-new account → send them to the branded Set Password page first so
		// they can choose a password (the auto-generated one is never shown). The
		// tokenised link re-signs them in on save and lands them onward. Existing
		// accounts already have a password, so go straight to the business page.
		if ( $is_new ) {
			$setpw = \cbd_set_password_url( $user );
			if ( $setpw ) {
				return $setpw;
			}
		}

		return get_permalink( $business_id ) ?: home_url( '/' );
	}

	/** Add a user as a delegate of a business with a given capability set. */
	private static function grant( int $business_id, int $user_id, array $perms ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $business_id, '_cbd_delegates', true ) ) ) ) );
		if ( ! in_array( $user_id, $ids, true ) ) {
			$ids[] = $user_id;
		}

		$caps     = array_keys( \cbd_delegate_capabilities() );
		$map      = [];
		foreach ( $caps as $cap ) {
			// No explicit perms on the invite → grant everything.
			$map[ $cap ] = ( ! $perms || in_array( $cap, $perms, true ) ) ? 1 : 0;
		}

		$all_perms             = (array) get_post_meta( $business_id, '_cbd_delegate_perms', true );
		$all_perms[ $user_id ] = $map;

		update_post_meta( $business_id, '_cbd_delegates', $ids );
		update_post_meta( $business_id, '_cbd_delegate_perms', $all_perms );
	}

	// ── Email ─────────────────────────────────────────────────────────

	/** The accept URL embedded in the invitation email. */
	public static function accept_url( int $business_id, string $token ): string {
		return add_query_arg(
			[
				'cbd_accept_invite' => $token,
				'cbd_biz'           => $business_id,
			],
			home_url( '/' )
		);
	}

	private static function send_invite_email( int $business_id, string $email, string $token, int $inviter_id ): bool {
		$site     = get_bloginfo( 'name' );
		$biz_name = get_the_title( $business_id );
		$inviter  = get_userdata( $inviter_id );
		$by       = $inviter ? $inviter->display_name : $site;
		$url      = self::accept_url( $business_id, $token );

		// Send under the inviter's name ("Jo via Site") with replies going to them,
		// so the invite reads as a personal message rather than brand marketing.
		// The From *address* stays the site sender (SPF/DKIM unaffected) — only the
		// display name and Reply-To change.
		$from_name = ( $inviter && trim( (string) $inviter->display_name ) !== '' )
			? sprintf(
				/* translators: 1: inviter name, 2: site name */
				_x( '%1$s via %2$s', 'email From display name', 'community-business-directory' ),
				$inviter->display_name,
				$site
			)
			: $site;
		$reply_to = ( $inviter && is_email( $inviter->user_email ) ) ? $inviter->user_email : '';

		$subject = sprintf(
			/* translators: 1: business name, 2: site name */
			__( 'You\'re invited to help manage %1$s on %2$s', 'community-business-directory' ),
			$biz_name,
			$site
		);

		// Capture a wp_mail failure reason to the debug log so a non-delivery is
		// diagnosable (transport/SMTP issues are the usual cause, not the code).
		$listener = static function ( $wp_error ) use ( $email ) {
			if ( is_wp_error( $wp_error ) ) {
				error_log( 'CBD delegate invite to ' . $email . ' failed: ' . $wp_error->get_error_message() );
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$sent = EmailVerification::send(
			$email,
			$subject,
			self::email_body( $biz_name, $by, $url, $site ),
			self::plain_body( $biz_name, $by, $url, $site ),
			$from_name,
			$reply_to
		);
		remove_action( 'wp_mail_failed', $listener );

		return $sent;
	}

	/** Sign In page permalink (static), falling back to wp-login. */
	private static function login_url(): string {
		$id = (int) get_option( 'cbd_login_page_id' );
		if ( $id && ( $url = get_permalink( $id ) ) ) {
			return $url;
		}
		return wp_login_url();
	}

	private static function email_body( string $biz_name, string $by, string $url, string $site ): string {
		$logo  = EmailVerification::logo_url();
		$brand = trim( (string) get_option( 'cbd_email_header_color', '' ) ) ?: '#0e2436';
		$header = $logo
			? sprintf( '<img src="%s" alt="%s" style="max-width:200px;max-height:72px;height:auto;width:auto;border:0;display:inline-block;">', esc_url( $logo ), esc_attr( $site ) )
			: sprintf( '<span style="font-size:22px;font-weight:700;color:#fff;">%s</span>', esc_html( $site ) );

		$intro = sprintf(
			/* translators: 1: inviter name, 2: business name */
			__( '%1$s has invited you to help manage %2$s. Just click Accept Invite below to get access — there\'s nothing else to fill in.', 'community-business-directory' ),
			$by,
			$biz_name
		);
		$how = __( 'If you don\'t have an account on the site yet, we\'ll create one for you automatically and sign you in. If you already have an account, we\'ll simply sign you in and take you straight to the business page.', 'community-business-directory' );
		$expiry = sprintf(
			/* translators: %s: hours valid */
			__( 'This invitation is valid for %s hours. If you weren\'t expecting it, you can safely ignore this email.', 'community-business-directory' ),
			number_format_i18n( self::EXPIRY / HOUR_IN_SECONDS )
		);

		ob_start(); ?>
<div style="background:#f3f4f6;padding:24px 0;">
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;color:#181c32;">
	<div style="text-align:center;background:<?php echo esc_attr( $brand ); ?>;border-radius:10px;padding:22px 16px;margin:0 0 24px;"><?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput — escaped parts ?></div>
	<h2 style="margin:0 0 16px;font-size:22px;color:#181c32;"><?php echo esc_html( sprintf( __( 'Manage %s', 'community-business-directory' ), $biz_name ) ); ?></h2>
	<p style="font-size:15px;line-height:1.6;color:#374151;margin:0 0 20px;"><?php echo esc_html( $intro ); ?></p>
	<p style="text-align:center;margin:0 0 24px;">
		<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#29ABE1;color:#fff;text-decoration:none;font-weight:600;font-size:15px;padding:13px 32px;border-radius:8px;">
			<?php esc_html_e( 'Accept Invite', 'community-business-directory' ); ?>
		</a>
	</p>
	<div style="background:#f3f4f6;border-radius:10px;padding:16px 18px;margin:0 0 24px;">
		<p style="font-size:13px;line-height:1.6;color:#374151;margin:0;"><?php echo esc_html( $how ); ?></p>
	</div>
	<p style="font-size:13px;line-height:1.6;color:#6b7280;margin:0 0 8px;"><?php esc_html_e( 'Or paste this link into your browser:', 'community-business-directory' ); ?></p>
	<p style="font-size:13px;line-height:1.5;word-break:break-all;margin:0 0 24px;"><a href="<?php echo esc_url( $url ); ?>" style="color:#29ABE1;"><?php echo esc_html( $url ); ?></a></p>
	<p style="font-size:12px;line-height:1.6;color:#9ca3af;margin:0;border-top:1px solid #e5e7eb;padding-top:16px;"><?php echo esc_html( $expiry ); ?></p>
</div>
</div>
<?php
		return (string) ob_get_clean();
	}

	private static function plain_body( string $biz_name, string $by, string $url, string $site ): string {
		$body = sprintf(
			/* translators: 1: inviter, 2: business, 3: URL, 4: site */
			__( "%1\$s has invited you to help manage %2\$s on %4\$s.\n\nClick Accept Invite to get access:\n\n%3\$s\n\nIf you don't have an account on the site yet, we'll create one for you automatically and sign you in. If you already have an account, we'll sign you in and take you straight to the business page.", 'community-business-directory' ),
			$by,
			$biz_name,
			$url,
			$site
		);
		$body .= "\n\n" . sprintf(
			/* translators: 1: hours valid, 2: site name */
			__( "This invitation is valid for %1\$s hours. If you weren't expecting it, you can ignore this email.\n\n— %2\$s", 'community-business-directory' ),
			number_format_i18n( self::EXPIRY / HOUR_IN_SECONDS ),
			$site
		);
		return $body;
	}

	// ── Helpers ───────────────────────────────────────────────────────

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
