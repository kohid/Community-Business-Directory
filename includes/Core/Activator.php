<?php
namespace CBD\Core;
defined( 'ABSPATH' ) || exit;

class Activator {
    public static function activate(): void {
        static::create_tables();
        static::add_roles();
        static::set_default_options();
        static::seed_categories();
        static::seed_plans();
        static::create_pages();
        static::create_website_business();

        // Register the rewrite-bearing CPTs + taxonomies BEFORE flushing. The
        // activation hook fires after `init` has already run for this request,
        // so the post types are NOT registered yet — flushing here without this
        // call regenerates the rules WITHOUT the /directory/<slug>/ routes, and
        // single-business pages 404 until something else re-flushes.
        static::register_rewrites();
        flush_rewrite_rules();

        update_option( 'cbd_db_version', CBD_DB_VERSION );
        update_option( 'cbd_pages_version', CBD_VERSION );

        // Force the on-load self-heal (Plugin::maybe_flush_rewrites) to run once
        // more on the next request. Without this, reinstalling the SAME version
        // leaves a stale `cbd_rewrite_version` behind (delete doesn't clear it),
        // so the self-heal is skipped and the 404 sticks.
        delete_option( 'cbd_rewrite_version' );
    }

    /**
     * Register every post type and taxonomy that owns a rewrite rule. Used at
     * activation so the flush captures their routes; the normal request path
     * registers these on `init` via Plugin.
     */
    private static function register_rewrites(): void {
        ( new \CBD\PostTypes\Business() )->register();
        ( new \CBD\PostTypes\BusinessPost() )->register();
        ( new \CBD\PostTypes\Event() )->register();
        ( new \CBD\PostTypes\Promotion() )->register();
        ( new \CBD\PostTypes\Review() )->register();
        ( new \CBD\Taxonomies\BusinessCategory() )->register();
        ( new \CBD\Taxonomies\BusinessLocation() )->register();
    }

    private static function create_tables(): void {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$p}cbd_businesses (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id         BIGINT UNSIGNED NOT NULL,
            owner_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
            plan            VARCHAR(20)  NOT NULL DEFAULT 'free',
            plan_expires_at DATETIME     DEFAULT NULL,
            phone           VARCHAR(50)  DEFAULT '',
            email           VARCHAR(200) DEFAULT '',
            website         VARCHAR(500) DEFAULT '',
            address_line1   VARCHAR(255) DEFAULT '',
            city            VARCHAR(100) DEFAULT '',
            state_province  VARCHAR(100) DEFAULT '',
            postal_code     VARCHAR(20)  DEFAULT '',
            country         VARCHAR(100) DEFAULT 'GB',
            latitude        DECIMAL(10,8) DEFAULT 0.00000000,
            longitude       DECIMAL(11,8) DEFAULT 0.00000000,
            rating_avg      DECIMAL(3,2) DEFAULT 0.00,
            review_count    INT          DEFAULT 0,
            follower_count  INT          DEFAULT 0,
            view_count      INT          DEFAULT 0,
            is_featured     TINYINT(1)   DEFAULT 0,
            is_verified     TINYINT(1)   DEFAULT 0,
            social_links    TEXT         DEFAULT '',
            opening_hours   TEXT         DEFAULT '',
            created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   uq_post (post_id),
            KEY idx_owner  (owner_id),
            KEY idx_status (status),
            KEY idx_city   (city),
            KEY idx_feat   (is_featured)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_events (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            owner_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            start_date  DATETIME NOT NULL,
            end_date    DATETIME NOT NULL,
            venue_name  VARCHAR(255) DEFAULT '',
            venue_addr  TEXT         DEFAULT '',
            latitude    DECIMAL(10,8) DEFAULT 0.00000000,
            longitude   DECIMAL(11,8) DEFAULT 0.00000000,
            ticket_url  VARCHAR(500) DEFAULT '',
            ticket_price DECIMAL(10,2) DEFAULT 0.00,
            is_free     TINYINT(1)   DEFAULT 1,
            capacity    INT          DEFAULT 0,
            status      VARCHAR(20)  DEFAULT 'draft',
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_post (post_id),
            KEY idx_business (business_id),
            KEY idx_owner    (owner_id),
            KEY idx_dates    (start_date, end_date)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_promotions (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id          BIGINT UNSIGNED NOT NULL,
            business_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            owner_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
            coupon_code      VARCHAR(100) DEFAULT '',
            discount_type    VARCHAR(20)  DEFAULT '',
            discount_value   DECIMAL(10,2) DEFAULT 0.00,
            start_date       DATE         DEFAULT NULL,
            expiry_date      DATE         DEFAULT NULL,
            cta_url          VARCHAR(500) DEFAULT '',
            cta_text         VARCHAR(100) DEFAULT 'Get Offer',
            is_featured      TINYINT(1)   DEFAULT 0,
            redemption_count INT          DEFAULT 0,
            status           VARCHAR(20)  DEFAULT 'active',
            created_at       DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_post (post_id),
            KEY idx_business (business_id),
            KEY idx_owner    (owner_id),
            KEY idx_expiry   (expiry_date),
            KEY idx_status   (status)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_reviews (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id   BIGINT UNSIGNED NOT NULL,
            author_id     BIGINT UNSIGNED DEFAULT 0,
            author_name   VARCHAR(200) DEFAULT '',
            author_email  VARCHAR(200) DEFAULT '',
            rating        TINYINT(1)   NOT NULL DEFAULT 5,
            title         VARCHAR(255) DEFAULT '',
            content       TEXT         DEFAULT '',
            response      TEXT         DEFAULT '',
            response_date DATETIME     DEFAULT NULL,
            is_verified   TINYINT(1)   DEFAULT 0,
            status        VARCHAR(20)  DEFAULT 'pending',
            helpful_count INT          DEFAULT 0,
            created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_business (business_id),
            KEY idx_author   (author_id),
            KEY idx_status   (status)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_follows (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_follow (user_id, business_id),
            KEY idx_user     (user_id),
            KEY idx_business (business_id)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_favorites (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     BIGINT UNSIGNED NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_fav (user_id, business_id),
            KEY idx_user     (user_id),
            KEY idx_business (business_id)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_analytics (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_id    BIGINT UNSIGNED NOT NULL,
            date           DATE NOT NULL,
            views          INT DEFAULT 0,
            clicks_website INT DEFAULT 0,
            clicks_phone   INT DEFAULT 0,
            clicks_map     INT DEFAULT 0,
            new_followers  INT DEFAULT 0,
            new_reviews    INT DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_day (business_id, date),
            KEY idx_business (business_id),
            KEY idx_date     (date)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_membership_plans (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug            VARCHAR(20)  NOT NULL,
            name            VARCHAR(100) NOT NULL DEFAULT '',
            description     TEXT         DEFAULT '',
            price_monthly   DECIMAL(10,2) DEFAULT 0.00,
            price_annual    DECIMAL(10,2) DEFAULT 0.00,
            image_limit     INT          DEFAULT 0,
            event_limit     INT          DEFAULT 0,
            promo_limit     INT          DEFAULT 0,
            is_featured     TINYINT(1)   DEFAULT 0,
            can_promote     TINYINT(1)   DEFAULT 0,
            show_analytics  TINYINT(1)   DEFAULT 0,
            stripe_price_id VARCHAR(100) DEFAULT '',
            features        TEXT         DEFAULT '',
            sort_order      INT          DEFAULT 0,
            is_active       TINYINT(1)   DEFAULT 1,
            created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_active (is_active),
            KEY idx_sort   (sort_order)
        ) $c;" );

        dbDelta( "CREATE TABLE {$p}cbd_reactions (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id    BIGINT UNSIGNED NOT NULL,
            user_id    BIGINT UNSIGNED NOT NULL,
            reaction   VARCHAR(20) NOT NULL DEFAULT 'like',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_react (post_id, user_id),
            KEY idx_post (post_id),
            KEY idx_user (user_id)
        ) $c;" );
    }

    /**
     * Run pending schema upgrades when the stored DB version is behind the
     * code. Lets new tables/columns appear on a normal page load without the
     * user having to deactivate + reactivate the plugin.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'cbd_db_version' ) === CBD_DB_VERSION ) {
            return;
        }
        static::create_tables();
        update_option( 'cbd_db_version', CBD_DB_VERSION );
    }

    private static function add_roles(): void {
        add_role( 'cbd_business_owner', __( 'Business Owner', 'community-business-directory' ), [
            'read' => true,
            'cbd_submit_business'   => true,
            'cbd_edit_own_business' => true,
            'cbd_create_event'      => true,
            'cbd_create_promotion'  => true,
            'cbd_view_analytics'    => true,
        ] );
        add_role( 'cbd_directory_admin', __( 'Directory Admin', 'community-business-directory' ), [
            'read'                       => true,
            'cbd_edit_others_businesses' => true,
            'cbd_approve_business'       => true,
            'cbd_moderate_reviews'       => true,
            'cbd_manage_payments'        => true,
        ] );
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( [ 'cbd_submit_business','cbd_edit_own_business','cbd_edit_others_businesses',
                        'cbd_approve_business','cbd_create_event','cbd_create_promotion',
                        'cbd_moderate_reviews','cbd_view_analytics','cbd_manage_payments','cbd_manage_settings' ] as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    private static function set_default_options(): void {
        $defaults = [
            'cbd_currency'                       => 'GBP',
            'cbd_currency_symbol'                => '£',
            'cbd_default_country'                => 'GB',
            'cbd_registration_requires_approval' => '1',
            'cbd_reviews_require_moderation'     => '1',
            'cbd_google_maps_api_key'            => '',
            'cbd_stripe_publishable_key'         => '',
            'cbd_stripe_secret_key'              => '',
            'cbd_admin_email'                    => get_option( 'admin_email' ),
            'cbd_items_per_page'                 => '20',
            'cbd_primary_color'                  => '#2b6344',
            'cbd_email_logo'                     => '',
            'cbd_email_from_name'                => get_bloginfo( 'name' ),
            'cbd_email_from_address'             => get_option( 'admin_email' ),
            'cbd_email_reply_to'                 => '',
        ];
        foreach ( $defaults as $k => $v ) add_option( $k, $v );
    }

    /**
     * Create any missing plugin pages. Idempotent — skips a page whose option
     * already points at a post — so it is safe to call on every (re)activation
     * and from the self-heal path in Plugin::maybe_create_pages().
     */
    public static function create_pages(): void {
        $pages = [
            'cbd_directory_page_id'   => [ 'Business Directory',  '[cbd_directory]',          'directory'          ],
            'cbd_register_page_id'    => [ 'List Your Business',  '[cbd_register_business]',  'list-your-business' ],
            'cbd_events_page_id'      => [ 'Events',              '[cbd_events]',             'events'             ],
            'cbd_promotions_page_id'  => [ 'Offers & Deals',      '[cbd_promotions]',         'offers'             ],
            'cbd_jobs_page_id'        => [ 'Jobs',                '[cbd_jobs]',               'jobs'               ],
            'cbd_plans_page_id'       => [ 'Membership Plans',    '[cbd_plans]',              'membership-plans'   ],
            'cbd_login_page_id'       => [ 'Sign In',             '[cbd_login]',              'sign-in'            ],
            'cbd_signup_page_id'      => [ 'Create Account',      '[cbd_signup]',            'create-account'     ],
            'cbd_setpw_page_id'       => [ 'Set Password',        '[cbd_set_password]',       'set-password'       ],
            'cbd_socmed_page_id'      => [ 'Social Feeds',        '[cbd_social_sync]',        'socmed'            ],
        ];
        foreach ( $pages as $option => [ $title, $content, $slug ] ) {
            if ( get_option( $option ) ) continue;
            $id = wp_insert_post( [ 'post_title' => $title, 'post_content' => $content,
                'post_name' => $slug, 'post_status' => 'publish', 'post_type' => 'page' ] );
            if ( $id && ! is_wp_error( $id ) ) update_option( $option, $id );
        }
    }

	/**
	 * Create the site's own "Website" business listing, owned by the site
	 * administrator (the admin-email account — the directory "super admin"), and
	 * grant that admin the Business Owner role so the account menu shows them the
	 * owner tools. Idempotent: guarded by the cbd_website_business_id option, so
	 * it is safe on every (re)activation.
	 */
	public static function create_website_business(): void {
		global $wpdb;

		$existing = (int) get_option( 'cbd_website_business_id' );
		if ( $existing && get_post( $existing ) ) {
			return;
		}

		// The administrator behind the site's admin email; fall back to the
		// earliest administrator account.
		$admin = get_user_by( 'email', (string) get_option( 'admin_email' ) );
		if ( ! $admin ) {
			$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
			$admin  = $admins[0] ?? null;
		}
		if ( ! $admin ) {
			return;
		}

		// The super admin owns the site's own listing — make them a Business
		// Owner too so the account menu surfaces "My Page" and the owner tools.
		$admin->add_role( 'cbd_business_owner' );

		$site_name = get_bloginfo( 'name' ) ?: __( 'Our Website', 'community-business-directory' );

		$post_id = wp_insert_post( [
			'post_type'    => 'cbd_business',
			'post_title'   => $site_name,
			'post_content' => sprintf(
				/* translators: %s: site name. */
				__( 'The official directory listing for %s.', 'community-business-directory' ),
				$site_name
			),
			'post_excerpt' => (string) get_bloginfo( 'description' ),
			'post_status'  => 'publish',
			'post_author'  => (int) $admin->ID,
		] );
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return;
		}

		$wpdb->insert( $wpdb->prefix . 'cbd_businesses', [
			'post_id'     => $post_id,
			'owner_id'    => (int) $admin->ID,
			'status'      => 'active',
			'plan'        => 'elite',
			'email'       => (string) get_option( 'admin_email' ),
			'website'     => home_url( '/' ),
			'country'     => (string) ( get_option( 'cbd_default_country' ) ?: 'GB' ),
			'is_verified' => 1,
			'created_at'  => current_time( 'mysql', true ),
		] );

		// Logo: reuse the site's uploaded Customizer logo / site icon if one
		// exists; otherwise generate a monogram placeholder so the listing never
		// shows an empty logo.
		self::set_business_logo( (int) $post_id, $site_name );

		update_option( 'cbd_website_business_id', (int) $post_id );
	}

	/**
	 * Set the Website business logo (featured image): the site's uploaded logo
	 * (Customizer custom_logo) or site icon when present, else a generated
	 * monogram placeholder.
	 */
	private static function set_business_logo( int $post_id, string $name ): void {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $logo_id ) {
			$logo_id = (int) get_option( 'site_icon' );
		}
		if ( $logo_id && wp_get_attachment_image_url( $logo_id, 'thumbnail' ) ) {
			set_post_thumbnail( $post_id, $logo_id );
			return;
		}
		$placeholder = self::create_placeholder_logo( $post_id, $name );
		if ( $placeholder ) {
			set_post_thumbnail( $post_id, $placeholder );
		}
	}

	/**
	 * Generate a flat monogram placeholder logo (GD) in the configured primary
	 * colour, attach it to the post and return its attachment ID (0 on failure).
	 * Unlike the demo generator this is real content — it is NOT demo-flagged.
	 */
	private static function create_placeholder_logo( int $post_id, string $name ): int {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagescale' ) ) {
			return 0;
		}
		$hex = ltrim( (string) get_option( 'cbd_primary_color', '#2b6344' ), '#' );
		if ( strlen( $hex ) !== 6 ) {
			$hex = '2b6344';
		}
		$r = (int) hexdec( substr( $hex, 0, 2 ) );
		$g = (int) hexdec( substr( $hex, 2, 2 ) );
		$b = (int) hexdec( substr( $hex, 4, 2 ) );

		$size = 512;
		$img  = imagecreatetruecolor( $size, $size );
		$bg   = imagecolorallocate( $img, $r, $g, $b );
		imagefilledrectangle( $img, 0, 0, $size, $size, $bg );

		$initials = self::logo_initials( $name );
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
		$filename = wp_unique_filename( $upload['path'], 'cbd-website-logo-' . sanitize_title( $name ) . '.png' );
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
		return (int) $aid;
	}

	/** First letters of up to two significant words of a name (logo monogram). */
	private static function logo_initials( string $label ): string {
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
		return $letters ?: 'WB';
	}

	public static function seed_categories_public(): void {
		// Reset the flag so categories are re-seeded.
		delete_option( 'cbd_categories_seeded' );
		static::seed_categories();
	}

	private static function seed_categories(): void {
		// Only seed once — skip if categories already exist.
		if ( get_option( 'cbd_categories_seeded' ) ) {
			return;
		}

		$categories = [
			[
				'name'     => 'Retail & Shops',
				'children' => [ 'Boutiques & Fashion', 'Books & Stationery', 'Gifts & Home', 'Jewellery & Accessories' ],
			],
			[
				'name'     => 'Food & Drink',
				'children' => [ 'Restaurants', 'Cafes & Coffee', 'Bakeries', 'Bars & Pubs', 'Takeaways' ],
			],
			[
				'name'     => 'Services & Professional',
				'children' => [ 'Financial & Legal', 'Health & Beauty', 'Marketing & Media', 'IT & Technology' ],
			],
			[
				'name'     => 'Arts & Culture',
				'children' => [ 'Galleries', 'Museums', 'Theatres', 'Heritage Sites' ],
			],
			[
				'name'     => 'Accommodation',
				'children' => [ 'Hotels', 'Guest Houses', 'Self-Catering', 'Hostels' ],
			],
			[
				'name'     => 'Events & Entertainment',
				'children' => [ 'Live Music Venues', 'Sports & Leisure', 'Family Activities' ],
			],
			[
				'name'     => 'Health & Wellness',
				'children' => [ 'Gyms & Fitness', 'Spas & Beauty', 'Medical & Dental' ],
			],
			[
				'name'     => 'Attractions',
				'children' => [ 'Tourist Attractions', 'Parks & Outdoors', 'Historic Sites' ],
			],
		];

		foreach ( $categories as $cat ) {
			$parent = get_term_by( 'name', $cat['name'], 'cbd_category' );
			if ( ! $parent ) {
				$result = wp_insert_term( $cat['name'], 'cbd_category' );
				$parent_id = is_wp_error( $result ) ? 0 : $result['term_id'];
			} else {
				$parent_id = $parent->term_id;
			}

			if ( $parent_id && ! empty( $cat['children'] ) ) {
				foreach ( $cat['children'] as $child ) {
					if ( ! get_term_by( 'name', $child, 'cbd_category' ) ) {
						wp_insert_term( $child, 'cbd_category', [ 'parent' => $parent_id ] );
					}
				}
			}
		}

		// Seed locations.
		$locations = [ 'City Centre', 'North', 'South', 'East', 'West', 'Riverside', 'Old Town' ];
		foreach ( $locations as $loc ) {
			if ( ! get_term_by( 'name', $loc, 'cbd_location' ) ) {
				wp_insert_term( $loc, 'cbd_location' );
			}
		}

		update_option( 'cbd_categories_seeded', true );
	}


	public static function seed_plans_public(): void {
		delete_option( 'cbd_plans_seeded' );
		static::seed_plans();
	}

	private static function seed_plans(): void {
		if ( get_option( 'cbd_plans_seeded' ) ) {
			return;
		}

		global $wpdb;
		$p = $wpdb->prefix;

		$plans = [
			[
				'slug'           => 'free',
				'name'           => 'Free',
				'description'    => 'Get listed in the directory with a basic profile.',
				'price_monthly'  => 0.00,
				'price_annual'   => 0.00,
				'image_limit'    => 3,
				'event_limit'    => 2,
				'promo_limit'    => 1,
				'is_featured'    => 0,
				'can_promote'    => 0,
				'show_analytics' => 0,
				'features'       => wp_json_encode( [
					'Basic business listing',
					'Up to 3 photos',
					'2 events per month',
					'1 promotion',
					'Customer reviews',
				] ),
				'sort_order'     => 1,
			],
			[
				'slug'           => 'basic',
				'name'           => 'Basic',
				'description'    => 'More visibility with extra images and promotions.',
				'price_monthly'  => 9.99,
				'price_annual'   => 89.99,
				'image_limit'    => 10,
				'event_limit'    => 10,
				'promo_limit'    => 5,
				'is_featured'    => 0,
				'can_promote'    => 1,
				'show_analytics' => 0,
				'features'       => wp_json_encode( [
					'Everything in Free',
					'Up to 10 photos',
					'10 events per month',
					'5 promotions',
					'Priority in search results',
					'Business posts & updates',
				] ),
				'sort_order'     => 2,
			],
			[
				'slug'           => 'premium',
				'name'           => 'Premium',
				'description'    => 'Featured listing with full analytics and promotion tools.',
				'price_monthly'  => 24.99,
				'price_annual'   => 224.99,
				'image_limit'    => 30,
				'event_limit'    => 0,
				'promo_limit'    => 0,
				'is_featured'    => 1,
				'can_promote'    => 1,
				'show_analytics' => 1,
				'features'       => wp_json_encode( [
					'Everything in Basic',
					'Up to 30 photos',
					'Unlimited events',
					'Unlimited promotions',
					'Featured listing badge',
					'Full analytics dashboard',
					'Homepage visibility',
				] ),
				'sort_order'     => 3,
			],
			[
				'slug'           => 'elite',
				'name'           => 'Elite',
				'description'    => 'Maximum exposure with homepage featuring and priority placement.',
				'price_monthly'  => 49.99,
				'price_annual'   => 449.99,
				'image_limit'    => 0,
				'event_limit'    => 0,
				'promo_limit'    => 0,
				'is_featured'    => 1,
				'can_promote'    => 1,
				'show_analytics' => 1,
				'features'       => wp_json_encode( [
					'Everything in Premium',
					'Unlimited photos',
					'Homepage featured placement',
					'Priority search ranking',
					'Verified business badge',
					'Dedicated support',
					'Advanced analytics',
				] ),
				'sort_order'     => 4,
			],
		];

		foreach ( $plans as $plan ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$p}cbd_membership_plans WHERE slug = %s",
				$plan['slug']
			) );
			if ( ! $exists ) {
				$wpdb->insert( $p . 'cbd_membership_plans', array_merge( $plan, [ 'is_active' => 1 ] ) );
			}
		}

		update_option( 'cbd_plans_seeded', true );
	}

}
