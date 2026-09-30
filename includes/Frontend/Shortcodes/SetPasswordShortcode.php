<?php
/**
 * [cbd_set_password] — branded "set / reset your password" page.
 *
 * The landing page for the tokenised links emailed by the delegate invite
 * ("Set your password") and the Forgot Password flow — replacing the stock
 * WordPress wp-login.php?action=rp screen. The link carries `key` + `login`
 * (a standard WordPress reset key); we validate it with check_password_reset_key()
 * and, on submit (AJAX cbd_set_password), set the new password and sign the user in.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

class SetPasswordShortcode {

	public function render( $atts ): string {
		$key   = isset( $_GET['key'] )   ? sanitize_text_field( wp_unslash( $_GET['key'] ) )   : '';
		$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

		$login_url = $this->login_url();

		// No / invalid token → friendly dead-end with a way to get a fresh link.
		$user = ( $key && $login ) ? check_password_reset_key( $key, $login ) : new \WP_Error( 'cbd_missing', '' );
		if ( is_wp_error( $user ) ) {
			ob_start(); ?>
<div class="cbd-login-wrap" id="cbd-setpw">
	<?php echo \cbd_auth_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in helper ?>
	<h2><?php esc_html_e( 'Set your password', 'community-business-directory' ); ?></h2>
	<div class="cbd-login-msg is-error" style="display:block;"><?php esc_html_e( 'This link is invalid or has expired. Please request a new one from the sign-in page.', 'community-business-directory' ); ?></div>
	<p class="cbd-login-footer"><a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Go to Sign In', 'community-business-directory' ); ?></a></p>
</div>
<?php
			return (string) ob_get_clean();
		}

		ob_start(); ?>
<div class="cbd-login-wrap" id="cbd-setpw">
	<?php echo \cbd_auth_logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in helper ?>
	<h2><?php esc_html_e( 'Set your password', 'community-business-directory' ); ?></h2>
	<p class="cbd-login-sub"><?php
		/* translators: %s: account display name */
		printf( esc_html__( 'Choose a new password for %s.', 'community-business-directory' ), '<strong>' . esc_html( $user->display_name ?: $user->user_login ) . '</strong>' );
	?></p>
	<p class="cbd-login-sub cbd-login-username"><?php
		/* translators: %s: account email address */
		printf( esc_html__( 'username: %s', 'community-business-directory' ), '<strong>' . esc_html( $user->user_email ) . '</strong>' );
	?></p>

	<form id="cbd-setpw-form" class="cbd-form cbd-login-form" novalidate>
		<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
		<input type="hidden" name="action" value="cbd_set_password">
		<input type="hidden" name="key"   value="<?php echo esc_attr( $key ); ?>">
		<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

		<div class="cbd-form-row">
			<label for="cbd-setpw-pass"><?php esc_html_e( 'New password', 'community-business-directory' ); ?></label>
			<div class="cbd-setpw-input">
				<input type="password" id="cbd-setpw-pass" name="password" autocomplete="new-password" minlength="8" required>
				<button type="button" class="cbd-setpw-toggle" data-cbd-togglepw aria-label="<?php esc_attr_e( 'Show password', 'community-business-directory' ); ?>">👁</button>
			</div>
			<small class="cbd-field-hint"><?php esc_html_e( 'At least 8 characters. Use a mix of letters, numbers and symbols.', 'community-business-directory' ); ?></small>
		</div>

		<div class="cbd-form-row">
			<label for="cbd-setpw-confirm"><?php esc_html_e( 'Confirm password', 'community-business-directory' ); ?></label>
			<input type="password" id="cbd-setpw-confirm" name="password_confirm" autocomplete="new-password" minlength="8" required>
		</div>

		<button type="button" class="cbd-btn cbd-btn-outline cbd-btn-full" data-cbd-genpw style="margin-bottom:10px;"><?php esc_html_e( 'Generate strong password', 'community-business-directory' ); ?></button>

		<button type="submit" class="cbd-login-submit">
			<span class="btn-text"><?php esc_html_e( 'Save password & sign in', 'community-business-directory' ); ?></span>
			<span class="btn-loading" style="display:none;"><?php esc_html_e( 'Saving…', 'community-business-directory' ); ?></span>
		</button>
		<div class="cbd-form-msg" style="display:none;"></div>
	</form>

	<p class="cbd-login-footer"><a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Back to Sign In', 'community-business-directory' ); ?></a></p>
</div>
<?php
		return (string) ob_get_clean();
	}

	private function login_url(): string {
		$id = (int) get_option( 'cbd_login_page_id' );
		if ( $id && ( $url = get_permalink( $id ) ) ) {
			return $url;
		}
		return wp_login_url();
	}
}
