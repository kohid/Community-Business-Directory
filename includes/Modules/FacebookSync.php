<?php
/**
 * Facebook Page sync.
 *
 * Connects a Facebook Page to the site in one of two ways, both surfaced by the
 * `[cbd_facebook_page]` shortcode and the "Facebook" admin page:
 *
 *   - EMBED mode  — renders Facebook's official Page Plugin (an SDK iframe).
 *                   Needs only the public Page URL; no app, token or review.
 *   - FEED mode   — pulls the Page's recent posts through the Graph API using a
 *                   Page ID + long-lived Page Access Token, caches them locally
 *                   and renders them as native, theme-styled cards.
 *
 * This class owns CONFIG + DATA only (option accessors, the Graph fetch and its
 * cache). Presentation lives in Frontend\Shortcodes\FacebookShortcode, mirroring
 * how LoqivaSync owns data while the Shortcodes own markup.
 *
 * The remote feed is cached in a transient (`cbd_fb_feed_cache`) for the
 * configured TTL, with a persistent backup option (`cbd_fb_feed_backup`) so a
 * transient eviction or a transient Graph error still serves the last good data
 * instead of an empty widget.
 *
 * @package CBD\Modules
 */

namespace CBD\Modules;

defined( 'ABSPATH' ) || exit;

class FacebookSync {

	/** Graph API version used for feed requests + the Page Plugin SDK. Bump to move versions. */
	public const GRAPH_VERSION = 'v23.0';

	/** UK site → the Page Plugin SDK loads its en_GB locale bundle. */
	public const SDK_LOCALE = 'en_GB';

	// Option keys.
	public const OPT_PAGE_URL  = 'cbd_fb_page_url';
	public const OPT_PAGE_ID   = 'cbd_fb_page_id';
	public const OPT_TOKEN     = 'cbd_fb_access_token';
	public const OPT_MODE      = 'cbd_fb_mode';        // auto | embed | feed | code
	public const OPT_EMBED     = 'cbd_fb_embed_html';  // raw third-party widget snippet (code mode)
	public const OPT_COUNT     = 'cbd_fb_count';
	public const OPT_TABS      = 'cbd_fb_tabs';        // embed: comma list of timeline|events|messages
	public const OPT_HEIGHT    = 'cbd_fb_height';      // embed pixel height
	public const OPT_HIDE_COVER= 'cbd_fb_hide_cover';
	public const OPT_SMALL_HDR = 'cbd_fb_small_header';
	public const OPT_TTL       = 'cbd_fb_cache_ttl';   // feed cache minutes

	// Transient + status option keys.
	private const CACHE_KEY   = 'cbd_fb_feed_cache';
	private const BACKUP_OPT  = 'cbd_fb_feed_backup';
	private const FETCHED_OPT = 'cbd_fb_last_fetch';
	private const ERROR_OPT   = 'cbd_fb_last_error';

	// ── Config accessors ──────────────────────────────────────────

	/** Public Page URL (used for the embed href + "View on Facebook" links). */
	public static function page_url(): string {
		$url = trim( (string) get_option( self::OPT_PAGE_URL, '' ) );
		if ( $url === '' ) {
			// Derive a sensible URL from the Page ID / username when only that is set.
			$id = self::page_id();
			if ( $id !== '' ) {
				$url = 'https://www.facebook.com/' . rawurlencode( $id );
			}
		}
		return $url;
	}

	/** Numeric Page ID or vanity username, for Graph API feed requests. */
	public static function page_id(): string {
		$id = trim( (string) get_option( self::OPT_PAGE_ID, '' ) );
		if ( $id === '' ) {
			// Fall back to the trailing segment of a configured Page URL.
			$path = trim( (string) wp_parse_url( (string) get_option( self::OPT_PAGE_URL, '' ), PHP_URL_PATH ), '/' );
			if ( $path !== '' && strpos( $path, '/' ) === false ) {
				$id = $path;
			}
		}
		return $id;
	}

	/** Long-lived Page Access Token for the Graph API (feed mode). */
	public static function token(): string {
		return trim( (string) get_option( self::OPT_TOKEN, '' ) );
	}

	/** True when a Page ID + token are both present → the feed can be fetched. */
	public static function feed_configured(): bool {
		return self::page_id() !== '' && self::token() !== '';
	}

	/**
	 * Raw third-party widget snippet (Behold, SnapWidget, Curator, Elfsight,
	 * LightWidget…) pasted on the "Facebook" admin page, rendered as-is by
	 * 'code' mode. Admin-authored trusted markup — see FacebookShortcode.
	 */
	public static function embed_html(): string {
		return trim( (string) get_option( self::OPT_EMBED, '' ) );
	}

	/** True when a third-party embed snippet has been saved. */
	public static function has_embed(): bool {
		return self::embed_html() !== '';
	}

	/**
	 * Resolve the effective render mode for a given requested mode. An explicit
	 * 'feed' / 'embed' / 'code' is honoured as-is; 'auto' prefers a working Graph
	 * feed, then a saved third-party embed, then the official Page Plugin.
	 */
	public static function resolve_mode( string $requested = '' ): string {
		$mode = $requested !== '' ? $requested : (string) get_option( self::OPT_MODE, 'auto' );
		if ( $mode === 'feed' || $mode === 'embed' || $mode === 'code' ) {
			return $mode;
		}
		if ( self::feed_configured() ) {
			return 'feed';
		}
		return self::has_embed() ? 'code' : 'embed';
	}

	public static function count(): int {
		return max( 1, min( 50, (int) get_option( self::OPT_COUNT, 6 ) ) );
	}

	/** Embed tabs, sanitised to the Page Plugin's allowed values. */
	public static function tabs(): string {
		$raw   = (string) get_option( self::OPT_TABS, 'timeline' );
		$valid = array_intersect(
			array_map( 'trim', explode( ',', strtolower( $raw ) ) ),
			[ 'timeline', 'events', 'messages' ]
		);
		return $valid ? implode( ',', $valid ) : 'timeline';
	}

	public static function height(): int {
		return max( 130, min( 2000, (int) get_option( self::OPT_HEIGHT, 700 ) ) );
	}

	public static function hide_cover(): bool {
		return (bool) get_option( self::OPT_HIDE_COVER, false );
	}

	public static function small_header(): bool {
		return (bool) get_option( self::OPT_SMALL_HDR, false );
	}

	/** Feed cache lifetime in seconds (5 min – 24 h; stored as minutes). */
	public static function ttl_seconds(): int {
		$mins = (int) get_option( self::OPT_TTL, 30 );
		return max( 5, min( 1440, $mins ) ) * MINUTE_IN_SECONDS;
	}

	// ── Persist settings (admin page + [cbd_social_sync] front-end) ─

	/**
	 * Write every Facebook setting from a submitted, already-unslashed input map
	 * (the admin form's $_POST, or the front-end sync form's). Both callers post
	 * the full field set — the front-end page round-trips the fields it doesn't
	 * display as hidden inputs — so this stays a plain overwrite. Clears the feed
	 * cache so the next render reflects the change.
	 *
	 * @param array<string,mixed> $in
	 */
	public static function save( array $in ): void {
		update_option( self::OPT_PAGE_URL, esc_url_raw( trim( (string) ( $in['cbd_fb_page_url'] ?? '' ) ) ) );
		update_option( self::OPT_PAGE_ID,  sanitize_text_field( (string) ( $in['cbd_fb_page_id'] ?? '' ) ) );
		update_option( self::OPT_TOKEN,    sanitize_text_field( (string) ( $in['cbd_fb_access_token'] ?? '' ) ) );
		update_option( self::OPT_EMBED,    \cbd_kses_embed( (string) ( $in['cbd_fb_embed_html'] ?? '' ) ) );

		$mode = sanitize_key( (string) ( $in['cbd_fb_mode'] ?? 'auto' ) );
		update_option( self::OPT_MODE, in_array( $mode, [ 'auto', 'embed', 'feed', 'code' ], true ) ? $mode : 'auto' );

		update_option( self::OPT_COUNT, max( 1, min( 50, (int) ( $in['cbd_fb_count'] ?? 6 ) ) ) );

		$tabs = array_map( 'sanitize_key', (array) ( $in['cbd_fb_tabs'] ?? [] ) );
		$tabs = array_values( array_intersect( $tabs, [ 'timeline', 'events', 'messages' ] ) );
		update_option( self::OPT_TABS, $tabs ? implode( ',', $tabs ) : 'timeline' );

		update_option( self::OPT_HEIGHT, max( 130, min( 2000, (int) ( $in['cbd_fb_height'] ?? 700 ) ) ) );
		update_option( self::OPT_HIDE_COVER, empty( $in['cbd_fb_hide_cover'] ) ? 0 : 1 );
		update_option( self::OPT_SMALL_HDR,  empty( $in['cbd_fb_small_header'] ) ? 0 : 1 );
		update_option( self::OPT_TTL, max( 5, min( 1440, (int) ( $in['cbd_fb_cache_ttl'] ?? 30 ) ) ) );

		self::clear_cache();
	}

	// ── Feed (Graph API + cache) ──────────────────────────────────

	/**
	 * Return the cached, normalised feed for rendering — never hits the network
	 * unless the cache is cold, and always falls back to the last good backup so
	 * a temporary Graph error can't blank an already-populated widget.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_feed(): array {
		if ( ! self::feed_configured() ) {
			return [];
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$fresh = self::fetch();
		if ( is_array( $fresh ) ) {
			return $fresh;
		}
		// Fetch failed → serve the last successful pull if we have one.
		$backup = get_option( self::BACKUP_OPT );
		return is_array( $backup ) ? $backup : [];
	}

	/**
	 * Fetch + normalise the Page's recent posts from the Graph API and refresh
	 * the cache + backup. Records the last-fetch time or last error either way.
	 *
	 * @return array<int,array<string,string>>|\WP_Error  items, or WP_Error on failure.
	 */
	public static function fetch() {
		$page  = self::page_id();
		$token = self::token();
		if ( $page === '' || $token === '' ) {
			return new \WP_Error( 'cbd_fb_unconfigured', __( 'Add a Facebook Page ID and Access Token to sync posts.', 'community-business-directory' ) );
		}

		// /feed = everything on the Page (own posts + visitor posts). The older
		// /posts edge is deprecated and 400s ("#100 nonexisting field") on newer
		// Graph versions.
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/feed',
			self::GRAPH_VERSION,
			rawurlencode( $page )
		);
		$url = add_query_arg( [
			'fields'       => 'id,message,story,created_time,permalink_url,full_picture',
			'limit'        => self::count(),
			'access_token' => $token,
		], $endpoint );

		$res = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $res ) ) {
			return self::record_error( $res->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return self::record_error( __( 'Facebook returned an unreadable response.', 'community-business-directory' ) );
		}
		if ( isset( $body['error'] ) ) {
			$msg = (string) ( $body['error']['message'] ?? __( 'Unknown Graph API error.', 'community-business-directory' ) );
			return self::record_error( $msg );
		}

		$items = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $post ) {
			$message = trim( (string) ( $post['message'] ?? $post['story'] ?? '' ) );
			$image   = esc_url_raw( (string) ( $post['full_picture'] ?? '' ) );
			if ( $message === '' && $image === '' ) {
				continue; // Nothing to show for this post.
			}
			$items[] = [
				'id'        => (string) ( $post['id'] ?? '' ),
				'message'   => $message,
				'created'   => (string) ( $post['created_time'] ?? '' ),
				'image'     => $image,
				'permalink' => esc_url_raw( (string) ( $post['permalink_url'] ?? '' ) ),
			];
		}

		set_transient( self::CACHE_KEY, $items, self::ttl_seconds() );
		update_option( self::BACKUP_OPT, $items, false );
		update_option( self::FETCHED_OPT, current_time( 'mysql' ), false );
		delete_option( self::ERROR_OPT );

		return $items;
	}

	/**
	 * Force a fresh pull (admin "Refresh" button). Clears the cache first so the
	 * next public view is served the newly fetched data.
	 *
	 * @return array{ok:bool,count:int,message:string}
	 */
	public static function refresh(): array {
		delete_transient( self::CACHE_KEY );
		$result = self::fetch();
		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'count' => 0, 'message' => $result->get_error_message() ];
		}
		return [
			'ok'      => true,
			'count'   => count( $result ),
			'message' => sprintf(
				/* translators: %d: number of Facebook posts synced */
				_n( 'Synced %d post from your Facebook Page.', 'Synced %d posts from your Facebook Page.', count( $result ), 'community-business-directory' ),
				count( $result )
			),
		];
	}

	/** Drop the cached feed so the next read re-fetches (e.g. after a settings change). */
	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/** Persist an error message + timestamp and return it as a WP_Error. */
	private static function record_error( string $message ): \WP_Error {
		update_option( self::ERROR_OPT, $message, false );
		update_option( self::FETCHED_OPT, current_time( 'mysql' ), false );
		return new \WP_Error( 'cbd_fb_fetch', $message );
	}

	// ── Status (admin screen) ─────────────────────────────────────

	/** @return array{configured:bool,cached:int,last_fetch:string,last_error:string} */
	public static function status(): array {
		$backup = get_option( self::BACKUP_OPT );
		return [
			'configured' => self::feed_configured(),
			'cached'     => is_array( $backup ) ? count( $backup ) : 0,
			'last_fetch' => (string) get_option( self::FETCHED_OPT, '' ),
			'last_error' => (string) get_option( self::ERROR_OPT, '' ),
		];
	}
}
