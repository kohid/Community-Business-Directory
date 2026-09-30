<?php
/**
 * [cbd_social_sync] — front-end self-service page for the Facebook & Instagram
 * feeds, so the site owner's team can connect the social platforms without a
 * WordPress dashboard login.
 *
 * Lives on the auto-created "/socmed" page. Visible only to `manage_options`
 * users and invited team members (`cbd_site_team` user-meta — see
 * CBD\Admin\TeamInvite); CBD\Core\Plugin::guard_socmed_page() bounces everyone
 * else before this even renders, and render() re-checks.
 *
 * Layout: a live preview of both feeds on the left (the exact shortcode output
 * the home page uses), tabbed Facebook / Instagram settings on the right. Saves
 * through the `cbd_socmed_save` AJAX action into the same options the
 * "Community Directory → Facebook / Instagram" admin pages write, via
 * FacebookSync::save() / InstagramSync::save(). The page reloads after a save so
 * pasted widget <script>s re-run and the preview reflects reality.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

use CBD\Modules\FacebookSync;
use CBD\Modules\InstagramSync;

defined( 'ABSPATH' ) || exit;

class SocialSyncShortcode {

	/**
	 * True while the left-hand preview is being built. FacebookShortcode /
	 * InstagramShortcode read this to suppress their admin "Open … settings"
	 * hint (which points at wp-admin) — the /socmed page has its own placeholder.
	 */
	public static bool $rendering_preview = false;

	/** Everyone who may view /socmed and save its settings. */
	public static function current_user_allowed(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$uid = get_current_user_id();
		return $uid && '1' === (string) get_user_meta( $uid, 'cbd_site_team', true );
	}

	/** wp_ajax_cbd_socmed_save — persist one platform's settings from the front-end form. */
	public function ajax_save(): void {
		if ( ! check_ajax_referer( 'cbd_nonce', 'cbd_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed — reload the page and try again.', 'community-business-directory' ) ], 403 );
		}
		if ( ! self::current_user_allowed() ) {
			wp_send_json_error( [ 'message' => __( 'You don’t have permission to change these settings.', 'community-business-directory' ) ], 403 );
		}

		$in       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput — each field is sanitised inside *Sync::save().
		$platform = sanitize_key( (string) ( $in['platform'] ?? '' ) );

		if ( 'facebook' === $platform ) {
			FacebookSync::save( $in );
			$msg = __( 'Facebook settings saved.', 'community-business-directory' );
		} elseif ( 'instagram' === $platform ) {
			InstagramSync::save( $in );
			$msg = __( 'Instagram settings saved.', 'community-business-directory' );
		} else {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'community-business-directory' ) ] );
		}

		wp_send_json_success( [ 'message' => $msg . ' ' . __( 'Refreshing the preview…', 'community-business-directory' ), 'reload' => true ] );
	}

	// ── Render ───────────────────────────────────────────────────────

	public function render( array $atts ): string {
		if ( ! self::current_user_allowed() ) {
			$msg = is_user_logged_in()
				? __( 'You don’t have access to this page.', 'community-business-directory' )
				: __( 'Please sign in to manage the social feeds.', 'community-business-directory' );
			return '<div class="cbd-wrap"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$nonce = wp_create_nonce( 'cbd_nonce' );

		self::$rendering_preview = true;
		$fb_preview = trim( do_shortcode( '[cbd_facebook_page]' ) );
		$ig_preview = trim( do_shortcode( '[cbd_instagram_feed]' ) );
		self::$rendering_preview = false;

		$empty_fb = '<p class="cbd-socmed-preview-empty">' . esc_html__( 'Nothing to show yet. Set it up in the Facebook tab and click Save — your feed appears here.', 'community-business-directory' ) . '</p>';
		$empty_ig = '<p class="cbd-socmed-preview-empty">' . esc_html__( 'Nothing to show yet. Set it up in the Instagram tab and click Save — your feed appears here.', 'community-business-directory' ) . '</p>';

		ob_start(); ?>
<div class="cbd-wrap cbd-socmed" id="cbd-socmed-page">
  <div class="cbd-socmed-grid">

    <aside class="cbd-socmed-preview" aria-label="<?php esc_attr_e( 'Live preview', 'community-business-directory' ); ?>">
      <h2 class="cbd-socmed-preview-title"><?php esc_html_e( 'Live preview', 'community-business-directory' ); ?></h2>
      <p class="cbd-socmed-preview-note"><?php esc_html_e( 'This is exactly what visitors see on the home page. Save a change and it updates here.', 'community-business-directory' ); ?></p>

      <div class="cbd-socmed-preview-item">
        <span class="cbd-socmed-preview-label"><?php esc_html_e( 'Facebook', 'community-business-directory' ); ?></span>
        <?php echo $fb_preview !== '' ? $fb_preview : $empty_fb; // phpcs:ignore WordPress.Security.EscapeOutput — shortcode output / esc_html__ ?>
      </div>

      <div class="cbd-socmed-preview-item">
        <span class="cbd-socmed-preview-label"><?php esc_html_e( 'Instagram', 'community-business-directory' ); ?></span>
        <?php echo $ig_preview !== '' ? $ig_preview : $empty_ig; // phpcs:ignore WordPress.Security.EscapeOutput — shortcode output / esc_html__ ?>
      </div>
    </aside>

    <div class="cbd-socmed-panel">
      <h1><?php esc_html_e( 'Sync your social feeds', 'community-business-directory' ); ?></h1>
      <p class="cbd-socmed-lead"><?php esc_html_e( 'Connect Facebook and Instagram so their latest posts show on the website. The quickest way is to paste a widget code from a free service like Behold or SnapWidget.', 'community-business-directory' ); ?></p>

      <div class="cbd-socmed-tabs" role="tablist">
        <button type="button" class="cbd-socmed-tab is-active" role="tab" aria-selected="true" data-tab="facebook"><?php esc_html_e( 'Facebook', 'community-business-directory' ); ?></button>
        <button type="button" class="cbd-socmed-tab" role="tab" aria-selected="false" data-tab="instagram"><?php esc_html_e( 'Instagram', 'community-business-directory' ); ?></button>
      </div>

      <section class="cbd-socmed-tabpanel is-active" id="cbd-socmed-facebook" role="tabpanel">
        <?php echo $this->facebook_form( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput — built with esc_* below ?>
      </section>

      <section class="cbd-socmed-tabpanel" id="cbd-socmed-instagram" role="tabpanel" hidden>
        <?php echo $this->instagram_form( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput — built with esc_* below ?>
      </section>
    </div>

  </div>
</div>
<?php
		return (string) ob_get_clean();
	}

	/** One inline "where to go" link, appended right to the instruction step it supports. */
	private function steplink( string $text, string $url ): string {
		return ' <a class="cbd-socmed-steplink" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . ' &#8599;</a>';
	}

	// ── Facebook tab ─────────────────────────────────────────────────

	private function facebook_form( string $nonce ): string {
		$mode  = (string) get_option( FacebookSync::OPT_MODE, 'auto' );
		$modes = [
			'auto'  => __( 'Automatic — best available', 'community-business-directory' ),
			'code'  => __( 'Widget code (full width) — recommended', 'community-business-directory' ),
			'embed' => __( 'Facebook’s own box (max 500px)', 'community-business-directory' ),
			'feed'  => __( 'Native posts (needs the advanced setup below)', 'community-business-directory' ),
		];

		ob_start(); ?>
<form id="cbd-socmed-fb-form" class="cbd-socmed-form" data-platform="facebook">
  <input type="hidden" name="action" value="cbd_socmed_save">
  <input type="hidden" name="cbd_nonce" value="<?php echo esc_attr( $nonce ); ?>">
  <input type="hidden" name="platform" value="facebook">
  <?php echo $this->fb_hidden_passthrough(); // phpcs:ignore WordPress.Security.EscapeOutput — esc_attr'd inside ?>

  <label class="cbd-socmed-field">
    <span><?php esc_html_e( 'How the Facebook feed is shown', 'community-business-directory' ); ?></span>
    <select name="cbd_fb_mode">
      <?php foreach ( $modes as $val => $label ) : ?>
        <option value="<?php echo esc_attr( $val ); ?>"<?php selected( $mode, $val ); ?>><?php echo esc_html( $label ); ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="cbd-socmed-field">
    <span><?php esc_html_e( 'Facebook Page address', 'community-business-directory' ); ?></span>
    <input type="url" name="cbd_fb_page_url" value="<?php echo esc_attr( (string) get_option( FacebookSync::OPT_PAGE_URL, '' ) ); ?>" placeholder="https://www.facebook.com/YourPage">
  </label>

  <div class="cbd-socmed-subtabs" role="tablist">
    <button type="button" class="cbd-socmed-subtab is-active" role="tab" aria-selected="true" data-subtab="fb-snap"><?php esc_html_e( 'SnapWidget', 'community-business-directory' ); ?></button>
    <button type="button" class="cbd-socmed-subtab" role="tab" aria-selected="false" data-subtab="fb-native"><?php esc_html_e( 'Native Meta sync (advanced, optional)', 'community-business-directory' ); ?></button>
  </div>

  <div class="cbd-socmed-subpanel is-active" data-subpanel="fb-snap" role="tabpanel">
    <p class="cbd-socmed-method-intro"><?php esc_html_e( 'The quick way — a free widget service holds the Meta permissions for you. Full width, about 10 minutes.', 'community-business-directory' ); ?></p>
    <ol class="cbd-socmed-steps">
      <li><?php echo esc_html__( 'Sign up free at SnapWidget (does Facebook and Instagram) or Curator.', 'community-business-directory' )
        . $this->steplink( __( 'SnapWidget', 'community-business-directory' ), 'https://snapwidget.com' )
        . $this->steplink( __( 'Curator.io', 'community-business-directory' ), 'https://curator.io' ); // phpcs:ignore WordPress.Security.EscapeOutput — esc_html__ + steplink() escape ?></li>
      <li><?php esc_html_e( 'Create a widget, choose “Facebook Page”, connect and pick your Page.', 'community-business-directory' ); ?></li>
      <li><?php esc_html_e( 'Copy the embed code it gives you and paste it below.', 'community-business-directory' ); ?></li>
      <li><?php esc_html_e( 'Set “How the Facebook feed is shown” (above) to “Widget code”, then Save.', 'community-business-directory' ); ?></li>
    </ol>
    <label class="cbd-socmed-field">
      <span><?php esc_html_e( 'Paste widget code', 'community-business-directory' ); ?></span>
      <textarea name="cbd_fb_embed_html" rows="5" spellcheck="false" placeholder="&lt;script src=&quot;https://…&quot;&gt;&lt;/script&gt;"><?php echo esc_textarea( FacebookSync::embed_html() ); ?></textarea>
    </label>
  </div>

  <div class="cbd-socmed-subpanel" data-subpanel="fb-native" role="tabpanel" hidden>
    <p class="cbd-socmed-method-intro"><?php esc_html_e( 'Shows Facebook posts as cards styled to match the rest of the site. Needs a Meta developer app, about 45 minutes. If a step won’t let you continue, stop and use SnapWidget instead — nothing will be broken.', 'community-business-directory' ); ?></p>
    <ol class="cbd-socmed-steps">
      <li><?php echo esc_html__( 'Go to developers.facebook.com and click “Create App”. Choose the “Business” type and connect it to your business portfolio.', 'community-business-directory' )
        . $this->steplink( __( 'Your Meta apps', 'community-business-directory' ), 'https://developers.facebook.com/apps/' )
        . $this->steplink( __( 'Business settings & verification', 'community-business-directory' ), 'https://business.facebook.com/settings' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
      <li><?php esc_html_e( 'Add the product “Facebook Login for Business”.', 'community-business-directory' ); ?></li>
      <li><?php echo esc_html__( 'Under “Use cases” (or “Permissions”), add: pages_show_list, pages_read_engagement, pages_read_user_content, instagram_basic.', 'community-business-directory' )
        . $this->steplink( __( 'Permissions reference', 'community-business-directory' ), 'https://developers.facebook.com/docs/permissions' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
      <li><?php echo esc_html__( 'Open the “Graph API Explorer” tool, choose your app, click “Generate Access Token”, and allow access to your Page.', 'community-business-directory' )
        . $this->steplink( __( 'Graph API Explorer', 'community-business-directory' ), 'https://developers.facebook.com/tools/explorer/' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
      <li><?php esc_html_e( 'In the “User or Page” box, switch to the Page token for your Page and copy it.', 'community-business-directory' ); ?></li>
      <li><?php echo esc_html__( 'Click the small ⓘ next to the token → “Open in Access Token Tool” → “Extend Access Token”. Copy this longer token — it does not expire.', 'community-business-directory' )
        . $this->steplink( __( 'Access Token Tool', 'community-business-directory' ), 'https://developers.facebook.com/tools/debug/accesstoken/' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
      <li><?php esc_html_e( 'Paste your numeric Page ID and the extended token below, set “How the Facebook feed is shown” (above) to “Native posts”, and Save.', 'community-business-directory' ); ?></li>
    </ol>
    <label class="cbd-socmed-field">
      <span><?php esc_html_e( 'Page ID (numeric — not your App ID)', 'community-business-directory' ); ?></span>
      <input type="text" name="cbd_fb_page_id" value="<?php echo esc_attr( (string) get_option( FacebookSync::OPT_PAGE_ID, '' ) ); ?>">
    </label>
    <label class="cbd-socmed-field">
      <span><?php esc_html_e( 'Page Access Token', 'community-business-directory' ); ?></span>
      <input type="text" name="cbd_fb_access_token" autocomplete="off" value="<?php echo esc_attr( (string) get_option( FacebookSync::OPT_TOKEN, '' ) ); ?>">
    </label>
  </div>

  <div class="cbd-form-msg" style="display:none;"></div>
  <button type="submit" class="cbd-btn cbd-btn-primary cbd-socmed-save">
    <span class="btn-text"><?php esc_html_e( 'Save Facebook settings', 'community-business-directory' ); ?></span>
    <span class="btn-loading" style="display:none;"><?php esc_html_e( 'Saving…', 'community-business-directory' ); ?></span>
  </button>
</form>
<?php
		return (string) ob_get_clean();
	}

	/** Hidden inputs that round-trip the Facebook fields /socmed doesn't show, so a save never wipes them. */
	private function fb_hidden_passthrough(): string {
		$out  = '<input type="hidden" name="cbd_fb_count" value="' . esc_attr( (string) FacebookSync::count() ) . '">';
		foreach ( array_filter( explode( ',', FacebookSync::tabs() ) ) as $tab ) {
			$out .= '<input type="hidden" name="cbd_fb_tabs[]" value="' . esc_attr( $tab ) . '">';
		}
		$out .= '<input type="hidden" name="cbd_fb_height" value="' . esc_attr( (string) FacebookSync::height() ) . '">';
		if ( FacebookSync::hide_cover() ) {
			$out .= '<input type="hidden" name="cbd_fb_hide_cover" value="1">';
		}
		if ( FacebookSync::small_header() ) {
			$out .= '<input type="hidden" name="cbd_fb_small_header" value="1">';
		}
		$out .= '<input type="hidden" name="cbd_fb_cache_ttl" value="' . esc_attr( (string) get_option( FacebookSync::OPT_TTL, 30 ) ) . '">';
		return $out;
	}

	// ── Instagram tab ────────────────────────────────────────────────

	private function instagram_form( string $nonce ): string {
		$mode  = (string) get_option( InstagramSync::OPT_MODE, 'auto' );
		$modes = [
			'auto'    => __( 'Automatic — best available', 'community-business-directory' ),
			'code'    => __( 'Widget code (full width) — recommended', 'community-business-directory' ),
			'profile' => __( '“Follow on Instagram” button only', 'community-business-directory' ),
			'feed'    => __( 'Native grid (needs advanced setup)', 'community-business-directory' ),
		];
		$ig_nonce = wp_create_nonce( 'cbd_instagram' );

		ob_start(); ?>
<form id="cbd-socmed-ig-form" class="cbd-socmed-form" data-platform="instagram">
  <input type="hidden" name="action" value="cbd_socmed_save">
  <input type="hidden" name="cbd_nonce" value="<?php echo esc_attr( $nonce ); ?>">
  <input type="hidden" name="platform" value="instagram">
  <?php echo $this->ig_hidden_passthrough(); // phpcs:ignore WordPress.Security.EscapeOutput — esc_attr'd inside ?>

  <label class="cbd-socmed-field">
    <span><?php esc_html_e( 'How the Instagram feed is shown', 'community-business-directory' ); ?></span>
    <select name="cbd_ig_mode">
      <?php foreach ( $modes as $val => $label ) : ?>
        <option value="<?php echo esc_attr( $val ); ?>"<?php selected( $mode, $val ); ?>><?php echo esc_html( $label ); ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="cbd-socmed-field">
    <span><?php esc_html_e( 'Instagram @username', 'community-business-directory' ); ?></span>
    <input type="text" name="cbd_ig_username" value="<?php echo esc_attr( InstagramSync::username() ); ?>" placeholder="yourhandle">
  </label>

  <div class="cbd-socmed-subtabs" role="tablist">
    <button type="button" class="cbd-socmed-subtab is-active" role="tab" aria-selected="true" data-subtab="ig-snap"><?php esc_html_e( 'SnapWidget', 'community-business-directory' ); ?></button>
    <button type="button" class="cbd-socmed-subtab" role="tab" aria-selected="false" data-subtab="ig-native"><?php esc_html_e( 'Native Meta sync (advanced, optional)', 'community-business-directory' ); ?></button>
  </div>

  <div class="cbd-socmed-subpanel is-active" data-subpanel="ig-snap" role="tabpanel">
    <p class="cbd-socmed-method-intro"><?php esc_html_e( 'The quick way — a free widget service holds the Meta permissions for you. Full width, about 10 minutes.', 'community-business-directory' ); ?></p>
    <ol class="cbd-socmed-steps">
      <li><?php echo esc_html__( 'Sign up free at Behold (Instagram only, tidiest) or SnapWidget.', 'community-business-directory' )
        . $this->steplink( __( 'Behold', 'community-business-directory' ), 'https://behold.so' )
        . $this->steplink( __( 'SnapWidget', 'community-business-directory' ), 'https://snapwidget.com' ); // phpcs:ignore WordPress.Security.EscapeOutput — esc_html__ + steplink() escape ?></li>
      <li><?php echo esc_html__( 'Connect the Instagram account — it must be a Business or Creator account.', 'community-business-directory' )
        . $this->steplink( __( 'Switch to a Business account', 'community-business-directory' ), 'https://help.instagram.com/502981923235522' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
      <li><?php esc_html_e( 'Copy the embed code and paste it below.', 'community-business-directory' ); ?></li>
      <li><?php esc_html_e( 'Set “How the Instagram feed is shown” (above) to “Widget code”, then Save.', 'community-business-directory' ); ?></li>
    </ol>
    <label class="cbd-socmed-field">
      <span><?php esc_html_e( 'Paste widget code', 'community-business-directory' ); ?></span>
      <textarea name="cbd_ig_embed_html" rows="5" spellcheck="false" placeholder="&lt;script src=&quot;https://…&quot;&gt;&lt;/script&gt;"><?php echo esc_textarea( InstagramSync::embed_html() ); ?></textarea>
    </label>
  </div>

  <div class="cbd-socmed-subpanel" data-subpanel="ig-native" role="tabpanel" hidden>
    <p class="cbd-socmed-method-intro"><?php esc_html_e( 'Shows Instagram posts as a grid styled to match the rest of the site. It builds on the Facebook native sync, so set that up first.', 'community-business-directory' ); ?></p>
    <ol class="cbd-socmed-steps">
      <li><?php esc_html_e( 'Finish “Native Meta sync” on the Facebook tab (developer app, permissions, extended Page token).', 'community-business-directory' ); ?></li>
      <li><?php echo esc_html__( 'Make sure this Instagram account is a Business or Creator account linked to that Facebook Page.', 'community-business-directory' )
        . $this->steplink( __( 'Switch to a Business account', 'community-business-directory' ), 'https://help.instagram.com/502981923235522' )
        . $this->steplink( __( 'Instagram Platform (docs)', 'community-business-directory' ), 'https://developers.facebook.com/docs/instagram-platform/' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
      <li><?php esc_html_e( 'Click “Detect from Facebook Page” below — it fills in the account ID for you.', 'community-business-directory' ); ?></li>
      <li><?php esc_html_e( 'Set “How the Instagram feed is shown” (above) to “Native grid” and Save. Leave the token blank to reuse the Facebook one.', 'community-business-directory' ); ?></li>
    </ol>
    <label class="cbd-socmed-field">
      <span><?php esc_html_e( 'Instagram account ID', 'community-business-directory' ); ?></span>
      <input type="text" name="cbd_ig_user_id" id="cbd-socmed-ig-id" value="<?php echo esc_attr( (string) get_option( InstagramSync::OPT_USER_ID, '' ) ); ?>">
    </label>
    <p>
      <button type="button" class="cbd-btn cbd-btn-outline" id="cbd-socmed-ig-detect" data-nonce="<?php echo esc_attr( $ig_nonce ); ?>"><?php esc_html_e( 'Detect from Facebook Page', 'community-business-directory' ); ?></button>
      <span class="cbd-socmed-detect-msg" aria-live="polite"></span>
    </p>
    <label class="cbd-socmed-field">
      <span><?php esc_html_e( 'Access Token', 'community-business-directory' ); ?></span>
      <input type="text" name="cbd_ig_access_token" autocomplete="off" value="<?php echo esc_attr( (string) get_option( InstagramSync::OPT_TOKEN, '' ) ); ?>">
      <small><?php esc_html_e( 'Leave blank to reuse the Facebook Page token.', 'community-business-directory' ); ?></small>
    </label>
  </div>

  <div class="cbd-form-msg" style="display:none;"></div>
  <button type="submit" class="cbd-btn cbd-btn-primary cbd-socmed-save">
    <span class="btn-text"><?php esc_html_e( 'Save Instagram settings', 'community-business-directory' ); ?></span>
    <span class="btn-loading" style="display:none;"><?php esc_html_e( 'Saving…', 'community-business-directory' ); ?></span>
  </button>
</form>
<?php
		return (string) ob_get_clean();
	}

	/** Hidden inputs that round-trip the Instagram fields /socmed doesn't show. */
	private function ig_hidden_passthrough(): string {
		return '<input type="hidden" name="cbd_ig_count" value="' . esc_attr( (string) InstagramSync::count() ) . '">'
			. '<input type="hidden" name="cbd_ig_layout" value="' . esc_attr( InstagramSync::layout() ) . '">'
			. '<input type="hidden" name="cbd_ig_columns" value="' . esc_attr( (string) InstagramSync::columns() ) . '">'
			. '<input type="hidden" name="cbd_ig_cache_ttl" value="' . esc_attr( (string) get_option( InstagramSync::OPT_TTL, 30 ) ) . '">'
			. '<input type="hidden" name="cbd_ig_profile_url" value="' . esc_attr( (string) get_option( InstagramSync::OPT_PROFILE_URL, '' ) ) . '">';
	}
}
