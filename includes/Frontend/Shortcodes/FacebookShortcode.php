<?php
/**
 * [cbd_facebook_page] — surface a connected Facebook Page on the site.
 *
 * Renders one of two ways (see CBD\Modules\FacebookSync for the config/data):
 *   - embed : Facebook's official Page Plugin (SDK iframe) — needs only a URL.
 *   - feed  : native cards built from posts synced via the Graph API.
 *   - code  : a third-party widget snippet (Behold, SnapWidget, Curator…) pasted
 *             on the admin page, output verbatim and stretched to full width.
 *
 * Attributes (each falls back to the "Facebook" admin settings when omitted):
 *   mode="auto|embed|feed|code"  page="https://facebook.com/YourPage"  count="6"
 *   tabs="timeline,events"  height="700"  hide_cover="1"  small_header="1"
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

use CBD\Modules\FacebookSync;

defined( 'ABSPATH' ) || exit;

class FacebookShortcode {

	/** Emit the shared SDK loader + #fb-root only once per request. */
	private static bool $sdk_printed = false;

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'mode'         => '',
			'page'         => '',
			'count'        => '',
			'tabs'         => '',
			'height'       => '',
			'hide_cover'   => '',
			'small_header' => '',
		], $atts, 'cbd_facebook_page' );

		$page_url = $atts['page'] !== '' ? esc_url_raw( $atts['page'] ) : FacebookSync::page_url();
		$mode     = FacebookSync::resolve_mode( in_array( $atts['mode'], [ 'embed', 'feed', 'code', 'auto' ], true ) ? $atts['mode'] : '' );

		if ( $mode === 'code' && FacebookSync::has_embed() ) {
			return $this->render_code();
		}

		// Nothing to show and nothing configured — stay silent for visitors, but
		// nudge admins toward the settings screen so a blank block isn't a mystery.
		if ( $page_url === '' && ! FacebookSync::feed_configured() ) {
			return $this->admin_hint();
		}

		return $mode === 'feed'
			? $this->render_feed( $atts, $page_url )
			: $this->render_embed( $atts, $page_url );
	}

	// ── Code mode (third-party embed snippet) ─────────────────────

	/**
	 * Output a third-party feed widget snippet (Behold, SnapWidget, Curator,
	 * Elfsight, LightWidget…) exactly as pasted in Community Directory → Facebook.
	 * The snippet is admin-authored trusted HTML — stored raw like WordPress's
	 * own Custom HTML widget — so it prints unescaped; the wrapper only gives it
	 * the plugin scope and full width.
	 */
	private function render_code(): string {
		return '<div class="cbd-wrap cbd-fb cbd-fb-embed-code">'
			. FacebookSync::embed_html() // phpcs:ignore WordPress.Security.EscapeOutput — admin-authored trusted markup
			. '</div>';
	}

	// ── Feed mode (native synced cards) ───────────────────────────

	private function render_feed( array $atts, string $page_url ): string {
		$items = FacebookSync::get_feed();
		$limit = (int) $atts['count'] > 0 ? (int) $atts['count'] : FacebookSync::count();
		$items = array_slice( $items, 0, $limit );

		if ( ! $items ) {
			return $this->admin_hint( __( 'No Facebook posts synced yet. Open Community Directory → Facebook and click “Refresh”.', 'community-business-directory' ) );
		}

		ob_start(); ?>
<div class="cbd-wrap cbd-fb cbd-fb-feed">
  <div class="cbd-fb-head">
    <span class="cbd-fb-mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7.03H7.9v-2.9h2.54V9.85c0-2.51 1.49-3.9 3.78-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.44 2.9h-2.34V22c4.78-.79 8.43-4.94 8.43-9.94Z"/></svg>
    </span>
    <span class="cbd-fb-head-text"><?php esc_html_e( 'Latest from Facebook', 'community-business-directory' ); ?></span>
    <?php if ( $page_url ) : ?>
      <a class="cbd-fb-follow" href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Follow', 'community-business-directory' ); ?></a>
    <?php endif; ?>
  </div>

  <div class="cbd-fb-posts">
    <?php foreach ( $items as $post ) : ?>
      <article class="cbd-fb-post">
        <?php if ( ! empty( $post['image'] ) ) : ?>
          <a class="cbd-fb-post-media" href="<?php echo esc_url( $post['permalink'] ?: $page_url ); ?>" target="_blank" rel="noopener noreferrer">
            <img src="<?php echo esc_url( $post['image'] ); ?>" alt="" loading="lazy">
          </a>
        <?php endif; ?>
        <div class="cbd-fb-post-body">
          <?php if ( ! empty( $post['message'] ) ) : ?>
            <p class="cbd-fb-post-text"><?php echo make_clickable( nl2br( esc_html( wp_trim_words( $post['message'], 55 ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped before linkifying ?></p>
          <?php endif; ?>
          <div class="cbd-fb-post-meta">
            <?php if ( ! empty( $post['created'] ) ) : ?>
              <time datetime="<?php echo esc_attr( $post['created'] ); ?>"><?php echo esc_html( $this->time_ago( $post['created'] ) ); ?></time>
            <?php endif; ?>
            <?php if ( ! empty( $post['permalink'] ) ) : ?>
              <a href="<?php echo esc_url( $post['permalink'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on Facebook', 'community-business-directory' ); ?></a>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>
<?php
		return (string) ob_get_clean();
	}

	// ── Embed mode (official Page Plugin) ─────────────────────────

	private function render_embed( array $atts, string $page_url ): string {
		if ( $page_url === '' ) {
			return $this->admin_hint();
		}

		$tabs    = $atts['tabs'] !== '' ? sanitize_text_field( $atts['tabs'] ) : FacebookSync::tabs();
		$height  = (int) $atts['height'] > 0 ? max( 130, min( 2000, (int) $atts['height'] ) ) : FacebookSync::height();
		$cover   = $this->flag( $atts['hide_cover'], FacebookSync::hide_cover() );
		$small   = $this->flag( $atts['small_header'], FacebookSync::small_header() );

		ob_start(); ?>
<div class="cbd-wrap cbd-fb cbd-fb-embed">
  <?php echo $this->sdk_loader(); // phpcs:ignore WordPress.Security.EscapeOutput — static, no user data ?>
  <div class="fb-page"
       data-href="<?php echo esc_attr( $page_url ); ?>"
       data-tabs="<?php echo esc_attr( $tabs ); ?>"
       data-width="500"
       data-height="<?php echo esc_attr( (string) $height ); ?>"
       data-small-header="<?php echo $small ? 'true' : 'false'; ?>"
       data-adapt-container-width="true"
       data-hide-cover="<?php echo $cover ? 'true' : 'false'; ?>"
       data-show-facepile="true">
    <blockquote cite="<?php echo esc_url( $page_url ); ?>" class="fb-xfbml-parse-ignore">
      <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Visit our Facebook Page', 'community-business-directory' ); ?></a>
    </blockquote>
  </div>
</div>
<?php
		return (string) ob_get_clean();
	}

	/**
	 * The Facebook JS SDK loader + #fb-root. Printed once per page; later embed
	 * instances get a lightweight re-parse so multiple widgets all render.
	 */
	private function sdk_loader(): string {
		if ( self::$sdk_printed ) {
			return '<script>if(window.FB&&FB.XFBML){FB.XFBML.parse();}</script>';
		}
		self::$sdk_printed = true;

		$src = 'https://connect.facebook.net/' . self::locale() . '/sdk.js#xfbml=1&version=' . FacebookSync::GRAPH_VERSION;

		return '<div id="fb-root"></div>'
			. '<script>(function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0];'
			. 'if(d.getElementById(id)){if(window.FB&&FB.XFBML){FB.XFBML.parse();}return;}'
			. 'js=d.createElement(s);js.id=id;js.src=' . wp_json_encode( $src ) . ';'
			. "fjs.parentNode.insertBefore(js,fjs);}(document,'script','facebook-jssdk'));</script>";
	}

	/** SDK locale, filterable so non-UK sites can override en_GB. */
	private function locale(): string {
		$loc = (string) apply_filters( 'cbd_facebook_sdk_locale', FacebookSync::SDK_LOCALE );
		return preg_replace( '/[^a-zA-Z_]/', '', $loc ) ?: FacebookSync::SDK_LOCALE;
	}

	// ── Helpers ───────────────────────────────────────────────────

	/** Resolve a boolean shortcode att ('','1','true','yes'…) against a default. */
	private function flag( string $att, bool $default ): bool {
		if ( $att === '' ) {
			return $default;
		}
		return in_array( strtolower( $att ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/** "3 days ago" from an ISO-8601 created_time (empty on parse failure). */
	private function time_ago( string $iso ): string {
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human-readable time difference, e.g. "3 days" */
		return sprintf( __( '%s ago', 'community-business-directory' ), human_time_diff( $ts ) );
	}

	/** Admin-only placeholder shown where a Page isn't configured / synced yet. */
	private function admin_hint( string $message = '' ): string {
		// The /socmed page renders this shortcode as its own live preview and
		// shows its own placeholder — don't add a link back into wp-admin there.
		if ( class_exists( '\CBD\Frontend\Shortcodes\SocialSyncShortcode' ) && SocialSyncShortcode::$rendering_preview ) {
			return '';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		// Prefer the front-end "Social Feeds" page (/socmed) over wp-admin.
		$socmed = (int) get_option( 'cbd_socmed_page_id' );
		if ( $socmed && get_post_status( $socmed ) === 'publish' && ( $socmed_url = get_permalink( $socmed ) ) ) {
			$url   = $socmed_url;
			$label = __( 'Open the Social Feeds page', 'community-business-directory' );
			$message = $message ?: __( 'No Facebook feed connected yet.', 'community-business-directory' );
		} else {
			$url   = admin_url( 'admin.php?page=cbd-facebook' );
			$label = __( 'Open Facebook settings', 'community-business-directory' );
			$message = $message ?: __( 'Connect a Facebook Page under Community Directory → Facebook to display it here.', 'community-business-directory' );
		}

		return '<div class="cbd-wrap cbd-fb cbd-fb-hint"><p>' . esc_html( $message )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p></div>';
	}
}
