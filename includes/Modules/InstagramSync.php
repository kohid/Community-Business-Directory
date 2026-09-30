<?php
/**
 * Instagram feed sync.
 *
 * Connects an Instagram account to the site, surfaced by the
 * `[cbd_instagram_feed]` shortcode and the "Instagram" admin page:
 *
 *   - FEED mode    — pulls recent media through the Instagram Graph API using an
 *                    IG Business/Creator account ID + access token, caches it and
 *                    renders it as a native, theme-styled tile grid.
 *   - PROFILE mode — a "Follow @handle" call-to-action card when no API is set.
 *                    (Instagram has no official whole-profile embed widget, and
 *                    the Basic Display API was retired in Dec 2024, so the Graph
 *                    API is the only supported way to read a profile's media.)
 *
 * Because the Instagram Graph API requires the IG account to be a Business/
 * Creator profile linked to a Facebook Page, it uses a Facebook *Page* Access
 * Token — so token() falls back to the one saved for the Facebook feature, and
 * discover() can resolve the (hard-to-find) IG account ID from that linked Page.
 *
 * Owns CONFIG + DATA only; presentation lives in
 * Frontend\Shortcodes\InstagramShortcode. Mirrors CBD\Modules\FacebookSync.
 *
 * @package CBD\Modules
 */

namespace CBD\Modules;

defined( 'ABSPATH' ) || exit;

class InstagramSync {

	/** Graph API version used for media requests. */
	public const GRAPH_VERSION = 'v23.0';

	// Option keys.
	public const OPT_USER_ID     = 'cbd_ig_user_id';     // IG Business Account ID (numeric)
	public const OPT_TOKEN       = 'cbd_ig_access_token';
	public const OPT_USERNAME    = 'cbd_ig_username';     // @handle (profile card + caption byline)
	public const OPT_PROFILE_URL = 'cbd_ig_profile_url';
	public const OPT_MODE        = 'cbd_ig_mode';         // auto | feed | code | profile
	public const OPT_EMBED       = 'cbd_ig_embed_html';   // raw third-party widget snippet (code mode)
	public const OPT_COUNT       = 'cbd_ig_count';
	public const OPT_LAYOUT      = 'cbd_ig_layout';       // grid | list
	public const OPT_COLUMNS     = 'cbd_ig_columns';
	public const OPT_TTL         = 'cbd_ig_cache_ttl';    // feed cache minutes

	// Transient + status option keys.
	private const CACHE_KEY   = 'cbd_ig_feed_cache';
	private const BACKUP_OPT  = 'cbd_ig_feed_backup';
	private const FETCHED_OPT = 'cbd_ig_last_fetch';
	private const ERROR_OPT   = 'cbd_ig_last_error';

	// ── Config accessors ──────────────────────────────────────────

	/** Instagram Business/Creator account ID, for Graph API media requests. */
	public static function user_id(): string {
		return trim( (string) get_option( self::OPT_USER_ID, '' ) );
	}

	/**
	 * Access token for the Graph API. Falls back to the Facebook Page token,
	 * since the Instagram Graph API authenticates with the linked Page's token.
	 */
	public static function token(): string {
		$token = trim( (string) get_option( self::OPT_TOKEN, '' ) );
		if ( $token === '' && class_exists( FacebookSync::class ) ) {
			$token = FacebookSync::token();
		}
		return $token;
	}

	/** @handle without a leading "@". */
	public static function username(): string {
		return ltrim( trim( (string) get_option( self::OPT_USERNAME, '' ) ), '@' );
	}

	/** Public profile URL (derived from the @handle when not set explicitly). */
	public static function profile_url(): string {
		$url = trim( (string) get_option( self::OPT_PROFILE_URL, '' ) );
		if ( $url === '' ) {
			$user = self::username();
			if ( $user !== '' ) {
				$url = 'https://www.instagram.com/' . rawurlencode( $user ) . '/';
			}
		}
		return $url;
	}

	/** True when an account ID + token are both present → media can be fetched. */
	public static function feed_configured(): bool {
		return self::user_id() !== '' && self::token() !== '';
	}

	/**
	 * Raw third-party widget snippet (Behold, SnapWidget, Curator, Elfsight,
	 * LightWidget…) pasted on the "Instagram" admin page, rendered as-is by
	 * 'code' mode. Admin-authored trusted markup — see InstagramShortcode.
	 */
	public static function embed_html(): string {
		return trim( (string) get_option( self::OPT_EMBED, '' ) );
	}

	/** True when a third-party embed snippet has been saved. */
	public static function has_embed(): bool {
		return self::embed_html() !== '';
	}

	/**
	 * Resolve the effective render mode. An explicit 'feed' / 'code' / 'profile'
	 * is honoured as-is; 'auto' prefers a working Graph feed, then a saved
	 * third-party embed, then the "Follow" profile card.
	 */
	public static function resolve_mode( string $requested = '' ): string {
		$mode = $requested !== '' ? $requested : (string) get_option( self::OPT_MODE, 'auto' );
		if ( $mode === 'feed' || $mode === 'profile' || $mode === 'code' ) {
			return $mode;
		}
		if ( self::feed_configured() ) {
			return 'feed';
		}
		return self::has_embed() ? 'code' : 'profile';
	}

	public static function count(): int {
		return max( 1, min( 50, (int) get_option( self::OPT_COUNT, 9 ) ) );
	}

	/** 'grid' or 'list'. */
	public static function layout(): string {
		return get_option( self::OPT_LAYOUT, 'grid' ) === 'list' ? 'list' : 'grid';
	}

	public static function columns(): int {
		return max( 2, min( 6, (int) get_option( self::OPT_COLUMNS, 3 ) ) );
	}

	/** Feed cache lifetime in seconds (5 min – 24 h; stored as minutes). */
	public static function ttl_seconds(): int {
		$mins = (int) get_option( self::OPT_TTL, 30 );
		return max( 5, min( 1440, $mins ) ) * MINUTE_IN_SECONDS;
	}

	// ── Persist settings (admin page + [cbd_social_sync] front-end) ─

	/**
	 * Write every Instagram setting from a submitted, already-unslashed input map
	 * (the admin form's $_POST, or the front-end sync form's). Both callers post
	 * the full field set — the front-end page round-trips the fields it doesn't
	 * display as hidden inputs — so this stays a plain overwrite. Clears the feed
	 * cache so the next render reflects the change.
	 *
	 * @param array<string,mixed> $in
	 */
	public static function save( array $in ): void {
		update_option( self::OPT_USER_ID,     sanitize_text_field( (string) ( $in['cbd_ig_user_id'] ?? '' ) ) );
		update_option( self::OPT_TOKEN,       sanitize_text_field( (string) ( $in['cbd_ig_access_token'] ?? '' ) ) );
		update_option( self::OPT_USERNAME,    ltrim( sanitize_text_field( (string) ( $in['cbd_ig_username'] ?? '' ) ), '@' ) );
		update_option( self::OPT_PROFILE_URL, esc_url_raw( trim( (string) ( $in['cbd_ig_profile_url'] ?? '' ) ) ) );
		update_option( self::OPT_EMBED,       \cbd_kses_embed( (string) ( $in['cbd_ig_embed_html'] ?? '' ) ) );

		$mode = sanitize_key( (string) ( $in['cbd_ig_mode'] ?? 'auto' ) );
		update_option( self::OPT_MODE, in_array( $mode, [ 'auto', 'feed', 'code', 'profile' ], true ) ? $mode : 'auto' );

		update_option( self::OPT_COUNT, max( 1, min( 50, (int) ( $in['cbd_ig_count'] ?? 9 ) ) ) );

		$layout = sanitize_key( (string) ( $in['cbd_ig_layout'] ?? 'grid' ) );
		update_option( self::OPT_LAYOUT, 'list' === $layout ? 'list' : 'grid' );

		update_option( self::OPT_COLUMNS, max( 2, min( 6, (int) ( $in['cbd_ig_columns'] ?? 3 ) ) ) );
		update_option( self::OPT_TTL, max( 5, min( 1440, (int) ( $in['cbd_ig_cache_ttl'] ?? 30 ) ) ) );

		self::clear_cache();
	}

	// ── Feed (Graph API + cache) ──────────────────────────────────

	/**
	 * Cached, normalised media for rendering — hits the network only when the
	 * cache is cold, and falls back to the last good backup on error so a
	 * transient Graph failure can't blank an already-populated grid.
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
		$backup = get_option( self::BACKUP_OPT );
		return is_array( $backup ) ? $backup : [];
	}

	/**
	 * Fetch + normalise recent media from the Instagram Graph API and refresh the
	 * cache + backup. Records the last-fetch time or last error either way.
	 *
	 * @return array<int,array<string,string>>|\WP_Error
	 */
	public static function fetch() {
		$user  = self::user_id();
		$token = self::token();
		if ( $user === '' || $token === '' ) {
			return new \WP_Error( 'cbd_ig_unconfigured', __( 'Add an Instagram account ID and access token to sync media.', 'community-business-directory' ) );
		}

		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/media',
			self::GRAPH_VERSION,
			rawurlencode( $user )
		);
		$url = add_query_arg( [
			'fields'       => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
			'limit'        => self::count(),
			'access_token' => $token,
		], $endpoint );

		$res = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $res ) ) {
			return self::record_error( $res->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return self::record_error( __( 'Instagram returned an unreadable response.', 'community-business-directory' ) );
		}
		if ( isset( $body['error'] ) ) {
			$msg = (string) ( $body['error']['message'] ?? __( 'Unknown Graph API error.', 'community-business-directory' ) );
			return self::record_error( $msg );
		}

		$items = [];
		foreach ( (array) ( $body['data'] ?? [] ) as $media ) {
			$type = (string) ( $media['media_type'] ?? 'IMAGE' );
			// Videos expose the file in media_url and the poster in thumbnail_url;
			// images/carousels expose the picture in media_url. Prefer the still.
			$image = $type === 'VIDEO'
				? (string) ( $media['thumbnail_url'] ?? $media['media_url'] ?? '' )
				: (string) ( $media['media_url'] ?? $media['thumbnail_url'] ?? '' );
			$image = esc_url_raw( $image );
			if ( $image === '' ) {
				continue; // Nothing to render for this item.
			}
			$items[] = [
				'id'        => (string) ( $media['id'] ?? '' ),
				'caption'   => trim( (string) ( $media['caption'] ?? '' ) ),
				'type'      => $type,
				'image'     => $image,
				'permalink' => esc_url_raw( (string) ( $media['permalink'] ?? '' ) ),
				'timestamp' => (string) ( $media['timestamp'] ?? '' ),
			];
		}

		set_transient( self::CACHE_KEY, $items, self::ttl_seconds() );
		update_option( self::BACKUP_OPT, $items, false );
		update_option( self::FETCHED_OPT, current_time( 'mysql' ), false );
		delete_option( self::ERROR_OPT );

		return $items;
	}

	/**
	 * Force a fresh pull (admin "Refresh" button). Clears the cache first.
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
				/* translators: %d: number of Instagram posts synced */
				_n( 'Synced %d post from Instagram.', 'Synced %d posts from Instagram.', count( $result ), 'community-business-directory' ),
				count( $result )
			),
		];
	}

	/**
	 * Resolve the Instagram Business Account ID (and username) from a Facebook
	 * Page — the "Detect my account" helper. Uses the Facebook feature's saved
	 * Page ID + this feature's effective token, saves what it finds, and returns
	 * it. This is the least-obvious setup step, so we automate it.
	 *
	 * @return array{ok:bool,id:string,username:string,message:string}
	 */
	public static function discover(): array {
		$token   = self::token();
		$page_id = class_exists( FacebookSync::class ) ? FacebookSync::page_id() : '';
		if ( $page_id === '' || $token === '' ) {
			return [ 'ok' => false, 'id' => '', 'username' => '', 'message' => __( 'Save your Facebook Page ID and access token first (Community Directory → Facebook).', 'community-business-directory' ) ];
		}

		$url = add_query_arg( [
			'fields'       => 'instagram_business_account{id,username}',
			'access_token' => $token,
		], sprintf( 'https://graph.facebook.com/%s/%s', self::GRAPH_VERSION, rawurlencode( $page_id ) ) );

		$res = wp_remote_get( $url, [ 'timeout' => 20, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'id' => '', 'username' => '', 'message' => $res->get_error_message() ];
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( isset( $body['error'] ) ) {
			return [ 'ok' => false, 'id' => '', 'username' => '', 'message' => (string) ( $body['error']['message'] ?? __( 'Graph API error.', 'community-business-directory' ) ) ];
		}

		$iga = $body['instagram_business_account'] ?? null;
		if ( ! is_array( $iga ) || empty( $iga['id'] ) ) {
			return [ 'ok' => false, 'id' => '', 'username' => '', 'message' => __( 'No Instagram Business account is linked to that Facebook Page. Link one in Meta Business settings, then try again.', 'community-business-directory' ) ];
		}

		$id       = (string) $iga['id'];
		$username = (string) ( $iga['username'] ?? '' );
		update_option( self::OPT_USER_ID, $id );
		if ( $username !== '' && self::username() === '' ) {
			update_option( self::OPT_USERNAME, $username );
		}
		self::clear_cache();

		return [
			'ok'       => true,
			'id'       => $id,
			'username' => $username,
			/* translators: %s: Instagram @handle */
			'message'  => $username !== ''
				? sprintf( __( 'Found @%s. Click “Refresh posts now” to sync.', 'community-business-directory' ), $username )
				: __( 'Instagram account linked. Click “Refresh posts now” to sync.', 'community-business-directory' ),
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
		return new \WP_Error( 'cbd_ig_fetch', $message );
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
