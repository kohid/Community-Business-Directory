<?php
/**
 * [cbd_instagram_feed] — surface a connected Instagram account on the site.
 *
 * Renders one of two ways (see CBD\Modules\InstagramSync for the config/data):
 *   - feed    : native tile grid (or list) built from media synced via the
 *               Instagram Graph API.
 *   - code    : a third-party widget snippet (Behold, SnapWidget, Curator…)
 *               pasted on the admin page, output verbatim at full width.
 *   - profile : a "Follow @handle" call-to-action card when no API is set.
 *
 * Attributes (each falls back to the "Instagram" admin settings when omitted):
 *   mode="auto|feed|code|profile"  count="9"  layout="grid|list"  columns="3"
 *   username="yourhandle"
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

use CBD\Modules\InstagramSync;

defined( 'ABSPATH' ) || exit;

class InstagramShortcode {

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'mode'     => '',
			'count'    => '',
			'layout'   => '',
			'columns'  => '',
			'username' => '',
		], $atts, 'cbd_instagram_feed' );

		$mode = InstagramSync::resolve_mode( in_array( $atts['mode'], [ 'feed', 'code', 'profile', 'auto' ], true ) ? $atts['mode'] : '' );

		if ( $mode === 'code' && InstagramSync::has_embed() ) {
			return $this->render_code();
		}

		return $mode === 'feed'
			? $this->render_feed( $atts )
			: $this->render_profile( $atts );
	}

	// ── Code mode (third-party embed snippet) ─────────────────────

	/**
	 * Output a third-party feed widget snippet (Behold, SnapWidget, Curator,
	 * Elfsight, LightWidget…) exactly as pasted in Community Directory → Instagram.
	 * The snippet is admin-authored trusted HTML — stored raw like WordPress's
	 * own Custom HTML widget — so it prints unescaped; the wrapper only gives it
	 * the plugin scope and full width.
	 */
	private function render_code(): string {
		return '<div class="cbd-wrap cbd-ig cbd-ig-embed-code">'
			. InstagramSync::embed_html() // phpcs:ignore WordPress.Security.EscapeOutput — admin-authored trusted markup
			. '</div>';
	}

	// ── Feed mode (native synced tiles) ───────────────────────────

	private function render_feed( array $atts ): string {
		$items = InstagramSync::get_feed();
		$limit = (int) $atts['count'] > 0 ? (int) $atts['count'] : InstagramSync::count();
		$items = array_slice( $items, 0, $limit );

		if ( ! $items ) {
			// Configured but nothing cached yet, or credentials missing entirely.
			return InstagramSync::feed_configured()
				? $this->admin_hint( __( 'No Instagram posts synced yet. Open Community Directory → Instagram and click “Refresh”.', 'community-business-directory' ) )
				: $this->render_profile( $atts );
		}

		$layout  = in_array( $atts['layout'], [ 'grid', 'list' ], true ) ? $atts['layout'] : InstagramSync::layout();
		$columns = (int) $atts['columns'] > 0 ? max( 2, min( 6, (int) $atts['columns'] ) ) : InstagramSync::columns();
		$user    = $atts['username'] !== '' ? ltrim( $atts['username'], '@' ) : InstagramSync::username();
		$profile = InstagramSync::profile_url();

		ob_start(); ?>
<div class="cbd-wrap cbd-ig cbd-ig-feed cbd-ig-<?php echo esc_attr( $layout ); ?>">
  <div class="cbd-ig-head">
    <span class="cbd-ig-mark" aria-hidden="true"><?php echo $this->glyph(); // phpcs:ignore WordPress.Security.EscapeOutput — static SVG ?></span>
    <span class="cbd-ig-head-text">
      <?php if ( $user ) : ?>
        <a href="<?php echo esc_url( $profile ?: '#' ); ?>" target="_blank" rel="noopener noreferrer">@<?php echo esc_html( $user ); ?></a>
      <?php else : ?>
        <?php esc_html_e( 'Latest from Instagram', 'community-business-directory' ); ?>
      <?php endif; ?>
    </span>
    <?php if ( $profile ) : ?>
      <a class="cbd-ig-follow" href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Follow', 'community-business-directory' ); ?></a>
    <?php endif; ?>
  </div>

  <?php if ( 'list' === $layout ) : ?>
    <div class="cbd-ig-list-items">
      <?php foreach ( $items as $m ) : ?>
        <article class="cbd-ig-card">
          <a class="cbd-ig-card-media" href="<?php echo esc_url( $m['permalink'] ?: ( $profile ?: '#' ) ); ?>" target="_blank" rel="noopener noreferrer">
            <img src="<?php echo esc_url( $m['image'] ); ?>" alt="" loading="lazy">
            <?php if ( 'VIDEO' === $m['type'] ) echo $this->play_badge(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
          </a>
          <div class="cbd-ig-card-body">
            <?php if ( ! empty( $m['caption'] ) ) : ?>
              <p class="cbd-ig-card-text"><?php echo make_clickable( esc_html( wp_trim_words( $m['caption'], 40 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped before linkifying ?></p>
            <?php endif; ?>
            <div class="cbd-ig-card-meta">
              <?php if ( ! empty( $m['timestamp'] ) ) : ?>
                <time datetime="<?php echo esc_attr( $m['timestamp'] ); ?>"><?php echo esc_html( $this->time_ago( $m['timestamp'] ) ); ?></time>
              <?php endif; ?>
              <?php if ( ! empty( $m['permalink'] ) ) : ?>
                <a href="<?php echo esc_url( $m['permalink'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on Instagram', 'community-business-directory' ); ?></a>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else : ?>
    <div class="cbd-ig-grid" style="--cbd-ig-cols:<?php echo esc_attr( (string) $columns ); ?>;">
      <?php foreach ( $items as $m ) : ?>
        <a class="cbd-ig-tile" href="<?php echo esc_url( $m['permalink'] ?: ( $profile ?: '#' ) ); ?>" target="_blank" rel="noopener noreferrer">
          <img src="<?php echo esc_url( $m['image'] ); ?>" alt="" loading="lazy">
          <?php if ( 'VIDEO' === $m['type'] ) echo $this->play_badge(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
          <?php if ( ! empty( $m['caption'] ) ) : ?>
            <span class="cbd-ig-tile-caption"><span><?php echo esc_html( wp_trim_words( $m['caption'], 20 ) ); ?></span></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
		return (string) ob_get_clean();
	}

	// ── Profile mode (follow call-to-action) ──────────────────────

	private function render_profile( array $atts ): string {
		$user    = $atts['username'] !== '' ? ltrim( $atts['username'], '@' ) : InstagramSync::username();
		$profile = InstagramSync::profile_url();

		if ( $profile === '' && $user === '' ) {
			return $this->admin_hint();
		}

		ob_start(); ?>
<div class="cbd-wrap cbd-ig cbd-ig-profile">
  <span class="cbd-ig-profile-mark" aria-hidden="true"><?php echo $this->glyph(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
  <div class="cbd-ig-profile-text">
    <strong><?php echo $user ? esc_html( '@' . $user ) : esc_html__( 'We’re on Instagram', 'community-business-directory' ); ?></strong>
    <span><?php esc_html_e( 'Follow us for the latest photos and stories.', 'community-business-directory' ); ?></span>
  </div>
  <?php if ( $profile ) : ?>
    <a class="cbd-ig-follow" href="<?php echo esc_url( $profile ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Follow on Instagram', 'community-business-directory' ); ?></a>
  <?php endif; ?>
</div>
<?php
		return (string) ob_get_clean();
	}

	// ── Helpers ───────────────────────────────────────────────────

	/** Instagram glyph (inline SVG, currentColor). */
	private function glyph(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>'
			. '<path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/>'
			. '<line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>';
	}

	/** Small play indicator overlaid on video tiles. */
	private function play_badge(): string {
		return '<span class="cbd-ig-play" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>';
	}

	/** "3 days ago" from an ISO-8601 timestamp (empty on parse failure). */
	private function time_ago( string $iso ): string {
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human-readable time difference, e.g. "3 days" */
		return sprintf( __( '%s ago', 'community-business-directory' ), human_time_diff( $ts ) );
	}

	/** Admin-only placeholder shown where an account isn't configured yet. */
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
			$message = $message ?: __( 'No Instagram feed connected yet.', 'community-business-directory' );
		} else {
			$url   = admin_url( 'admin.php?page=cbd-instagram' );
			$label = __( 'Open Instagram settings', 'community-business-directory' );
			$message = $message ?: __( 'Connect an Instagram account under Community Directory → Instagram to display it here.', 'community-business-directory' );
		}

		return '<div class="cbd-wrap cbd-ig cbd-ig-hint"><p>' . esc_html( $message )
			. ' <a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p></div>';
	}
}
