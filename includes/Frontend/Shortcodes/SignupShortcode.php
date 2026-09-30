<?php
/**
 * [cbd_signup] — Create-account form with email verification.
 *
 * Distinct from [cbd_register_business] (which lists a *business* and assumes a
 * logged-in user): this registers a brand-new WordPress user. Submission goes to
 * the cbd_signup AJAX handler, which creates an inactive account and emails an
 * activation link (see CBD\Frontend\EmailVerification). The visitor is NOT
 * signed in until they confirm their email, so on success we swap the form for a
 * "check your inbox" panel rather than redirecting.
 *
 * Reuses the .cbd-login-* styling so it sits visually alongside [cbd_login].
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

class SignupShortcode {

	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'login_url' => '',
			],
			(array) $atts,
			'cbd_signup'
		);

		// Already signed in? Nothing to create.
		if ( is_user_logged_in() ) {
			$dest = \cbd_login_redirect( wp_get_current_user() );
			return sprintf(
				'<div class="cbd-login-wrap"><p class="cbd-login-sub">%s</p><p class="cbd-login-footer"><a href="%s">%s</a></p></div>',
				esc_html__( 'You are already signed in.', 'community-business-directory' ),
				esc_url( $dest ),
				esc_html__( 'Continue', 'community-business-directory' )
			);
		}

		$login_url = $atts['login_url'] ?: (
			get_option( 'cbd_login_page_id' )
				? get_permalink( (int) get_option( 'cbd_login_page_id' ) )
				: wp_login_url()
		);

		ob_start(); ?>
<div class="cbd-login-wrap" id="cbd-signup">
	<?php echo \cbd_auth_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in helper ?>
	<h2><?php esc_html_e( 'Create your account', 'community-business-directory' ); ?></h2>
	<p class="cbd-login-sub"><?php esc_html_e( 'Sign up to follow businesses, save favourites and list your own.', 'community-business-directory' ); ?></p>

	<div class="cbd-login-msg" id="cbd-signup-msg" role="status" aria-live="polite"></div>

	<form id="cbd-signup-form" class="cbd-form cbd-login-form" novalidate>
		<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
		<input type="hidden" name="action" value="cbd_signup">

		<div class="cbd-form-cols">
			<div class="cbd-form-row">
				<label for="cbd-signup-first"><?php esc_html_e( 'First name', 'community-business-directory' ); ?></label>
				<input type="text" id="cbd-signup-first" name="first_name" autocomplete="given-name" required>
			</div>
			<div class="cbd-form-row">
				<label for="cbd-signup-last"><?php esc_html_e( 'Last name', 'community-business-directory' ); ?></label>
				<input type="text" id="cbd-signup-last" name="last_name" autocomplete="family-name">
			</div>
		</div>

		<div class="cbd-form-row">
			<label for="cbd-signup-email"><?php esc_html_e( 'Email', 'community-business-directory' ); ?></label>
			<input type="email" id="cbd-signup-email" name="email" autocomplete="email" required>
		</div>
		<div class="cbd-form-row">
			<label for="cbd-signup-pass"><?php esc_html_e( 'Password', 'community-business-directory' ); ?></label>
			<input type="password" id="cbd-signup-pass" name="password" autocomplete="new-password" minlength="8" required>
			<small><?php esc_html_e( 'At least 8 characters.', 'community-business-directory' ); ?></small>
		</div>
		<div class="cbd-form-row">
			<label for="cbd-signup-pass2"><?php esc_html_e( 'Confirm password', 'community-business-directory' ); ?></label>
			<input type="password" id="cbd-signup-pass2" name="password_confirm" autocomplete="new-password" minlength="8" required>
		</div>

		<button type="submit" class="cbd-login-submit"><?php esc_html_e( 'Create account', 'community-business-directory' ); ?></button>
	</form>

	<p class="cbd-login-footer">
		<?php
		printf(
			/* translators: %s: sign-in page link */
			wp_kses( __( 'Already have an account? <a href="%s">Sign in</a>.', 'community-business-directory' ), [ 'a' => [ 'href' => [] ] ] ),
			esc_url( $login_url )
		);
		?>
	</p>
</div>
<?php
		return (string) ob_get_clean();
	}
}
