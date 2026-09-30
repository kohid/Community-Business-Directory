<?php
/**
 * Demo Data generator — populates every plugin feature with realistic
 * Inverness-themed sample content, and tears it all down again.
 *
 * Everything created here is tagged so teardown only ever removes demo
 * content, never real listings:
 *   - posts & users  → meta key `_cbd_demo` = 1
 *   - custom tables  → rows are matched by their demo post_id / business_id
 *
 * @package CBD\Modules
 */

namespace CBD\Modules;

defined( 'ABSPATH' ) || exit;

class DemoData {

	public const FLAG = '_cbd_demo';

	/** Inverness town centre — demo coordinates are jittered around this. */
	private const LAT = 57.4778;
	private const LNG = -4.2247;

	/** Business category → loremflickr search tag(s) for themed stock photos. */
	private const CATEGORY_KEYWORDS = [
		'Restaurants'         => 'restaurant',
		'Cafes & Coffee'      => 'coffeeshop',
		'Boutiques & Fashion' => 'boutique',
		'Books & Stationery'  => 'bookshop',
		'Family Activities'   => 'loch,scotland',
		'Spas & Beauty'       => 'spa',
		'Guest Houses'        => 'guesthouse',
		'IT & Technology'     => 'office',
		'Galleries'           => 'artgallery',
		'Hotels'              => 'hotel',
		'Bars & Pubs'         => 'pub',
	];

	/** Once a remote photo fetch fails we stop trying and fall back to GD. */
	private static bool $remote_ok = true;

	/** Runtime cache of downloaded pool file paths, keyed by keyword. */
	private static array $pool = [];

	public static function is_enabled(): bool {
		return get_option( 'cbd_demo_mode' ) === '1';
	}

	public static function is_generated(): bool {
		return (bool) get_option( 'cbd_demo_generated' );
	}

	// ── Generate ──────────────────────────────────────────────────

	/** @return array<string,int> counts of created records */
	public static function generate(): array {
		if ( self::is_generated() ) {
			return [ 'skipped' => 1 ];
		}

		self::ensure_terms();

		$counts = self::empty_counts();
		$cat_by_name = self::terms_by_name( 'cbd_category' );
		$loc_by_name = self::terms_by_name( 'cbd_location' );

		[ $owners, $customers ] = self::make_demo_users();
		$counts['users'] = count( $owners ) + count( $customers );

		foreach ( self::dataset() as $i => $b ) {
			self::create_business( $b, $i, $owners, $customers, $cat_by_name, $loc_by_name, $counts );
		}

		// Vacancies reference the demo businesses just created as their companies.
		self::make_demo_jobs( $owners[0] ?? 0, $counts );

		$counts['images'] = count( self::demo_attachment_ids() );

		update_option( 'cbd_demo_generated', '1' );
		update_option( 'cbd_demo_mode', '1' );

		return $counts;
	}

	/** Zeroed counter set shared by the one-shot and batched generators. */
	private static function empty_counts(): array {
		return [ 'businesses' => 0, 'events' => 0, 'promotions' => 0, 'reviews' => 0, 'posts' => 0, 'jobs' => 0, 'users' => 0, 'analytics' => 0, 'reactions' => 0, 'images' => 0 ];
	}

	/** Make sure categories & locations exist to attach businesses to. */
	private static function ensure_terms(): void {
		if ( (int) wp_count_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false ] ) === 0 ) {
			\CBD\Core\Activator::seed_categories_public();
		}
	}

	/**
	 * Create the demo owner + customer accounts and vary their sign-in source.
	 *
	 * @return array{0:int[],1:int[]} [ owner IDs, customer IDs ]
	 */
	private static function make_demo_users(): array {
		$owners    = self::make_users( 'owner', 4, 'cbd_business_owner' );
		$customers = self::make_users( 'customer', 6, 'subscriber' );

		// Vary the sign-in source so the account-menu provider badge shows a mix
		// of Google / Facebook / X (empty = a plain email/password account).
		$prov_cycle = [ 'google', 'facebook', '', 'twitter', 'google', '' ];
		foreach ( array_values( array_merge( $owners, $customers ) ) as $idx => $uid ) {
			$p = $prov_cycle[ $idx % count( $prov_cycle ) ];
			if ( $p ) {
				update_user_meta( $uid, 'cbd_social_provider', $p );
			}
		}

		return [ $owners, $customers ];
	}

	/** Review copy pool — [ rating, title, body ]. */
	private static function review_snippets(): array {
		return [
			[ 5, 'Absolutely first class', 'Couldn\'t fault a thing. Friendly staff and a real local gem — will be back.' ],
			[ 5, 'A Highland favourite', 'We come here every time we\'re in Inverness. Always consistent and welcoming.' ],
			[ 4, 'Really good', 'Great experience overall, just a short wait at peak times. Highly recommend.' ],
			[ 4, 'Lovely spot', 'Warm atmosphere and good value. Parking nearby can be tricky.' ],
			[ 3, 'Decent', 'Did the job and the staff were polite. A few small things could be improved.' ],
			[ 5, 'Brilliant service', 'Went above and beyond for us. Genuinely impressed.' ],
		];
	}

	/**
	 * Build one demo business and all of its related content (taxonomies,
	 * imagery, reviews, follows/favourites, analytics, events, promotions, news
	 * posts and emoji reactions). Counters are accumulated into $counts by ref so
	 * the same routine drives both the one-shot generate() and the batched
	 * generate_tick() progress flow.
	 *
	 * @param array               $b           One row from self::dataset().
	 * @param int                 $i           Index into the dataset (picks the owner).
	 * @param int[]               $owners      Demo owner user IDs.
	 * @param int[]               $customers   Demo customer user IDs.
	 * @param array<string,int>   $cat_by_name Category name → term_id.
	 * @param array<string,int>   $loc_by_name Location name → term_id.
	 * @param array<string,int>   $counts      Running counters (by reference).
	 */
	private static function create_business( array $b, int $i, array $owners, array $customers, array $cat_by_name, array $loc_by_name, array &$counts ): void {
		global $wpdb;

		$owner_id = $owners[ $i % count( $owners ) ];
		$created  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . wp_rand( 1, 45 ) . ' days' ) );

		$post_id = wp_insert_post( [
			'post_type'    => 'cbd_business',
			'post_title'   => $b['name'],
			'post_content' => $b['desc'],
			'post_excerpt' => $b['tagline'],
			// Mirror the approval model: only active listings are public; a
			// pending one stays out of the directory until it's approved.
			'post_status'  => $b['status'] === 'active' ? 'publish' : 'pending',
			'post_author'  => $owner_id,
			'post_date'    => get_date_from_gmt( $created ),
			'post_date_gmt'=> $created,
		] );
		if ( is_wp_error( $post_id ) ) {
			return;
		}
		self::flag_post( $post_id );
		$counts['businesses']++;

		// Taxonomies.
		$term_ids = [];
		if ( isset( $cat_by_name[ $b['category'] ] ) ) {
			$term_ids[] = $cat_by_name[ $b['category'] ];
		}
		if ( $term_ids ) {
			wp_set_post_terms( $post_id, $term_ids, 'cbd_category' );
		}
		if ( isset( $loc_by_name[ $b['area'] ] ) ) {
			wp_set_post_terms( $post_id, [ $loc_by_name[ $b['area'] ] ], 'cbd_location' );
		}

		$is_featured = in_array( $b['plan'], [ 'premium', 'elite' ], true ) ? 1 : 0;
		$is_active   = $b['status'] === 'active';
		$is_paid     = in_array( $b['plan'], [ 'basic', 'premium', 'elite' ], true );
		// Paid plans carry a future renewal date (drives the Plan Usage modal);
		// free plans never expire.
		$plan_expires = $is_paid ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . wp_rand( 20, 320 ) . ' days' ) ) : null;

		$wpdb->insert( $wpdb->prefix . 'cbd_businesses', [
			'post_id'        => $post_id,
			'owner_id'       => $owner_id,
			'status'         => $b['status'],
			'plan'           => $b['plan'],
			'plan_expires_at'=> $plan_expires,
			'phone'          => $b['phone'],
			'email'          => $b['email'],
			'website'        => $b['website'],
			'address_line1'  => $b['address'],
			'city'           => 'Inverness',
			'state_province' => 'Highland',
			'postal_code'    => $b['postcode'],
			'country'        => 'GB',
			'latitude'       => self::jitter( self::LAT ),
			'longitude'      => self::jitter( self::LNG ),
			'is_featured'    => $is_featured,
			'is_verified'    => $is_active ? (int) ( wp_rand( 0, 1 ) ) : 0,
			'social_links'   => wp_json_encode( [
				'facebook'  => 'https://facebook.com/' . sanitize_title( $b['name'] ),
				'instagram' => 'https://instagram.com/' . sanitize_title( $b['name'] ),
				'twitter'   => '',
				'linkedin'  => '',
			] ),
			'opening_hours'  => wp_json_encode( self::opening_hours() ),
			'created_at'     => $created,
		] );

		update_post_meta( $post_id, '_cbd_is_featured', (string) $is_featured );

		// Imagery — real free stock photos (loremflickr), themed to the
		// category, with a GD placeholder fallback when offline.
		$kw = self::category_keyword( $b['category'] );

		// Logo (featured image) — a clean brand monogram avatar, the way real
		// directory listings present a logo, rather than a cropped stock photo.
		$logo_id = self::attach_logo( $post_id, $b['name'] );
		if ( $logo_id ) {
			set_post_thumbnail( $post_id, $logo_id );
		}

		// Cover banner — a branded gradient in the same brand colour as the logo
		// above, so the logo + cover read as one coherent identity instead of a
		// random stock photo that clashes with the monogram.
		$cover_id = self::create_cover_image( $post_id, $b['name'] );
		if ( $cover_id ) {
			update_post_meta( $post_id, '_cbd_cover_id', (int) $cover_id );
		}

		// Gallery photos — Gallery tab + Plan Usage "Photos" gauge. Count is
		// capped per plan so a free listing can sit right at its 3-photo limit.
		if ( $is_active ) {
			$cap    = [ 'free' => 3, 'basic' => 4, 'premium' => 5, 'elite' => 6 ][ $b['plan'] ] ?? 3;
			$albums = [ 'Gallery', 'Interior', 'Highlights' ];
			for ( $g = 0, $ng = wp_rand( 2, $cap ); $g < $ng; $g++ ) {
				$gid = self::attach_themed( $post_id, $kw, 'wide', $g + 2, $b['name'] );
				if ( $gid ) {
					update_post_meta( $gid, '_cbd_album', $albums[ $g % count( $albums ) ] );
				}
			}
		}

		// Reviews (active businesses only).
		$rating_sum = 0;
		$rating_cnt = 0;
		if ( $is_active ) {
			$snippets = self::review_snippets();
			$n = wp_rand( 2, 5 );
			for ( $r = 0; $r < $n; $r++ ) {
				$snip = $snippets[ array_rand( $snippets ) ];
				$cust = $customers[ array_rand( $customers ) ];
				$u    = get_userdata( $cust );
				$wpdb->insert( $wpdb->prefix . 'cbd_reviews', [
					'business_id'  => $post_id,
					'author_id'    => $cust,
					'author_name'  => $u ? $u->display_name : 'Guest',
					'author_email' => $u ? $u->user_email : 'guest@example.com',
					'rating'       => $snip[0],
					'title'        => $snip[1],
					'content'      => $snip[2],
					'status'       => 'approved',
					'is_verified'  => 1,
					'helpful_count'=> wp_rand( 0, 12 ),
					'created_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '-' . wp_rand( 1, 30 ) . ' days' ) ),
				] );
				$rating_sum += $snip[0];
				$rating_cnt++;
				$counts['reviews']++;
			}
		}

		// Follows & favourites from random customers.
		$follower_count = 0;
		foreach ( $customers as $cust ) {
			if ( wp_rand( 0, 2 ) === 0 ) {
				$wpdb->replace( $wpdb->prefix . 'cbd_follows', [ 'user_id' => $cust, 'business_id' => $post_id ] );
				$follower_count++;
			}
			if ( wp_rand( 0, 3 ) === 0 ) {
				$wpdb->replace( $wpdb->prefix . 'cbd_favorites', [ 'user_id' => $cust, 'business_id' => $post_id ] );
			}
		}

		// Analytics for the last 30 days (active only).
		$view_total = 0;
		if ( $is_active ) {
			for ( $d = 0; $d < 30; $d++ ) {
				$views = wp_rand( 2, 60 );
				$view_total += $views;
				$wpdb->insert( $wpdb->prefix . 'cbd_analytics', [
					'business_id'    => $post_id,
					'date'           => gmdate( 'Y-m-d', strtotime( "-{$d} days" ) ),
					'views'          => $views,
					'clicks_website' => wp_rand( 0, 12 ),
					'clicks_phone'   => wp_rand( 0, 8 ),
					'clicks_map'     => wp_rand( 0, 6 ),
					'new_followers'  => wp_rand( 0, 3 ),
					'new_reviews'    => wp_rand( 0, 1 ),
				] );
				$counts['analytics']++;
			}
		}

		// Roll up stats onto the business row + meta mirrors.
		$rating_avg = $rating_cnt ? round( $rating_sum / $rating_cnt, 2 ) : 0.00;
		$wpdb->update( $wpdb->prefix . 'cbd_businesses', [
			'rating_avg'     => $rating_avg,
			'review_count'   => $rating_cnt,
			'follower_count' => $follower_count,
			'view_count'     => $view_total,
		], [ 'post_id' => $post_id ] );
		update_post_meta( $post_id, '_cbd_rating_avg', (string) $rating_avg );
		update_post_meta( $post_id, '_cbd_view_count', (string) $view_total );

		// "Newly joined" business cards appear in the feed too — give the active
		// ones a few reactions so their reaction bar isn't empty.
		if ( $is_active ) {
			$counts['reactions'] += self::seed_reactions( $post_id, $customers, 3 );
		}

		// Events, promotions and news posts for active businesses — enough to
		// fill the activity feed, the profile tabs and the Plan Usage gauges.
		if ( $is_active ) {
			for ( $e = 0, $ne = wp_rand( 1, 2 ); $e < $ne; $e++ ) {
				$counts['events'] += self::make_event( $post_id, $owner_id, $b['name'], $b['area'], $kw, $customers, $counts );
			}
			for ( $pr = 0, $np = wp_rand( 0, 2 ); $pr < $np; $pr++ ) {
				$counts['promotions'] += self::make_promotion( $post_id, $owner_id, $b['name'], $kw, $customers, $counts );
			}
			for ( $po = 0, $npo = wp_rand( 1, 3 ); $po < $npo; $po++ ) {
				$counts['posts'] += self::make_business_post( $post_id, $owner_id, $b['name'], $b['area'], $kw, $customers, $counts );
			}
		}
	}

	// ── Batched generate / teardown (AJAX progress) ───────────────

	/**
	 * One generation tick for the admin progress UI. The first call (no stored
	 * progress) sets up terms + demo accounts; each subsequent call builds the
	 * next single business. State lives in the `cbd_demo_progress` option until
	 * the run completes.
	 *
	 * @return array{done:bool,processed:int,total:int,counts:array<string,int>,label:string}
	 */
	public static function generate_tick(): array {
		$state = get_option( 'cbd_demo_progress' );

		// Begin: create accounts, stash state, report a "preparing" step.
		if ( ! is_array( $state ) || ( $state['mode'] ?? '' ) !== 'generate' ) {
			if ( self::is_generated() ) {
				return [ 'done' => true, 'processed' => 0, 'total' => 0, 'counts' => [ 'skipped' => 1 ], 'label' => '' ];
			}
			self::ensure_terms();
			$counts = self::empty_counts();
			[ $owners, $customers ] = self::make_demo_users();
			$counts['users'] = count( $owners ) + count( $customers );

			$state = [
				'mode'      => 'generate',
				'owners'    => $owners,
				'customers' => $customers,
				'index'     => 0,
				'total'     => count( self::dataset() ),
				'counts'    => $counts,
			];
			update_option( 'cbd_demo_progress', $state, false );

			return [ 'done' => false, 'processed' => 0, 'total' => $state['total'], 'counts' => $counts, 'label' => __( 'Creating demo accounts…', 'community-business-directory' ) ];
		}

		// Step: build the next business.
		$dataset     = self::dataset();
		$cat_by_name = self::terms_by_name( 'cbd_category' );
		$loc_by_name = self::terms_by_name( 'cbd_location' );
		$counts      = $state['counts'];
		$i           = (int) $state['index'];
		$b           = $dataset[ $i ] ?? null;
		$label       = '';

		if ( $b ) {
			self::create_business( $b, $i, $state['owners'], $state['customers'], $cat_by_name, $loc_by_name, $counts );
			$label = $b['name'];
		}

		$state['index']  = $i + 1;
		$state['counts'] = $counts;

		if ( $state['index'] >= $state['total'] ) {
			// All businesses built — seed the demo vacancies that reference them.
			self::make_demo_jobs( $state['owners'][0] ?? 0, $counts );
			$counts['images'] = count( self::demo_attachment_ids() );
			update_option( 'cbd_demo_generated', '1' );
			update_option( 'cbd_demo_mode', '1' );
			delete_option( 'cbd_demo_progress' );
			return [ 'done' => true, 'processed' => $state['total'], 'total' => $state['total'], 'counts' => $counts, 'label' => $label ];
		}

		update_option( 'cbd_demo_progress', $state, false );
		return [ 'done' => false, 'processed' => $state['index'], 'total' => $state['total'], 'counts' => $counts, 'label' => $label ];
	}

	/**
	 * One teardown tick for the admin progress UI. The first call gathers every
	 * tagged record, removes the fast custom-table rows + cached photo pool, and
	 * queues the (slower) attachment/post/user deletions; each later call deletes
	 * the next batch from that queue.
	 *
	 * @return array{done:bool,processed:int,total:int,removed:array<string,int>}
	 */
	public static function teardown_tick( int $batch = 8 ): array {
		global $wpdb;
		$state = get_option( 'cbd_demo_progress' );

		// Begin: delete table rows + pool, then queue the heavy deletions.
		if ( ! is_array( $state ) || ( $state['mode'] ?? '' ) !== 'teardown' ) {
			$removed = [ 'posts' => 0, 'users' => 0, 'rows' => 0, 'images' => 0 ];

			$attachment_ids = self::demo_attachment_ids();
			$biz_ids        = self::demo_post_ids( 'cbd_business' );
			$all_ids        = self::demo_post_ids();
			$user_ids       = self::demo_user_ids();

			// Remove the cached stock-photo pool (raw files, not attachments).
			$upload = wp_upload_dir();
			if ( empty( $upload['error'] ) ) {
				$pool_dir = trailingslashit( $upload['basedir'] ) . 'cbd-demo-pool';
				if ( is_dir( $pool_dir ) ) {
					foreach ( (array) glob( $pool_dir . '/*' ) as $f ) {
						@unlink( $f );
					}
					@rmdir( $pool_dir );
				}
			}

			// Reactions are keyed by post_id (business posts, events, promos, businesses).
			if ( $all_ids ) {
				$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_reactions WHERE post_id IN (" . self::id_list( $all_ids ) . ')' );
			}
			if ( $biz_ids ) {
				$in = self::id_list( $biz_ids );
				foreach ( [ 'cbd_businesses', 'cbd_reviews', 'cbd_follows', 'cbd_favorites', 'cbd_analytics' ] as $tbl ) {
					$col = $tbl === 'cbd_businesses' ? 'post_id' : 'business_id';
					$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}{$tbl} WHERE {$col} IN ($in)" );
				}
				$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_events     WHERE business_id IN ($in)" );
				$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_promotions WHERE business_id IN ($in)" );
			}
			$event_ids = self::demo_post_ids( 'cbd_event' );
			$promo_ids = self::demo_post_ids( 'cbd_promotion' );
			if ( $event_ids ) {
				$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_events WHERE post_id IN (" . self::id_list( $event_ids ) . ')' );
			}
			if ( $promo_ids ) {
				$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_promotions WHERE post_id IN (" . self::id_list( $promo_ids ) . ')' );
			}

			// Queue heavy deletions: attachments first, then posts, then users.
			$queue = [];
			foreach ( $attachment_ids as $id ) {
				$queue[] = [ 'attachment', $id ];
			}
			foreach ( $all_ids as $id ) {
				$queue[] = [ 'post', $id ];
			}
			foreach ( $user_ids as $id ) {
				$queue[] = [ 'user', $id ];
			}

			$state = [ 'mode' => 'teardown', 'queue' => $queue, 'total' => count( $queue ), 'removed' => $removed ];

			if ( ! $queue ) {
				delete_option( 'cbd_demo_generated' );
				update_option( 'cbd_demo_mode', '0' );
				delete_option( 'cbd_demo_progress' );
				return [ 'done' => true, 'processed' => 0, 'total' => 0, 'removed' => $removed ];
			}

			update_option( 'cbd_demo_progress', $state, false );
			return [ 'done' => false, 'processed' => 0, 'total' => $state['total'], 'removed' => $removed ];
		}

		// Step: delete the next batch from the queue.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$queue   = (array) $state['queue'];
		$removed = $state['removed'];

		for ( $n = 0; $n < $batch && $queue; $n++ ) {
			[ $type, $id ] = array_shift( $queue );
			switch ( $type ) {
				case 'attachment':
					wp_delete_attachment( (int) $id, true );
					$removed['images']++;
					break;
				case 'post':
					wp_delete_post( (int) $id, true );
					$removed['posts']++;
					break;
				case 'user':
					wp_delete_user( (int) $id );
					$removed['users']++;
					break;
			}
		}

		$state['queue']   = $queue;
		$state['removed'] = $removed;
		$processed        = (int) $state['total'] - count( $queue );

		if ( ! $queue ) {
			delete_option( 'cbd_demo_generated' );
			update_option( 'cbd_demo_mode', '0' );
			delete_option( 'cbd_demo_progress' );
			return [ 'done' => true, 'processed' => $processed, 'total' => (int) $state['total'], 'removed' => $removed ];
		}

		update_option( 'cbd_demo_progress', $state, false );
		return [ 'done' => false, 'processed' => $processed, 'total' => (int) $state['total'], 'removed' => $removed ];
	}

	// ── Teardown ──────────────────────────────────────────────────

	/** @return array<string,int> counts of removed records */
	public static function teardown(): array {
		global $wpdb;
		$removed = [ 'posts' => 0, 'users' => 0, 'rows' => 0, 'images' => 0 ];

		// Delete demo media (featured images) and their files first — deleting
		// the parent post does not remove attached media automatically.
		foreach ( self::demo_attachment_ids() as $aid ) {
			wp_delete_attachment( $aid, true );
			$removed['images']++;
		}

		// Remove the cached stock-photo pool (raw files, not attachments).
		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) ) {
			$pool_dir = trailingslashit( $upload['basedir'] ) . 'cbd-demo-pool';
			if ( is_dir( $pool_dir ) ) {
				foreach ( (array) glob( $pool_dir . '/*' ) as $f ) {
					@unlink( $f );
				}
				@rmdir( $pool_dir );
			}
		}

		$biz_ids = self::demo_post_ids( 'cbd_business' );
		$all_ids = self::demo_post_ids();

		// Reactions are keyed by post_id (business posts, events, promos).
		if ( $all_ids ) {
			$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_reactions WHERE post_id IN (" . self::id_list( $all_ids ) . ')' );
		}

		if ( $biz_ids ) {
			$in = self::id_list( $biz_ids );
			foreach ( [ 'cbd_businesses', 'cbd_reviews', 'cbd_follows', 'cbd_favorites', 'cbd_analytics' ] as $tbl ) {
				$col = $tbl === 'cbd_businesses' ? 'post_id' : 'business_id';
				$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}{$tbl} WHERE {$col} IN ($in)" );
			}
			$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_events     WHERE business_id IN ($in)" );
			$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_promotions WHERE business_id IN ($in)" );
		}

		// Remaining demo rows keyed by their own post_id (events/promotions).
		$event_ids = self::demo_post_ids( 'cbd_event' );
		$promo_ids = self::demo_post_ids( 'cbd_promotion' );
		if ( $event_ids ) {
			$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_events WHERE post_id IN (" . self::id_list( $event_ids ) . ')' );
		}
		if ( $promo_ids ) {
			$removed['rows'] += (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cbd_promotions WHERE post_id IN (" . self::id_list( $promo_ids ) . ')' );
		}

		foreach ( $all_ids as $pid ) {
			wp_delete_post( $pid, true );
			$removed['posts']++;
		}

		// Demo users.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( self::demo_user_ids() as $uid ) {
			wp_delete_user( $uid );
			$removed['users']++;
		}

		delete_option( 'cbd_demo_generated' );
		update_option( 'cbd_demo_mode', '0' );

		return $removed;
	}

	// ── Status counts (for the Settings screen) ───────────────────

	/** @return array<string,int> */
	public static function counts(): array {
		global $wpdb;
		$biz_ids = self::demo_post_ids( 'cbd_business' );
		$all_ids = self::demo_post_ids();
		$reviews = 0;
		if ( $biz_ids ) {
			$reviews = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_reviews WHERE business_id IN (" . self::id_list( $biz_ids ) . ')' );
		}
		$reactions = 0;
		if ( $all_ids ) {
			$reactions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_reactions WHERE post_id IN (" . self::id_list( $all_ids ) . ')' );
		}
		return [
			'businesses' => count( $biz_ids ),
			'events'     => count( self::demo_post_ids( 'cbd_event' ) ),
			'promotions' => count( self::demo_post_ids( 'cbd_promotion' ) ),
			'posts'      => count( self::demo_post_ids( 'cbd_business_post' ) ),
			'jobs'       => count( self::demo_post_ids( 'cbd_job' ) ),
			'reviews'    => $reviews,
			'reactions'  => $reactions,
			'users'      => count( self::demo_user_ids() ),
			'images'     => count( self::demo_attachment_ids() ),
		];
	}

	// ── Jobs-only demo (standalone, driven from the Jobs admin screen) ──

	/** How many demo-tagged jobs currently exist. */
	public static function count_demo_jobs(): int {
		return count( self::demo_post_ids( 'cbd_job' ) );
	}

	/**
	 * Seed the demo vacancies independently of full Demo Mode. No-op when demo
	 * jobs already exist, so repeated clicks can't pile up duplicates.
	 *
	 * @return array{created:int,existed:int}
	 */
	public static function generate_demo_jobs(): array {
		$existing = self::count_demo_jobs();
		if ( $existing > 0 ) {
			return [ 'created' => 0, 'existed' => $existing ];
		}
		$counts = [ 'jobs' => 0 ];
		self::make_demo_jobs( self::admin_owner_id(), $counts );
		return [ 'created' => (int) $counts['jobs'], 'existed' => 0 ];
	}

	/**
	 * Delete every demo-tagged job (only cbd_job posts flagged _cbd_demo — real
	 * vacancies are never touched).
	 *
	 * @return int number removed
	 */
	public static function remove_demo_jobs(): int {
		$ids = self::demo_post_ids( 'cbd_job' );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $ids );
	}

	/** The site-admin user id to own standalone demo jobs. */
	private static function admin_owner_id(): int {
		$admin = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		if ( $admin ) {
			return (int) $admin->ID;
		}
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ] );
		return $admins ? (int) $admins[0] : 0;
	}

	// ── Internals ─────────────────────────────────────────────────

	/**
	 * Seed a set of Inverness-themed demo vacancies (post + meta only — jobs have
	 * no custom table). Companies are borrowed from the demo businesses so the
	 * board feels connected; every job is tagged for clean teardown.
	 *
	 * @return int number of jobs created
	 */
	private static function make_demo_jobs( int $owner_id, array &$counts ): int {
		$J = '\CBD\PostTypes\Job';

		$companies = [];
		foreach ( self::demo_post_ids( 'cbd_business' ) as $bid ) {
			$t = get_the_title( $bid );
			if ( $t ) {
				$companies[] = $t;
			}
		}
		if ( ! $companies ) {
			$companies = [ 'Highland Coffee Co.', 'Ness Digital', 'Riverside Retail', 'Caledonian Care', 'Inverness Books' ];
		}

		$areas = [ 'City Centre', 'Crown', 'Longman', 'Inshes', 'Culloden', 'Merkinch', 'Hilton' ];

		// [ title, type, workplace, salary ]
		$roles = [
			[ 'Barista / Front of House',        'part-time',  'onsite', '£11.50 – £12.50 an hour' ],
			[ 'Retail Sales Assistant',          'full-time',  'onsite', '£21,000 – £23,000 a year' ],
			[ 'Digital Marketing Executive',     'full-time',  'hybrid', '£26,000 – £30,000 a year' ],
			[ 'Software Developer',              'full-time',  'remote', '£35,000 – £45,000 a year' ],
			[ 'Care Assistant',                  'full-time',  'onsite', '£12.00 – £13.50 an hour' ],
			[ 'Housekeeping Team Member',        'temporary',  'onsite', '£11.60 an hour' ],
			[ 'Administrative Assistant',        'part-time',  'hybrid', '£22,000 pro rata' ],
			[ 'Graduate Account Manager',        'full-time',  'hybrid', '£24,000 + commission' ],
			[ 'Content & Social Media Intern',   'internship', 'hybrid', '£12.00 an hour' ],
			[ 'Chef de Partie',                  'full-time',  'onsite', '£28,000 – £32,000 a year' ],
		];

		$body = "We're looking for a motivated %1\$s to join the team at %2\$s in %3\$s. You'll play a key role day to day, working alongside a friendly local crew and helping us deliver a great experience for the Inverness community.\n\nWhat you'll do:\n• Bring energy, reliability and a positive attitude\n• Work well as part of a small, supportive team\n• Take pride in doing a great job for our customers\n\nWhat we offer:\n• Competitive pay and flexible hours\n• On-the-job training and genuine progression\n• A welcoming workplace in the heart of the Highlands\n\nTo apply, send us a short note about yourself — we'd love to hear from you.";

		$created = 0;
		foreach ( $roles as $i => $r ) {
			$company = $companies[ $i % count( $companies ) ];
			$area    = $areas[ $i % count( $areas ) ];
			[ $title, $type, $workplace, $salary ] = $r;

			$post_id = wp_insert_post( [
				'post_type'    => 'cbd_job',
				'post_title'   => $title,
				'post_content' => sprintf( $body, $title, $company, $area ),
				'post_excerpt' => wp_trim_words( sprintf( 'Join %1$s in %2$s as a %3$s.', $company, $area, $title ), 26 ),
				'post_status'  => 'publish',
				'post_author'  => $owner_id ?: 0,
				'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '-' . wp_rand( 0, 20 ) . ' days' ) ),
			] );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}
			$post_id = (int) $post_id;
			self::flag_post( $post_id );

			update_post_meta( $post_id, $J::M_COMPANY,   $company );
			update_post_meta( $post_id, $J::M_LOCATION,  $area . ', Inverness' );
			update_post_meta( $post_id, $J::M_TYPE,      $type );
			update_post_meta( $post_id, $J::M_WORKPLACE, $workplace );
			update_post_meta( $post_id, $J::M_SALARY,    $salary );
			update_post_meta( $post_id, $J::M_CLOSING,   gmdate( 'Y-m-d', strtotime( '+' . wp_rand( 10, 45 ) . ' days' ) ) );
			update_post_meta( $post_id, $J::M_APPLY_EML, 'careers@' . sanitize_title( $company ) . '.example' );
			update_post_meta( $post_id, $J::M_APPLY_URL, '' );
			update_post_meta( $post_id, $J::M_FEATURED,  0 === $i % 4 ? '1' : '0' );

			$created++;
		}

		$counts['jobs'] = ( $counts['jobs'] ?? 0 ) + $created;
		return $created;
	}

	private static function make_event( int $biz_post_id, int $owner_id, string $biz_name, string $area, string $keyword, array $customers, array &$counts ): int {
		$start = strtotime( '+' . wp_rand( 3, 40 ) . ' days ' . wp_rand( 17, 20 ) . ':00' );

		// [ title template, body template ] — %1$s = business, %2$s = area.
		$variants = [
			[ 'Live Music Night at %1$s', "Join us at %1\$s for an unforgettable evening of live Highland music. Local musicians will fill the room with everything from traditional folk to contemporary Scottish sounds, while our team keeps the drinks and nibbles flowing. Doors open early, so come down to %2\$s, grab a seat and settle in for the night. Booking is recommended as these evenings tend to fill up fast." ],
			[ 'Tasting Evening — %1$s', "We're throwing open our doors for a relaxed tasting evening at %1\$s. Sample a carefully chosen selection of local produce and drinks, meet the makers behind them, and discover a few new favourites along the way. It's a lovely chance to get to know your %2\$s neighbours over good food and easy conversation. Spaces are limited, so reserve yours today." ],
			[ 'Community Open Day: %1$s', "Everyone is welcome to our community open day at %1\$s. Bring the whole family along for a friendly, informal afternoon in %2\$s with demonstrations, refreshments and plenty to see and do. Whether you've been coming for years or you're simply curious, we'd love to say hello and show you what we're all about. Free entry, no booking needed." ],
			[ 'Seasonal Showcase at %1$s', "The seasons are turning and %1\$s is celebrating with a special showcase. Expect a beautifully curated line-up that reflects the very best of the Highlands at this time of year, all set in the heart of %2\$s. It's the perfect excuse for an afternoon out with friends. Pop the date in your diary — we can't wait to see you there." ],
		];
		$v     = $variants[ array_rand( $variants ) ];
		$title = sprintf( $v[0], $biz_name );

		$post_id = wp_insert_post( [
			'post_type'    => 'cbd_event',
			'post_title'   => $title,
			'post_content' => sprintf( $v[1], $biz_name, $area ),
			'post_excerpt' => wp_trim_words( sprintf( $v[1], $biz_name, $area ), 26 ),
			'post_status'  => 'publish',
			'post_author'  => $owner_id,
		] );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		self::flag_post( $post_id );
		$img = self::attach_themed( $post_id, $keyword ?: 'event', 'wide', wp_rand( 0, 9 ), $title );
		if ( $img ) {
			set_post_thumbnail( $post_id, $img );
		}

		$free = wp_rand( 0, 1 );
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'cbd_events', [
			'post_id'      => $post_id,
			'business_id'  => $biz_post_id,
			'owner_id'     => $owner_id,
			'start_date'   => gmdate( 'Y-m-d H:i:s', $start ),
			'end_date'     => gmdate( 'Y-m-d H:i:s', $start + 2 * HOUR_IN_SECONDS ),
			'venue_name'   => $biz_name,
			'venue_addr'   => $area . ', Inverness',
			'latitude'     => self::jitter( self::LAT ),
			'longitude'    => self::jitter( self::LNG ),
			'ticket_price' => $free ? 0.00 : (float) wp_rand( 5, 25 ),
			'is_free'      => $free,
			// Paid events carry a ticketing link so the "Get Tickets" button shows.
			'ticket_url'   => $free ? '' : 'https://tickets.example/' . sanitize_title( $title ),
			'capacity'     => wp_rand( 20, 120 ),
			'status'       => 'published',
		] );

		// Reactions so the event's feed card shows engagement.
		$counts['reactions'] += self::seed_reactions( $post_id, $customers, 2 );

		return 1;
	}

	private static function make_promotion( int $biz_post_id, int $owner_id, string $biz_name, string $keyword, array $customers, array &$counts ): int {
		$pct = [ 10, 15, 20, 25, 30 ][ array_rand( [ 10, 15, 20, 25, 30 ] ) ];

		$title_variants = [
			$pct . '% Off Everything at ' . $biz_name,
			'Save ' . $pct . '% at ' . $biz_name . ' This Week',
			$biz_name . ': ' . $pct . '% Off for the Community',
		];
		$title = $title_variants[ array_rand( $title_variants ) ];

		$body_variants = [
			"For a limited time only, %s is treating our Inverness community to a little something special. Show this voucher in store to redeem — it's our way of saying thank you for your continued support. Offer valid while stocks last, so please don't leave it too long!",
			"We love our regulars, and this one is just for you. Drop in to %s, mention this offer and enjoy the saving on your next visit. It's the perfect nudge to try something new, or to stock up on an old favourite right here in the Highlands.",
			"A wee thank-you from all of us at %s. Quote the code below to claim your discount, whether you're popping in for the very first time or you're already part of the family. Availability is limited, so it really is first come, first served.",
		];
		$body = sprintf( $body_variants[ array_rand( $body_variants ) ], $biz_name );

		$post_id = wp_insert_post( [
			'post_type'    => 'cbd_promotion',
			'post_title'   => $title,
			'post_content' => $body,
			'post_excerpt' => 'Save ' . $pct . '% — for a limited time.',
			'post_status'  => 'publish',
			'post_author'  => $owner_id,
		] );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		self::flag_post( $post_id );
		$img = self::attach_themed( $post_id, $keyword ?: 'sale', 'wide', wp_rand( 0, 9 ), $title );
		if ( $img ) {
			set_post_thumbnail( $post_id, $img );
		}

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'cbd_promotions', [
			'post_id'        => $post_id,
			'business_id'    => $biz_post_id,
			'owner_id'       => $owner_id,
			'coupon_code'    => 'NESS' . $pct,
			'discount_type'  => 'percent',
			'discount_value' => $pct,
			'start_date'     => gmdate( 'Y-m-d' ),
			'expiry_date'    => gmdate( 'Y-m-d', strtotime( '+' . wp_rand( 7, 40 ) . ' days' ) ),
			// A landing link so the promo card / single page show the CTA button.
			'cta_url'        => 'https://' . sanitize_title( $biz_name ) . '.example/offers',
			'cta_text'       => 'Get Offer',
			'is_featured'    => wp_rand( 0, 1 ),
			'redemption_count' => wp_rand( 0, 40 ),
			'status'         => 'active',
		] );

		// Reactions so the promotion's feed card shows engagement.
		$counts['reactions'] += self::seed_reactions( $post_id, $customers, 2 );

		return 1;
	}

	private static function make_business_post( int $business_id, int $owner_id, string $biz_name, string $area, string $keyword, array $customers, array &$counts ): int {
		// [ post-type label, title template, body template ] — %1$s = business, %2$s = area.
		$variants = [
			[ 'update', 'A wee update from %1$s',
				"It's been a busy and brilliant few weeks here at %1\$s. We've welcomed lots of new faces from around %2\$s and across the Highlands, and the warm response has meant the world to our small team. We've been quietly refining what we do best, and we can't wait for you to see the results. Whether you're a regular or just passing through Inverness, there's never been a better time to drop by and say hello." ],
			[ 'news', 'Big news at %1$s',
				"We've got some news we've been bursting to share. %1\$s is growing, and with that come a few exciting changes designed to make your visit even better. From the team behind the counter to the little details you might not notice straight away, everything has been considered with our %2\$s community in mind. Keep an eye on this space over the coming weeks — there's plenty more still to come." ],
			[ 'product', 'Just landed at %1$s',
				"Something new has arrived at %1\$s and we couldn't be more excited about it. We work hard to source only the very best, and this latest addition is already a firm favourite with the team. Come and see it for yourself — our doors in %2\$s are open and we're always happy to talk you through what's new. We think you're going to love it as much as we do." ],
			[ 'event_promo', 'A little treat from %1$s',
				"Because our customers are the heart of everything we do, %1\$s is sharing a small thank-you. For a limited time we're offering something special to everyone who stops by, no strings attached. It's our way of giving a little back to the %2\$s community that has supported us so generously over the years. Pop in soon — we'd hate for you to miss out." ],
			[ 'community', '%1$s and our community',
				"At %1\$s we believe a business is only ever as strong as the community around it. This month we've been proud to support local causes and events right across %2\$s, and we want to thank everyone who joined in and gave so generously. Together we've achieved something genuinely lovely. Here's to doing even more in the months ahead — and to the Highlands that make it all possible." ],
		];
		$v       = $variants[ array_rand( $variants ) ];
		$created = gmdate( 'Y-m-d H:i:s', strtotime( '-' . wp_rand( 0, 30 ) . ' days -' . wp_rand( 0, 23 ) . ' hours' ) );

		$post_id = wp_insert_post( [
			'post_type'    => 'cbd_business_post',
			'post_title'   => sprintf( $v[1], $biz_name ),
			'post_content' => sprintf( $v[2], $biz_name, $area ),
			'post_excerpt' => 'The latest from ' . $biz_name . '.',
			'post_status'  => 'publish',
			'post_author'  => $owner_id,
			'post_date'    => get_date_from_gmt( $created ),
			'post_date_gmt'=> $created,
		] );
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}
		self::flag_post( $post_id );
		$img = self::attach_themed( $post_id, $keyword ?: 'highlands', 'wide', wp_rand( 0, 9 ), $biz_name );
		if ( $img ) {
			set_post_thumbnail( $post_id, $img );
		}
		update_post_meta( $post_id, '_cbd_post_type', $v[0] );
		update_post_meta( $post_id, '_cbd_business_id', $business_id ); // scope to its business feed

		// Seed reactions so the post's feed card shows engagement.
		$counts['reactions'] += self::seed_reactions( $post_id, $customers, 1 );

		return 1;
	}

	/**
	 * Drop a spread of emoji reactions onto a post from random demo customers so
	 * every feed card (post / event / promotion / business) shows engagement.
	 *
	 * @param int   $chance Lower = more reactions. A customer reacts when
	 *                      wp_rand( 0, $chance ) === 0 (1 ≈ 50%, 2 ≈ 33%).
	 * @return int Number of reactions seeded.
	 */
	private static function seed_reactions( int $post_id, array $customers, int $chance = 1 ): int {
		if ( ! $customers ) {
			return 0;
		}
		global $wpdb;
		$types = array_keys( \CBD\Frontend\Reactions::TYPES );
		$count = 0;
		foreach ( $customers as $cust ) {
			if ( wp_rand( 0, max( 0, $chance ) ) === 0 ) {
				$wpdb->replace( $wpdb->prefix . 'cbd_reactions', [
					'post_id'    => $post_id,
					'user_id'    => $cust,
					'reaction'   => $types[ array_rand( $types ) ],
					'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . wp_rand( 1, 20 ) . ' days' ) ),
				] );
				$count++;
			}
		}
		return $count;
	}

	/** @return int[] created user IDs */
	private static function make_users( string $kind, int $n, string $role ): array {
		$first = [ 'Hamish', 'Eilidh', 'Fraser', 'Iona', 'Calum', 'Mairi', 'Lachlan', 'Skye', 'Rory', 'Isla' ];
		$last  = [ 'MacDonald', 'Fraser', 'Cameron', 'Ross', 'Grant', 'Mackay', 'Munro', 'Sutherland' ];
		$ids   = [];
		for ( $k = 1; $k <= $n; $k++ ) {
			$login = "cbd_demo_{$kind}_{$k}";
			$email = "{$login}@example.com";
			$existing = get_user_by( 'login', $login );
			if ( $existing ) {
				$ids[] = (int) $existing->ID;
				continue;
			}
			$name = $first[ array_rand( $first ) ] . ' ' . $last[ array_rand( $last ) ];
			$uid  = wp_insert_user( [
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => $name,
				'first_name'   => explode( ' ', $name )[0],
				'role'         => $role,
			] );
			if ( ! is_wp_error( $uid ) ) {
				update_user_meta( $uid, self::FLAG, 1 );
				$ids[] = (int) $uid;
			}
		}
		return $ids;
	}

	private static function flag_post( int $post_id ): void {
		update_post_meta( $post_id, self::FLAG, 1 );
	}

	/** Map a category to a loremflickr search tag (themed stock photos). */
	private static function category_keyword( string $category ): string {
		return self::CATEGORY_KEYWORDS[ $category ] ?? 'shopfront,scotland';
	}

	/**
	 * Lazily download a small pool of real free stock photos for a keyword
	 * (loremflickr) into uploads/cbd-demo-pool/, cached on disk + in memory.
	 * Returns [ 'wide' => [paths], 'square' => [paths] ]. Network failures flip
	 * self::$remote_ok off so the rest of the run falls back to GD instantly.
	 *
	 * @return array{wide:string[],square:string[]}
	 */
	private static function photo_pool_for( string $keyword ): array {
		if ( isset( self::$pool[ $keyword ] ) ) {
			return self::$pool[ $keyword ];
		}

		$sets = [
			'wide'   => [ 'n' => 3, 'w' => 1024, 'h' => 683 ],
			'square' => [ 'n' => 1, 'w' => 640,  'h' => 640 ],
		];
		$out = [ 'wide' => [], 'square' => [] ];

		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) ) {
			$dir = trailingslashit( $upload['basedir'] ) . 'cbd-demo-pool';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$slug = sanitize_title( $keyword ) ?: 'photo';

			foreach ( $sets as $shape => $cfg ) {
				for ( $n = 1; $n <= $cfg['n']; $n++ ) {
					$path = "{$dir}/{$slug}-{$shape}-{$n}.jpg";
					if ( ! file_exists( $path ) ) {
						if ( ! self::$remote_ok ) {
							continue;
						}
						// loremflickr serves CC-licensed Flickr photos by tag; commas = multiple tags.
						$url = "https://loremflickr.com/{$cfg['w']}/{$cfg['h']}/{$keyword}";
						$tmp = download_url( $url, 8 );
						if ( is_wp_error( $tmp ) ) {
							self::$remote_ok = false;
							continue;
						}
						if ( ! @copy( $tmp, $path ) ) {
							@rename( $tmp, $path );
						}
						@unlink( $tmp );
					}
					if ( file_exists( $path ) && filesize( $path ) > 0 && @getimagesize( $path ) ) {
						$out[ $shape ][] = $path;
					} elseif ( file_exists( $path ) && ! @getimagesize( $path ) ) {
						@unlink( $path ); // discard a non-image (e.g. an error page) response
					}
				}
			}
		}

		self::$pool[ $keyword ] = $out;
		return $out;
	}

	/** A curated set of brand-logo background colours (hex, no #). */
	private const LOGO_COLORS = [ '2b6344', '1e6b7a', 'c47b1a', '6b2ba8', '2b6387', 'b84a35', '3a7d5a', '9c2c4b', '334155', '0f766e' ];

	/** Deterministic brand colour for a business name (hex, no #). */
	private static function brand_color( string $name ): string {
		return self::LOGO_COLORS[ abs( crc32( $name ) ) % count( self::LOGO_COLORS ) ];
	}

	/**
	 * Build a realistic business "logo" — a flat brand-coloured monogram avatar
	 * (initials on a solid colour), the way real directory listings show a logo,
	 * instead of a cropped stock photo. Uses the ui-avatars service (cached on
	 * disk in the demo pool) and falls back to a GD-drawn monogram when offline.
	 * Tags the attachment as demo content. Returns the attachment ID, or 0.
	 */
	private static function attach_logo( int $post_id, string $name ): int {
		$initials = self::initials( $name );
		$bg       = self::brand_color( $name );
		$src      = '';

		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) ) {
			$dir = trailingslashit( $upload['basedir'] ) . 'cbd-demo-pool';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$path = $dir . '/logo-' . sanitize_title( $name ) . '.png';
			if ( ! file_exists( $path ) && self::$remote_ok ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$url = add_query_arg( [
					'name'       => $initials,
					'length'     => 2,
					'size'       => 512,
					'bold'       => 'true',
					'format'     => 'png',
					'background' => $bg,
					'color'      => 'ffffff',
					'font-size'  => '0.42',
				], 'https://ui-avatars.com/api/' );
				$tmp = download_url( $url, 8 );
				if ( is_wp_error( $tmp ) ) {
					self::$remote_ok = false;
				} else {
					if ( ! @copy( $tmp, $path ) ) {
						@rename( $tmp, $path );
					}
					@unlink( $tmp );
				}
			}
			if ( file_exists( $path ) && filesize( $path ) > 0 && @getimagesize( $path ) ) {
				$src = $path;
			} elseif ( file_exists( $path ) && ! @getimagesize( $path ) ) {
				@unlink( $path ); // discard a non-image (e.g. an error page) response
			}
		}

		if ( $src && empty( $upload['error'] ) ) {
			$fname = wp_unique_filename( $upload['path'], 'cbd-demo-' . $post_id . '-logo-' . sanitize_title( $name ) . '.png' );
			$dest  = trailingslashit( $upload['path'] ) . $fname;
			if ( @copy( $src, $dest ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$aid = wp_insert_attachment( [
					'post_mime_type' => 'image/png',
					'post_title'     => $name . ' logo',
					'post_status'    => 'inherit',
				], $dest, $post_id );
				if ( ! is_wp_error( $aid ) && $aid ) {
					wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $dest ) );
					update_post_meta( $aid, self::FLAG, 1 );
					return (int) $aid;
				}
			}
		}

		// Offline / failure fallback — a GD-drawn brand monogram.
		return self::create_logo_image( $post_id, $name );
	}

	/**
	 * GD fallback for attach_logo(): a flat brand-coloured square with the
	 * business initials centred — the same minimalist look as the remote
	 * monogram, so an offline run still produces a logo (not a placeholder box).
	 * Returns the attachment ID, or 0 if GD is unavailable.
	 */
	private static function create_logo_image( int $post_id, string $name ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagescale' ) ) {
			return 0;
		}
		$hex = self::brand_color( $name );
		$r   = (int) hexdec( substr( $hex, 0, 2 ) );
		$g   = (int) hexdec( substr( $hex, 2, 2 ) );
		$b   = (int) hexdec( substr( $hex, 4, 2 ) );

		$size = 512;
		$img  = imagecreatetruecolor( $size, $size );
		$bg   = imagecolorallocate( $img, $r, $g, $b );
		imagefilledrectangle( $img, 0, 0, $size, $size, $bg );

		// Initials drawn small with a built-in font, then upscaled smoothly so
		// they fill roughly half the canvas — the text block shares the brand
		// background, so it composites seamlessly onto the flat fill.
		$initials = self::initials( $name );
		$font     = 5;
		$tw       = imagefontwidth( $font ) * strlen( $initials );
		$th       = imagefontheight( $font );
		$tmp      = imagecreatetruecolor( $tw, $th );
		imagefilledrectangle( $tmp, 0, 0, $tw, $th, $bg );
		$white = imagecolorallocate( $tmp, 255, 255, 255 );
		imagestring( $tmp, $font, 0, 0, $initials, $white );
		$scale = max( 1, (int) floor( ( $size * 0.5 ) / $th ) );
		$big   = imagescale( $tmp, $tw * $scale, $th * $scale, IMG_BICUBIC );
		if ( ! $big ) {
			imagedestroy( $tmp );
			imagedestroy( $img );
			return 0;
		}
		$bw = imagesx( $big );
		$bh = imagesy( $big );
		imagecopy( $img, $big, (int) ( ( $size - $bw ) / 2 ), (int) ( ( $size - $bh ) / 2 ), 0, 0, $bw, $bh );

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			imagedestroy( $tmp );
			imagedestroy( $big );
			imagedestroy( $img );
			return 0;
		}
		$filename = 'cbd-demo-' . $post_id . '-logo-' . sanitize_title( $name ) . '.png';
		$path     = trailingslashit( $upload['path'] ) . $filename;
		imagepng( $img, $path );
		imagedestroy( $tmp );
		imagedestroy( $big );
		imagedestroy( $img );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$aid = wp_insert_attachment( [
			'post_mime_type' => 'image/png',
			'post_title'     => $name . ' logo',
			'post_status'    => 'inherit',
		], $path, $post_id );
		if ( is_wp_error( $aid ) || ! $aid ) {
			return 0;
		}
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $path ) );
		update_post_meta( $aid, self::FLAG, 1 );
		return (int) $aid;
	}

	/**
	 * Generate a branded cover banner (GD) in the business's brand colour — the
	 * same colour as its monogram logo — so the logo and cover read as one
	 * identity. A clean vertical gradient (brand → darker) with a faint accent
	 * band, attached as demo content. Returns the attachment ID, or 0.
	 */
	private static function create_cover_image( int $post_id, string $name ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}
		$hex = self::brand_color( $name );
		$r   = (int) hexdec( substr( $hex, 0, 2 ) );
		$g   = (int) hexdec( substr( $hex, 2, 2 ) );
		$b   = (int) hexdec( substr( $hex, 4, 2 ) );

		$w   = 1200;
		$h   = 400;
		$img = imagecreatetruecolor( $w, $h );

		// Vertical gradient: brand colour at the top → ~42% darker at the foot.
		for ( $y = 0; $y < $h; $y++ ) {
			$t   = $y / $h;
			$rr  = (int) round( $r * ( 1 - 0.42 * $t ) );
			$gg  = (int) round( $g * ( 1 - 0.42 * $t ) );
			$bb  = (int) round( $b * ( 1 - 0.42 * $t ) );
			$col = imagecolorallocate( $img, $rr, $gg, $bb );
			imagefilledrectangle( $img, 0, $y, $w, $y, $col );
		}

		// Faint lighter accent band across the upper third for a designed feel.
		imagealphablending( $img, true );
		$accent = imagecolorallocatealpha(
			$img,
			min( 255, $r + 45 ),
			min( 255, $g + 45 ),
			min( 255, $b + 45 ),
			105
		);
		imagesetthickness( $img, (int) round( $h * 0.22 ) );
		imageline( $img, 0, (int) ( $h * 0.30 ), $w, (int) ( $h * 0.12 ), $accent );
		imagesetthickness( $img, 1 );

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			imagedestroy( $img );
			return 0;
		}
		$filename = 'cbd-demo-' . $post_id . '-cover-' . sanitize_title( $name ) . '.png';
		$path     = trailingslashit( $upload['path'] ) . $filename;
		imagepng( $img, $path );
		imagedestroy( $img );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$aid = wp_insert_attachment( [
			'post_mime_type' => 'image/png',
			'post_title'     => $name . ' cover',
			'post_status'    => 'inherit',
		], $path, $post_id );
		if ( is_wp_error( $aid ) || ! $aid ) {
			return 0;
		}
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $path ) );
		update_post_meta( $aid, self::FLAG, 1 );
		return (int) $aid;
	}

	/**
	 * Attach a themed photo to a post from the keyword pool (copying a pooled
	 * file into a fresh attachment), tagged as demo content. Falls back to a GD
	 * placeholder if no pooled photo is available. Does NOT set the thumbnail —
	 * callers decide (featured image vs cover vs gallery). Returns attachment ID.
	 */
	private static function attach_themed( int $post_id, string $keyword, string $shape, int $variant, string $title ): int {
		$pool  = self::photo_pool_for( $keyword );
		$files = $pool[ $shape ] ?? [];
		if ( ! $files && $shape === 'square' ) {
			$files = $pool['wide'] ?? []; // a wide crop is fine for a logo if no square was fetched
		}

		if ( $files ) {
			$src    = $files[ $variant % count( $files ) ];
			$upload = wp_upload_dir();
			if ( empty( $upload['error'] ) ) {
				$name = wp_unique_filename( $upload['path'], 'cbd-demo-' . $post_id . '-' . $shape . $variant . '-' . sanitize_title( $title ) . '.jpg' );
				$dest = trailingslashit( $upload['path'] ) . $name;
				if ( @copy( $src, $dest ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$aid = wp_insert_attachment( [
						'post_mime_type' => 'image/jpeg',
						'post_title'     => $title,
						'post_status'    => 'inherit',
					], $dest, $post_id );
					if ( ! is_wp_error( $aid ) && $aid ) {
						wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $dest ) );
						update_post_meta( $aid, self::FLAG, 1 );
						return (int) $aid;
					}
				}
			}
		}

		// No stock photo available — use the branded GD placeholder.
		return self::create_attached_image( $post_id, $title, false, $variant );
	}

	/**
	 * Generate a branded placeholder image (PNG via GD), attach it to the post
	 * and tag it as demo content. When $as_thumb is true it also becomes the
	 * featured image; otherwise it is a plain attached image (gallery photo).
	 * $variant shifts the colour + filename so a post can have several distinct
	 * photos. Returns the attachment ID, or 0 if GD is unavailable / it failed.
	 */
	private static function create_attached_image( int $post_id, string $label, bool $as_thumb, int $variant = 0 ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagescale' ) ) {
			return 0;
		}

		$palette = [ [ 43, 99, 68 ], [ 30, 107, 122 ], [ 196, 123, 26 ], [ 107, 43, 168 ], [ 43, 99, 135 ], [ 184, 74, 53 ], [ 58, 125, 90 ] ];
		$c = $palette[ ( abs( crc32( $label ) ) + $variant * 3 ) % count( $palette ) ];

		$w      = $as_thumb ? 600 : 800;
		$h      = $as_thumb ? 400 : 600;
		$band_h = (int) round( $h * 0.17 );

		$img = imagecreatetruecolor( $w, $h );
		$bg  = imagecolorallocate( $img, $c[0], $c[1], $c[2] );
		imagefilledrectangle( $img, 0, 0, $w, $h, $bg );

		// Darker footer band for a designed-placeholder feel.
		$band = imagecolorallocate( $img, (int) ( $c[0] * 0.78 ), (int) ( $c[1] * 0.78 ), (int) ( $c[2] * 0.78 ) );
		imagefilledrectangle( $img, 0, $h - $band_h, $w, $h, $band );

		// Initials, drawn small with a built-in font then upscaled.
		$initials = self::initials( $label );
		$font = 5;
		$tw   = imagefontwidth( $font ) * strlen( $initials );
		$th   = imagefontheight( $font );
		$tmp  = imagecreatetruecolor( $tw, $th );
		imagefilledrectangle( $tmp, 0, 0, $tw, $th, $bg );
		$white = imagecolorallocate( $tmp, 255, 255, 255 );
		imagestring( $tmp, $font, 0, 0, $initials, $white );
		$big = imagescale( $tmp, $tw * 16, $th * 16, IMG_BICUBIC );
		if ( ! $big ) {
			imagedestroy( $tmp );
			imagedestroy( $img );
			return 0;
		}
		$bw  = imagesx( $big );
		$bh  = imagesy( $big );
		imagecopy( $img, $big, (int) ( ( $w - $bw ) / 2 ), (int) ( ( $h - $band_h - $bh ) / 2 ), 0, 0, $bw, $bh );

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			imagedestroy( $tmp );
			imagedestroy( $big );
			imagedestroy( $img );
			return 0;
		}
		$suffix   = $as_thumb ? 'logo' : 'img' . $variant;
		$filename = 'cbd-demo-' . $post_id . '-' . $suffix . '-' . sanitize_title( $label ) . '.png';
		$path     = trailingslashit( $upload['path'] ) . $filename;
		imagepng( $img, $path );
		imagedestroy( $tmp );
		imagedestroy( $big );
		imagedestroy( $img );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_id = wp_insert_attachment( [
			'post_mime_type' => 'image/png',
			'post_title'     => $label,
			'post_status'    => 'inherit',
		], $path, $post_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return 0;
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $path ) );
		update_post_meta( $attach_id, self::FLAG, 1 );
		if ( $as_thumb ) {
			set_post_thumbnail( $post_id, $attach_id );
		}

		return (int) $attach_id;
	}

	private static function initials( string $label ): string {
		$skip    = [ 'the', 'and', 'of', 'at', '&' ];
		$letters = '';
		foreach ( preg_split( '/\s+/', trim( $label ) ) as $word ) {
			if ( $word === '' || in_array( strtolower( $word ), $skip, true ) ) {
				continue;
			}
			$letters .= strtoupper( $word[0] );
			if ( strlen( $letters ) >= 2 ) {
				break;
			}
		}
		return $letters ?: 'CBD';
	}

	/** @return int[] */
	private static function demo_post_ids( ?string $type = null ): array {
		$args = [
			'post_type'      => $type ?: [ 'cbd_business', 'cbd_event', 'cbd_promotion', 'cbd_business_post', 'cbd_job' ],
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::FLAG,
			'meta_value'     => 1,
		];
		return array_map( 'intval', ( new \WP_Query( $args ) )->posts );
	}

	/** @return int[] */
	private static function demo_user_ids(): array {
		return array_map( 'intval', get_users( [ 'meta_key' => self::FLAG, 'meta_value' => 1, 'fields' => 'ID' ] ) );
	}

	/** @return int[] */
	private static function demo_attachment_ids(): array {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::FLAG,
			'meta_value'     => 1,
		];
		return array_map( 'intval', ( new \WP_Query( $args ) )->posts );
	}

	/** @return array<string,int> term name → term_id */
	private static function terms_by_name( string $taxonomy ): array {
		$out   = [];
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ $t->name ] = (int) $t->term_id;
			}
		}
		return $out;
	}

	private static function id_list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	private static function jitter( float $base ): float {
		return round( $base + ( wp_rand( -1500, 1500 ) / 100000 ), 8 );
	}

	private static function opening_hours(): array {
		$hours = [];
		foreach ( [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ] as $day ) {
			$open = $day !== 'sun';
			$hours[ $day ] = [ 'open' => $open, 'from' => '09:00', 'to' => $day === 'sat' ? '16:00' : '17:30' ];
		}
		return $hours;
	}

	/** @return array<int,array<string,string>> */
	private static function dataset(): array {
		return [
			[ 'name' => 'The Highland Larder',     'category' => 'Restaurants',         'area' => 'City Centre', 'plan' => 'premium', 'status' => 'active',  'tagline' => 'Seasonal Scottish dining',        'desc' => 'A celebrated city-centre restaurant serving the best of Highland produce, from Black Isle beef to fresh-landed seafood.', 'phone' => '01463 100101', 'email' => 'hello@highlandlarder.example', 'website' => 'https://highlandlarder.example', 'address' => '12 Church Street',  'postcode' => 'IV1 1ES' ],
			[ 'name' => 'Ness Bank Coffee',         'category' => 'Cafes & Coffee',      'area' => 'Riverside',   'plan' => 'basic',   'status' => 'active',  'tagline' => 'Riverside roastery & café',       'desc' => 'Specialty coffee roasted on the banks of the River Ness, with house-baked pastries and a sunny terrace.', 'phone' => '01463 100102', 'email' => 'hello@nessbankcoffee.example', 'website' => 'https://nessbankcoffee.example', 'address' => '3 Ness Bank', 'postcode' => 'IV2 4SF' ],
			[ 'name' => 'Castle View Boutique',     'category' => 'Boutiques & Fashion', 'area' => 'Old Town',    'plan' => 'free',    'status' => 'active',  'tagline' => 'Independent Highland fashion',    'desc' => 'Carefully curated womenswear and accessories from Scottish and independent designers, in the shadow of Inverness Castle.', 'phone' => '01463 100103', 'email' => 'shop@castleview.example', 'website' => 'https://castleview.example', 'address' => '21 Castle Street', 'postcode' => 'IV2 3DU' ],
			[ 'name' => 'Caledonian Books',         'category' => 'Books & Stationery',  'area' => 'City Centre', 'plan' => 'basic',   'status' => 'active',  'tagline' => 'Books, maps & Highland reads',    'desc' => 'A much-loved independent bookshop specialising in Scottish history, Gaelic titles and local interest.', 'phone' => '01463 100104', 'email' => 'info@caledonianbooks.example', 'website' => 'https://caledonianbooks.example', 'address' => '8 Union Street', 'postcode' => 'IV1 1PP' ],
			[ 'name' => 'Loch Ness Adventures',     'category' => 'Family Activities',   'area' => 'West',        'plan' => 'premium', 'status' => 'active',  'tagline' => 'Cruises & guided tours',          'desc' => 'Family-run boat trips and guided wildlife tours on Loch Ness, departing daily from Inverness.', 'phone' => '01463 100105', 'email' => 'book@lochnessadventures.example', 'website' => 'https://lochnessadventures.example', 'address' => 'Tomnahurich Bridge', 'postcode' => 'IV3 8NN' ],
			[ 'name' => 'Inverness Wellness Spa',   'category' => 'Spas & Beauty',       'area' => 'North',       'plan' => 'elite',   'status' => 'active',  'tagline' => 'Relax, restore, renew',           'desc' => 'A tranquil day spa offering massage, facials and thermal experiences using Scottish botanical products.', 'phone' => '01463 100106', 'email' => 'spa@invernesswellness.example', 'website' => 'https://invernesswellness.example', 'address' => '44 Telford Road', 'postcode' => 'IV3 5LE' ],
			[ 'name' => 'River Ness Guest House',   'category' => 'Guest Houses',        'area' => 'Riverside',   'plan' => 'premium', 'status' => 'active',  'tagline' => 'Boutique riverside rooms',        'desc' => 'A warm Victorian guest house moments from the river and city centre, with award-winning Highland breakfasts.', 'phone' => '01463 100107', 'email' => 'stay@rivernessguesthouse.example', 'website' => 'https://rivernessguesthouse.example', 'address' => '27 Ness Bank', 'postcode' => 'IV2 4SF' ],
			[ 'name' => 'Highland Tech Solutions',  'category' => 'IT & Technology',     'area' => 'South',       'plan' => 'basic',   'status' => 'active',  'tagline' => 'IT support for local business',   'desc' => 'Friendly, jargon-free IT support, web and networking services for businesses across the Highlands.', 'phone' => '01463 100108', 'email' => 'support@highlandtech.example', 'website' => 'https://highlandtech.example', 'address' => '5 Longman Road', 'postcode' => 'IV1 1RY' ],
			[ 'name' => 'Victorian Market Crafts',  'category' => 'Galleries',           'area' => 'City Centre', 'plan' => 'free',    'status' => 'active',  'tagline' => 'Handmade Highland crafts',        'desc' => 'A collective of local makers in the historic Victorian Market — ceramics, textiles, jewellery and art.', 'phone' => '01463 100109', 'email' => 'makers@victorianmarketcrafts.example', 'website' => 'https://victorianmarketcrafts.example', 'address' => 'Victorian Market, Academy Street', 'postcode' => 'IV1 1JN' ],
			[ 'name' => 'Glen Mhor Hotel & Bar',    'category' => 'Hotels',              'area' => 'Riverside',   'plan' => 'elite',   'status' => 'active',  'tagline' => 'Riverside hotel & whisky bar',    'desc' => 'A landmark riverside hotel with a renowned whisky bar, restaurant and views to the castle.', 'phone' => '01463 100110', 'email' => 'reception@glenmhor.example', 'website' => 'https://glenmhor.example', 'address' => '9 Ness Bank', 'postcode' => 'IV2 4SG' ],
			[ 'name' => 'Aurora Hair Studio',       'category' => 'Spas & Beauty',       'area' => 'East',        'plan' => 'free',    'status' => 'pending', 'tagline' => 'Contemporary hair & colour',      'desc' => 'A modern salon on the east side of the city offering cut, colour and styling by an experienced team.', 'phone' => '01463 100111', 'email' => 'hello@aurorahair.example', 'website' => 'https://aurorahair.example', 'address' => '60 Crown Drive', 'postcode' => 'IV2 3QG' ],
			[ 'name' => 'The Whisky Cellar',        'category' => 'Bars & Pubs',         'area' => 'Old Town',    'plan' => 'basic',   'status' => 'pending', 'tagline' => 'Highland malts & live folk',      'desc' => 'An intimate old-town bar with one of the finest malt selections in the Highlands and regular live folk sessions.', 'phone' => '01463 100112', 'email' => 'slainte@whiskycellar.example', 'website' => 'https://whiskycellar.example', 'address' => '16 Baron Taylors Street', 'postcode' => 'IV1 1PR' ],
			[ 'name' => 'Riverside Fish Bar',       'category' => 'Restaurants',         'area' => 'Riverside',   'plan' => 'basic',   'status' => 'active',  'tagline' => 'Fresh-landed Scottish seafood',   'desc' => 'A friendly riverside chippy serving sustainably sourced haddock, hand-cut chips and daily seafood specials.', 'phone' => '01463 100113', 'email' => 'hello@riversidefish.example', 'website' => 'https://riversidefish.example', 'address' => '41 Bank Street', 'postcode' => 'IV1 1QR' ],
			[ 'name' => 'The Bean & Bothy',         'category' => 'Cafes & Coffee',      'area' => 'Old Town',    'plan' => 'free',    'status' => 'active',  'tagline' => 'Cosy bothy-style coffee house',   'desc' => 'A snug, plant-filled coffee house in the old town pouring local roasts alongside generous home bakes.', 'phone' => '01463 100114', 'email' => 'hello@beanandbothy.example', 'website' => 'https://beanandbothy.example', 'address' => '2 Church Lane', 'postcode' => 'IV1 1DR' ],
			[ 'name' => 'Thistle & Tweed',          'category' => 'Boutiques & Fashion', 'area' => 'City Centre', 'plan' => 'premium', 'status' => 'active',  'tagline' => 'Heritage tweed & knitwear',       'desc' => 'Beautifully made Scottish tweed, tartan and lambswool knitwear from Highland mills and independent makers.', 'phone' => '01463 100115', 'email' => 'shop@thistleandtweed.example', 'website' => 'https://thistleandtweed.example', 'address' => '18 High Street', 'postcode' => 'IV1 1HZ' ],
			[ 'name' => 'Ness Islands Tours',       'category' => 'Family Activities',   'area' => 'West',        'plan' => 'basic',   'status' => 'active',  'tagline' => 'Guided walks & river trails',     'desc' => 'Relaxed guided walks through the Ness Islands and along the river, with stories of Inverness past and present.', 'phone' => '01463 100116', 'email' => 'book@nessislandstours.example', 'website' => 'https://nessislandstours.example', 'address' => 'Bught Road', 'postcode' => 'IV3 5SS' ],
			[ 'name' => 'Serenity Beauty Rooms',    'category' => 'Spas & Beauty',       'area' => 'North',       'plan' => 'premium', 'status' => 'active',  'tagline' => 'Facials, nails & holistic care',  'desc' => 'A calm, contemporary beauty studio offering facials, nails and holistic treatments with natural products.', 'phone' => '01463 100117', 'email' => 'hello@serenityrooms.example', 'website' => 'https://serenityrooms.example', 'address' => '12 Kenneth Street', 'postcode' => 'IV3 5DH' ],
			[ 'name' => 'Clansman Guest House',     'category' => 'Guest Houses',        'area' => 'East',        'plan' => 'free',    'status' => 'active',  'tagline' => 'Friendly bed & breakfast',        'desc' => 'A welcoming family-run B&B on the quiet east side, a short stroll from the city and the canal towpath.', 'phone' => '01463 100118', 'email' => 'stay@clansmanguesthouse.example', 'website' => 'https://clansmanguesthouse.example', 'address' => '33 Crown Avenue', 'postcode' => 'IV2 3NF' ],
			[ 'name' => 'Castle Bytes IT',          'category' => 'IT & Technology',     'area' => 'City Centre', 'plan' => 'basic',   'status' => 'active',  'tagline' => 'Repairs, web & cloud help',       'desc' => 'Walk-in device repairs plus web, email and cloud setup for Highland households and small businesses.', 'phone' => '01463 100119', 'email' => 'help@castlebytes.example', 'website' => 'https://castlebytes.example', 'address' => '7 Queensgate', 'postcode' => 'IV1 1DJ' ],
			[ 'name' => 'Old Distillery Gallery',   'category' => 'Galleries',           'area' => 'Old Town',    'plan' => 'elite',   'status' => 'active',  'tagline' => 'Contemporary Highland art',       'desc' => 'A striking gallery in a converted distillery showing painting, print and sculpture by Highland artists.', 'phone' => '01463 100120', 'email' => 'art@olddistillerygallery.example', 'website' => 'https://olddistillerygallery.example', 'address' => '5 Friars Lane', 'postcode' => 'IV1 1RB' ],
			[ 'name' => 'Drumossie Inn',            'category' => 'Bars & Pubs',         'area' => 'South',       'plan' => 'premium', 'status' => 'active',  'tagline' => 'Real ales & hearty plates',       'desc' => 'A characterful inn on the south side with rotating Highland real ales, hearty food and a roaring fire.', 'phone' => '01463 100121', 'email' => 'cheers@drumossieinn.example', 'website' => 'https://drumossieinn.example', 'address' => '88 Old Edinburgh Road', 'postcode' => 'IV2 3HG' ],
		];
	}
}
