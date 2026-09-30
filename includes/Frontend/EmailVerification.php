<?php
/**
 * Email verification for self-service account registration.
 *
 * New accounts created through [cbd_signup] start life *pending*: they carry a
 * `cbd_pending_activation` user-meta flag and cannot sign in (the cbd_login AJAX
 * handler rejects them) until the visitor clicks the activation link we email
 * them. The link is a plain, path-agnostic URL —
 *   /?cbd_activate=<key>&cbd_uid=<id>
 * — handled on `init` (mirroring CBD\Frontend\SocialAuth), so it works no matter
 * which page WordPress happens to resolve it to.
 *
 * The key itself is never stored in the clear: we keep a salted hash (the same
 * scheme WordPress uses for passwords) and compare with wp_check_password(), so
 * a database leak does not hand out working activation links. Keys expire after
 * EXPIRY seconds; an expired or unknown link bounces to the Sign In page with a
 * friendly error, and a fresh one can be requested via cbd_resend_verification.
 *
 * @package CBD\Frontend
 */

namespace CBD\Frontend;

defined( 'ABSPATH' ) || exit;

class EmailVerification {

	/** While set, the account is unverified and barred from signing in. */
	public const META_PENDING = 'cbd_pending_activation';

	/** Salted hash of the current activation key. */
	public const META_KEY = 'cbd_activation_key';

	/** Unix time the current key was issued (drives expiry). */
	public const META_REQUESTED = 'cbd_activation_requested';

	/** How long an activation link stays valid. */
	public const EXPIRY = 2 * DAY_IN_SECONDS;

	/**
	 * Front controller — hooked on `init`. Cheap no-op on normal requests (a
	 * single isset() check); only does work on our activation links.
	 */
	public function maybe_handle(): void {
		if ( ! isset( $_GET['cbd_activate'], $_GET['cbd_uid'] ) ) {
			return;
		}

		$key    = sanitize_text_field( wp_unslash( $_GET['cbd_activate'] ) );
		$uid    = absint( $_GET['cbd_uid'] );
		$result = $this->activate( $uid, $key );

		$signin = $this->signin_url();
		if ( is_wp_error( $result ) ) {
			// add_query_arg() URL-encodes the value for us.
			wp_safe_redirect( add_query_arg( 'cbd_login_error', $result->get_error_message(), $signin ) );
		} else {
			wp_safe_redirect( add_query_arg( 'cbd_activated', '1', $signin ) );
		}
		exit;
	}

	/**
	 * Bar unverified accounts from authenticating anywhere — not just our AJAX
	 * login, but wp-login.php and XML-RPC too. Hooked on `authenticate` after
	 * the credential checks (priority 30) so we only reject otherwise-valid
	 * logins. Returns the WP_User untouched for verified accounts.
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public function block_unverified_login( $user, $username = '', $password = '' ) {
		if ( $user instanceof \WP_User && self::is_pending( (int) $user->ID ) ) {
			return new \WP_Error(
				'cbd_unverified',
				__( 'Please confirm your email address before signing in. Check your inbox for the activation link.', 'community-business-directory' )
			);
		}
		return $user;
	}

	/**
	 * Route outgoing mail through the configured SMTP server (Settings → Email →
	 * SMTP Delivery). Hooked on `phpmailer_init` only when SMTP is enabled, so a
	 * shared host's throttled / spam-prone PHP mail() is bypassed entirely. It is
	 * strictly opt-in because a misconfiguration would affect all site mail.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Passed by reference by WP.
	 */
	public function apply_smtp( $phpmailer ): void {
		// If a dedicated mailer plugin owns delivery (Brevo/Sendinblue, WP Mail
		// SMTP, FluentSMTP, Post SMTP…), step aside so we never fight it.
		if (
			class_exists( 'SIB_Manager' ) || class_exists( 'Mailin' )      // Brevo / Sendinblue
			|| class_exists( '\WPMailSMTP\Core' )                          // WP Mail SMTP
			|| function_exists( 'fluentMail' ) || class_exists( 'FluentMail\App\Application' )
			|| class_exists( 'PostmanWpMail' )                             // Post SMTP
		) {
			return;
		}
		$host = trim( (string) get_option( 'cbd_smtp_host', '' ) );
		if ( '' === $host ) {
			return;
		}
		$enc  = (string) get_option( 'cbd_smtp_encryption', 'ssl' );
		$auth = get_option( 'cbd_smtp_auth', '1' ) === '1';

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->Port       = (int) ( get_option( 'cbd_smtp_port', 465 ) ?: 465 );
		$phpmailer->SMTPAuth   = $auth;
		$phpmailer->SMTPSecure = in_array( $enc, [ 'ssl', 'tls' ], true ) ? $enc : '';
		if ( '' === $phpmailer->SMTPSecure ) {
			$phpmailer->SMTPAutoTLS = false;
		}
		if ( $auth ) {
			$phpmailer->Username = (string) get_option( 'cbd_smtp_user', '' );
			$phpmailer->Password = (string) get_option( 'cbd_smtp_pass', '' );
		}
		// Keep From aligned with the configured sender — many SMTP servers reject
		// or spam-flag a From that isn't the authenticated mailbox.
		$from = self::from_address();
		if ( is_email( $from ) ) {
			$phpmailer->setFrom( $from, self::from_name(), false );
		}
	}

	/** Is this account still awaiting email verification? */
	public static function is_pending( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_PENDING, true );
	}

	/**
	 * Generate, store and return a fresh activation key for a user. The plain
	 * key is returned for emailing; only its salted hash is persisted.
	 */
	public function issue_key( int $user_id ): string {
		$key = wp_generate_password( 32, false );
		update_user_meta( $user_id, self::META_PENDING, '1' );
		update_user_meta( $user_id, self::META_KEY, wp_hash_password( $key ) );
		update_user_meta( $user_id, self::META_REQUESTED, time() );
		return $key;
	}

	/**
	 * Validate a plain activation key against a user and, on success, clear the
	 * pending flag so the account can sign in.
	 *
	 * @return true|\WP_Error
	 */
	public function activate( int $user_id, string $key ) {
		$invalid = new \WP_Error( 'cbd_activate', __( 'That activation link is not valid.', 'community-business-directory' ) );

		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return $invalid;
		}

		// Already verified (e.g. the link was clicked twice) — treat as success.
		if ( ! self::is_pending( $user_id ) ) {
			return true;
		}

		$stored    = (string) get_user_meta( $user_id, self::META_KEY, true );
		$requested = (int) get_user_meta( $user_id, self::META_REQUESTED, true );
		if ( '' === $key || '' === $stored ) {
			return $invalid;
		}

		if ( $requested && ( time() - $requested ) > self::EXPIRY ) {
			return new \WP_Error( 'cbd_activate_expired', __( 'That activation link has expired — please request a new one.', 'community-business-directory' ) );
		}

		if ( ! wp_check_password( $key, $stored, $user_id ) ) {
			return $invalid;
		}

		delete_user_meta( $user_id, self::META_PENDING );
		delete_user_meta( $user_id, self::META_KEY );
		delete_user_meta( $user_id, self::META_REQUESTED );

		/**
		 * Fires once a user's email is confirmed and the account is activated.
		 *
		 * @param int $user_id Newly activated user ID.
		 */
		do_action( 'cbd_account_activated', $user_id );

		return true;
	}

	/** The full activation URL to embed in the verification email. */
	public function activation_url( int $user_id, string $key ): string {
		return add_query_arg(
			[
				'cbd_activate' => $key,
				'cbd_uid'      => $user_id,
			],
			home_url( '/' )
		);
	}

	/**
	 * Issue a fresh key and email the activation link to the user. Sent as a
	 * multipart message (HTML + plain-text) from the configured sender, which
	 * cuts down on the spam-folder scoring of an HTML-only email.
	 */
	public function send_verification_email( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$key  = $this->issue_key( $user_id );
		$url  = $this->activation_url( $user_id, $key );
		$site = get_bloginfo( 'name' );
		$name = $user->first_name ?: ( $user->display_name ?: __( 'there', 'community-business-directory' ) );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Confirm your email to activate your account', 'community-business-directory' ),
			$site
		);

		// Log any wp_mail failure reason so a non-delivery is diagnosable.
		$listener = static function ( $wp_error ) use ( $user ) {
			if ( is_wp_error( $wp_error ) ) {
				error_log( 'CBD verification email to ' . $user->user_email . ' failed: ' . $wp_error->get_error_message() );
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$sent = self::send( $user->user_email, $subject, $this->email_body( $name, $url, $site ), $this->plain_body( $name, $url, $site ) );
		remove_action( 'wp_mail_failed', $listener );

		return $sent;
	}

	/**
	 * Send a branded multipart email (HTML body + plain-text alternative) using
	 * the plugin's configurable From / Reply-To. The plain-text part is attached
	 * via a one-shot phpmailer_init hook so the result is a proper
	 * multipart/alternative message. Shared so other plugin mail can reuse it.
	 *
	 * @param string $from_name Optional From display-name override (the address is
	 *                          always the authenticated sender, so SPF/DKIM hold —
	 *                          only the visible name changes, e.g. "Jo via Site").
	 * @param string $reply_to  Optional Reply-To override (e.g. the inviter).
	 */
	public static function send( string $to, string $subject, string $html, string $text = '', string $from_name = '', string $reply_to = '' ): bool {
		$from_name = $from_name !== '' ? $from_name : self::from_name();
		$from_addr = self::from_address();

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', self::encode_display_name( $from_name ), $from_addr ),
		];
		$reply = $reply_to !== '' ? sanitize_email( $reply_to ) : self::reply_to();
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$attach_alt = null;
		if ( '' !== $text ) {
			$attach_alt = static function ( $phpmailer ) use ( $text ) {
				$phpmailer->AltBody = $text;
			};
			add_action( 'phpmailer_init', $attach_alt );
		}

		$sent = wp_mail( $to, $subject, $html, $headers );

		if ( $attach_alt ) {
			remove_action( 'phpmailer_init', $attach_alt );
		}

		return $sent;
	}

	// ── Sender identity (Settings → Email) ───────────────────────────

	/** From name — configurable, defaults to the site name. */
	public static function from_name(): string {
		$name = trim( (string) get_option( 'cbd_email_from_name', '' ) );
		return $name !== '' ? $name : get_bloginfo( 'name' );
	}

	/** From address — configurable, defaults to the site admin email. */
	public static function from_address(): string {
		$addr = sanitize_email( (string) get_option( 'cbd_email_from_address', '' ) );
		return is_email( $addr ) ? $addr : (string) get_option( 'admin_email' );
	}

	/** Optional Reply-To address; empty string when unset. */
	public static function reply_to(): string {
		$addr = sanitize_email( (string) get_option( 'cbd_email_reply_to', '' ) );
		return is_email( $addr ) ? $addr : '';
	}

	/**
	 * Make a display name safe for a From/Reply-To header: drop CR/LF + quotes
	 * (header-injection guard), then MIME-encode if it carries non-ASCII, else
	 * quote it so commas and other specials don't break header parsing.
	 */
	private static function encode_display_name( string $name ): string {
		$name = trim( str_replace( [ "\r", "\n", '"' ], '', $name ) );
		if ( '' === $name ) {
			return '""';
		}
		if ( function_exists( 'mb_encode_mimeheader' ) && preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return mb_encode_mimeheader( $name, 'UTF-8' );
		}
		return '"' . $name . '"';
	}

	// ── Branding ─────────────────────────────────────────────────────

	/**
	 * Resolve the email logo URL. Prefers the explicit Settings → Email value,
	 * then the theme's custom logo, then the site icon. Returns '' if none.
	 */
	public static function logo_url(): string {
		$url = trim( (string) get_option( 'cbd_email_logo', '' ) );
		if ( $url ) {
			return $url;
		}
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $src ) {
				return (string) $src[0];
			}
		}
		return (string) get_site_icon_url( 192 );
	}

	/**
	 * Branded header. The logo sits on a navy band so a white/transparent logo
	 * (like the Inverness BID mark) always reads, and so it matches the brand
	 * regardless of the logo's own background. Falls back to the site name text.
	 */
	private function logo_html( string $site ): string {
		$logo  = self::logo_url();
		$brand = trim( (string) get_option( 'cbd_email_header_color', '' ) ) ?: '#0e2436';

		$inner = $logo
			? sprintf(
				'<img src="%s" alt="%s" style="max-width:200px;max-height:72px;height:auto;width:auto;border:0;display:inline-block;">',
				esc_url( $logo ),
				esc_attr( $site )
			)
			: sprintf( '<span style="font-size:22px;font-weight:700;color:#fff;">%s</span>', esc_html( $site ) );

		return sprintf(
			'<div style="text-align:center;background:%s;border-radius:10px;padding:22px 16px;margin:0 0 24px;">%s</div>',
			esc_attr( $brand ),
			$inner
		);
	}

	/** Build a simple, on-brand HTML verification email. */
	private function email_body( string $name, string $url, string $site ): string {
		$intro = sprintf(
			/* translators: %s: site name */
			__( 'Thanks for creating an account on %s. Please confirm your email address to activate it.', 'community-business-directory' ),
			$site
		);
		$expiry_note = sprintf(
			/* translators: %s: number of hours the link is valid */
			__( 'This link is valid for %s hours. If you did not create this account, you can safely ignore this email.', 'community-business-directory' ),
			number_format_i18n( self::EXPIRY / HOUR_IN_SECONDS )
		);

		ob_start(); ?>
<div style="background:#f3f4f6;padding:24px 0;">
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;color:#181c32;">
	<?php echo $this->logo_html( $site ); // phpcs:ignore WordPress.Security.EscapeOutput — built from escaped parts ?>
	<h2 style="margin:0 0 16px;font-size:22px;color:#181c32;"><?php echo esc_html( sprintf( __( 'Hi %s,', 'community-business-directory' ), $name ) ); ?></h2>
	<p style="font-size:15px;line-height:1.6;color:#374151;margin:0 0 24px;"><?php echo esc_html( $intro ); ?></p>
	<p style="text-align:center;margin:0 0 24px;">
		<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#29ABE1;color:#fff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 28px;border-radius:8px;">
			<?php esc_html_e( 'Activate my account', 'community-business-directory' ); ?>
		</a>
	</p>
	<p style="font-size:13px;line-height:1.6;color:#6b7280;margin:0 0 8px;"><?php esc_html_e( 'Or paste this link into your browser:', 'community-business-directory' ); ?></p>
	<p style="font-size:13px;line-height:1.5;word-break:break-all;margin:0 0 24px;"><a href="<?php echo esc_url( $url ); ?>" style="color:#29ABE1;"><?php echo esc_html( $url ); ?></a></p>
	<p style="font-size:12px;line-height:1.6;color:#9ca3af;margin:0;border-top:1px solid #e5e7eb;padding-top:16px;"><?php echo esc_html( $expiry_note ); ?></p>
</div>
</div>
<?php
		return (string) ob_get_clean();
	}

	/** Plain-text alternative — the multipart counterpart of email_body(). */
	private function plain_body( string $name, string $url, string $site ): string {
		return sprintf(
			/* translators: 1: recipient name, 2: site name, 3: activation URL, 4: hours valid */
			__( "Hi %1\$s,\n\nThanks for creating an account on %2\$s. Please confirm your email address to activate it by opening the link below:\n\n%3\$s\n\nThis link is valid for %4\$s hours. If you did not create this account, you can safely ignore this email.\n\n— %2\$s", 'community-business-directory' ),
			$name,
			$site,
			$url,
			number_format_i18n( self::EXPIRY / HOUR_IN_SECONDS )
		);
	}

	/** Sign In page permalink, falling back to wp-login. */
	private function signin_url(): string {
		$id = (int) get_option( 'cbd_login_page_id' );
		if ( $id && ( $url = get_permalink( $id ) ) ) {
			return $url;
		}
		return wp_login_url();
	}
}
