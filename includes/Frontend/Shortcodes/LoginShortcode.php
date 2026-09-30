<?php
/**
 * [cbd_login] — Login form with email/password + social sign-in.
 *
 * Social buttons (Google, Facebook, Twitter / X, Instagram) render only for the
 * providers configured under Settings → Social Login. Each is a plain link into
 * the server-side OAuth redirect flow handled by CBD\Frontend\SocialAuth — there
 * is no client-side SDK. OAuth failures bounce back here with a ?cbd_login_error
 * message that is shown in the status area.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

class LoginShortcode {

	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'redirect'      => '',
				'register_url'  => '',
			],
			(array) $atts,
			'cbd_login'
		);

		// Already signed in? Bounce straight to the redirect / dashboard.
		if ( is_user_logged_in() ) {
			$dest = $atts['redirect'] ?: \cbd_login_redirect( wp_get_current_user() );
			return sprintf(
				'<div class="cbd-login-wrap"><p class="cbd-login-sub">%s</p><p class="cbd-login-footer"><a href="%s">%s</a></p></div>',
				esc_html__( 'You are already signed in.', 'community-business-directory' ),
				esc_url( $dest ),
				esc_html__( 'Continue', 'community-business-directory' )
			);
		}

		$redirect     = $atts['redirect'] ?: ( isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '' );
		// "Create one" points at the account-signup page ([cbd_signup]); only fall
		// back to the business-listing page / wp-register if signup isn't set up.
		$register_url = $atts['register_url'] ?: (
			get_option( 'cbd_signup_page_id' )   ? get_permalink( (int) get_option( 'cbd_signup_page_id' ) ) : (
			get_option( 'cbd_register_page_id' ) ? get_permalink( (int) get_option( 'cbd_register_page_id' ) ) : wp_registration_url() )
		);

		// Social sign-in is fully server-side (OAuth redirect, see SocialAuth):
		// each configured provider renders as a plain link — no JS SDK needed.
		$social = [
			'google'    => __( 'Continue with Google',    'community-business-directory' ),
			'facebook'  => __( 'Continue with Facebook',  'community-business-directory' ),
			'twitter'   => __( 'Continue with X',         'community-business-directory' ),
			'instagram' => __( 'Continue with Instagram', 'community-business-directory' ),
		];
		$any_social  = \CBD\Frontend\SocialAuth::any_enabled();
		$login_error = isset( $_GET['cbd_login_error'] ) ? sanitize_text_field( wp_unslash( $_GET['cbd_login_error'] ) ) : '';
		// Set after a successful email activation (see EmailVerification::maybe_handle).
		$activated   = isset( $_GET['cbd_activated'] );
		$notice      = $login_error ?: ( $activated ? __( 'Your email is confirmed — you can now sign in.', 'community-business-directory' ) : '' );
		$notice_kind = $login_error ? 'is-error' : ( $activated ? 'is-success' : '' );

		ob_start(); ?>
<div class="cbd-login-wrap" id="cbd-login" data-redirect="<?php echo esc_attr( $redirect ); ?>">
	<?php echo \cbd_auth_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in helper ?>
	<h2><?php esc_html_e( 'Sign In', 'community-business-directory' ); ?></h2>
	<p class="cbd-login-sub"><?php esc_html_e( 'Welcome back — sign in to continue.', 'community-business-directory' ); ?></p>

	<div class="cbd-login-msg<?php echo $notice_kind ? ' ' . esc_attr( $notice_kind ) : ''; ?>" id="cbd-login-msg" role="status" aria-live="polite"><?php echo esc_html( $notice ); ?></div>

	<form id="cbd-login-form" class="cbd-form cbd-login-form" novalidate>
		<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
		<input type="hidden" name="action" value="cbd_login">
		<input type="hidden" name="redirect" value="<?php echo esc_attr( $redirect ); ?>">

		<div class="cbd-form-row">
			<label for="cbd-login-email"><?php esc_html_e( 'Email', 'community-business-directory' ); ?></label>
			<input type="email" id="cbd-login-email" name="email" autocomplete="email" required>
		</div>
		<div class="cbd-form-row">
			<div class="cbd-login-pass-head">
				<label for="cbd-login-pass"><?php esc_html_e( 'Password', 'community-business-directory' ); ?></label>
				<a href="#" class="cbd-login-forgot" data-cbd-open="#cbd-forgot-modal"><?php esc_html_e( 'Forgot password?', 'community-business-directory' ); ?></a>
			</div>
			<input type="password" id="cbd-login-pass" name="password" autocomplete="current-password" required>
		</div>
		<button type="submit" class="cbd-login-submit"><?php esc_html_e( 'Sign In', 'community-business-directory' ); ?></button>
	</form>

	<?php // ── Forgot Password modal ── ?>
	<div id="cbd-forgot-modal" class="cbd-modal-wrap" style="display:none;">
		<div class="cbd-modal cbd-forgot-modal-inner">
			<button type="button" class="cbd-modal-close" aria-label="<?php esc_attr_e( 'Close', 'community-business-directory' ); ?>">✕</button>
			<?php echo \cbd_auth_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in helper ?>
			<h3><?php esc_html_e( 'Reset your password', 'community-business-directory' ); ?></h3>
			<p class="cbd-modal-sub"><?php esc_html_e( 'Enter the email address for your account and we\'ll send you a link to set a new password.', 'community-business-directory' ); ?></p>
			<form id="cbd-forgot-form" class="cbd-form">
				<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
				<input type="hidden" name="action" value="cbd_forgot_password">
				<div class="cbd-form-row">
					<label for="cbd-forgot-email"><?php esc_html_e( 'Email', 'community-business-directory' ); ?></label>
					<input type="email" id="cbd-forgot-email" name="email" autocomplete="email" required>
				</div>
				<button type="submit" class="cbd-btn cbd-btn-primary cbd-btn-full">
					<span class="btn-text"><?php esc_html_e( 'Send reset link', 'community-business-directory' ); ?></span>
					<span class="btn-loading" style="display:none;"><?php esc_html_e( 'Sending…', 'community-business-directory' ); ?></span>
				</button>
				<div class="cbd-form-msg" style="display:none;"></div>
			</form>
		</div>
	</div>

	<?php if ( $any_social ) : ?>
	<div class="cbd-login-divider"><?php esc_html_e( 'Or', 'community-business-directory' ); ?></div>

	<div class="cbd-social-list">
		<?php foreach ( $social as $provider => $label ) :
			if ( ! \CBD\Frontend\SocialAuth::is_enabled( $provider ) ) {
				continue;
			}
			$url = \CBD\Frontend\SocialAuth::start_url( $provider, $redirect );
		?>
		<a class="cbd-social-btn cbd-social-<?php echo esc_attr( $provider ); ?>" href="<?php echo esc_url( $url ); ?>" rel="nofollow">
			<?php echo $this->provider_icon( $provider ); // phpcs:ignore — static inline SVG ?>
			<span><?php echo esc_html( $label ); ?></span>
		</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<p class="cbd-login-footer">
		<?php
		printf(
			/* translators: %s: register page link */
			wp_kses( __( 'No account? <a href="%s">Create one</a>.', 'community-business-directory' ), [ 'a' => [ 'href' => [] ] ] ),
			esc_url( $register_url )
		);
		?>
	</p>
</div>
<?php
		return (string) ob_get_clean();
	}

	/**
	 * Inline brand SVG for a social provider. Returns '' for unknown providers.
	 */
	private function provider_icon( string $provider ): string {
		switch ( $provider ) {
			case 'google':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.27-4.74 3.27-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.99.66-2.25 1.06-3.71 1.06-2.85 0-5.27-1.92-6.13-4.51H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.87 14.13a6.6 6.6 0 0 1 0-4.26V7.04H2.18a11 11 0 0 0 0 9.92z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15A11 11 0 0 0 12 1 11 11 0 0 0 2.18 7.04l3.69 2.84C6.73 7.3 9.15 5.38 12 5.38z"/></svg>';
			case 'facebook':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.5-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.89h-2.33v6.99A10 10 0 0 0 22 12z"/></svg>';
			case 'twitter':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231Zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77Z"/></svg>';
			case 'instagram':
				return '<svg viewBox="0 0 24 24" aria-hidden="true"><defs><radialGradient id="cbd-ig" cx="0.3" cy="1" r="1"><stop offset="0" stop-color="#FED576"/><stop offset="0.25" stop-color="#F47133"/><stop offset="0.5" stop-color="#BC3081"/><stop offset="1" stop-color="#4C63D2"/></radialGradient></defs><path fill="url(#cbd-ig)" d="M12 2c2.72 0 3.06.01 4.12.06 1.07.05 1.8.22 2.43.47.66.25 1.21.59 1.77 1.15.56.56.9 1.11 1.15 1.77.25.64.42 1.36.47 2.43.05 1.07.06 1.4.06 4.12s-.01 3.06-.06 4.12c-.05 1.07-.22 1.8-.47 2.43-.25.66-.59 1.21-1.15 1.77-.56.56-1.11.9-1.77 1.15-.64.25-1.36.42-2.43.47-1.07.05-1.4.06-4.12.06s-3.06-.01-4.12-.06c-1.07-.05-1.8-.22-2.43-.47a4.9 4.9 0 0 1-1.77-1.15 4.9 4.9 0 0 1-1.15-1.77c-.25-.64-.42-1.36-.47-2.43C2.01 15.06 2 14.72 2 12s.01-3.06.06-4.12c.05-1.07.22-1.8.47-2.43.25-.66.59-1.21 1.15-1.77.56-.56 1.11-.9 1.77-1.15.64-.25 1.36-.42 2.43-.47C8.94 2.01 9.28 2 12 2Zm0 5a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0 8.25a3.25 3.25 0 1 1 0-6.5 3.25 3.25 0 0 1 0 6.5ZM17.25 5.5a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Z"/></svg>';
		}
		return '';
	}
}
