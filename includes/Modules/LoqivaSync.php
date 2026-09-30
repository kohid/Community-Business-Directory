<?php
/**
 * Love Inverness (Loqiva) importer.
 *
 * Pulls the three public JSON feeds from the Love Inverness digital town hub
 * (built on the Loqiva platform) and imports them as NATIVE plugin content —
 * cbd_business / cbd_event / cbd_promotion posts plus their parallel custom-table
 * rows — so the data flows through directory search, maps, single pages, blocks
 * and SEO schema exactly like a real listing.
 *
 * Everything created here is tagged so a re-sync updates in place and teardown
 * only ever removes imported content, never real listings:
 *   - posts & attachments → meta `_cbd_source` = 'loqiva'
 *   - each post           → meta `_cbd_loqiva_id` = '{type}:{remoteId}' (idempotent key)
 *
 * Feeds:
 *   - events   → cbd_event       (venue-only; no owning business)
 *   - rewards  → cbd_promotion    (linked to a business by BusinessName)
 *   - business → cbd_business
 *
 * @package CBD\Modules
 */

namespace CBD\Modules;

defined( 'ABSPATH' ) || exit;

class LoqivaSync {

	/** Marker tagging every imported post + attachment. */
	public const SOURCE      = 'loqiva';
	public const SOURCE_META = '_cbd_source';
	public const ID_META     = '_cbd_loqiva_id';      // '{type}:{remoteId}'
	public const UPDATED_META= '_cbd_loqiva_updated';  // remote UpdatedAt
	public const APPURL_META = '_cbd_loqiva_app_url';  // deep-link back to the hub
	public const FIMG_META   = '_cbd_loqiva_fimg_src'; // featured-image source URL (skip re-download)

	/** WP-Cron hook name for the scheduled sync. */
	public const CRON_HOOK = 'cbd_loqiva_sync';

	/**
	 * Auto-sync cadence. WordPress ships no schedule shorter than 'hourly', so
	 * we register our own interval (see cron_schedules()). Change SYNC_INTERVAL
	 * to retune — e.g. 300 for every 5 minutes, 600 for every 10.
	 */
	public const SYNC_INTERVAL = 600;                 // seconds → 10 minutes
	public const SYNC_SCHEDULE = 'cbd_loqiva_interval';

	/** Records processed per admin progress tick (kept small — images are slow). */
	private const TICK_BATCH = 4;

	/** Default feed URLs (resolved from the InvernessBID links). Overridable via options. */
	private const DEFAULT_URLS = [
		'business' => 'https://inverness.loqiva.com/public/api/business/json/token/ejo7kEn06eEFOZw6IZrm0MdHnJynsZ4fBGzpC2aFaSbB83icKMPNX4CeMWi7NJXP',
		'rewards'  => 'https://inverness.loqiva.com/public/api/rewards/json/token/r52e0ouCk1X77rbYqzMwM5lD8pyRXhZpEbuWcOMjDGlnwjk7hTSPCeaW4WDP1Ttu',
		'events'   => 'https://inverness.loqiva.com/public/api/events/json/token/OvD2VzHv8IcKSk3gY8sUYVrmU7MygbUZoEVyAgKXfStLmyAAh14ZrEihnfkTN7Bh',
	];

	/** Once a remote image fetch fails we stop trying for the rest of the run. */
	private static bool $remote_ok = true;

	// ── Registration helpers (called from Plugin) ─────────────────

	/** Fires from the scheduled WP-Cron event — run a full sync. */
	public function run_cron(): void {
		self::sync_all();
	}

	/**
	 * Register our sub-hourly WP-Cron interval. schedule_cron() adds this filter
	 * on every load, so the interval is always resolvable when WP-Cron reschedules.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$secs = self::interval_seconds();
		$schedules[ self::SYNC_SCHEDULE ] = [
			'interval' => $secs,
			'display'  => sprintf(
				/* translators: %d: number of minutes between syncs */
				__( 'Every %d minutes (Love Inverness sync)', 'community-business-directory' ),
				max( 1, (int) round( $secs / 60 ) )
			),
		];
		return $schedules;
	}

	/**
	 * Ensure the auto-sync event runs on our custom interval (idempotent). Also
	 * migrates any event still on the legacy 'daily' schedule to the new one.
	 */
	public static function schedule_cron(): void {
		// Our interval must exist before we (re)schedule against it.
		if ( ! has_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] ) ) {
			add_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] );
		}
		// Already on the right schedule → leave it untouched.
		if ( self::SYNC_SCHEDULE === wp_get_schedule( self::CRON_HOOK ) ) {
			return;
		}
		// Clear any stale event (e.g. the old 'daily' one) and reschedule.
		self::unschedule_cron();
		wp_schedule_event( time() + self::interval_seconds(), self::SYNC_SCHEDULE, self::CRON_HOOK );
	}

	/** Selectable auto-sync cadences: seconds => human label. */
	public static function interval_choices(): array {
		return [
			300   => __( 'Every 5 minutes', 'community-business-directory' ),
			600   => __( 'Every 10 minutes', 'community-business-directory' ),
			1800  => __( 'Every 30 minutes', 'community-business-directory' ),
			3600  => __( 'Hourly', 'community-business-directory' ),
			86400 => __( 'Daily', 'community-business-directory' ),
		];
	}

	/** Configured auto-sync interval in seconds (validated against the presets). */
	public static function interval_seconds(): int {
		$secs = (int) get_option( 'cbd_loqiva_sync_interval', self::SYNC_INTERVAL );
		return isset( self::interval_choices()[ $secs ] ) ? $secs : self::SYNC_INTERVAL;
	}

	/** Persist a new cadence and re-arm the cron immediately at the new rate. */
	public static function set_interval( int $secs ): void {
		if ( ! isset( self::interval_choices()[ $secs ] ) ) {
			$secs = self::SYNC_INTERVAL;
		}
		update_option( 'cbd_loqiva_sync_interval', $secs );
		self::unschedule_cron();
		self::schedule_cron();   // reschedules using the new interval_seconds()
	}

	/** Remove the scheduled cron event (deactivation / disable). */
	public static function unschedule_cron(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	// ── Config ────────────────────────────────────────────────────

	/** Configured feed URL for a type, falling back to the discovered default. */
	public static function url( string $type ): string {
		$opt = get_option( 'cbd_loqiva_url_' . $type );
		return $opt ? (string) $opt : ( self::DEFAULT_URLS[ $type ] ?? '' );
	}

	/** Memoised owner id (resolved once per request). */
	private static int $owner_id = 0;

	/** The user account that owns imported listings (the site's directory admin). */
	private static function owner_id(): int {
		if ( self::$owner_id ) {
			return self::$owner_id;
		}
		$id      = 0;
		$website = (int) get_option( 'cbd_website_business_id' );
		if ( $website && ( $author = (int) get_post_field( 'post_author', $website ) ) ) {
			$id = $author;
		} elseif ( $admin = get_user_by( 'email', (string) get_option( 'admin_email' ) ) ) {
			$id = (int) $admin->ID;
		} else {
			$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
			$id     = $admins ? (int) $admins[0]->ID : 0;
		}
		return self::$owner_id = $id;
	}

	// ── Fetch ─────────────────────────────────────────────────────

	/**
	 * Fetch + decode one feed. Cached in a 10-minute transient so the many calls
	 * across a batched sync reuse a single HTTP request.
	 *
	 * @return array<int,array<string,mixed>>|null  null on hard failure.
	 */
	public static function fetch( string $type ): ?array {
		$cache_key = 'cbd_loqiva_raw_' . $type;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$url = self::url( $type );
		if ( ! $url ) {
			return null;
		}
		$res = wp_remote_get( $url, [ 'timeout' => 25, 'redirection' => 5, 'headers' => [ 'Accept' => 'application/json' ] ] );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		set_transient( $cache_key, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/** Drop the cached raw feeds (force the next fetch to hit the network). */
	private static function clear_cache(): void {
		foreach ( [ 'business', 'rewards', 'events' ] as $t ) {
			delete_transient( 'cbd_loqiva_raw_' . $t );
		}
	}

	// ── One-shot sync (cron / WP-CLI) ─────────────────────────────

	/**
	 * Import every record from all three feeds in one pass. Order matters:
	 * businesses first so offers can link to them by name. Prunes imported
	 * records that have since vanished from the feed.
	 *
	 * @return array<string,int>
	 */
	public static function sync_all(): array {
		@set_time_limit( 0 );
		self::clear_cache();
		$counts  = [ 'businesses' => 0, 'events' => 0, 'promotions' => 0, 'images' => 0, 'skipped' => 0 ];

		$businesses = self::fetch( 'business' ) ?? [];
		$offers     = self::fetch( 'rewards' )  ?? [];
		$events     = self::fetch( 'events' )   ?? [];

		$seen = [ 'business' => [], 'event' => [], 'reward' => [] ];

		foreach ( $businesses as $row ) {
			$id = self::import_business( $row );
			if ( $id ) { $counts['businesses']++; $seen['business'][] = (string) ( $row['Id'] ?? '' ); }
		}
		$biz_map = self::business_name_map();
		foreach ( $offers as $row ) {
			$id = self::import_offer( $row, $biz_map );
			if ( $id ) { $counts['promotions']++; $seen['reward'][] = (string) ( $row['Id'] ?? '' ); }
		}
		foreach ( $events as $row ) {
			$id = self::import_event( $row );
			if ( $id ) { $counts['events']++; $seen['event'][] = (string) ( $row['Id'] ?? '' ); }
		}

		// Prune stale imports (only when the feed returned content).
		if ( $businesses ) { self::prune( 'cbd_business',  'business', $seen['business'] ); }
		if ( $offers )     { self::prune( 'cbd_promotion', 'reward',   $seen['reward'] ); }
		if ( $events )     { self::prune( 'cbd_event',     'event',    $seen['event'] ); }

		update_option( 'cbd_loqiva_last_sync', current_time( 'mysql' ) );
		return $counts;
	}

	// ── Batched sync (admin progress UI) ──────────────────────────

	/**
	 * One sync tick for the admin progress bar. The first call builds a flat work
	 * queue across the three feeds (businesses → offers → events, so offer→business
	 * links resolve); each later call imports the next TICK_BATCH records.
	 *
	 * @return array{done:bool,processed:int,total:int,counts:array<string,int>,label:string}
	 */
	public static function sync_tick(): array {
		@set_time_limit( 0 );
		$state = get_option( 'cbd_loqiva_progress' );

		if ( ! is_array( $state ) || ( $state['mode'] ?? '' ) !== 'sync' ) {
			self::clear_cache();
			$business = self::fetch( 'business' );
			$rewards  = self::fetch( 'rewards' );
			$events   = self::fetch( 'events' );

			if ( $business === null && $rewards === null && $events === null ) {
				return [ 'done' => true, 'processed' => 0, 'total' => 0, 'counts' => [ 'error' => 1 ], 'label' => __( 'Could not reach the Love Inverness feeds.', 'community-business-directory' ) ];
			}

			$queue = [];
			foreach ( array_keys( (array) $business ) as $i ) { $queue[] = [ 'business', $i ]; }
			foreach ( array_keys( (array) $rewards )  as $i ) { $queue[] = [ 'reward',   $i ]; }
			foreach ( array_keys( (array) $events )   as $i ) { $queue[] = [ 'event',    $i ]; }

			$state = [
				'mode'    => 'sync',
				'cursor'  => 0,
				'total'   => count( $queue ),
				'queue'   => $queue,
				'counts'  => [ 'businesses' => 0, 'events' => 0, 'promotions' => 0, 'images' => 0 ],
				'seen'    => [ 'business' => [], 'event' => [], 'reward' => [] ],
			];
			update_option( 'cbd_loqiva_progress', $state, false );
			return [ 'done' => false, 'processed' => 0, 'total' => $state['total'], 'counts' => $state['counts'], 'label' => __( 'Fetching feeds…', 'community-business-directory' ) ];
		}

		$business = self::fetch( 'business' ) ?? [];
		$rewards  = self::fetch( 'rewards' )  ?? [];
		$events   = self::fetch( 'events' )   ?? [];
		$biz_map  = self::business_name_map();

		$queue  = (array) $state['queue'];
		$counts = $state['counts'];
		$seen   = $state['seen'];
		$label  = '';

		for ( $n = 0; $n < self::TICK_BATCH && $state['cursor'] < count( $queue ); $n++, $state['cursor']++ ) {
			[ $type, $idx ] = $queue[ $state['cursor'] ];
			if ( $type === 'business' ) {
				$row = $business[ $idx ] ?? null;
				if ( $row && self::import_business( $row ) ) {
					$counts['businesses']++;
					$seen['business'][] = (string) ( $row['Id'] ?? '' );
					$label = (string) ( $row['BusinessName'] ?? '' );
				}
			} elseif ( $type === 'reward' ) {
				$row = $rewards[ $idx ] ?? null;
				if ( $row && self::import_offer( $row, $biz_map ) ) {
					$counts['promotions']++;
					$seen['reward'][] = (string) ( $row['Id'] ?? '' );
					$label = (string) ( $row['Title'] ?? '' );
				}
			} else {
				$row = $events[ $idx ] ?? null;
				if ( $row && self::import_event( $row ) ) {
					$counts['events']++;
					$seen['event'][] = (string) ( $row['Id'] ?? '' );
					$label = (string) ( $row['EventName'] ?? '' );
				}
			}
		}

		$state['counts'] = $counts;
		$state['seen']   = $seen;
		$processed       = (int) $state['cursor'];

		if ( $state['cursor'] >= count( $queue ) ) {
			if ( $business ) { self::prune( 'cbd_business',  'business', $seen['business'] ); }
			if ( $rewards )  { self::prune( 'cbd_promotion', 'reward',   $seen['reward'] ); }
			if ( $events )   { self::prune( 'cbd_event',     'event',    $seen['event'] ); }
			update_option( 'cbd_loqiva_last_sync', current_time( 'mysql' ) );
			delete_option( 'cbd_loqiva_progress' );
			return [ 'done' => true, 'processed' => $processed, 'total' => (int) $state['total'], 'counts' => $counts, 'label' => $label ];
		}

		update_option( 'cbd_loqiva_progress', $state, false );
		return [ 'done' => false, 'processed' => $processed, 'total' => (int) $state['total'], 'counts' => $counts, 'label' => $label ];
	}

	// ── Importers (one record → post + custom-table row) ──────────

	/** @return int post ID, or 0 on failure. */
	private static function import_business( array $r ): int {
		$remote_id = (string) ( $r['Id'] ?? '' );
		$name      = trim( (string) ( $r['BusinessName'] ?? '' ) );
		if ( $remote_id === '' || $name === '' ) {
			return 0;
		}
		$post_id = self::upsert_post( 'cbd_business', 'business', $remote_id, [
			'post_title'   => $name,
			'post_content' => wp_kses_post( (string) ( $r['Description'] ?? '' ) ),
			'post_excerpt' => self::excerpt( (string) ( $r['Description'] ?? '' ) ),
		], (string) ( $r['UpdatedAt'] ?? '' ), (string) ( $r['AppUrl'] ?? '' ) );
		if ( ! $post_id ) {
			return 0;
		}

		// Categories: UPPERCASE top-level (title-cased) + SubCategory child.
		$terms  = [];
		$parent = self::term_id( self::titlecase( (string) ( $r['Category'] ?? '' ) ) );
		if ( $parent ) {
			$terms[] = $parent;
			$sub = self::term_id( self::titlecase( (string) ( $r['SubCategory'] ?? '' ) ), $parent );
			if ( $sub ) { $terms[] = $sub; }
		}
		if ( $terms ) {
			wp_set_post_terms( $post_id, $terms, 'cbd_category' );
		}

		self::upsert_table( 'cbd_businesses', $post_id, [
			'owner_id'      => self::owner_id(),
			'status'        => 'active',
			'plan'          => 'free',
			'phone'         => self::clip( (string) ( $r['Telephone'] ?? '' ), 50 ),
			'website'       => esc_url_raw( (string) ( $r['Url'] ?? '' ) ),
			'address_line1' => self::clip( (string) ( $r['Address'] ?? '' ), 255 ),
			'city'          => 'Inverness',
			'state_province'=> 'Highland',
			'country'       => 'GB',
			'latitude'      => self::coord( $r['Latitude'] ?? 0 ),
			'longitude'     => self::coord( $r['Longitude'] ?? 0 ),
			'is_verified'   => 1,
			'opening_hours' => wp_json_encode( self::hours( $r['WorkingTime'] ?? [] ) ),
		] );

		$date = (string) ( $r['UpdatedAt'] ?? '' );
		self::set_featured( $post_id, (string) ( $r['MediaUrl'] ?? $r['LargeImage'] ?? '' ), $name, $date );
		self::sync_gallery( $post_id, (array) ( $r['AdditionalImages'] ?? [] ), $date );

		return $post_id;
	}

	/** @return int post ID, or 0 on failure. */
	private static function import_event( array $r ): int {
		$remote_id = (string) ( $r['Id'] ?? '' );
		$name      = trim( (string) ( $r['EventName'] ?? '' ) );
		if ( $remote_id === '' || $name === '' ) {
			return 0;
		}
		$post_id = self::upsert_post( 'cbd_event', 'event', $remote_id, [
			'post_title'   => $name,
			'post_content' => wp_kses_post( (string) ( $r['EventDescription'] ?? '' ) ),
			'post_excerpt' => self::excerpt( (string) ( $r['EventDescription'] ?? '' ) ),
		], (string) ( $r['UpdatedAt'] ?? '' ), (string) ( $r['AppUrl'] ?? '' ) );
		if ( ! $post_id ) {
			return 0;
		}

		$cat = self::term_id( (string) ( $r['EventCategory'] ?? '' ) );
		if ( $cat ) {
			wp_set_post_terms( $post_id, [ $cat ], 'cbd_category' );
		}

		$start = self::datetime( (string) ( $r['EventStartDate'] ?? '' ), (string) ( $r['EventStartTime'] ?? '' ) );
		$end   = self::datetime( (string) ( $r['EventEndDate'] ?? '' ), (string) ( $r['EventEndTime'] ?? '' ) );
		if ( ! $start ) { $start = current_time( 'mysql' ); }
		if ( ! $end )   { $end   = $start; }

		self::upsert_table( 'cbd_events', $post_id, [
			'business_id' => 0,
			'owner_id'    => self::owner_id(),
			'start_date'  => $start,
			'end_date'    => $end,
			'venue_name'  => self::clip( (string) ( $r['EventVenueName'] ?? '' ), 255 ),
			'venue_addr'  => (string) ( $r['VenueAddress'] ?? '' ),
			'latitude'    => self::coord( $r['VenueLatitude'] ?? 0 ),
			'longitude'   => self::coord( $r['VenueLongitude'] ?? 0 ),
			'ticket_url'  => esc_url_raw( (string) ( $r['EventWebsite'] ?? '' ) ),
			'is_free'     => 1,
			'status'      => 'published',
		] );

		self::set_featured( $post_id, (string) ( $r['featured_image'] ?? $r['LargeImage'] ?? '' ), $name, (string) ( $r['UpdatedAt'] ?? '' ) );

		return $post_id;
	}

	/**
	 * @param array<string,int> $biz_map  lowercase business name → business post_id.
	 * @return int post ID, or 0 on failure.
	 */
	private static function import_offer( array $r, array $biz_map ): int {
		$remote_id = (string) ( $r['Id'] ?? '' );
		$title     = trim( (string) ( $r['Title'] ?? '' ) );
		if ( $remote_id === '' || $title === '' ) {
			return 0;
		}
		$post_id = self::upsert_post( 'cbd_promotion', 'reward', $remote_id, [
			'post_title'   => $title,
			'post_content' => wp_kses_post( (string) ( $r['Description'] ?? '' ) ),
			'post_excerpt' => self::excerpt( (string) ( $r['Description'] ?? '' ) ),
		], (string) ( $r['UpdatedAt'] ?? '' ), (string) ( $r['AppUrl'] ?? '' ) );
		if ( ! $post_id ) {
			return 0;
		}

		$cat = self::term_id( self::titlecase( (string) ( $r['Category'] ?? '' ) ) );
		if ( $cat ) {
			wp_set_post_terms( $post_id, [ $cat ], 'cbd_category' );
		}

		// Offers link to a business by NAME (the feed's BusinessId is a different namespace).
		$biz_post_id = $biz_map[ strtolower( trim( (string) ( $r['BusinessName'] ?? '' ) ) ) ] ?? 0;

		self::upsert_table( 'cbd_promotions', $post_id, [
			'business_id' => (int) $biz_post_id,
			'owner_id'    => self::owner_id(),
			'cta_url'     => esc_url_raw( (string) ( $r['Url'] ?? '' ) ),
			'cta_text'    => 'Get Offer',
			'start_date'  => self::date( (string) ( $r['LiveStart'] ?? '' ) ),
			'expiry_date' => self::date( (string) ( $r['LiveEnd'] ?? '' ) ),
			'status'      => 'active',
		] );

		self::set_featured( $post_id, (string) ( $r['MediaUrl'] ?? $r['LargeImage'] ?? '' ), $title, (string) ( $r['UpdatedAt'] ?? '' ) );

		return $post_id;
	}

	// ── Upsert helpers ────────────────────────────────────────────

	/**
	 * Find the imported post for a remote record (by the idempotent id meta) and
	 * update it, or create it. Always (re)applies the source/id/updated tags.
	 *
	 * @return int post ID, or 0 on failure.
	 */
	private static function upsert_post( string $post_type, string $type, string $remote_id, array $fields, string $updated, string $app_url ): int {
		$key      = $type . ':' . $remote_id;
		$existing = self::find_post( $key );

		$postarr = array_merge( $fields, [
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_author' => self::owner_id(),
		] );

		// Back-date the post to the feed's UpdatedAt so the activity feed shows the
		// real "posted" date (and orders correctly) instead of "just now". The feed
		// carries local Inverness wall-clock; mirror it locally and derive the GMT.
		$ts = $updated ? strtotime( $updated ) : 0;
		if ( $ts ) {
			$postarr['post_date']     = date( 'Y-m-d H:i:s', $ts );
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
		}

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, self::SOURCE_META, self::SOURCE );
		update_post_meta( $post_id, self::ID_META, $key );
		if ( $updated ) { update_post_meta( $post_id, self::UPDATED_META, $updated ); }
		if ( $app_url ) { update_post_meta( $post_id, self::APPURL_META, esc_url_raw( $app_url ) ); }

		return $post_id;
	}

	/** Locate an imported post by its '{type}:{id}' key. */
	private static function find_post( string $key ): int {
		$ids = get_posts( [
			'post_type'        => [ 'cbd_business', 'cbd_event', 'cbd_promotion' ],
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => self::ID_META,
			'meta_value'       => $key,
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Insert or update a custom-table row keyed on post_id (the canonical join key).
	 * Refreshes updated_at where the table has the column.
	 */
	private static function upsert_table( string $table, int $post_id, array $data ): void {
		global $wpdb;
		$tbl    = $wpdb->prefix . $table;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$tbl} WHERE post_id = %d", $post_id ) );
		if ( $exists ) {
			$wpdb->update( $tbl, $data, [ 'post_id' => $post_id ] );
		} else {
			$wpdb->insert( $tbl, array_merge( [ 'post_id' => $post_id ], $data ) );
		}
	}

	/** lowercase business name → business post_id, for offer linking. */
	private static function business_name_map(): array {
		$map = [];
		$ids = get_posts( [
			'post_type'        => 'cbd_business',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => self::SOURCE_META,
			'meta_value'       => self::SOURCE,
		] );
		foreach ( $ids as $id ) {
			$map[ strtolower( trim( (string) get_the_title( $id ) ) ) ] = (int) $id;
		}
		return $map;
	}

	// ── Images ────────────────────────────────────────────────────

	/**
	 * Set the post's featured image from a remote URL, skipping the download when
	 * the source URL hasn't changed since last sync. Best-effort: failures are
	 * swallowed and disable further remote fetches for this run.
	 */
	private static function set_featured( int $post_id, string $url, string $title, string $date = '' ): void {
		$url = trim( $url );
		if ( ! $url || ! self::$remote_ok ) {
			return;
		}
		// Already imported this exact image and the thumbnail still exists → skip.
		if ( get_post_meta( $post_id, self::FIMG_META, true ) === $url && get_post_thumbnail_id( $post_id ) ) {
			return;
		}
		$aid = self::sideload( $url, $post_id, $title, $date );
		if ( $aid ) {
			set_post_thumbnail( $post_id, $aid );
			update_post_meta( $post_id, self::FIMG_META, $url );
		}
	}

	/**
	 * Sync a business gallery (attachments parented to the post — the Gallery
	 * shortcode reads them via post_parent). Idempotent: skips URLs already
	 * imported, removes attachments whose source URL is gone from the feed.
	 */
	private static function sync_gallery( int $post_id, array $urls, string $date = '' ): void {
		if ( ! self::$remote_ok ) {
			return;
		}
		$urls = array_values( array_filter( array_map( 'trim', $urls ) ) );
		$have = (array) get_post_meta( $post_id, '_cbd_loqiva_gallery', true ); // url => attachment_id

		// Remove gallery items no longer in the feed.
		foreach ( $have as $u => $aid ) {
			if ( ! in_array( $u, $urls, true ) ) {
				wp_delete_attachment( (int) $aid, true );
				unset( $have[ $u ] );
			}
		}
		$ts = $date ? strtotime( $date ) : 0;
		// Add new ones.
		foreach ( $urls as $u ) {
			if ( isset( $have[ $u ] ) && get_post( (int) $have[ $u ] ) ) {
				// Already imported — keep its date in step with the source record.
				if ( $ts ) {
					wp_update_post( [ 'ID' => (int) $have[ $u ], 'post_date' => date( 'Y-m-d H:i:s', $ts ), 'post_date_gmt' => get_gmt_from_date( date( 'Y-m-d H:i:s', $ts ) ) ] );
				}
				continue;
			}
			$aid = self::sideload( $u, $post_id, get_the_title( $post_id ), $date );
			if ( $aid ) {
				update_post_meta( $aid, '_cbd_album', 'Gallery' );
				$have[ $u ] = $aid;
			}
		}
		update_post_meta( $post_id, '_cbd_loqiva_gallery', $have );
	}

	/**
	 * Download a remote image and attach it to a post; returns the attachment ID
	 * (0 on failure). Tags the attachment as imported content so teardown removes
	 * it. Disables further remote fetches on the first hard failure.
	 */
	private static function sideload( string $url, int $post_id, string $title, string $date = '' ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			self::$remote_ok = false;
			return 0;
		}
		$name = basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'loqiva-image.jpg' );
		$file = [ 'name' => $name, 'tmp_name' => $tmp ];

		// Back-date the attachment to the source record's date so gallery photo
		// timestamps ("x ago") reflect the Love Inverness data, not the import run.
		$post_data = [];
		$ts        = $date ? strtotime( $date ) : 0;
		if ( $ts ) {
			$post_data['post_date']     = date( 'Y-m-d H:i:s', $ts );
			$post_data['post_date_gmt'] = get_gmt_from_date( $post_data['post_date'] );
		}

		$aid = media_handle_sideload( $file, $post_id, $title, $post_data );
		if ( is_wp_error( $aid ) ) {
			@unlink( $tmp );
			return 0;
		}
		update_post_meta( (int) $aid, self::SOURCE_META, self::SOURCE );
		return (int) $aid;
	}

	// ── Prune / teardown ──────────────────────────────────────────

	/**
	 * Delete imported posts of a type whose remote id is NOT in the seen list
	 * (records removed from the feed since a previous sync).
	 */
	private static function prune( string $post_type, string $type, array $seen_ids ): void {
		$seen = array_flip( array_map( static fn( $id ) => $type . ':' . $id, $seen_ids ) );
		$ids  = get_posts( [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => self::SOURCE_META,
			'meta_value'       => self::SOURCE,
		] );
		foreach ( $ids as $id ) {
			$key = (string) get_post_meta( $id, self::ID_META, true );
			if ( ! isset( $seen[ $key ] ) ) {
				self::delete_post( (int) $id );
			}
		}
	}

	/** @return array<string,int> counts of imported content (for the admin screen). */
	public static function counts(): array {
		return [
			'businesses' => self::count_type( 'cbd_business' ),
			'events'     => self::count_type( 'cbd_event' ),
			'promotions' => self::count_type( 'cbd_promotion' ),
			'last_sync'  => (string) get_option( 'cbd_loqiva_last_sync', '' ),
		];
	}

	private static function count_type( string $post_type ): int {
		$ids = get_posts( [
			'post_type'        => $post_type,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => self::SOURCE_META,
			'meta_value'       => self::SOURCE,
		] );
		return count( $ids );
	}

	/**
	 * Remove every imported post (and its custom-table row, attachments, gallery).
	 * Only touches `_cbd_source = loqiva` content — real listings are never
	 * affected. Batched for the admin progress UI.
	 *
	 * @return array{done:bool,processed:int,total:int,removed:array<string,int>}
	 */
	public static function teardown_tick( int $batch = 6 ): array {
		$state = get_option( 'cbd_loqiva_progress' );

		if ( ! is_array( $state ) || ( $state['mode'] ?? '' ) !== 'teardown' ) {
			$ids = get_posts( [
				'post_type'        => [ 'cbd_business', 'cbd_event', 'cbd_promotion' ],
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::SOURCE_META,
				'meta_value'       => self::SOURCE,
			] );
			$state = [ 'mode' => 'teardown', 'queue' => array_map( 'intval', $ids ), 'total' => count( $ids ), 'removed' => [ 'posts' => 0 ] ];
			if ( ! $state['queue'] ) {
				delete_option( 'cbd_loqiva_progress' );
				return [ 'done' => true, 'processed' => 0, 'total' => 0, 'removed' => $state['removed'] ];
			}
			update_option( 'cbd_loqiva_progress', $state, false );
			return [ 'done' => false, 'processed' => 0, 'total' => $state['total'], 'removed' => $state['removed'] ];
		}

		$queue   = (array) $state['queue'];
		$removed = $state['removed'];
		for ( $n = 0; $n < $batch && $queue; $n++ ) {
			self::delete_post( (int) array_shift( $queue ) );
			$removed['posts']++;
		}
		$state['queue']   = $queue;
		$state['removed'] = $removed;
		$processed        = (int) $state['total'] - count( $queue );

		if ( ! $queue ) {
			delete_option( 'cbd_loqiva_progress' );
			return [ 'done' => true, 'processed' => $processed, 'total' => (int) $state['total'], 'removed' => $removed ];
		}
		update_option( 'cbd_loqiva_progress', $state, false );
		return [ 'done' => false, 'processed' => $processed, 'total' => (int) $state['total'], 'removed' => $removed ];
	}

	/** Delete one imported post: its custom-table rows, attachments, then the post. */
	private static function delete_post( int $post_id ): void {
		global $wpdb;
		$type = get_post_type( $post_id );

		// Attachments (featured + gallery) parented to the post.
		$children = get_posts( [
			'post_type'   => 'attachment',
			'post_parent' => $post_id,
			'numberposts' => -1,
			'fields'      => 'ids',
			'post_status' => 'any',
		] );
		foreach ( $children as $aid ) {
			wp_delete_attachment( (int) $aid, true );
		}

		if ( $type === 'cbd_business' ) {
			$wpdb->delete( $wpdb->prefix . 'cbd_businesses', [ 'post_id' => $post_id ] );
			// Detach offers/events that pointed at this business.
			$wpdb->update( $wpdb->prefix . 'cbd_promotions', [ 'business_id' => 0 ], [ 'business_id' => $post_id ] );
			$wpdb->update( $wpdb->prefix . 'cbd_events',     [ 'business_id' => 0 ], [ 'business_id' => $post_id ] );
		} elseif ( $type === 'cbd_event' ) {
			$wpdb->delete( $wpdb->prefix . 'cbd_events', [ 'post_id' => $post_id ] );
		} elseif ( $type === 'cbd_promotion' ) {
			$wpdb->delete( $wpdb->prefix . 'cbd_promotions', [ 'post_id' => $post_id ] );
		}

		wp_delete_post( $post_id, true );
	}

	// ── Field mappers ─────────────────────────────────────────────

	/** Find-or-create a cbd_category term, returning its term_id (0 on failure). */
	private static function term_id( string $name, int $parent = 0 ): int {
		$name = trim( $name );
		if ( $name === '' ) {
			return 0;
		}
		$existing = get_term_by( 'name', $name, 'cbd_category' );
		if ( $existing ) {
			return (int) $existing->term_id;
		}
		$res = wp_insert_term( $name, 'cbd_category', $parent ? [ 'parent' => $parent ] : [] );
		if ( is_wp_error( $res ) ) {
			// Race / slug clash — try fetching again.
			$existing = get_term_by( 'name', $name, 'cbd_category' );
			return $existing ? (int) $existing->term_id : 0;
		}
		return (int) $res['term_id'];
	}

	/** "FOOD & DRINK" → "Food & Drink". */
	private static function titlecase( string $s ): string {
		$s = trim( $s );
		return $s === '' ? '' : ucwords( strtolower( $s ) );
	}

	/** Map Loqiva WorkingTime[] to the plugin's opening_hours JSON shape. */
	private static function hours( $working ): array {
		$map   = [ 'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun' ];
		$index = [];
		foreach ( (array) $working as $wt ) {
			$day = substr( strtolower( (string) ( $wt['Day'] ?? '' ) ), 0, 3 );
			if ( $day ) {
				$index[ $day ] = [
					'from' => substr( (string) ( $wt['OpeningTime'] ?? '' ), 0, 5 ),
					'to'   => substr( (string) ( $wt['ClosingTime'] ?? '' ), 0, 5 ),
				];
			}
		}
		$out = [];
		foreach ( $map as $key => $_ ) {
			$open = isset( $index[ $key ] );
			$out[ $key ] = [
				'open' => $open,
				'from' => $open ? ( $index[ $key ]['from'] ?: '09:00' ) : '09:00',
				'to'   => $open ? ( $index[ $key ]['to'] ?: '17:00' ) : '17:00',
			];
		}
		return $out;
	}

	/**
	 * Combine a 'YYYY-MM-DD' date + 'HH:MM:SS' time into a MySQL datetime
	 * ('' on failure). The feed carries local Inverness wall-clock times with no
	 * timezone, so we parse and format in the SAME timezone (date(), not gmdate())
	 * — the stored value always equals the feed's wall-clock, with no TZ shift.
	 */
	private static function datetime( string $date, string $time ): string {
		$date = trim( $date );
		if ( $date === '' ) {
			return '';
		}
		$ts = strtotime( $date . ' ' . ( trim( $time ) ?: '00:00:00' ) );
		return $ts ? date( 'Y-m-d H:i:s', $ts ) : '';
	}

	/** Normalise a 'YYYY-MM-DD' date (null on empty — column is nullable). */
	private static function date( string $date ): ?string {
		$date = trim( $date );
		if ( $date === '' ) {
			return null;
		}
		$ts = strtotime( $date );
		return $ts ? date( 'Y-m-d', $ts ) : null;
	}

	/** Clamp a coordinate to the table's DECIMAL range (0 when unparseable). */
	private static function coord( $v ): float {
		$f = (float) $v;
		return ( $f >= -180 && $f <= 180 ) ? round( $f, 8 ) : 0.0;
	}

	/** Plain-text excerpt from (possibly HTML) description. */
	private static function excerpt( string $html ): string {
		return wp_trim_words( wp_strip_all_tags( $html ), 28 );
	}

	private static function clip( string $s, int $len ): string {
		$s = sanitize_text_field( $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}
}
