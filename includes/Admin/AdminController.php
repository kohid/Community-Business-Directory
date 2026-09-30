<?php
/**
 * Admin Controller — full admin panel for Community Business Directory.
 *
 * @package CBD\Admin
 */

namespace CBD\Admin;

defined( 'ABSPATH' ) || exit;

class AdminController {

	// ── Menu Registration ─────────────────────────────────────────

	public function add_menu_pages(): void {
		add_action( 'admin_notices', [ $this, 'show_setup_notices' ] );
		$this->handle_admin_actions();
		$this->handle_team_actions();

		add_menu_page(
			__( 'Community Directory', 'community-business-directory' ),
			__( 'Community Directory', 'community-business-directory' ),
			'manage_options',
			'cbd-dashboard',
			[ $this, 'page_dashboard' ],
			'dashicons-store',
			30
		);

		$subpages = [
			[ 'cbd-dashboard',   __( 'Dashboard',   'community-business-directory' ), [ $this, 'page_dashboard'   ] ],
			[ 'cbd-businesses',  __( 'Businesses',  'community-business-directory' ), [ $this, 'page_businesses'  ] ],
			[ 'cbd-categories',  __( 'Categories',  'community-business-directory' ), [ $this, 'page_categories'  ] ],
			[ 'cbd-events',      __( 'Events',      'community-business-directory' ), [ $this, 'page_events'      ] ],
			[ 'cbd-promotions',  __( 'Promotions',  'community-business-directory' ), [ $this, 'page_promotions'  ] ],
			[ 'cbd-loqiva',      __( 'Love Inverness', 'community-business-directory' ), [ $this, 'page_loqiva'    ] ],
			[ 'cbd-facebook',    __( 'Facebook',    'community-business-directory' ), [ $this, 'page_facebook'   ] ],
			[ 'cbd-instagram',   __( 'Instagram',   'community-business-directory' ), [ $this, 'page_instagram'  ] ],
			[ 'cbd-reviews',     __( 'Reviews',     'community-business-directory' ), [ $this, 'page_reviews'     ] ],
			[ 'cbd-team',        __( 'Team',        'community-business-directory' ), [ $this, 'page_team'        ] ],
			[ 'cbd-settings',    __( 'Settings',    'community-business-directory' ), [ $this, 'page_settings'    ] ],
			[ 'cbd-plans',       __( 'Plans',       'community-business-directory' ), [ $this, 'page_plans'       ] ],
			[ 'cbd-reports',     __( 'Reports',     'community-business-directory' ), [ $this, 'page_reports'     ] ],
			[ 'cbd-diagnostics', __( 'Diagnostics', 'community-business-directory' ), [ $this, 'page_diagnostics' ] ],
		];

		foreach ( $subpages as [ $slug, $title, $cb ] ) {
			add_submenu_page( 'cbd-dashboard', $title, $title, 'manage_options', $slug, $cb );
		}
	}

	public function enqueue_scripts( string $hook ): void {
		// Native Jobs CPT list screen (hook 'edit.php', not a cbd- prefixed page):
		// load the admin styles + the standalone demo-jobs controller.
		if ( 'edit.php' === $hook && ( $screen = get_current_screen() ) && 'cbd_job' === $screen->post_type ) {
			$css_path = CBD_DIR . 'assets/css/admin.css';
			wp_enqueue_style( 'cbd-admin', CBD_URL . 'assets/css/admin.css', [], file_exists( $css_path ) ? (string) filemtime( $css_path ) : CBD_VERSION );
			$js_path = CBD_DIR . 'assets/js/admin-jobs.js';
			wp_enqueue_script( 'cbd-admin-jobs', CBD_URL . 'assets/js/admin-jobs.js', [ 'jquery' ], file_exists( $js_path ) ? (string) filemtime( $js_path ) : CBD_VERSION, true );
			wp_localize_script( 'cbd-admin-jobs', 'cbdJobsDemo', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cbd_jobs_demo' ),
				'i18n'    => [
					'working'   => __( 'Working…', 'community-business-directory' ),
					'failed'    => __( 'Something went wrong. Please reload and try again.', 'community-business-directory' ),
					'confirmRm' => __( 'Remove all demo jobs? Real vacancies are never touched.', 'community-business-directory' ),
				],
			] );
			return;
		}

		if ( strpos( $hook, 'cbd-' ) === false ) {
			return;
		}
		$css_path = CBD_DIR . 'assets/css/admin.css';
		wp_enqueue_style( 'cbd-admin', CBD_URL . 'assets/css/admin.css', [], file_exists( $css_path ) ? (string) filemtime( $css_path ) : CBD_VERSION );

		if ( strpos( $hook, 'cbd-settings' ) !== false ) {
			// Settings → Email uses the media library for the logo picker.
			wp_enqueue_media();

			// Settings → Demo Mode toggle + live generate/teardown progress.
			$js_path = CBD_DIR . 'assets/js/admin.js';
			wp_enqueue_script( 'cbd-admin', CBD_URL . 'assets/js/admin.js', [ 'jquery' ], file_exists( $js_path ) ? (string) filemtime( $js_path ) : CBD_VERSION, true );
			wp_localize_script( 'cbd-admin', 'cbdAdmin', [
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'demoNonce' => wp_create_nonce( 'cbd_demo' ),
				'i18n'      => [
					'generating'  => __( 'Generating demo content…', 'community-business-directory' ),
					'removing'    => __( 'Removing demo content…', 'community-business-directory' ),
					'preparing'   => __( 'Preparing…', 'community-business-directory' ),
					'creating'    => __( 'Creating: %s', 'community-business-directory' ),
					'genDone'     => __( 'Demo content generated.', 'community-business-directory' ),
					'rmDone'      => __( 'Demo content removed.', 'community-business-directory' ),
					'failed'      => __( 'Something went wrong. Please reload and try again.', 'community-business-directory' ),
					'confirmOff'  => __( 'Turn Demo Mode off? This permanently deletes all generated demo content (your real listings are never touched).', 'community-business-directory' ),
				],
			] );
		}

		if ( strpos( $hook, 'cbd-loqiva' ) !== false ) {
			$js_path = CBD_DIR . 'assets/js/admin-loqiva.js';
			wp_enqueue_script( 'cbd-loqiva', CBD_URL . 'assets/js/admin-loqiva.js', [ 'jquery' ], file_exists( $js_path ) ? (string) filemtime( $js_path ) : CBD_VERSION, true );
			wp_localize_script( 'cbd-loqiva', 'cbdLoqiva', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cbd_loqiva' ),
				'i18n'    => [
					'syncing'   => __( 'Importing Love Inverness content…', 'community-business-directory' ),
					'removing'  => __( 'Removing imported content…', 'community-business-directory' ),
					'fetching'  => __( 'Fetching feeds…', 'community-business-directory' ),
					'importing' => __( 'Importing: %s', 'community-business-directory' ),
					'syncDone'  => __( 'Import complete.', 'community-business-directory' ),
					'rmDone'    => __( 'Imported content removed.', 'community-business-directory' ),
					'failed'    => __( 'Something went wrong. Please reload and try again.', 'community-business-directory' ),
					'confirmRm' => __( 'Remove all imported Love Inverness listings? Your own listings are never touched.', 'community-business-directory' ),
				],
			] );
		}

		if ( strpos( $hook, 'cbd-facebook' ) !== false ) {
			$js_path = CBD_DIR . 'assets/js/admin-facebook.js';
			wp_enqueue_script( 'cbd-facebook', CBD_URL . 'assets/js/admin-facebook.js', [ 'jquery' ], file_exists( $js_path ) ? (string) filemtime( $js_path ) : CBD_VERSION, true );
			wp_localize_script( 'cbd-facebook', 'cbdFacebook', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cbd_facebook' ),
				'i18n'    => [
					'refreshing' => __( 'Syncing posts from Facebook…', 'community-business-directory' ),
					'failed'     => __( 'Could not reach Facebook. Check the Page ID and Access Token.', 'community-business-directory' ),
				],
			] );
		}

		if ( strpos( $hook, 'cbd-instagram' ) !== false ) {
			$js_path = CBD_DIR . 'assets/js/admin-instagram.js';
			wp_enqueue_script( 'cbd-instagram', CBD_URL . 'assets/js/admin-instagram.js', [ 'jquery' ], file_exists( $js_path ) ? (string) filemtime( $js_path ) : CBD_VERSION, true );
			wp_localize_script( 'cbd-instagram', 'cbdInstagram', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cbd_instagram' ),
				'i18n'    => [
					'refreshing' => __( 'Syncing posts from Instagram…', 'community-business-directory' ),
					'detecting'  => __( 'Looking up the linked Instagram account…', 'community-business-directory' ),
					'failed'     => __( 'Could not reach Instagram. Check the account ID and access token.', 'community-business-directory' ),
				],
			] );
		}
	}

	// ── Admin Notices ─────────────────────────────────────────────

	public function show_setup_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'cbd' ) === false ) {
			return;
		}

		// Warn about missing categories.
		$cat_count = wp_count_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false ] );
		if ( ! is_wp_error( $cat_count ) && (int) $cat_count === 0 ) {
			$seed_url = wp_nonce_url(
				add_query_arg( 'cbd_action', 'seed_cats', admin_url( 'admin.php?page=cbd-categories' ) ),
				'cbd_seed_cats'
			);
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>Community Directory:</strong> No business categories found. ';
			echo '<a href="' . esc_url( $seed_url ) . '" class="button button-primary button-small">Seed Default Categories Now</a>';
			echo '</p></div>';
		}

		// Detect Elementor-managed CBD pages.
		$page_map = [
			(int) get_option( 'cbd_directory_page_id' )  => [ 'Business Directory', '[cbd_directory]'         ],
			(int) get_option( 'cbd_register_page_id' )   => [ 'List Your Business',  '[cbd_register_business]' ],
			(int) get_option( 'cbd_events_page_id' )     => [ 'Events',              '[cbd_events]'            ],
			(int) get_option( 'cbd_promotions_page_id' ) => [ 'Offers & Deals',      '[cbd_promotions]'        ],
		];
		$elementor_pages = [];
		foreach ( $page_map as $pid => $info ) {
			if ( $pid && get_post_meta( $pid, '_elementor_edit_mode', true ) === 'builder' ) {
				$elementor_pages[ $pid ] = $info;
			}
		}
		if ( $elementor_pages ) {
			echo '<div class="notice notice-info is-dismissible"><p>';
			echo '<strong>Community Directory — Elementor Detected:</strong> ';
			echo 'These pages are managed by Elementor. Open each in Elementor, add a <strong>Shortcode widget</strong>, and paste the shortcode:';
			echo '<ul style="margin:8px 0 0 20px;">';
			foreach ( $elementor_pages as $pid => [ $label, $sc ] ) {
				echo '<li><a href="' . esc_url( admin_url( 'post.php?post=' . $pid . '&action=elementor' ) ) . '" target="_blank"><strong>' . esc_html( $label ) . '</strong></a> → <code>' . esc_html( $sc ) . '</code></li>';
			}
			echo '</ul></p></div>';
		}

		// Pending businesses notice.
		global $wpdb;
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_businesses WHERE status='pending'" );
		if ( $pending > 0 ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>Community Directory:</strong> ';
			printf( '<a href="%s">%d business listing(s) pending approval</a>.', esc_url( admin_url( 'admin.php?page=cbd-businesses&filter=pending' ) ), $pending );
			echo '</p></div>';
		}
	}

	// ── Action Handlers ───────────────────────────────────────────

	private function handle_admin_actions(): void {
		$action = sanitize_key( $_GET['cbd_action'] ?? '' );
		if ( ! $action ) {
			return;
		}

		switch ( $action ) {
			case 'seed_cats':
				if ( check_admin_referer( 'cbd_seed_cats' ) ) {
					\CBD\Core\Activator::seed_categories_public();
					wp_redirect( add_query_arg( 'cbd_notice', 'cats_seeded', admin_url( 'admin.php?page=cbd-categories' ) ) );
					exit;
				}
				break;

			case 'delete_category':
				if ( check_admin_referer( 'cbd_delete_cat' ) && ! empty( $_GET['term_id'] ) ) {
					wp_delete_term( (int) $_GET['term_id'], 'cbd_category' );
					wp_redirect( add_query_arg( 'cbd_notice', 'cat_deleted', admin_url( 'admin.php?page=cbd-categories' ) ) );
					exit;
				}
				break;

			case 'delete_event':
				if ( check_admin_referer( 'cbd_delete_event' ) && ! empty( $_GET['post_id'] ) ) {
					wp_delete_post( (int) $_GET['post_id'], true );
					wp_redirect( add_query_arg( 'cbd_notice', 'event_deleted', admin_url( 'admin.php?page=cbd-events' ) ) );
					exit;
				}
				break;

			case 'toggle_featured':
				if ( check_admin_referer( 'cbd_toggle_featured' ) && ! empty( $_GET['post_id'] ) ) {
					$pid     = (int) $_GET['post_id'];
					global $wpdb;
					$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_featured FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d", $pid ) );
					$wpdb->update( $wpdb->prefix . 'cbd_businesses', [ 'is_featured' => $current ? 0 : 1 ], [ 'post_id' => $pid ] );
					// Also update post meta for query convenience
					update_post_meta( $pid, '_cbd_is_featured', $current ? '0' : '1' );
					wp_redirect( add_query_arg( 'cbd_notice', 'featured_toggled', admin_url( 'admin.php?page=cbd-businesses' ) ) );
					exit;
				}
				break;

			case 'change_plan':
				if ( check_admin_referer( 'cbd_change_plan' ) && ! empty( $_GET['post_id'] ) && ! empty( $_GET['plan'] ) ) {
					global $wpdb;
					$pid  = (int) $_GET['post_id'];
					$plan = sanitize_key( $_GET['plan'] );
					$allowed_plans = [ 'free', 'basic', 'premium', 'elite' ];
					if ( in_array( $plan, $allowed_plans, true ) ) {
						$wpdb->update( $wpdb->prefix . 'cbd_businesses', [ 'plan' => $plan ], [ 'post_id' => $pid ] );
					}
					wp_redirect( add_query_arg( 'cbd_notice', 'plan_changed', admin_url( 'admin.php?page=cbd-businesses' ) ) );
					exit;
				}
				break;

			case 'delete_promotion':
				if ( check_admin_referer( 'cbd_delete_promo' ) && ! empty( $_GET['post_id'] ) ) {
					wp_delete_post( (int) $_GET['post_id'], true );
					wp_redirect( add_query_arg( 'cbd_notice', 'promo_deleted', admin_url( 'admin.php?page=cbd-promotions' ) ) );
					exit;
				}
				break;
		}
	}

	/**
	 * Process Community Directory → Team form posts + action links. Runs from
	 * add_menu_pages() (on `admin_menu`, before any output) so it can redirect.
	 */
	private function handle_team_actions(): void {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( '\CBD\Admin\TeamInvite' ) ) {
			return;
		}

		// Send an invitation (POST).
		if ( isset( $_POST['cbd_team_email'] ) && check_admin_referer( 'cbd_team_invite' ) ) {
			$res = \CBD\Admin\TeamInvite::invite(
				(string) wp_unslash( $_POST['cbd_team_email'] ),
				'administrator',
				get_current_user_id()
			);
			if ( is_wp_error( $res ) ) {
				set_transient( 'cbd_team_error_' . get_current_user_id(), $res->get_error_message(), MINUTE_IN_SECONDS );
			}
			wp_safe_redirect( add_query_arg( 'cbd_notice', is_wp_error( $res ) ? 'team_error' : 'team_invited', admin_url( 'admin.php?page=cbd-team' ) ) );
			exit;
		}

		// Cancel a pending invite / revoke a member (nonce-signed GET links).
		$act = sanitize_key( $_GET['cbd_team_action'] ?? '' );
		if ( 'cancel' === $act && check_admin_referer( 'cbd_team_cancel' ) ) {
			\CBD\Admin\TeamInvite::cancel( (string) wp_unslash( $_GET['email'] ?? '' ) );
			wp_safe_redirect( add_query_arg( 'cbd_notice', 'team_cancelled', admin_url( 'admin.php?page=cbd-team' ) ) );
			exit;
		}
		if ( 'revoke' === $act && check_admin_referer( 'cbd_team_revoke' ) ) {
			\CBD\Admin\TeamInvite::revoke( (int) ( $_GET['user'] ?? 0 ) );
			wp_safe_redirect( add_query_arg( 'cbd_notice', 'team_revoked', admin_url( 'admin.php?page=cbd-team' ) ) );
			exit;
		}
	}

	private function show_notice(): void {
		$notice = sanitize_key( $_GET['cbd_notice'] ?? '' );
		$messages = [
			'cats_seeded'   => [ 'success', 'Default categories seeded successfully!' ],
			'cat_added'     => [ 'success', 'Category added.' ],
			'cat_deleted'   => [ 'success', 'Category deleted.' ],
			'event_created' => [ 'success', 'Event created successfully.' ],
			'event_deleted' => [ 'success', 'Event deleted.' ],
			'promo_created' => [ 'success', 'Promotion created successfully.' ],
			'promo_deleted' => [ 'success', 'Promotion deleted.' ],
			'settings_saved'=> [ 'success', 'Settings saved.' ],
			'team_invited'  => [ 'success', 'Invitation sent.' ],
			'team_cancelled'=> [ 'success', 'Invitation cancelled.' ],
			'team_revoked'  => [ 'success', 'Team member access revoked.' ],
		];
		if ( 'team_error' === $notice ) {
			$msg = (string) get_transient( 'cbd_team_error_' . get_current_user_id() );
			delete_transient( 'cbd_team_error_' . get_current_user_id() );
			echo '<div class="notice notice-error is-dismissible"><p><strong>Community Directory:</strong> ' . esc_html( $msg ?: 'Could not send the invitation.' ) . '</p></div>';
		}
		if ( $notice && isset( $messages[ $notice ] ) ) {
			[ $type, $msg ] = $messages[ $notice ];
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p><strong>Community Directory:</strong> ' . esc_html( $msg ) . '</p></div>';
		}
	}

	// ── PAGE: Dashboard ───────────────────────────────────────────

	public function page_dashboard(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$stats = [
			[ 'Total Listings',   (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_businesses" ),                          '#2b6344' ],
			[ 'Pending Approval', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_businesses WHERE status='pending'" ),    '#c47b1a' ],
			[ 'Active Listings',  (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_businesses WHERE status='active'" ),     '#1e6b7a' ],
			[ 'Events',           (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_events" ),                               '#6b2ba8' ],
			[ 'Active Promos',    (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_promotions WHERE status='active'" ),     '#2b6387' ],
			[ 'Pending Reviews',  (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_reviews WHERE status='pending'" ),       '#b84a35' ],
		];

		echo '<div class="wrap cbd-admin">';
		echo '<h1>Community Business Directory</h1>';
		echo '<div class="cbd-admin-stats">';
		foreach ( $stats as [ $label, $val, $color ] ) {
			echo '<div class="cbd-admin-stat" style="border-top-color:' . esc_attr( $color ) . ';">';
			echo '<span class="cbd-stat-num" style="color:' . esc_attr( $color ) . ';">' . esc_html( number_format( $val ) ) . '</span>';
			echo '<span class="cbd-stat-label">' . esc_html( $label ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		// Quick links panel
		echo '<div class="cbd-admin-info" style="margin-top:24px;">';
		echo '<h2>Quick Actions</h2>';
		echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px;">';
		$actions = [
			[ admin_url( 'admin.php?page=cbd-businesses' ), '🏢', 'Manage Businesses' ],
			[ admin_url( 'admin.php?page=cbd-categories' ), '🗂️', 'Manage Categories' ],
			[ admin_url( 'admin.php?page=cbd-events&mode=create' ), '📅', 'Create Event' ],
			[ admin_url( 'admin.php?page=cbd-promotions&mode=create' ), '🏷️', 'Create Promotion' ],
			[ admin_url( 'admin.php?page=cbd-reviews' ), '⭐', 'Moderate Reviews' ],
			[ admin_url( 'admin.php?page=cbd-settings' ), '⚙️', 'Settings' ],
			[ admin_url( 'admin.php?page=cbd-diagnostics' ), '🔍', 'Diagnostics' ],
			[ admin_url( 'admin.php?page=cbd-settings&tab=pages' ), '📄', 'Plugin Pages' ],
		];
		foreach ( $actions as [ $url, $icon, $label ] ) {
			echo '<a href="' . esc_url( $url ) . '" style="display:flex;align-items:center;gap:8px;padding:14px;background:#fff;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#1a1a18;font-weight:600;font-size:13px;transition:border-color .2s;" onmouseover="this.style.borderColor=\'#2b6344\'" onmouseout="this.style.borderColor=\'#ddd\'">';
			echo '<span style="font-size:20px;">' . $icon . '</span>' . esc_html( $label );
			echo '</a>';
		}
		echo '</div></div>';

		// Shortcode reference — collapsible dropdown panel.
		echo '<div class="cbd-admin-info" style="margin-top:20px;">';
		echo '<details class="cbd-shortcode-ref">';
		echo '<summary class="cbd-shortcode-ref-toggle"><span class="cbd-shortcode-ref-title">Shortcode Reference</span><span class="cbd-shortcode-ref-hint">' . esc_html__( 'Click to expand', 'community-business-directory' ) . '</span></summary>';
		echo '<table class="widefat" style="margin-top:12px;"><thead><tr><th>Shortcode</th><th>Description</th><th>Page</th></tr></thead><tbody>';
		$sc_list = [
			[ '[cbd_directory]',           'Full searchable business directory',          get_option( 'cbd_directory_page_id' ) ],
			[ '[cbd_register_business]',   'Business registration form',                  get_option( 'cbd_register_page_id' ) ],
			[ '[cbd_events]',              'Events listing + create form',                get_option( 'cbd_events_page_id' ) ],
			[ '[cbd_promotions]',          'Promotions + create form',                    get_option( 'cbd_promotions_page_id' ) ],
			[ '[cbd_jobs]',                'Job listings + search / type / location filters', get_option( 'cbd_jobs_page_id' ), '[cbd_jobs type="full-time" count="12"]' ],
			[ '[cbd_job_profile]',         'Single job detail (for an Elementor Theme Builder single template)', null ],
			[ '[cbd_featured_businesses]', 'Featured businesses grid',                    null ],
			[ '[cbd_business_map]',        'Interactive Google Map',                      null ],
			[ '[cbd_reviews]',             'Reviews for a business', null, '[cbd_reviews business_id="123"]' ],
			[ '[cbd_search]',              'Standalone search bar',                       null ],
			[ '[cbd_activity_feed]',       'Community activity stream',                   null ],
			[ '[cbd_calendar]',            'Events calendar (month / list / day views)',  null ],
			[ '[cbd_business_feed]',       'Business posts/events/promotions feed',       null ],
			[ '[cbd_gallery]',             'Site-wide photo gallery wall (bento / mosaic)', null, '[cbd_gallery style="mosaic" speed="14" height="80vh"]' ],
			[ '[cbd_plans]',               'Membership plans / pricing table',            get_option( 'cbd_plans_page_id' ) ],
			[ '[cbd_login]',               'Login form + social sign-in',                 get_option( 'cbd_login_page_id' ) ],
			[ '[cbd_signup]',              'Create account / sign-up form',               get_option( 'cbd_signup_page_id' ) ],
			[ '[cbd_set_password]',        'Set / reset password form',                   get_option( 'cbd_setpw_page_id' ) ],
			[ '[cbd_account_menu]',        'Header avatar + profile dropdown menu',       null ],
			[ '[cbd_facebook_page]',       'Facebook Page — Graph feed, Page Plugin embed, or a third-party widget (mode="code")', null, '[cbd_facebook_page mode="code"]' ],
			[ '[cbd_instagram_feed]',      'Instagram — Graph feed, third-party widget (mode="code"), or follow card', null, '[cbd_instagram_feed mode="code"]' ],
			[ '[cbd_social_sync]',         'Front-end self-service page (/socmed) for team members to connect Facebook & Instagram', get_option( 'cbd_socmed_page_id' ) ],
		];
		foreach ( $sc_list as $row ) {
			$sc   = $row[0];
			$desc = $row[1];
			$pid  = $row[2];
			$ex   = $row[3] ?? '';
			$page_link = $pid ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '" target="_blank">' . esc_html( get_the_title( $pid ) ) . '</a>' : '<em style="color:#999;">—</em>';
			$sc_cell = '<code>' . esc_html( $sc ) . '</code>';
			if ( '' !== $ex ) {
				// Copyable usage example for shortcodes with notable attributes.
				$sc_cell .= '<br><code class="cbd-sc-example" style="display:inline-block;margin-top:6px;padding:2px 7px;font-size:11px;color:#2271b1;background:#f0f6fc;border-radius:4px;">' . esc_html( $ex ) . '</code>';
			}
			echo '<tr><td>' . $sc_cell . '</td><td>' . esc_html( $desc ) . '</td><td>' . $page_link . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</details>';
		echo '</div></div>';
	}

	// ── PAGE: Businesses ──────────────────────────────────────────

	public function page_businesses(): void {
		global $wpdb;
		$this->show_notice();

		// Handle approve/reject.
		if ( isset( $_GET['cbd_biz_action'], $_GET['post_id'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cbd_biz_action' ) ) {
			$pid    = (int) $_GET['post_id'];
			$status = $_GET['cbd_biz_action'] === 'approve' ? 'active' : 'rejected';
			$wpdb->update( $wpdb->prefix . 'cbd_businesses', [ 'status' => $status ], [ 'post_id' => $pid ] );
			// Publish on approval (makes it public); unpublish on rejection.
			wp_update_post( [ 'ID' => $pid, 'post_status' => $status === 'active' ? 'publish' : 'draft' ] );
			if ( $status === 'active' ) {
				\cbd_promote_business_owner( $pid );
			}
			// Fire notification hooks
			do_action( $status === 'active' ? 'cbd_business_approved' : 'cbd_business_rejected', $pid );
		}

		$filter = sanitize_key( $_GET['filter'] ?? 'all' );
		$where  = $filter !== 'all' ? $wpdb->prepare( "WHERE b.status = %s", $filter ) : '';

		$rows = $wpdb->get_results(
			"SELECT b.*, p.post_title FROM {$wpdb->prefix}cbd_businesses b
			 LEFT JOIN {$wpdb->posts} p ON p.ID = b.post_id
			 $where
			 ORDER BY b.created_at DESC LIMIT 200"
		);

		echo '<div class="wrap cbd-admin">';
		echo '<h1>Businesses <a href="' . esc_url( admin_url( 'admin.php?page=cbd-businesses&filter=pending' ) ) . '" class="page-title-action">Pending</a></h1>';

		// Filter tabs
		$filters = [ 'all' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'rejected' => 'Rejected' ];
		echo '<ul class="subsubsub" style="margin-bottom:16px;">';
		foreach ( $filters as $slug => $label ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_businesses" . ( $slug !== 'all' ? " WHERE status=%s" : '' ), $slug !== 'all' ? $slug : null ) );
			echo '<li><a href="' . esc_url( add_query_arg( 'filter', $slug, admin_url( 'admin.php?page=cbd-businesses' ) ) ) . '"'
				. ( $filter === $slug ? ' class="current" style="font-weight:700;"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . $count . ')</span></a> |</li>';
		}
		echo '</ul>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>'
			. '<th>Business</th><th>Owner</th><th>City</th><th>Category</th>'
			. '<th>Status</th><th>Plan</th><th>Rating</th><th>Actions</th>'
			. '</tr></thead><tbody>';

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$status_colors = [ 'active' => '#2b6344', 'pending' => '#c47b1a', 'rejected' => '#b84a35' ];
				$color  = $status_colors[ $row->status ] ?? '#888';
				$owner  = get_userdata( (int) $row->owner_id );
				$cats   = get_the_terms( $row->post_id, 'cbd_category' );
				$cat_name = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '—';

				echo '<tr>';
				echo '<td><strong><a href="' . esc_url( get_edit_post_link( $row->post_id ) ) . '">' . esc_html( $row->post_title ) . '</a></strong>'
					. '<br><small><a href="' . esc_url( get_permalink( $row->post_id ) ) . '" target="_blank">View →</a></small></td>';
				echo '<td>' . esc_html( $owner ? $owner->display_name : '—' ) . '</td>';
				echo '<td>' . esc_html( $row->city ) . '</td>';
				echo '<td>' . esc_html( $cat_name ) . '</td>';
				echo '<td><span style="background:' . esc_attr( $color ) . ';color:#fff;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">' . esc_html( $row->status ) . '</span></td>';
				echo '<td>' . esc_html( ucfirst( $row->plan ) ) . '</td>';
				echo '<td>' . ( $row->rating_avg > 0 ? esc_html( number_format( (float) $row->rating_avg, 1 ) ) . ' ★ (' . (int) $row->review_count . ')' : '—' ) . '</td>';
				echo '<td style="white-space:nowrap;">';

				if ( $row->status === 'pending' ) {
					$approve_url = wp_nonce_url( add_query_arg( [ 'cbd_biz_action' => 'approve', 'post_id' => $row->post_id ], admin_url( 'admin.php?page=cbd-businesses' ) ), 'cbd_biz_action' );
					$reject_url  = wp_nonce_url( add_query_arg( [ 'cbd_biz_action' => 'reject',  'post_id' => $row->post_id ], admin_url( 'admin.php?page=cbd-businesses' ) ), 'cbd_biz_action' );
					echo '<a href="' . esc_url( $approve_url ) . '" class="button button-primary button-small">✓ Approve</a> ';
					echo '<a href="' . esc_url( $reject_url  ) . '" class="button button-small">✗ Reject</a> ';
				}

				// Quick create links for this business
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=cbd-events&mode=create&business_id=' . $row->post_id ) ) . '" class="button button-small" title="Add Event for this business">+ Event</a> ';
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=cbd-promotions&mode=create&business_id=' . $row->post_id ) ) . '" class="button button-small" title="Add Promotion for this business">+ Promo</a>';
				echo '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="8" style="text-align:center;padding:30px;color:#888;">';
			echo $filter === 'pending' ? 'No businesses pending approval.' : 'No businesses found.';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── PAGE: Categories ──────────────────────────────────────────

	public function page_categories(): void {
		$this->show_notice();

		// Handle add category form.
		if ( isset( $_POST['cbd_add_cat'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_add_cat' ) ) {
			$name      = sanitize_text_field( $_POST['cat_name'] ?? '' );
			$parent_id = (int) ( $_POST['cat_parent'] ?? 0 );
			$desc      = sanitize_textarea_field( $_POST['cat_desc'] ?? '' );
			if ( $name ) {
				$result = wp_insert_term( $name, 'cbd_category', [ 'parent' => $parent_id, 'description' => $desc ] );
				if ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success"><p><strong>Community Directory:</strong> Category "' . esc_html( $name ) . '" added.</p></div>';
				}
			}
		}

		// Handle add location form.
		if ( isset( $_POST['cbd_add_loc'], $_POST['_wpnonce_loc'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_loc'] ) ), 'cbd_add_loc' ) ) {
			$name = sanitize_text_field( $_POST['loc_name'] ?? '' );
			if ( $name ) {
				$result = wp_insert_term( $name, 'cbd_location' );
				if ( ! is_wp_error( $result ) ) {
					echo '<div class="notice notice-success"><p><strong>Community Directory:</strong> Location "' . esc_html( $name ) . '" added.</p></div>';
				}
			}
		}

		$categories = get_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false, 'parent' => 0 ] );
		$locations  = get_terms( [ 'taxonomy' => 'cbd_location',  'hide_empty' => false ] );
		$all_cats   = get_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false ] );

		$seed_url = wp_nonce_url( add_query_arg( 'cbd_action', 'seed_cats', admin_url( 'admin.php?page=cbd-categories' ) ), 'cbd_seed_cats' );

		echo '<div class="wrap cbd-admin">';
		echo '<h1>Categories & Locations <a href="' . esc_url( $seed_url ) . '" class="page-title-action">Reseed Defaults</a></h1>';

		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">';

		// ── LEFT: Business Categories ──
		echo '<div>';
		echo '<h2>Business Categories</h2>';

		// Add form
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">';
		echo '<h3 style="margin-top:0;">Add New Category</h3>';
		echo '<form method="post">' . wp_nonce_field( 'cbd_add_cat', '_wpnonce', true, false );
		echo '<table class="form-table" style="margin:0;">';
		echo '<tr><th style="padding:8px 10px 8px 0;width:120px;">Name <span style="color:red">*</span></th><td><input type="text" name="cat_name" class="regular-text" required placeholder="e.g. Food &amp; Drink"></td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">Parent</th><td><select name="cat_parent" class="regular-text"><option value="0">— Top Level —</option>';
		if ( ! is_wp_error( $all_cats ) ) {
			foreach ( $all_cats as $cat ) {
				echo '<option value="' . esc_attr( $cat->term_id ) . '"' . ( $cat->parent ? ' style="padding-left:20px;"' : '' ) . '>';
				echo esc_html( ( $cat->parent ? '&nbsp;&nbsp;&nbsp;' : '' ) . $cat->name );
				echo '</option>';
			}
		}
		echo '</select></td></tr>';
		echo '<tr><th style="padding:8px 10px 8px 0;">Description</th><td><textarea name="cat_desc" rows="2" class="large-text"></textarea></td></tr>';
		echo '</table>';
		echo '<p style="margin-top:12px;"><button type="submit" name="cbd_add_cat" class="button button-primary">Add Category</button></p>';
		echo '</form></div>';

		// Category list
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Category</th><th>Slug</th><th>Count</th><th>Actions</th></tr></thead><tbody>';
		if ( ! is_wp_error( $categories ) && $categories ) {
			foreach ( $categories as $cat ) {
				$del_url = wp_nonce_url( add_query_arg( [ 'cbd_action' => 'delete_category', 'term_id' => $cat->term_id ], admin_url( 'admin.php?page=cbd-categories' ) ), 'cbd_delete_cat' );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $cat->name ) . '</strong></td>';
				echo '<td><code>' . esc_html( $cat->slug ) . '</code></td>';
				echo '<td>' . (int) $cat->count . '</td>';
				echo '<td>';
				echo '<a href="' . esc_url( admin_url( 'edit-tags.php?action=edit&taxonomy=cbd_category&tag_ID=' . $cat->term_id ) ) . '">Edit</a> | ';
				echo '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this category?\');" style="color:#b84a35;">Delete</a>';
				echo '</td></tr>';

				// Show children
				$children = get_terms( [ 'taxonomy' => 'cbd_category', 'parent' => $cat->term_id, 'hide_empty' => false ] );
				if ( ! is_wp_error( $children ) ) {
					foreach ( $children as $child ) {
						$cdel = wp_nonce_url( add_query_arg( [ 'cbd_action' => 'delete_category', 'term_id' => $child->term_id ], admin_url( 'admin.php?page=cbd-categories' ) ), 'cbd_delete_cat' );
						echo '<tr style="background:#fafafa;">';
						echo '<td style="padding-left:30px;">↳ ' . esc_html( $child->name ) . '</td>';
						echo '<td><code>' . esc_html( $child->slug ) . '</code></td>';
						echo '<td>' . (int) $child->count . '</td>';
						echo '<td><a href="' . esc_url( admin_url( 'edit-tags.php?action=edit&taxonomy=cbd_category&tag_ID=' . $child->term_id ) ) . '">Edit</a> | ';
						echo '<a href="' . esc_url( $cdel ) . '" onclick="return confirm(\'Delete?\');" style="color:#b84a35;">Delete</a></td></tr>';
					}
				}
			}
		} else {
			echo '<tr><td colspan="4" style="text-align:center;padding:20px;color:#888;">No categories yet. <a href="' . esc_url( $seed_url ) . '">Seed defaults</a> or add one above.</td></tr>';
		}
		echo '</tbody></table></div>';

		// ── RIGHT: Locations ──
		echo '<div>';
		echo '<h2>Locations</h2>';
		echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px;">';
		echo '<h3 style="margin-top:0;">Add New Location</h3>';
		echo '<form method="post">' . wp_nonce_field( 'cbd_add_loc', '_wpnonce_loc', true, false );
		echo '<table class="form-table" style="margin:0;"><tr><th style="padding:8px 10px 8px 0;width:120px;">Location Name <span style="color:red">*</span></th>';
		echo '<td><input type="text" name="loc_name" class="regular-text" required placeholder="e.g. City Centre"></td></tr></table>';
		echo '<p style="margin-top:12px;"><button type="submit" name="cbd_add_loc" class="button button-primary">Add Location</button></p>';
		echo '</form></div>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Location</th><th>Slug</th><th>Count</th><th>Actions</th></tr></thead><tbody>';
		if ( ! is_wp_error( $locations ) && $locations ) {
			foreach ( $locations as $loc ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $loc->name ) . '</strong></td>';
				echo '<td><code>' . esc_html( $loc->slug ) . '</code></td>';
				echo '<td>' . (int) $loc->count . '</td>';
				echo '<td><a href="' . esc_url( admin_url( 'edit-tags.php?action=edit&taxonomy=cbd_location&tag_ID=' . $loc->term_id ) ) . '">Edit</a></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="4" style="text-align:center;padding:20px;color:#888;">No locations yet.</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '</div></div>';
	}

	// ── PAGE: Events ──────────────────────────────────────────────

	public function page_events(): void {
		global $wpdb;
		$this->show_notice();

		$mode        = sanitize_key( $_GET['mode'] ?? 'list' );
		$business_id = (int) ( $_GET['business_id'] ?? 0 );

		// Handle create form submission.
		if ( isset( $_POST['cbd_create_event'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_admin_event' ) ) {
			$this->handle_create_event();
			return;
		}

		echo '<div class="wrap cbd-admin">';

		if ( $mode === 'create' ) {
			$this->render_create_event_form( $business_id );
		} else {
			$this->render_events_list();
		}

		echo '</div>';
	}

	private function render_create_event_form( int $business_id = 0 ): void {
		global $wpdb;
		$businesses = $wpdb->get_results(
			"SELECT b.post_id, p.post_title FROM {$wpdb->prefix}cbd_businesses b
			 INNER JOIN {$wpdb->posts} p ON p.ID = b.post_id
			 WHERE b.status = 'active' ORDER BY p.post_title ASC"
		);

		echo '<h1>Create New Event</h1>';
		echo '<form method="post" style="max-width:700px;">';
		echo wp_nonce_field( 'cbd_admin_event', '_wpnonce', true, false );
		echo '<input type="hidden" name="cbd_create_event" value="1">';

		echo '<table class="form-table">';
		echo '<tr><th>Event Title <span style="color:red">*</span></th><td><input type="text" name="event_title" class="large-text" required></td></tr>';
		echo '<tr><th>Description</th><td><textarea name="event_description" rows="5" class="large-text"></textarea></td></tr>';

		echo '<tr><th>Business <span style="color:red">*</span></th><td><select name="event_business_id" class="regular-text" required>';
		echo '<option value="">— Select Business —</option>';
		foreach ( $businesses as $biz ) {
			echo '<option value="' . esc_attr( $biz->post_id ) . '"' . selected( $business_id, $biz->post_id, false ) . '>' . esc_html( $biz->post_title ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>Start Date &amp; Time <span style="color:red">*</span></th><td><input type="datetime-local" name="event_start" required></td></tr>';
		echo '<tr><th>End Date &amp; Time <span style="color:red">*</span></th><td><input type="datetime-local" name="event_end" required></td></tr>';
		echo '<tr><th>Venue Name</th><td><input type="text" name="event_venue" class="regular-text" placeholder="e.g. City Hall"></td></tr>';
		echo '<tr><th>Venue Address</th><td><input type="text" name="event_venue_addr" class="regular-text" placeholder="Full address"></td></tr>';
		echo '<tr><th>Ticket URL</th><td><input type="url" name="event_ticket_url" class="regular-text"></td></tr>';
		echo '<tr><th>Ticket Price</th><td><input type="number" name="event_ticket_price" min="0" step="0.01" value="0" style="width:100px;"> ';
		echo '<label><input type="checkbox" name="event_is_free" value="1" checked> Free event</label></td></tr>';
		echo '<tr><th>Capacity</th><td><input type="number" name="event_capacity" min="0" value="0" style="width:100px;"> <small>(0 = unlimited)</small></td></tr>';
		echo '</table>';

		echo '<p><button type="submit" class="button button-primary button-large">Create Event</button> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cbd-events' ) ) . '" class="button">Cancel</a></p>';
		echo '</form>';
	}

	private function handle_create_event(): void {
		global $wpdb;
		$title      = sanitize_text_field( $_POST['event_title'] ?? '' );
		$desc       = wp_kses_post( $_POST['event_description'] ?? '' );
		$biz_id     = (int) ( $_POST['event_business_id'] ?? 0 );
		$start      = sanitize_text_field( $_POST['event_start'] ?? '' );
		$end        = sanitize_text_field( $_POST['event_end']   ?? '' );

		if ( ! $title || ! $biz_id || ! $start || ! $end ) {
			echo '<div class="notice notice-error"><p>Title, business, start and end date are required.</p></div>';
			echo '<div class="wrap cbd-admin">';
			$this->render_create_event_form( $biz_id );
			echo '</div>';
			return;
		}

		$biz_owner = get_post_field( 'post_author', $biz_id );
		$post_id   = wp_insert_post( [
			'post_type'    => 'cbd_event',
			'post_title'   => $title,
			'post_content' => $desc,
			'post_status'  => 'publish',
			'post_author'  => $biz_owner ?: get_current_user_id(),
		] );

		if ( ! is_wp_error( $post_id ) ) {
			$wpdb->insert( $wpdb->prefix . 'cbd_events', [
				'post_id'      => $post_id,
				'business_id'  => $biz_id,
				'owner_id'     => $biz_owner ?: get_current_user_id(),
				'start_date'   => gmdate( 'Y-m-d H:i:s', strtotime( $start ) ),
				'end_date'     => gmdate( 'Y-m-d H:i:s', strtotime( $end ) ),
				'venue_name'   => sanitize_text_field( $_POST['event_venue']       ?? '' ),
				'venue_addr'   => sanitize_text_field( $_POST['event_venue_addr']  ?? '' ),
				'ticket_url'   => esc_url_raw( $_POST['event_ticket_url']          ?? '' ),
				'ticket_price' => (float) ( $_POST['event_ticket_price']           ?? 0 ),
				'is_free'      => ! empty( $_POST['event_is_free'] ) ? 1 : 0,
				'capacity'     => (int) ( $_POST['event_capacity']                 ?? 0 ),
				'status'       => 'published',
			] );
			wp_redirect( add_query_arg( 'cbd_notice', 'event_created', admin_url( 'admin.php?page=cbd-events' ) ) );
			exit;
		}
		echo '<div class="notice notice-error"><p>Failed to create event: ' . esc_html( $post_id->get_error_message() ) . '</p></div>';
		echo '<div class="wrap cbd-admin">';
		$this->render_create_event_form( $biz_id );
		echo '</div>';
	}

	private function render_events_list(): void {
		global $wpdb;
		echo '<h1>Events <a href="' . esc_url( admin_url( 'admin.php?page=cbd-events&mode=create' ) ) . '" class="page-title-action">+ Create Event</a></h1>';

		$events = $wpdb->get_results(
			"SELECT e.*, p.post_title, p.post_author, biz.post_title AS biz_name
			 FROM {$wpdb->prefix}cbd_events e
			 INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
			 LEFT JOIN {$wpdb->posts} biz ON biz.ID = e.business_id
			 ORDER BY e.start_date DESC LIMIT 100"
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Event</th><th>Business</th><th>Start Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		if ( $events ) {
			foreach ( $events as $ev ) {
				$del_url = wp_nonce_url( add_query_arg( [ 'cbd_action' => 'delete_event', 'post_id' => $ev->post_id ], admin_url( 'admin.php?page=cbd-events' ) ), 'cbd_delete_event' );
				$start   = $ev->start_date ? gmdate( 'D j M Y, g:ia', strtotime( $ev->start_date ) ) : '—';
				echo '<tr>';
				echo '<td><strong><a href="' . esc_url( get_edit_post_link( $ev->post_id ) ) . '">' . esc_html( $ev->post_title ) . '</a></strong></td>';
				echo '<td>' . esc_html( $ev->biz_name ?: '—' ) . '</td>';
				echo '<td>' . esc_html( $start ) . '</td>';
				echo '<td>' . esc_html( $ev->status ) . '</td>';
				echo '<td><a href="' . esc_url( get_permalink( $ev->post_id ) ) . '" target="_blank">View</a> | ';
				echo '<a href="' . esc_url( get_edit_post_link( $ev->post_id ) ) . '">Edit</a> | ';
				echo '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this event?\');" style="color:#b84a35;">Delete</a></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="5" style="text-align:center;padding:30px;color:#888;">No events yet. <a href="' . esc_url( admin_url( 'admin.php?page=cbd-events&mode=create' ) ) . '">Create one</a>.</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── PAGE: Promotions ──────────────────────────────────────────

	public function page_promotions(): void {
		global $wpdb;
		$this->show_notice();

		$mode        = sanitize_key( $_GET['mode'] ?? 'list' );
		$business_id = (int) ( $_GET['business_id'] ?? 0 );

		if ( isset( $_POST['cbd_create_promo'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_admin_promo' ) ) {
			$this->handle_create_promotion();
			return;
		}

		echo '<div class="wrap cbd-admin">';
		if ( $mode === 'create' ) {
			$this->render_create_promo_form( $business_id );
		} else {
			$this->render_promotions_list();
		}
		echo '</div>';
	}

	private function render_create_promo_form( int $business_id = 0 ): void {
		global $wpdb;
		$businesses = $wpdb->get_results(
			"SELECT b.post_id, p.post_title FROM {$wpdb->prefix}cbd_businesses b
			 INNER JOIN {$wpdb->posts} p ON p.ID = b.post_id
			 WHERE b.status = 'active' ORDER BY p.post_title ASC"
		);
		$sym   = get_option( 'cbd_currency_symbol', '£' );
		$today = gmdate( 'Y-m-d' );

		echo '<h1>Create New Promotion</h1>';
		echo '<form method="post" style="max-width:700px;">';
		echo wp_nonce_field( 'cbd_admin_promo', '_wpnonce', true, false );
		echo '<input type="hidden" name="cbd_create_promo" value="1">';

		echo '<table class="form-table">';
		echo '<tr><th>Offer Title <span style="color:red">*</span></th><td><input type="text" name="promo_title" class="large-text" required placeholder="e.g. 20% Off Spring Collection"></td></tr>';
		echo '<tr><th>Description <span style="color:red">*</span></th><td><textarea name="promo_description" rows="4" class="large-text" required></textarea></td></tr>';

		echo '<tr><th>Business <span style="color:red">*</span></th><td><select name="promo_business_id" class="regular-text" required>';
		echo '<option value="">— Select Business —</option>';
		foreach ( $businesses as $biz ) {
			echo '<option value="' . esc_attr( $biz->post_id ) . '"' . selected( $business_id, $biz->post_id, false ) . '>' . esc_html( $biz->post_title ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>Coupon Code</th><td><input type="text" name="promo_code" class="regular-text" placeholder="e.g. SAVE20" style="text-transform:uppercase;"></td></tr>';
		echo '<tr><th>Discount</th><td>';
		echo '<input type="number" name="promo_discount_value" min="0" step="0.01" placeholder="20" style="width:80px;"> ';
		echo '<select name="promo_discount_type" style="width:80px;"><option value="percent">%</option><option value="fixed">' . esc_html( $sym ) . ' off</option></select>';
		echo '</td></tr>';

		echo '<tr><th>Valid From</th><td><input type="date" name="promo_start" value="' . esc_attr( $today ) . '"></td></tr>';
		echo '<tr><th>Expiry Date</th><td><input type="date" name="promo_expiry"></td></tr>';
		echo '<tr><th>CTA Button Text</th><td><input type="text" name="promo_cta_text" class="regular-text" value="Get Offer"></td></tr>';
		echo '<tr><th>CTA URL</th><td><input type="url" name="promo_cta_url" class="regular-text" placeholder="https://..."></td></tr>';
		echo '<tr><th>Featured</th><td><label><input type="checkbox" name="promo_featured" value="1"> Show as featured promotion</label></td></tr>';
		echo '</table>';

		echo '<p><button type="submit" class="button button-primary button-large">Create Promotion</button> ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=cbd-promotions' ) ) . '" class="button">Cancel</a></p>';
		echo '</form>';
	}

	private function handle_create_promotion(): void {
		global $wpdb;
		$title  = sanitize_text_field( $_POST['promo_title'] ?? '' );
		$desc   = wp_kses_post( $_POST['promo_description'] ?? '' );
		$biz_id = (int) ( $_POST['promo_business_id'] ?? 0 );

		if ( ! $title || ! $biz_id ) {
			echo '<div class="notice notice-error"><p>Title and business are required.</p></div>';
			echo '<div class="wrap cbd-admin">';
			$this->render_create_promo_form( $biz_id );
			echo '</div>';
			return;
		}

		$biz_owner = get_post_field( 'post_author', $biz_id );
		$post_id   = wp_insert_post( [
			'post_type'    => 'cbd_promotion',
			'post_title'   => $title,
			'post_content' => $desc,
			'post_excerpt' => $desc,
			'post_status'  => 'publish',
			'post_author'  => $biz_owner ?: get_current_user_id(),
		] );

		if ( ! is_wp_error( $post_id ) ) {
			$expiry = sanitize_text_field( $_POST['promo_expiry'] ?? '' );
			$wpdb->insert( $wpdb->prefix . 'cbd_promotions', [
				'post_id'        => $post_id,
				'business_id'    => $biz_id,
				'owner_id'       => $biz_owner ?: get_current_user_id(),
				'coupon_code'    => strtoupper( sanitize_text_field( $_POST['promo_code']            ?? '' ) ),
				'discount_type'  => sanitize_text_field( $_POST['promo_discount_type']  ?? 'percent' ),
				'discount_value' => (float) ( $_POST['promo_discount_value']             ?? 0 ),
				'start_date'     => sanitize_text_field( $_POST['promo_start']           ?? gmdate( 'Y-m-d' ) ),
				'expiry_date'    => $expiry ?: null,
				'cta_text'       => sanitize_text_field( $_POST['promo_cta_text']        ?? 'Get Offer' ),
				'cta_url'        => esc_url_raw( $_POST['promo_cta_url']                 ?? '' ),
				'is_featured'    => ! empty( $_POST['promo_featured'] ) ? 1 : 0,
				'status'         => 'active',
			] );
			wp_redirect( add_query_arg( 'cbd_notice', 'promo_created', admin_url( 'admin.php?page=cbd-promotions' ) ) );
			exit;
		}

		echo '<div class="notice notice-error"><p>Failed to create promotion.</p></div>';
		echo '<div class="wrap cbd-admin">';
		$this->render_create_promo_form( $biz_id );
		echo '</div>';
	}

	private function render_promotions_list(): void {
		global $wpdb;
		echo '<h1>Promotions <a href="' . esc_url( admin_url( 'admin.php?page=cbd-promotions&mode=create' ) ) . '" class="page-title-action">+ Create Promotion</a></h1>';

		$promos = $wpdb->get_results(
			"SELECT pr.*, p.post_title, biz.post_title AS biz_name
			 FROM {$wpdb->prefix}cbd_promotions pr
			 INNER JOIN {$wpdb->posts} p ON p.ID = pr.post_id
			 LEFT JOIN {$wpdb->posts} biz ON biz.ID = pr.business_id
			 ORDER BY pr.created_at DESC LIMIT 100"
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Promotion</th><th>Business</th><th>Code</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		if ( $promos ) {
			foreach ( $promos as $pr ) {
				$today     = gmdate( 'Y-m-d' );
				$expired   = $pr->expiry_date && $pr->expiry_date < $today;
				$del_url   = wp_nonce_url( add_query_arg( [ 'cbd_action' => 'delete_promotion', 'post_id' => $pr->post_id ], admin_url( 'admin.php?page=cbd-promotions' ) ), 'cbd_delete_promo' );
				$status_style = $expired ? 'color:#b84a35;' : 'color:#2b6344;';

				echo '<tr>';
				echo '<td><strong><a href="' . esc_url( get_edit_post_link( $pr->post_id ) ) . '">' . esc_html( $pr->post_title ) . '</a></strong></td>';
				echo '<td>' . esc_html( $pr->biz_name ?: '—' ) . '</td>';
				echo '<td>' . ( $pr->coupon_code ? '<code>' . esc_html( $pr->coupon_code ) . '</code>' : '—' ) . '</td>';
				echo '<td style="' . esc_attr( $status_style ) . '">' . ( $pr->expiry_date ? esc_html( gmdate( 'j M Y', strtotime( $pr->expiry_date ) ) ) : 'No expiry' ) . '</td>';
				echo '<td><span style="' . esc_attr( $status_style ) . '">' . esc_html( $expired ? 'Expired' : $pr->status ) . '</span></td>';
				echo '<td><a href="' . esc_url( get_permalink( $pr->post_id ) ) . '" target="_blank">View</a> | ';
				echo '<a href="' . esc_url( get_edit_post_link( $pr->post_id ) ) . '">Edit</a> | ';
				echo '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete?\');" style="color:#b84a35;">Delete</a></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="6" style="text-align:center;padding:30px;color:#888;">No promotions yet. <a href="' . esc_url( admin_url( 'admin.php?page=cbd-promotions&mode=create' ) ) . '">Create one</a>.</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── PAGE: Reviews ─────────────────────────────────────────────

	public function page_reviews(): void {
		global $wpdb;
		$this->show_notice();

		if ( isset( $_GET['cbd_review_action'], $_GET['review_id'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cbd_review_action' ) ) {
			$rid    = (int) $_GET['review_id'];
			$status = $_GET['cbd_review_action'] === 'approve' ? 'approved' : 'rejected';
			$wpdb->update( $wpdb->prefix . 'cbd_reviews', [ 'status' => $status ], [ 'id' => $rid ] );
			if ( $status === 'approved' ) {
				$review_obj = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_reviews WHERE id = %d", $rid ) );
				if ( $review_obj ) { do_action( 'cbd_review_approved', $review_obj ); }
			}

			if ( $status === 'approved' ) {
				$r   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_reviews WHERE id = %d", $rid ) );
				if ( $r ) {
					$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(rating) FROM {$wpdb->prefix}cbd_reviews WHERE business_id=%d AND status='approved'", $r->business_id ) );
					$cnt = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_reviews WHERE business_id=%d AND status='approved'", $r->business_id ) );
					$wpdb->update( $wpdb->prefix . 'cbd_businesses', [ 'rating_avg' => round( (float) $avg, 2 ), 'review_count' => (int) $cnt ], [ 'post_id' => $r->business_id ] );
				}
			}
		}

		$filter = sanitize_key( $_GET['filter'] ?? 'pending' );
		$where  = $filter !== 'all' ? $wpdb->prepare( "WHERE r.status = %s", $filter ) : '';

		$reviews = $wpdb->get_results(
			"SELECT r.*, p.post_title AS biz_name
			 FROM {$wpdb->prefix}cbd_reviews r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.business_id
			 $where ORDER BY r.created_at DESC LIMIT 100"
		);

		echo '<div class="wrap cbd-admin"><h1>Reviews</h1>';

		$f_tabs = [ 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All' ];
		echo '<ul class="subsubsub" style="margin-bottom:16px;">';
		foreach ( $f_tabs as $slug => $label ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_reviews" . ( $slug !== 'all' ? $wpdb->prepare( " WHERE status=%s", $slug ) : '' ) );
			echo '<li><a href="' . esc_url( add_query_arg( 'filter', $slug, admin_url( 'admin.php?page=cbd-reviews' ) ) ) . '"' . ( $filter === $slug ? ' class="current" style="font-weight:700;"' : '' ) . '>' . esc_html( $label ) . ' (' . $count . ')</a> |</li>';
		}
		echo '</ul>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>Author</th><th>Business</th><th>Rating</th><th>Review</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';

		if ( $reviews ) {
			foreach ( $reviews as $r ) {
				$status_colors = [ 'approved' => '#2b6344', 'pending' => '#c47b1a', 'rejected' => '#b84a35' ];
				$color = $status_colors[ $r->status ] ?? '#888';
				echo '<tr>';
				echo '<td><strong>' . esc_html( $r->author_name ) . '</strong><br><small>' . esc_html( $r->author_email ) . '</small></td>';
				echo '<td>' . esc_html( $r->biz_name ?: '—' ) . '</td>';
				echo '<td>' . str_repeat( '★', (int) $r->rating ) . str_repeat( '☆', 5 - (int) $r->rating ) . '</td>';
				echo '<td>' . esc_html( wp_trim_words( $r->content, 15 ) ) . '</td>';
				echo '<td><span style="background:' . esc_attr( $color ) . ';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">' . esc_html( $r->status ) . '</span></td>';
				echo '<td><small>' . esc_html( gmdate( 'j M Y', strtotime( $r->created_at ) ) ) . '</small></td>';
				echo '<td style="white-space:nowrap;">';
				if ( $r->status === 'pending' ) {
					$approve_url = wp_nonce_url( add_query_arg( [ 'cbd_review_action' => 'approve', 'review_id' => $r->id ], admin_url( 'admin.php?page=cbd-reviews' ) ), 'cbd_review_action' );
					$reject_url  = wp_nonce_url( add_query_arg( [ 'cbd_review_action' => 'reject',  'review_id' => $r->id ], admin_url( 'admin.php?page=cbd-reviews' ) ), 'cbd_review_action' );
					echo '<a href="' . esc_url( $approve_url ) . '" class="button button-primary button-small">✓ Approve</a> ';
					echo '<a href="' . esc_url( $reject_url  ) . '" class="button button-small">✗ Reject</a>';
				}
				echo '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="7" style="text-align:center;padding:30px;color:#888;">No reviews found.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── PAGE: Settings ────────────────────────────────────────────

	public function page_settings(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'general' );

		if ( isset( $_POST['cbd_save_settings'] ) && check_admin_referer( 'cbd_settings' ) ) {
			$this->save_settings( $tab );
			// The demo tab emits its own (generate/teardown) notice from save_settings().
			if ( $tab !== 'demo' ) {
				echo '<div class="notice notice-success is-dismissible"><p><strong>Community Directory:</strong> Settings saved.</p></div>';
			}
		}

		$tabs = [
			'general'      => '⚙️ General',
			'email'        => '✉️ Email',
			'registration' => '📝 Registration',
			'events'       => '📅 Events',
			'promotions'   => '🏷️ Promotions',
			'payments'     => '💳 Payments',
			'social'       => '🔑 Social Login',
			'pages'        => '📄 Pages',
			'demo'         => '🧪 Demo Mode',
		];

		echo '<div class="wrap cbd-admin"><h1>Settings</h1>';
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a href="' . esc_url( add_query_arg( [ 'page' => 'cbd-settings', 'tab' => $slug ], admin_url( 'admin.php' ) ) ) . '"'
				. ' class="nav-tab' . ( $tab === $slug ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<form method="post" style="max-width:800px;">' . wp_nonce_field( 'cbd_settings', '_wpnonce', true, false );

		switch ( $tab ) {
			case 'general':       $this->settings_tab_general();      break;
			case 'email':         $this->settings_tab_email();        break;
			case 'registration':  $this->settings_tab_registration(); break;
			case 'events':        $this->settings_tab_events();       break;
			case 'promotions':    $this->settings_tab_promotions();   break;
			case 'payments':      $this->settings_tab_payments();     break;
			case 'social':        $this->settings_tab_social();       break;
			case 'pages':         $this->settings_tab_pages();        break;
			case 'demo':          $this->settings_tab_demo();         break;
		}

		// The Demo tab saves instantly via its own AJAX toggle — no submit button.
		if ( $tab !== 'demo' ) {
			echo '<p class="submit"><input type="submit" name="cbd_save_settings" class="button-primary button-large" value="Save Settings"></p>';
		}
		echo '</form></div>';
	}

	private function save_settings( string $tab ): void {
		switch ( $tab ) {
			case 'general':
				update_option( 'cbd_currency',            sanitize_text_field( $_POST['currency']     ?? 'GBP' ) );
				update_option( 'cbd_currency_symbol',     sanitize_text_field( $_POST['currency_sym'] ?? '£' ) );
				update_option( 'cbd_default_country',     sanitize_text_field( $_POST['country']      ?? 'GB' ) );
				update_option( 'cbd_google_maps_api_key', sanitize_text_field( $_POST['maps_key']     ?? '' ) );
				update_option( 'cbd_items_per_page',      (int) ( $_POST['per_page']                  ?? 20 ) );
				break;

			case 'email':
				update_option( 'cbd_email_logo',         esc_url_raw( $_POST['email_logo']     ?? '' ) );
				update_option( 'cbd_email_from_name',     sanitize_text_field( $_POST['email_from_name'] ?? '' ) );
				update_option( 'cbd_email_from_address',  sanitize_email( $_POST['email_from_address']   ?? '' ) );
				update_option( 'cbd_email_reply_to',      sanitize_email( $_POST['email_reply_to']       ?? '' ) );
				// SMTP — route mail through an authenticated server instead of the
				// host's throttled/spam-prone PHP mail().
				update_option( 'cbd_smtp_enabled',    ! empty( $_POST['smtp_enabled'] ) ? '1' : '0' );
				update_option( 'cbd_smtp_host',       sanitize_text_field( $_POST['smtp_host'] ?? '' ) );
				update_option( 'cbd_smtp_port',       (int) ( $_POST['smtp_port'] ?? 465 ) );
				$enc = sanitize_text_field( $_POST['smtp_encryption'] ?? 'ssl' );
				update_option( 'cbd_smtp_encryption', in_array( $enc, [ '', 'ssl', 'tls' ], true ) ? $enc : 'ssl' );
				update_option( 'cbd_smtp_auth',       ! empty( $_POST['smtp_auth'] ) ? '1' : '0' );
				update_option( 'cbd_smtp_user',       sanitize_text_field( $_POST['smtp_user'] ?? '' ) );
				// Only overwrite the stored password when a new one is typed (the
				// field is left blank on the form to avoid echoing the secret).
				if ( isset( $_POST['smtp_pass'] ) && '' !== $_POST['smtp_pass'] ) {
					update_option( 'cbd_smtp_pass', (string) wp_unslash( $_POST['smtp_pass'] ) );
				}
				break;

			case 'registration':
				update_option( 'cbd_registration_enabled',              ! empty( $_POST['reg_enabled'] )  ? '1' : '0' );
				update_option( 'cbd_registration_requires_approval',    ! empty( $_POST['approval'] )     ? '1' : '0' );
				update_option( 'cbd_registration_requires_login',       ! empty( $_POST['req_login'] )    ? '1' : '0' );
				update_option( 'cbd_max_businesses_per_user',           (int) ( $_POST['max_biz']         ?? 0 ) );
				break;

			case 'events':
				update_option( 'cbd_events_enabled',           ! empty( $_POST['events_enabled'] ) ? '1' : '0' );
				update_option( 'cbd_events_who_can_create',    sanitize_text_field( $_POST['events_who']   ?? 'business_owners' ) );
				update_option( 'cbd_events_require_approval',  ! empty( $_POST['events_approval'] ) ? '1' : '0' );
				update_option( 'cbd_max_events_per_business',  (int) ( $_POST['max_events']              ?? 0 ) );
				break;

			case 'promotions':
				update_option( 'cbd_promotions_enabled',           ! empty( $_POST['promos_enabled'] ) ? '1' : '0' );
				update_option( 'cbd_promotions_who_can_create',    sanitize_text_field( $_POST['promos_who']   ?? 'business_owners' ) );
				update_option( 'cbd_promotions_require_approval',  ! empty( $_POST['promos_approval'] ) ? '1' : '0' );
				update_option( 'cbd_max_promos_per_business',      (int) ( $_POST['max_promos']               ?? 0 ) );
				update_option( 'cbd_default_promo_expiry_days',    (int) ( $_POST['expiry_days']              ?? 30 ) );
				break;

			case 'payments':
				update_option( 'cbd_stripe_publishable_key', sanitize_text_field( $_POST['stripe_pub'] ?? '' ) );
				update_option( 'cbd_stripe_secret_key',      sanitize_text_field( $_POST['stripe_sec'] ?? '' ) );
				update_option( 'cbd_paypal_client_id',       sanitize_text_field( $_POST['paypal_id']  ?? '' ) );
				break;

			case 'social':
				update_option( 'cbd_google_client_id',       sanitize_text_field( $_POST['google_id']     ?? '' ) );
				update_option( 'cbd_google_client_secret',   sanitize_text_field( $_POST['google_secret'] ?? '' ) );
				update_option( 'cbd_facebook_app_id',        sanitize_text_field( $_POST['fb_id']         ?? '' ) );
				update_option( 'cbd_facebook_app_secret',    sanitize_text_field( $_POST['fb_secret']     ?? '' ) );
				update_option( 'cbd_twitter_client_id',      sanitize_text_field( $_POST['tw_id']         ?? '' ) );
				update_option( 'cbd_twitter_client_secret',  sanitize_text_field( $_POST['tw_secret']     ?? '' ) );
				update_option( 'cbd_instagram_client_id',    sanitize_text_field( $_POST['ig_id']         ?? '' ) );
				update_option( 'cbd_instagram_client_secret', sanitize_text_field( $_POST['ig_secret']    ?? '' ) );
				break;

			case 'demo':
				// Demo Mode is driven by the AJAX toggle (ajax_demo_generate /
				// ajax_demo_teardown) so it can stream progress — nothing to save here.
				break;
		}
	}

	private function settings_tab_general(): void {
		echo '<h2>General Settings</h2><table class="form-table">';
		$this->settings_row( 'Currency Code', '<select name="currency">' . $this->currency_options() . '</select>' );
		$this->settings_row( 'Currency Symbol', '<input type="text" name="currency_sym" value="' . esc_attr( get_option( 'cbd_currency_symbol', '£' ) ) . '" style="width:60px;">' );
		$this->settings_row( 'Default Country', '<input type="text" name="country" value="' . esc_attr( get_option( 'cbd_default_country', 'GB' ) ) . '" style="width:80px;" placeholder="GB">' );
		$this->settings_row( 'Google Maps API Key', '<input type="text" name="maps_key" value="' . esc_attr( get_option( 'cbd_google_maps_api_key', '' ) ) . '" class="large-text"><br><small>Required for map view. <a href="https://console.cloud.google.com/" target="_blank">Get key →</a></small>' );
		$this->settings_row( 'Businesses per Page', '<input type="number" name="per_page" value="' . esc_attr( get_option( 'cbd_items_per_page', 20 ) ) . '" min="1" max="100" style="width:80px;">' );
		echo '</table>';
	}

	private function settings_tab_email(): void {
		$logo     = (string) get_option( 'cbd_email_logo', '' );
		$resolved = \CBD\Frontend\EmailVerification::logo_url(); // includes site-logo fallback.

		echo '<h2>Email Settings</h2>';
		echo '<p class="description" style="margin:0 0 16px;max-width:640px;">These control the sender identity and branding on plugin emails (account verification, business approvals, review notices). A matching From address on a domain you control — plus an SMTP plugin — is what keeps these out of spam.</p>';
		echo '<table class="form-table">';

		// ── Logo picker ──
		$preview = $logo ?: $resolved;
		$control  = '<div class="cbd-logo-field">';
		$control .= '<input type="url" name="email_logo" id="cbd_email_logo" value="' . esc_attr( $logo ) . '" class="large-text" placeholder="https://…/logo.png">';
		$control .= '<p style="margin:8px 0;">'
			. '<button type="button" class="button" id="cbd_email_logo_pick">Select / upload logo</button> '
			. '<button type="button" class="button-link" id="cbd_email_logo_clear" style="color:#b32d2e;' . ( $logo ? '' : 'display:none;' ) . '">Remove</button>'
			. '</p>';
		$control .= '<div id="cbd_email_logo_preview" style="' . ( $preview ? '' : 'display:none;' ) . 'margin-top:6px;padding:14px;background:#0e2436;border-radius:8px;display:inline-block;">'
			. '<img src="' . esc_url( $preview ) . '" alt="" style="max-width:180px;max-height:70px;height:auto;display:block;">'
			. '</div>';
		$control .= '<p class="description">Shown at the top of emails. Leave blank to use the site logo' . ( $resolved ? '' : ' (none set yet)' ) . '. Use a PNG/JPG hosted in your Media Library; a wide/transparent logo works best.</p>';
		$control .= '</div>';
		$this->settings_row( 'Email Logo', $control );

		$this->settings_row( 'From Name',
			'<input type="text" name="email_from_name" value="' . esc_attr( get_option( 'cbd_email_from_name', '' ) ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"><br><small>Defaults to the site name.</small>'
		);
		$this->settings_row( 'From Address',
			'<input type="email" name="email_from_address" value="' . esc_attr( get_option( 'cbd_email_from_address', '' ) ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"><br><small>Use a real mailbox on your own domain (e.g. noreply@yourdomain.com) — not a Gmail/Outlook address. Defaults to the site admin email.</small>'
		);
		$this->settings_row( 'Reply-To',
			'<input type="email" name="email_reply_to" value="' . esc_attr( get_option( 'cbd_email_reply_to', '' ) ) . '" class="regular-text" placeholder="optional"><br><small>Where replies go, if different from the From address.</small>'
		);
		echo '</table>';

		// ── SMTP delivery ──
		$smtp_on  = get_option( 'cbd_smtp_enabled' ) === '1';
		$enc      = (string) get_option( 'cbd_smtp_encryption', 'ssl' );
		$auth_on  = get_option( 'cbd_smtp_auth', '1' ) === '1';
		$has_pass = '' !== (string) get_option( 'cbd_smtp_pass', '' );
		echo '<h2 style="margin-top:32px;">SMTP Delivery</h2>';
		echo '<p class="description" style="margin:0 0 16px;max-width:640px;">Shared hosts throttle and spam-flag PHP <code>mail()</code> — if emails stop arriving after a burst of sends, that is why. Enable SMTP to send through an authenticated mail server instead (your Hostinger mailbox, Gmail with an app password, Brevo, SendGrid, Mailtrap, etc.). After saving, send a test from <strong>Diagnostics → Email delivery test</strong>.</p>';
		echo '<table class="form-table">';
		$this->settings_row( 'Use SMTP',
			'<label><input type="checkbox" name="smtp_enabled" value="1"' . checked( $smtp_on, true, false ) . '> Send plugin (and site) email via SMTP</label>'
		);
		$this->settings_row( 'SMTP Host',
			'<input type="text" name="smtp_host" value="' . esc_attr( get_option( 'cbd_smtp_host', '' ) ) . '" class="regular-text" placeholder="smtp.hostinger.com"><br><small>e.g. <code>smtp.hostinger.com</code>, <code>smtp.gmail.com</code>, <code>smtp-relay.brevo.com</code>.</small>'
		);
		$this->settings_row( 'Port',
			'<input type="number" name="smtp_port" value="' . esc_attr( (string) get_option( 'cbd_smtp_port', 465 ) ) . '" class="small-text"> <small>465 (SSL) or 587 (TLS).</small>'
		);
		$this->settings_row( 'Encryption',
			'<select name="smtp_encryption">'
			. '<option value="ssl"' . selected( $enc, 'ssl', false ) . '>SSL</option>'
			. '<option value="tls"' . selected( $enc, 'tls', false ) . '>TLS</option>'
			. '<option value=""' . selected( $enc, '', false ) . '>None</option>'
			. '</select>'
		);
		$this->settings_row( 'Authentication',
			'<label><input type="checkbox" name="smtp_auth" value="1"' . checked( $auth_on, true, false ) . '> Server requires a username &amp; password (usually yes)</label>'
		);
		$this->settings_row( 'Username',
			'<input type="text" name="smtp_user" value="' . esc_attr( get_option( 'cbd_smtp_user', '' ) ) . '" class="regular-text" autocomplete="off" placeholder="noreply@yourdomain.com"><br><small>Usually the full mailbox address. This mailbox should match the From Address above.</small>'
		);
		$this->settings_row( 'Password',
			'<input type="password" name="smtp_pass" value="" class="regular-text" autocomplete="new-password" placeholder="' . ( $has_pass ? '•••••••• (leave blank to keep)' : 'mailbox or app password' ) . '"><br><small>' . ( $has_pass ? 'A password is saved. Leave blank to keep it, or type a new one to replace.' : 'For Gmail use an <strong>App Password</strong>, not your normal password.' ) . '</small>'
		);
		echo '</table>';
		?>
		<script>
		jQuery(function ($) {
			var frame;
			$('#cbd_email_logo_pick').on('click', function (e) {
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({ title: 'Select email logo', button: { text: 'Use this logo' }, multiple: false, library: { type: 'image' } });
				frame.on('select', function () {
					var att = frame.state().get('selection').first().toJSON();
					var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
					$('#cbd_email_logo').val(url);
					$('#cbd_email_logo_preview img').attr('src', url);
					$('#cbd_email_logo_preview').show();
					$('#cbd_email_logo_clear').show();
				});
				frame.open();
			});
			$('#cbd_email_logo_clear').on('click', function (e) {
				e.preventDefault();
				$('#cbd_email_logo').val('');
				$('#cbd_email_logo_preview').hide();
				$(this).hide();
			});
		});
		</script>
		<?php
	}

	private function settings_tab_registration(): void {
		echo '<h2>Business Registration Settings</h2><table class="form-table">';
		$this->settings_row( 'Enable Registration', $this->checkbox( 'reg_enabled', 'cbd_registration_enabled', '1', 'Allow businesses to register via [cbd_register_business]' ) );
		$this->settings_row( 'Require Admin Approval', $this->checkbox( 'approval', 'cbd_registration_requires_approval', '1', 'New listings go to "Pending" before going live' ) );
		$this->settings_row( 'Require Login to Register', $this->checkbox( 'req_login', 'cbd_registration_requires_login', '1', 'Users must have a WordPress account to submit a listing' ) );
		$this->settings_row( 'Max Businesses per User', '<input type="number" name="max_biz" value="' . esc_attr( get_option( 'cbd_max_businesses_per_user', 0 ) ) . '" min="0" style="width:80px;"> <small>0 = unlimited</small>' );
		echo '</table>';
	}

	private function settings_tab_events(): void {
		echo '<h2>Events Settings</h2><table class="form-table">';
		$this->settings_row( 'Enable Events Module', $this->checkbox( 'events_enabled', 'cbd_events_enabled', '1', 'Show [cbd_events] shortcode and allow event creation' ) );
		$this->settings_row( 'Who Can Create Events',
			'<select name="events_who">'
			. $this->select_option( 'all_users',       'All logged-in users',    get_option( 'cbd_events_who_can_create', 'business_owners' ) )
			. $this->select_option( 'business_owners', 'Business owners only',   get_option( 'cbd_events_who_can_create', 'business_owners' ) )
			. $this->select_option( 'admins_only',     'Admins only',            get_option( 'cbd_events_who_can_create', 'business_owners' ) )
			. '</select>'
		);
		$this->settings_row( 'Require Event Approval', $this->checkbox( 'events_approval', 'cbd_events_require_approval', '1', 'New events go to draft until approved by admin' ) );
		$this->settings_row( 'Max Events per Business', '<input type="number" name="max_events" value="' . esc_attr( get_option( 'cbd_max_events_per_business', 0 ) ) . '" min="0" style="width:80px;"> <small>0 = unlimited</small>' );
		echo '</table>';
	}

	private function settings_tab_promotions(): void {
		echo '<h2>Promotions Settings</h2><table class="form-table">';
		$this->settings_row( 'Enable Promotions Module', $this->checkbox( 'promos_enabled', 'cbd_promotions_enabled', '1', 'Show [cbd_promotions] shortcode and allow promotion creation' ) );
		$this->settings_row( 'Who Can Create Promotions',
			'<select name="promos_who">'
			. $this->select_option( 'all_users',       'All logged-in users',   get_option( 'cbd_promotions_who_can_create', 'business_owners' ) )
			. $this->select_option( 'business_owners', 'Business owners only',  get_option( 'cbd_promotions_who_can_create', 'business_owners' ) )
			. $this->select_option( 'admins_only',     'Admins only',           get_option( 'cbd_promotions_who_can_create', 'business_owners' ) )
			. '</select>'
		);
		$this->settings_row( 'Require Promotion Approval', $this->checkbox( 'promos_approval', 'cbd_promotions_require_approval', '1', 'New promotions go to draft until approved by admin' ) );
		$this->settings_row( 'Max Promotions per Business', '<input type="number" name="max_promos" value="' . esc_attr( get_option( 'cbd_max_promos_per_business', 0 ) ) . '" min="0" style="width:80px;"> <small>0 = unlimited</small>' );
		$this->settings_row( 'Default Expiry (days)', '<input type="number" name="expiry_days" value="' . esc_attr( get_option( 'cbd_default_promo_expiry_days', 30 ) ) . '" min="1" style="width:80px;"> <small>Applied when no expiry date is set</small>' );
		echo '</table>';
	}

	private function settings_tab_payments(): void {
		echo '<h2>Payment Settings</h2><table class="form-table">';
		$this->settings_row( 'Stripe Publishable Key', '<input type="text" name="stripe_pub" value="' . esc_attr( get_option( 'cbd_stripe_publishable_key', '' ) ) . '" class="regular-text">' );
		$this->settings_row( 'Stripe Secret Key', '<input type="password" name="stripe_sec" value="' . esc_attr( get_option( 'cbd_stripe_secret_key', '' ) ) . '" class="regular-text">' );
		$this->settings_row( 'PayPal Client ID', '<input type="text" name="paypal_id" value="' . esc_attr( get_option( 'cbd_paypal_client_id', '' ) ) . '" class="regular-text">' );
		echo '</table><p class="description">Payment integration for membership plans. Stripe and PayPal credentials from your developer dashboard.</p>';
	}

	private function settings_tab_social(): void {
		echo '<h2>Social Login</h2>';
		echo '<p>Let visitors sign in to <code>[cbd_login]</code> with Google, Facebook, Twitter / X or Instagram. Create an OAuth app on each provider, paste its credentials below, and register the exact <strong>Redirect URI</strong> shown for that provider. Each provider only appears on the login page once both its ID and secret are filled in.</p>';

		// Providers: option keys + POST field names + console link.
		$providers = [
			'google' => [
				'label'   => 'Google',
				'id_opt'  => 'cbd_google_client_id',     'id_field'  => 'google_id',     'id_label'  => 'Client ID',
				'sec_opt' => 'cbd_google_client_secret', 'sec_field' => 'google_secret', 'sec_label' => 'Client Secret',
				'console' => 'https://console.cloud.google.com/apis/credentials',
				'note'    => 'OAuth 2.0 Client ID (type: Web application). Returns a verified email.',
			],
			'facebook' => [
				'label'   => 'Facebook',
				'id_opt'  => 'cbd_facebook_app_id',      'id_field'  => 'fb_id',     'id_label'  => 'App ID',
				'sec_opt' => 'cbd_facebook_app_secret',  'sec_field' => 'fb_secret', 'sec_label' => 'App Secret',
				'console' => 'https://developers.facebook.com/apps/',
				'note'    => 'Add the “Facebook Login” product. Returns an email when the user grants it.',
			],
			'twitter' => [
				'label'   => 'Twitter / X',
				'id_opt'  => 'cbd_twitter_client_id',     'id_field'  => 'tw_id',     'id_label'  => 'Client ID',
				'sec_opt' => 'cbd_twitter_client_secret', 'sec_field' => 'tw_secret', 'sec_label' => 'Client Secret',
				'console' => 'https://developer.twitter.com/en/portal/dashboard',
				'note'    => 'OAuth 2.0 app with “Confidential client” + PKCE. X does not share email — a placeholder is used.',
			],
			'instagram' => [
				'label'   => 'Instagram',
				'id_opt'  => 'cbd_instagram_client_id',     'id_field'  => 'ig_id',     'id_label'  => 'App ID / Client ID',
				'sec_opt' => 'cbd_instagram_client_secret', 'sec_field' => 'ig_secret', 'sec_label' => 'App Secret',
				'console' => 'https://developers.facebook.com/apps/',
				'note'    => 'Instagram does not share email — a placeholder is used. Note Meta deprecated the Basic Display API (Dec 2024); use Instagram Login via a Meta app.',
			],
		];

		foreach ( $providers as $slug => $p ) {
			$enabled  = trim( (string) get_option( $p['id_opt'], '' ) ) !== '' && trim( (string) get_option( $p['sec_opt'], '' ) ) !== '';
			$callback = \CBD\Frontend\SocialAuth::callback_url( $slug );

			echo '<h3 style="margin-top:24px;display:flex;align-items:center;gap:8px;">' . esc_html( $p['label'] );
			echo $enabled
				? ' <span style="background:#dff3e4;color:#1e6b3a;padding:1px 8px;border-radius:10px;font-size:11px;">Active</span>'
				: ' <span style="background:#eee;color:#888;padding:1px 8px;border-radius:10px;font-size:11px;">Off</span>';
			echo '</h3>';
			echo '<table class="form-table">';
			$this->settings_row( $p['id_label'], '<input type="text" name="' . esc_attr( $p['id_field'] ) . '" value="' . esc_attr( get_option( $p['id_opt'], '' ) ) . '" class="regular-text" autocomplete="off">' );
			$this->settings_row( $p['sec_label'], '<input type="password" name="' . esc_attr( $p['sec_field'] ) . '" value="' . esc_attr( get_option( $p['sec_opt'], '' ) ) . '" class="regular-text" autocomplete="off">' );
			$this->settings_row(
				'Redirect URI',
				'<input type="text" readonly onclick="this.select()" value="' . esc_attr( $callback ) . '" class="large-text code" style="background:#f6f7f7;">'
				. '<br><small>' . esc_html( $p['note'] ) . ' <a href="' . esc_url( $p['console'] ) . '" target="_blank" rel="noopener">Open developer console →</a></small>'
			);
			echo '</table>';
		}
	}

	private function settings_tab_pages(): void {
		echo '<h2>Plugin Pages</h2>';
		echo '<p>These pages were auto-created by the plugin. Each contains a shortcode in its content.</p>';
		echo '<table class="wp-list-table widefat fixed" style="max-width:700px;">';
		echo '<thead><tr><th>Page</th><th>Shortcode</th><th>URL</th><th>Actions</th></tr></thead><tbody>';
		$page_map = [
			'cbd_directory_page_id'   => [ 'Business Directory', '[cbd_directory]'         ],
			'cbd_register_page_id'    => [ 'List Your Business',  '[cbd_register_business]' ],
			'cbd_login_page_id'       => [ 'Sign In',             '[cbd_login]'             ],
			'cbd_events_page_id'      => [ 'Events',              '[cbd_events]'            ],
			'cbd_promotions_page_id'  => [ 'Offers & Deals',      '[cbd_promotions]'        ],
			'cbd_jobs_page_id'        => [ 'Jobs',                '[cbd_jobs]'              ],
			'cbd_plans_page_id'       => [ 'Membership Plans',    '[cbd_plans]'             ],
			'cbd_socmed_page_id'      => [ 'Social Feeds (/socmed)', '[cbd_social_sync]'    ],
		];
		foreach ( $page_map as $opt => [ $label, $sc ] ) {
			$pid = get_option( $opt );
			if ( $pid ) {
				$is_elementor = get_post_meta( (int) $pid, '_elementor_edit_mode', true ) === 'builder';
				echo '<tr>';
				echo '<td><strong>' . esc_html( $label ) . '</strong>' . ( $is_elementor ? ' <span style="background:#f0c33c;padding:1px 6px;border-radius:3px;font-size:11px;">Elementor</span>' : '' ) . '</td>';
				echo '<td><code>' . esc_html( $sc ) . '</code></td>';
				echo '<td><a href="' . esc_url( get_permalink( (int) $pid ) ) . '" target="_blank">View</a></td>';
				echo '<td>';
				echo '<a href="' . esc_url( get_edit_post_link( (int) $pid ) ) . '">Edit</a>';
				if ( $is_elementor ) {
					echo ' | <a href="' . esc_url( admin_url( 'post.php?post=' . $pid . '&action=elementor' ) ) . '" target="_blank" style="color:#c47b1a;">Edit in Elementor</a>';
				}
				echo '</td></tr>';
			} else {
				echo '<tr><td>' . esc_html( $label ) . '</td><td><code>' . esc_html( $sc ) . '</code></td><td colspan="2" style="color:#888;">Not created</td></tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:16px;"><strong>Elementor pages:</strong> Add a <em>Shortcode</em> widget and paste the shortcode shown above.</p>';

		$this->settings_tab_pages_templates();
	}

	/**
	 * Single & archive view editing via Elementor Pro Theme Builder.
	 *
	 * Unlike the Pages above, single business/event/promotion views and the
	 * event archive are not WordPress Pages — they are rendered by the plugin's
	 * templates/ files. The TemplateLoader yields to Elementor when a Theme
	 * Builder template's display conditions match the view, so designing one
	 * here governs every post of that type at once.
	 */
	private function settings_tab_pages_templates(): void {
		echo '<h2 style="margin-top:32px;">Single &amp; Archive Templates</h2>';

		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			echo '<p style="color:#888;">Editing single &amp; archive views in Elementor requires <strong>Elementor Pro</strong> (Theme Builder). These views currently use the plugin\'s built-in templates.</p>';
			return;
		}

		echo '<p>These views aren\'t Pages — each is rendered for every matching post. Design one Theme Builder template and it applies to all of them. A template you build here automatically overrides the plugin\'s default layout for that view. Inside the template, drop a <em>Shortcode</em> widget with the shortcode below to render the plugin\'s profile/listing.</p>';
		echo '<table class="wp-list-table widefat fixed" style="max-width:760px;">';
		echo '<thead><tr><th>View</th><th>Shortcode</th><th>Applies to</th><th>Actions</th></tr></thead><tbody>';

		// label, Theme Builder location, post type slug matched in conditions, shortcode.
		$views = [
			[ 'Single Business',  'single',  'cbd_business',  '[cbd_business_profile]'  ],
			[ 'Single Event',     'single',  'cbd_event',     '[cbd_event_profile]'     ],
			[ 'Single Promotion', 'single',  'cbd_promotion', '[cbd_promotion_profile]' ],
			[ 'Single Job',       'single',  'cbd_job',       '[cbd_job_profile]'       ],
			[ 'Events Archive',   'archive', 'cbd_event',     '[cbd_events]'            ],
		];

		foreach ( $views as [ $label, $location, $slug, $shortcode ] ) {
			$tid    = $this->find_elementor_tb_template( $location, $slug );
			$applies = ucfirst( $location ) . ' · ' . $slug;
			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong>' . ( $tid ? ' <span style="background:#f0c33c;padding:1px 6px;border-radius:3px;font-size:11px;">Elementor</span>' : '' ) . '</td>';
			echo '<td><code>' . esc_html( $shortcode ) . '</code></td>';
			echo '<td><code>' . esc_html( $applies ) . '</code></td>';
			echo '<td>';
			if ( $tid ) {
				echo '<a href="' . esc_url( admin_url( 'post.php?post=' . $tid . '&action=elementor' ) ) . '" target="_blank" style="color:#c47b1a;">Edit in Elementor</a>';
				echo ' | <a href="' . esc_url( get_edit_post_link( $tid ) ) . '">Settings</a>';
			} else {
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=elementor-app' ) . '#/site-editor/templates/' . $location ) . '" target="_blank">Create in Theme Builder</a>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p style="margin-top:12px;color:#666;font-size:12px;">To create one: open Theme Builder → add a <strong>Single</strong>/<strong>Archive</strong> template → set its display condition to the relevant content type (e.g. <em>Businesses</em>) → add a <strong>Shortcode</strong> widget and paste the shortcode above. It will then appear here with an <em>Edit in Elementor</em> link and take over that view automatically.</p>';
	}

	/**
	 * Find a published Elementor Theme Builder template of the given type
	 * ('single'|'archive') whose display conditions reference $slug (a CBD post
	 * type). Returns the template post ID, or 0 if none. Heuristic match on the
	 * stored condition strings (e.g. "include/single/cbd_business").
	 */
	private function find_elementor_tb_template( string $type, string $slug ): int {
		$ids = get_posts( [
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_key'    => '_elementor_template_type',
			'meta_value'  => $type,
		] );

		foreach ( $ids as $tid ) {
			$conditions = get_post_meta( (int) $tid, '_elementor_conditions', true );
			if ( ! is_array( $conditions ) ) {
				continue;
			}
			foreach ( $conditions as $c ) {
				if ( is_string( $c ) && strpos( $c, $slug ) !== false ) {
					return (int) $tid;
				}
			}
		}

		return 0;
	}

	private function settings_tab_demo(): void {
		$enabled = \CBD\Modules\DemoData::is_enabled();

		echo '<h2>Demo Mode</h2>';
		echo '<p>Populate the entire directory with realistic, Inverness-themed sample content so you can preview every feature — listings, events, promotions, reviews, follows, favourites, analytics, the news feed and emoji reactions — without entering anything by hand.</p>';

		echo '<div class="cbd-demo-warning">';
		echo '<strong>⚠️ Turning Demo Mode off deletes the generated content.</strong> ';
		echo 'Only items created by Demo Mode are removed (they are tagged internally) — your real businesses, events and reviews are never touched.';
		echo '</div>';

		// ── Modern toggle + live progress (driven by assets/js/admin.js) ──
		echo '<div class="cbd-demo-control" id="cbd-demo-control" data-state="' . ( $enabled ? 'on' : 'off' ) . '">';

			echo '<div class="cbd-demo-toggle-row">';
				echo '<button type="button" class="cbd-switch' . ( $enabled ? ' is-on' : '' ) . '" id="cbd-demo-switch" role="switch" aria-checked="' . ( $enabled ? 'true' : 'false' ) . '">';
					echo '<span class="cbd-switch-track"><span class="cbd-switch-thumb"></span></span>';
				echo '</button>';
				echo '<span class="cbd-switch-text">';
					echo '<strong id="cbd-demo-state-label">' . ( $enabled ? esc_html__( 'Demo content is live', 'community-business-directory' ) : esc_html__( 'Demo Mode is off', 'community-business-directory' ) ) . '</strong>';
					echo '<small id="cbd-demo-state-help">' . ( $enabled
						? esc_html__( 'Flip the switch to remove all generated sample content.', 'community-business-directory' )
						: esc_html__( 'Flip the switch to generate Inverness sample content. No page reload needed.', 'community-business-directory' ) ) . '</small>';
				echo '</span>';
			echo '</div>';

			// Progress (hidden until a run starts).
			echo '<div class="cbd-demo-progress" id="cbd-demo-progress" hidden>';
				echo '<div class="cbd-demo-progress-head">';
					echo '<span id="cbd-demo-progress-title">' . esc_html__( 'Working…', 'community-business-directory' ) . '</span>';
					echo '<span id="cbd-demo-progress-pct">0%</span>';
				echo '</div>';
				echo '<div class="cbd-demo-bar"><span class="cbd-demo-bar-fill" id="cbd-demo-bar-fill"></span></div>';
				echo '<p class="cbd-demo-progress-detail" id="cbd-demo-progress-detail"></p>';
			echo '</div>';

			echo '<div class="cbd-demo-result notice inline" id="cbd-demo-result" hidden></div>';

		echo '</div>';

		echo '<div id="cbd-demo-counts">';
		$this->render_demo_counts();
		echo '</div>';
	}

	/** Current-demo-content table (re-rendered after a generate run). */
	private function render_demo_counts(): void {
		if ( ! \CBD\Modules\DemoData::is_generated() ) {
			echo '<p style="color:#888;">' . esc_html__( 'No demo content has been generated yet.', 'community-business-directory' ) . '</p>';
			return;
		}
		$c = \CBD\Modules\DemoData::counts();
		echo '<h3>' . esc_html__( 'Current demo content', 'community-business-directory' ) . '</h3>';
		echo '<table class="wp-list-table widefat fixed striped" style="max-width:420px;"><tbody>';
		foreach ( [
			'Businesses'     => $c['businesses'],
			'Events'         => $c['events'],
			'Promotions'     => $c['promotions'],
			'Business posts' => $c['posts'],
			'Reviews'        => $c['reviews'],
			'Reactions'      => $c['reactions'] ?? 0,
			'Demo users'     => $c['users'],
			'Images'         => $c['images'],
		] as $label => $val ) {
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( number_format( (int) $val ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── AJAX: Demo Mode generate / teardown (streamed progress) ────

	/** wp_ajax_cbd_demo_generate — process one generation tick. */
	public function ajax_demo_generate(): void {
		$this->verify_demo_ajax();
		wp_send_json_success( \CBD\Modules\DemoData::generate_tick() );
	}

	/** wp_ajax_cbd_demo_teardown — process one teardown tick. */
	public function ajax_demo_teardown(): void {
		$this->verify_demo_ajax();
		wp_send_json_success( \CBD\Modules\DemoData::teardown_tick() );
	}

	private function verify_demo_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ], 403 );
		}
		check_ajax_referer( 'cbd_demo', 'nonce' );
	}

	// ── PAGE: Love Inverness importer ─────────────────────────────

	public function page_loqiva(): void {
		// Save feed URLs (token rotation).
		if ( isset( $_POST['cbd_save_loqiva'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_loqiva_urls' ) ) {
			foreach ( [ 'business', 'rewards', 'events' ] as $type ) {
				$val = esc_url_raw( trim( (string) wp_unslash( $_POST[ 'cbd_loqiva_url_' . $type ] ?? '' ) ) );
				update_option( 'cbd_loqiva_url_' . $type, $val );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Feed URLs saved.', 'community-business-directory' ) . '</p></div>';
		}

		// Save auto-sync frequency.
		if ( isset( $_POST['cbd_save_loqiva_interval'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_loqiva_interval' ) ) {
			\CBD\Modules\LoqivaSync::set_interval( (int) ( $_POST['cbd_loqiva_interval'] ?? 0 ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Auto-sync frequency updated.', 'community-business-directory' ) . '</p></div>';
		}

		$counts = \CBD\Modules\LoqivaSync::counts();
		$next   = wp_next_scheduled( \CBD\Modules\LoqivaSync::CRON_HOOK );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Love Inverness Import', 'community-business-directory' ) . '</h1>';
		echo '<p>' . esc_html__( 'Import Events, Offers and Business Profiles from the Love Inverness digital town hub (Loqiva) as native directory listings. Imported content is tagged internally, so re-syncing updates in place and removing only ever deletes imported items — never your own listings.', 'community-business-directory' ) . '</p>';

		// Status summary.
		echo '<table class="wp-list-table widefat fixed striped" style="max-width:480px;margin:1em 0;"><tbody>';
		echo '<tr><td><strong>' . esc_html__( 'Business Profiles', 'community-business-directory' ) . '</strong></td><td>' . esc_html( number_format( (int) $counts['businesses'] ) ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Events', 'community-business-directory' ) . '</strong></td><td>' . esc_html( number_format( (int) $counts['events'] ) ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Offers', 'community-business-directory' ) . '</strong></td><td>' . esc_html( number_format( (int) $counts['promotions'] ) ) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__( 'Last sync', 'community-business-directory' ) . '</strong></td><td>' . esc_html( $counts['last_sync'] ?: __( 'never', 'community-business-directory' ) ) . '</td></tr>';
		if ( $next ) {
			echo '<tr><td><strong>' . esc_html__( 'Next auto-sync', 'community-business-directory' ) . '</strong></td><td>' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $next ), 'Y-m-d H:i' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Auto-sync frequency selector.
		$cur_interval = \CBD\Modules\LoqivaSync::interval_seconds();
		echo '<form method="post" style="margin:0 0 .5em;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
		wp_nonce_field( 'cbd_loqiva_interval' );
		echo '<label for="cbd-loqiva-interval" style="font-weight:600;">' . esc_html__( 'Auto-sync frequency', 'community-business-directory' ) . '</label> ';
		echo '<select name="cbd_loqiva_interval" id="cbd-loqiva-interval">';
		foreach ( \CBD\Modules\LoqivaSync::interval_choices() as $secs => $label ) {
			echo '<option value="' . esc_attr( (string) $secs ) . '"' . selected( $cur_interval, $secs, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" name="cbd_save_loqiva_interval" value="1" class="button">' . esc_html__( 'Save', 'community-business-directory' ) . '</button>';
		echo '</form>';
		echo '<p class="description" style="margin:0 0 1.5em;max-width:560px;">' . esc_html__( 'WordPress runs scheduled tasks on site visits, so short intervals can drift on low-traffic sites. For reliable sub-hourly syncing, set up a real server cron.', 'community-business-directory' ) . '</p>';

		// Sync / Remove controls + progress (reuses the demo-control styling).
		echo '<div class="cbd-demo-control" id="cbd-loqiva-control">';
			echo '<p>';
				echo '<button type="button" class="button button-primary" id="cbd-loqiva-sync">' . esc_html__( 'Sync Now', 'community-business-directory' ) . '</button> ';
				echo '<button type="button" class="button" id="cbd-loqiva-remove">' . esc_html__( 'Remove Imported Content', 'community-business-directory' ) . '</button>';
			echo '</p>';
			echo '<div class="cbd-demo-progress" id="cbd-loqiva-progress" hidden>';
				echo '<div class="cbd-demo-progress-head">';
					echo '<span id="cbd-loqiva-progress-title">' . esc_html__( 'Working…', 'community-business-directory' ) . '</span>';
					echo '<span id="cbd-loqiva-progress-pct">0%</span>';
				echo '</div>';
				echo '<div class="cbd-demo-bar"><span class="cbd-demo-bar-fill" id="cbd-loqiva-bar-fill"></span></div>';
				echo '<p class="cbd-demo-progress-detail" id="cbd-loqiva-progress-detail"></p>';
			echo '</div>';
			echo '<div class="cbd-demo-result notice inline" id="cbd-loqiva-result" hidden></div>';
		echo '</div>';

		// Feed URL config (collapsed details).
		echo '<details style="margin-top:1.5em;max-width:760px;"><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Feed URLs (advanced)', 'community-business-directory' ) . '</summary>';
		echo '<form method="post" style="margin-top:1em;">';
		wp_nonce_field( 'cbd_loqiva_urls' );
		echo '<p class="description">' . esc_html__( 'Paste the private token URLs supplied by InvernessBID. They are stored in the database only, never in the code.', 'community-business-directory' ) . '</p>';
		foreach ( [ 'business' => __( 'Business Profiles', 'community-business-directory' ), 'rewards' => __( 'Offers', 'community-business-directory' ), 'events' => __( 'Events', 'community-business-directory' ) ] as $type => $label ) {
			echo '<p><label><strong>' . esc_html( $label ) . '</strong><br>';
			echo '<input type="url" name="cbd_loqiva_url_' . esc_attr( $type ) . '" value="' . esc_attr( \CBD\Modules\LoqivaSync::url( $type ) ) . '" style="width:100%;max-width:720px;"></label></p>';
		}
		echo '<p><button type="submit" name="cbd_save_loqiva" value="1" class="button">' . esc_html__( 'Save Feed URLs', 'community-business-directory' ) . '</button></p>';
		echo '</form></details>';

		echo '</div>';
	}

	// ── AJAX: Love Inverness import (streamed progress) ───────────

	/** wp_ajax_cbd_loqiva_sync — process one import tick. */
	public function ajax_loqiva_sync(): void {
		$this->verify_loqiva_ajax();
		wp_send_json_success( \CBD\Modules\LoqivaSync::sync_tick() );
	}

	/** wp_ajax_cbd_loqiva_teardown — process one removal tick. */
	public function ajax_loqiva_teardown(): void {
		$this->verify_loqiva_ajax();
		wp_send_json_success( \CBD\Modules\LoqivaSync::teardown_tick() );
	}

	private function verify_loqiva_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ], 403 );
		}
		check_ajax_referer( 'cbd_loqiva', 'nonce' );
	}


	// ── PAGE: Facebook Page sync ──────────────────────────────────

	public function page_facebook(): void {
		$fb = '\CBD\Modules\FacebookSync';

		// Save settings.
		if ( isset( $_POST['cbd_save_facebook'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_facebook_settings' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce checked above; FacebookSync::save() sanitises each field.
			$fb::save( wp_unslash( $_POST ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Facebook settings saved.', 'community-business-directory' ) . '</p></div>';
		}

		$status    = $fb::status();
		$cur_mode  = (string) get_option( $fb::OPT_MODE, 'auto' );
		$eff_mode  = $fb::resolve_mode();
		$cur_tabs  = explode( ',', $fb::tabs() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Facebook Page', 'community-business-directory' ) . '</h1>';
		echo '<p>' . esc_html__( 'Show your Facebook Page on the site with the [cbd_facebook_page] shortcode or the “Facebook Page” block. Embed mode needs only your Page URL (Facebook caps its width at 500px). Third-party embed mode renders a full-width widget you paste from a service like Behold or SnapWidget. Feed mode syncs posts through the Graph API into native cards, but needs Meta app access.', 'community-business-directory' ) . '</p>';

		// Status summary.
		$mode_labels = [
			'feed'  => __( 'Feed (synced posts)', 'community-business-directory' ),
			'code'  => __( 'Third-party embed', 'community-business-directory' ),
			'embed' => __( 'Embed (Page Plugin)', 'community-business-directory' ),
		];
		echo '<table class="wp-list-table widefat fixed striped" style="max-width:520px;margin:1em 0;"><tbody>';
		echo '<tr><td><strong>' . esc_html__( 'Active mode', 'community-business-directory' ) . '</strong></td><td>' . esc_html( $mode_labels[ $eff_mode ] ?? $eff_mode ) . '</td></tr>';
		if ( 'feed' === $eff_mode ) {
			echo '<tr><td><strong>' . esc_html__( 'Feed credentials', 'community-business-directory' ) . '</strong></td><td>' . ( $status['configured'] ? esc_html__( 'Connected', 'community-business-directory' ) : esc_html__( 'Not set (embed only)', 'community-business-directory' ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( 'Synced posts', 'community-business-directory' ) . '</strong></td><td>' . esc_html( number_format( (int) $status['cached'] ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( 'Last sync', 'community-business-directory' ) . '</strong></td><td>' . esc_html( $status['last_fetch'] ?: __( 'never', 'community-business-directory' ) ) . '</td></tr>';
			if ( $status['last_error'] ) {
				echo '<tr><td><strong>' . esc_html__( 'Last error', 'community-business-directory' ) . '</strong></td><td style="color:#b32d2e;">' . esc_html( $status['last_error'] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		// Refresh (feed) control — only relevant while the Graph feed is the active mode.
		if ( 'feed' === $eff_mode ) {
			echo '<div class="cbd-demo-control" id="cbd-fb-control">';
			if ( $status['configured'] ) {
				echo '<p><button type="button" class="button button-primary" id="cbd-fb-refresh">' . esc_html__( 'Refresh posts now', 'community-business-directory' ) . '</button> ';
				echo '<span class="spinner" id="cbd-fb-spinner" style="float:none;margin:0;"></span></p>';
				echo '<div class="cbd-demo-result notice inline" id="cbd-fb-result" hidden></div>';
			} else {
				echo '<p class="description">' . esc_html__( 'Add a Page ID and Access Token below to enable live post syncing. Without them the shortcode falls back to the embedded Page Plugin.', 'community-business-directory' ) . '</p>';
			}
			echo '</div>';
		}

		// Settings form.
		echo '<form method="post" style="margin-top:1.5em;max-width:760px;">';
		wp_nonce_field( 'cbd_facebook_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="cbd-fb-url">' . esc_html__( 'Facebook Page URL', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="url" id="cbd-fb-url" name="cbd_fb_page_url" class="regular-text" placeholder="https://www.facebook.com/YourPage" value="' . esc_attr( (string) get_option( $fb::OPT_PAGE_URL, '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Used by Embed mode and as the “View on Facebook” link.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-fb-mode">' . esc_html__( 'Display mode', 'community-business-directory' ) . '</label></th><td>';
		echo '<select id="cbd-fb-mode" name="cbd_fb_mode">';
		foreach ( [
			'auto'  => __( 'Auto (Feed, then embed code, then Page Plugin)', 'community-business-directory' ),
			'embed' => __( 'Embed — official Page Plugin (max 500px wide)', 'community-business-directory' ),
			'code'  => __( 'Third-party embed — paste widget code (full width)', 'community-business-directory' ),
			'feed'  => __( 'Feed — synced native posts (needs Graph API)', 'community-business-directory' ),
		] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $cur_mode, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		// Third-party embed snippet (Behold / SnapWidget / Curator / Elfsight …).
		echo '<tr><th scope="row"><label for="cbd-fb-embed">' . esc_html__( 'Third-party embed code', 'community-business-directory' ) . '</label></th><td>';
		echo '<textarea id="cbd-fb-embed" name="cbd_fb_embed_html" rows="4" class="large-text code" spellcheck="false" placeholder="&lt;script src=&quot;https://…&quot;&gt;&lt;/script&gt;">' . esc_textarea( (string) get_option( $fb::OPT_EMBED, '' ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Paste the full widget snippet from Behold, SnapWidget, Curator, Elfsight, LightWidget, etc. Used when Display mode is “Third-party embed” (or Auto with no live feed). Renders full-width.', 'community-business-directory' ) . '</p>';
		if ( ! current_user_can( 'unfiltered_html' ) && trim( (string) get_option( $fb::OPT_EMBED, '' ) ) !== '' ) {
			echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Your account is not allowed to save raw HTML/scripts, so part of the snippet may have been stripped. Ask a full site administrator to paste it.', 'community-business-directory' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cbd-fb-count">' . esc_html__( 'Posts to show', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="number" id="cbd-fb-count" name="cbd_fb_count" min="1" max="50" value="' . esc_attr( (string) $fb::count() ) . '" class="small-text"> ';
		echo '<span class="description">' . esc_html__( '(Feed mode)', 'community-business-directory' ) . '</span></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Embed tabs', 'community-business-directory' ) . '</th><td><fieldset>';
		foreach ( [ 'timeline' => __( 'Timeline', 'community-business-directory' ), 'events' => __( 'Events', 'community-business-directory' ), 'messages' => __( 'Messages', 'community-business-directory' ) ] as $val => $label ) {
			echo '<label style="margin-right:14px;"><input type="checkbox" name="cbd_fb_tabs[]" value="' . esc_attr( $val ) . '"' . checked( in_array( $val, $cur_tabs, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Which tabs the embedded Page Plugin shows.', 'community-business-directory' ) . '</p></fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-fb-height">' . esc_html__( 'Embed height (px)', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="number" id="cbd-fb-height" name="cbd_fb_height" min="130" max="2000" value="' . esc_attr( (string) $fb::height() ) . '" class="small-text"></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Embed options', 'community-business-directory' ) . '</th><td><fieldset>';
		echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="cbd_fb_hide_cover" value="1"' . checked( $fb::hide_cover(), true, false ) . '> ' . esc_html__( 'Hide cover photo', 'community-business-directory' ) . '</label>';
		echo '<label style="display:block;"><input type="checkbox" name="cbd_fb_small_header" value="1"' . checked( $fb::small_header(), true, false ) . '> ' . esc_html__( 'Use small header', 'community-business-directory' ) . '</label>';
		echo '</fieldset></td></tr>';

		echo '<tr><td colspan="2"><hr><p style="margin:.6em 0 0;font-weight:600;">' . esc_html__( 'Native feed sync (advanced — Facebook Graph API)', 'community-business-directory' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Only needed for Feed mode. Leave blank when using Embed or Third-party embed.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-fb-id">' . esc_html__( 'Page ID or username', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="text" id="cbd-fb-id" name="cbd_fb_page_id" class="regular-text" value="' . esc_attr( (string) get_option( $fb::OPT_PAGE_ID, '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Required for Feed mode. The numeric Page ID (or vanity name) whose posts to sync — not your Meta App ID.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-fb-token">' . esc_html__( 'Page Access Token', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="text" id="cbd-fb-token" name="cbd_fb_access_token" class="large-text code" autocomplete="off" value="' . esc_attr( (string) get_option( $fb::OPT_TOKEN, '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'A long-lived Page Access Token with pages_read_engagement. Required for Feed mode.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-fb-ttl">' . esc_html__( 'Cache lifetime (minutes)', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="number" id="cbd-fb-ttl" name="cbd_fb_cache_ttl" min="5" max="1440" value="' . esc_attr( (string) get_option( $fb::OPT_TTL, 30 ) ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'How long synced posts are cached before the next automatic refresh.', 'community-business-directory' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" name="cbd_save_facebook" value="1" class="button button-primary">' . esc_html__( 'Save Settings', 'community-business-directory' ) . '</button></p>';
		echo '</form>';

		// Usage + token help.
		echo '<div class="cbd-fb-help" style="max-width:760px;margin-top:1em;">';
		echo '<h2>' . esc_html__( 'Add it to a page', 'community-business-directory' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste this shortcode into any page or widget, or add the “Facebook Page” block:', 'community-business-directory' ) . '</p>';
		echo '<p><code>[cbd_facebook_page]</code></p>';
		echo '<p class="description">' . esc_html__( 'Override the defaults per instance, e.g. [cbd_facebook_page mode="code"], [cbd_facebook_page mode="embed" tabs="timeline,events" height="600"], or [cbd_facebook_page mode="feed" count="4"].', 'community-business-directory' ) . '</p>';

		echo '<details style="margin-top:1em;" open><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Which mode should I use?', 'community-business-directory' ) . '</summary>';
		echo '<ul style="margin-top:.8em;line-height:1.7;list-style:disc;padding-left:1.4em;">';
		echo '<li>' . esc_html__( 'Embed — works right away with just the Page URL, but Facebook limits its width to 500px.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Third-party embed — paste a widget snippet from Behold, SnapWidget or Curator for a full-width feed. Someone with access to the Page connects it on that service first.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Feed — native theme-styled cards, but needs a Meta app with pages_read_engagement (see below).', 'community-business-directory' ) . '</li>';
		echo '</ul>';
		echo '<p><a href="https://snapwidget.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'SnapWidget', 'community-business-directory' ) . '</a> &middot; <a href="https://curator.io" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Curator.io', 'community-business-directory' ) . '</a></p>';
		echo '</details>';

		echo '<details style="margin-top:1em;"><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'How do I get a Page Access Token? (Feed mode)', 'community-business-directory' ) . '</summary>';
		echo '<ol style="margin-top:.8em;line-height:1.7;">';
		echo '<li>' . esc_html__( 'Create a Facebook App at developers.facebook.com and add the “Facebook Login” product.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'In Graph API Explorer, select your App and your Page, and grant the pages_read_engagement and pages_show_list permissions.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Generate a Page Access Token, then exchange it for a long-lived token so it does not expire.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the Page ID and long-lived token above and click Save, then Refresh posts now.', 'community-business-directory' ) . '</li>';
		echo '</ol>';
		echo '<p><a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Graph API Explorer', 'community-business-directory' ) . '</a>';
		echo ' &middot; <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Your Facebook Apps', 'community-business-directory' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'No token? Leave Feed mode off — Embed mode shows your Page with just the URL above.', 'community-business-directory' ) . '</p>';
		echo '</details></div>';

		echo '</div>';
	}

	// ── AJAX: Facebook feed refresh ───────────────────────────────

	/** wp_ajax_cbd_fb_refresh — force a fresh Graph API pull and report the count. */
	public function ajax_fb_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ], 403 );
		}
		check_ajax_referer( 'cbd_facebook', 'nonce' );

		$result = \CBD\Modules\FacebookSync::refresh();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( $result );
	}


	// ── PAGE: Instagram feed sync ─────────────────────────────────

	public function page_instagram(): void {
		$ig = '\CBD\Modules\InstagramSync';

		// Save settings.
		if ( isset( $_POST['cbd_save_instagram'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_instagram_settings' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce checked above; InstagramSync::save() sanitises each field.
			$ig::save( wp_unslash( $_POST ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instagram settings saved.', 'community-business-directory' ) . '</p></div>';
		}

		$status   = $ig::status();
		$cur_mode = (string) get_option( $ig::OPT_MODE, 'auto' );
		$eff_mode = $ig::resolve_mode();
		$cur_lay  = $ig::layout();
		$has_own  = trim( (string) get_option( $ig::OPT_TOKEN, '' ) ) !== '';
		$fb_token = class_exists( '\CBD\Modules\FacebookSync' ) && \CBD\Modules\FacebookSync::token() !== '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Instagram', 'community-business-directory' ) . '</h1>';
		echo '<p>' . esc_html__( 'Show your Instagram posts on the site with the [cbd_instagram_feed] shortcode or the “Instagram Feed” block. Instagram no longer offers a token-free feed, so the simplest route is Third-party embed mode — paste a widget snippet from a service like Behold (free) or SnapWidget. Feed mode reads the Graph API directly but needs a Business account, a linked Facebook Page and Meta app access. Without either, a “Follow on Instagram” card is shown.', 'community-business-directory' ) . '</p>';

		// Status summary.
		$mode_labels = [
			'feed'    => __( 'Feed (synced posts)', 'community-business-directory' ),
			'code'    => __( 'Third-party embed', 'community-business-directory' ),
			'profile' => __( 'Profile card (follow CTA)', 'community-business-directory' ),
		];
		echo '<table class="wp-list-table widefat fixed striped" style="max-width:520px;margin:1em 0;"><tbody>';
		echo '<tr><td><strong>' . esc_html__( 'Active mode', 'community-business-directory' ) . '</strong></td><td>' . esc_html( $mode_labels[ $eff_mode ] ?? $eff_mode ) . '</td></tr>';
		if ( 'feed' === $eff_mode ) {
			echo '<tr><td><strong>' . esc_html__( 'Feed credentials', 'community-business-directory' ) . '</strong></td><td>' . ( $status['configured'] ? esc_html__( 'Connected', 'community-business-directory' ) : esc_html__( 'Not set (profile card only)', 'community-business-directory' ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( 'Synced posts', 'community-business-directory' ) . '</strong></td><td>' . esc_html( number_format( (int) $status['cached'] ) ) . '</td></tr>';
			echo '<tr><td><strong>' . esc_html__( 'Last sync', 'community-business-directory' ) . '</strong></td><td>' . esc_html( $status['last_fetch'] ?: __( 'never', 'community-business-directory' ) ) . '</td></tr>';
			if ( $status['last_error'] ) {
				echo '<tr><td><strong>' . esc_html__( 'Last error', 'community-business-directory' ) . '</strong></td><td style="color:#b32d2e;">' . esc_html( $status['last_error'] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		// Refresh (feed) control. The result box always renders so the "Detect"
		// button in the advanced section can report inline even before setup.
		echo '<div class="cbd-demo-control" id="cbd-ig-control">';
		if ( 'feed' === $eff_mode && $status['configured'] ) {
			echo '<p><button type="button" class="button button-primary" id="cbd-ig-refresh">' . esc_html__( 'Refresh posts now', 'community-business-directory' ) . '</button> ';
			echo '<span class="spinner" id="cbd-ig-spinner" style="float:none;margin:0;"></span></p>';
		}
		echo '<div class="cbd-demo-result notice inline" id="cbd-ig-result" hidden></div>';
		echo '</div>';

		// Settings form.
		echo '<form method="post" style="margin-top:1.5em;max-width:760px;">';
		wp_nonce_field( 'cbd_instagram_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="cbd-ig-username">' . esc_html__( 'Instagram username', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="text" id="cbd-ig-username" name="cbd_ig_username" class="regular-text" placeholder="yourhandle" value="' . esc_attr( (string) get_option( $ig::OPT_USERNAME, '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'The @handle, used for the profile card and “View on Instagram” links.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-mode">' . esc_html__( 'Display mode', 'community-business-directory' ) . '</label></th><td>';
		echo '<select id="cbd-ig-mode" name="cbd_ig_mode">';
		foreach ( [
			'auto'    => __( 'Auto (Feed, then embed code, then Profile card)', 'community-business-directory' ),
			'feed'    => __( 'Feed — synced native posts (needs Graph API)', 'community-business-directory' ),
			'code'    => __( 'Third-party embed — paste widget code (full width)', 'community-business-directory' ),
			'profile' => __( 'Profile card — follow button only', 'community-business-directory' ),
		] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $cur_mode, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		// Third-party embed snippet (Behold / SnapWidget / Curator / Elfsight …).
		echo '<tr><th scope="row"><label for="cbd-ig-embed">' . esc_html__( 'Third-party embed code', 'community-business-directory' ) . '</label></th><td>';
		echo '<textarea id="cbd-ig-embed" name="cbd_ig_embed_html" rows="4" class="large-text code" spellcheck="false" placeholder="&lt;script src=&quot;https://…&quot;&gt;&lt;/script&gt;">' . esc_textarea( (string) get_option( $ig::OPT_EMBED, '' ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Paste the full widget snippet from Behold, SnapWidget, Curator, Elfsight, LightWidget, etc. Used when Display mode is “Third-party embed” (or Auto with no live feed). Renders full-width — the recommended way to show Instagram without your own Meta app.', 'community-business-directory' ) . '</p>';
		if ( ! current_user_can( 'unfiltered_html' ) && trim( (string) get_option( $ig::OPT_EMBED, '' ) ) !== '' ) {
			echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Your account is not allowed to save raw HTML/scripts, so part of the snippet may have been stripped. Ask a full site administrator to paste it.', 'community-business-directory' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-count">' . esc_html__( 'Posts to show', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="number" id="cbd-ig-count" name="cbd_ig_count" min="1" max="50" value="' . esc_attr( (string) $ig::count() ) . '" class="small-text"></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-layout">' . esc_html__( 'Layout', 'community-business-directory' ) . '</label></th><td>';
		echo '<select id="cbd-ig-layout" name="cbd_ig_layout">';
		echo '<option value="grid"' . selected( $cur_lay, 'grid', false ) . '>' . esc_html__( 'Grid (tiles)', 'community-business-directory' ) . '</option>';
		echo '<option value="list"' . selected( $cur_lay, 'list', false ) . '>' . esc_html__( 'List (cards)', 'community-business-directory' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-columns">' . esc_html__( 'Grid columns', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="number" id="cbd-ig-columns" name="cbd_ig_columns" min="2" max="6" value="' . esc_attr( (string) $ig::columns() ) . '" class="small-text"></td></tr>';

		echo '<tr><td colspan="2"><hr><p style="margin:.6em 0 0;font-weight:600;">' . esc_html__( 'Live feed connection (Instagram Graph API)', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-id">' . esc_html__( 'Instagram account ID', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="text" id="cbd-ig-id" name="cbd_ig_user_id" class="regular-text" value="' . esc_attr( (string) get_option( $ig::OPT_USER_ID, '' ) ) . '"> ';
		echo '<button type="button" class="button" id="cbd-ig-detect">' . esc_html__( 'Detect from Facebook Page', 'community-business-directory' ) . '</button> ';
		echo '<span class="spinner" id="cbd-ig-detect-spinner" style="float:none;margin:0;"></span>';
		echo '<p class="description">' . esc_html__( 'The numeric Instagram Business Account ID. Use “Detect” to look it up from the Facebook Page you connected under Community Directory → Facebook.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-token">' . esc_html__( 'Access Token', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="text" id="cbd-ig-token" name="cbd_ig_access_token" class="large-text code" autocomplete="off" value="' . esc_attr( (string) get_option( $ig::OPT_TOKEN, '' ) ) . '">';
		if ( ! $has_own && $fb_token ) {
			echo '<p class="description" style="color:#1b7a4b;">' . esc_html__( 'Currently reusing your Facebook Page token. Leave blank to keep using it, or paste a dedicated Instagram token here.', 'community-business-directory' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'A Page Access Token with instagram_basic + pages_show_list. Leave blank to reuse your Facebook Page token.', 'community-business-directory' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-profile">' . esc_html__( 'Profile URL (optional)', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="url" id="cbd-ig-profile" name="cbd_ig_profile_url" class="regular-text" placeholder="https://www.instagram.com/yourhandle/" value="' . esc_attr( (string) get_option( $ig::OPT_PROFILE_URL, '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Defaults to instagram.com/your-username when left blank.', 'community-business-directory' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="cbd-ig-ttl">' . esc_html__( 'Cache lifetime (minutes)', 'community-business-directory' ) . '</label></th><td>';
		echo '<input type="number" id="cbd-ig-ttl" name="cbd_ig_cache_ttl" min="5" max="1440" value="' . esc_attr( (string) get_option( $ig::OPT_TTL, 30 ) ) . '" class="small-text"></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" name="cbd_save_instagram" value="1" class="button button-primary">' . esc_html__( 'Save Settings', 'community-business-directory' ) . '</button></p>';
		echo '</form>';

		// Usage + token help.
		echo '<div class="cbd-ig-help" style="max-width:760px;margin-top:1em;">';
		echo '<h2>' . esc_html__( 'Add it to a page', 'community-business-directory' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste this shortcode into any page or widget, or add the “Instagram Feed” block:', 'community-business-directory' ) . '</p>';
		echo '<p><code>[cbd_instagram_feed]</code></p>';
		echo '<p class="description">' . esc_html__( 'Override per instance, e.g. [cbd_instagram_feed mode="code"], [cbd_instagram_feed layout="grid" columns="4" count="8"], or [cbd_instagram_feed mode="profile" username="yourhandle"].', 'community-business-directory' ) . '</p>';

		echo '<details style="margin-top:1em;" open><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'How do I get a full-width Instagram feed? (recommended)', 'community-business-directory' ) . '</summary>';
		echo '<ol style="margin-top:.8em;line-height:1.7;">';
		echo '<li>' . esc_html__( 'Sign up for a free feed service — Behold (behold.so) or SnapWidget (snapwidget.com). This must be done by someone with full access to the Instagram account.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Connect the Instagram account there and copy the embed snippet it gives you.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste it into “Third-party embed code” above, set Display mode to “Third-party embed”, and Save.', 'community-business-directory' ) . '</li>';
		echo '</ol>';
		echo '<p><a href="https://behold.so" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Behold — free Instagram feeds', 'community-business-directory' ) . '</a> &middot; <a href="https://snapwidget.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'SnapWidget', 'community-business-directory' ) . '</a></p>';
		echo '</details>';

		echo '<details style="margin-top:1em;"><summary style="cursor:pointer;font-weight:600;">' . esc_html__( 'Native feed via the Instagram Graph API (advanced)', 'community-business-directory' ) . '</summary>';
		echo '<ol style="margin-top:.8em;line-height:1.7;">';
		echo '<li>' . esc_html__( 'Convert the Instagram account to a Business or Creator profile and link it to a Facebook Page (in Meta Business settings).', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Set up the Facebook feature first (Community Directory → Facebook) with a Page Access Token that also grants instagram_basic and pages_show_list — this needs a Meta app with those permissions.', 'community-business-directory' ) . '</li>';
		echo '<li>' . esc_html__( 'Come back here, click “Detect from Facebook Page”, then Save and “Refresh posts now”.', 'community-business-directory' ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'No feed connection at all? The shortcode shows a “Follow on Instagram” card using just the username above.', 'community-business-directory' ) . '</p>';
		echo '</details></div>';

		echo '</div>';
	}

	// ── AJAX: Instagram feed refresh + account detect ─────────────

	/** wp_ajax_cbd_ig_refresh — force a fresh Graph API pull and report the count. */
	public function ajax_ig_refresh(): void {
		$this->verify_instagram_ajax();
		$result = \CBD\Modules\InstagramSync::refresh();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( $result );
	}

	/** wp_ajax_cbd_ig_discover — resolve the IG account ID from the linked FB Page. */
	public function ajax_ig_discover(): void {
		$this->verify_instagram_ajax();
		$result = \CBD\Modules\InstagramSync::discover();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
		wp_send_json_success( $result );
	}

	private function verify_instagram_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ], 403 );
		}
		check_ajax_referer( 'cbd_instagram', 'nonce' );
	}

	// ── PAGE: Team (dashboard-access invitations) ─────────────────

	/**
	 * Invite colleagues into wp-admin by email. Form posts + action links are
	 * processed early in handle_team_actions(); this only renders.
	 */
	public function page_team(): void {
		$ti = '\CBD\Admin\TeamInvite';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Team', 'community-business-directory' ) . '</h1>';
		$this->show_notice();
		echo '<p>' . esc_html__( 'Invite a colleague to help manage this website. They get an email invitation, and on accepting it are given an Administrator account and taken straight to the front-end “Social Feeds” page (/socmed) where they can connect Facebook and Instagram themselves. The full WordPress dashboard stays available to them as well.', 'community-business-directory' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Only invite people you trust with full site access. Revoke it here at any time.', 'community-business-directory' ) . '</p>';

		if ( ! class_exists( $ti ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The invitation module is unavailable on this install.', 'community-business-directory' ) . '</p></div></div>';
			return;
		}

		// Invite form.
		echo '<h2>' . esc_html__( 'Invite someone', 'community-business-directory' ) . '</h2>';
		echo '<form method="post" style="margin:1em 0;max-width:640px;">';
		wp_nonce_field( 'cbd_team_invite' );
		echo '<input type="email" name="cbd_team_email" class="regular-text" placeholder="name@example.com" required> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Send Invitation', 'community-business-directory' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Access level: Administrator — full site access. The link is valid for 7 days.', 'community-business-directory' ) . '</p>';
		echo '</form>';

		// Pending invitations.
		$pending = \CBD\Admin\TeamInvite::all();
		echo '<h2>' . esc_html__( 'Pending invitations', 'community-business-directory' ) . '</h2>';
		if ( ! $pending ) {
			echo '<p class="description">' . esc_html__( 'No pending invitations.', 'community-business-directory' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped" style="max-width:720px;"><thead><tr>';
			echo '<th>' . esc_html__( 'Email', 'community-business-directory' ) . '</th><th>' . esc_html__( 'Sent', 'community-business-directory' ) . '</th><th>' . esc_html__( 'Invited by', 'community-business-directory' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			foreach ( $pending as $row ) {
				$email    = (string) ( $row['email'] ?? '' );
				$inviter  = get_userdata( (int) ( $row['inviter'] ?? 0 ) );
				$cancel   = wp_nonce_url(
					add_query_arg(
						[ 'page' => 'cbd-team', 'cbd_team_action' => 'cancel', 'email' => rawurlencode( $email ) ],
						admin_url( 'admin.php' )
					),
					'cbd_team_cancel'
				);
				echo '<tr>';
				echo '<td>' . esc_html( $email ) . '</td>';
				echo '<td>' . esc_html( $row['created'] ? human_time_diff( (int) $row['created'] ) . ' ' . __( 'ago', 'community-business-directory' ) : '—' ) . '</td>';
				echo '<td>' . esc_html( $inviter ? $inviter->display_name : '—' ) . '</td>';
				echo '<td><a href="' . esc_url( $cancel ) . '" class="button button-small">' . esc_html__( 'Cancel', 'community-business-directory' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// Active team members.
		$members = \CBD\Admin\TeamInvite::members();
		echo '<h2 style="margin-top:1.6em;">' . esc_html__( 'Team members', 'community-business-directory' ) . '</h2>';
		if ( ! $members ) {
			echo '<p class="description">' . esc_html__( 'No invited team members yet.', 'community-business-directory' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped" style="max-width:720px;"><thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'community-business-directory' ) . '</th><th>' . esc_html__( 'Email', 'community-business-directory' ) . '</th><th>' . esc_html__( 'Role', 'community-business-directory' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';
			$role_names = wp_roles()->role_names;
			foreach ( $members as $m ) {
				$revoke = wp_nonce_url(
					add_query_arg(
						[ 'page' => 'cbd-team', 'cbd_team_action' => 'revoke', 'user' => (int) $m->ID ],
						admin_url( 'admin.php' )
					),
					'cbd_team_revoke'
				);
				$roles = array_map(
					static fn( $r ) => translate_user_role( $role_names[ $r ] ?? ucfirst( $r ) ),
					(array) $m->roles
				);
				echo '<tr>';
				echo '<td>' . esc_html( $m->display_name ) . '</td>';
				echo '<td>' . esc_html( $m->user_email ) . '</td>';
				echo '<td>' . esc_html( implode( ', ', $roles ) ) . '</td>';
				echo '<td><a href="' . esc_url( $revoke ) . '" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Revoke this person’s dashboard access?', 'community-business-directory' ) ) . '\');">' . esc_html__( 'Revoke access', 'community-business-directory' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}


	// ── Jobs screen: standalone demo jobs ─────────────────────────

	/**
	 * Render the "Demo Jobs" control panel above the native Jobs (cbd_job) list
	 * table. Hooked to admin_notices; only prints on that screen for admins.
	 */
	public function render_jobs_demo_panel(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-cbd_job' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$count = \CBD\Modules\DemoData::count_demo_jobs();

		echo '<div class="cbd-demo-control" id="cbd-jobs-demo" style="max-width:none;">';
		echo '<p style="margin:0 0 4px;font-size:14px;"><strong>' . esc_html__( 'Demo Jobs', 'community-business-directory' ) . '</strong></p>';
		echo '<p style="margin:0 0 12px;color:#50575e;">' . esc_html__( 'Populate this list with sample Inverness vacancies to preview the Jobs page — separate from full Demo Mode. Removing only ever deletes demo jobs, never real ones.', 'community-business-directory' ) . '</p>';
		echo '<p id="cbd-jobs-demo-count" style="margin:0 0 12px;">' . esc_html( sprintf(
			/* translators: %d: number of demo jobs currently loaded */
			_n( '%d demo job currently loaded.', '%d demo jobs currently loaded.', $count, 'community-business-directory' ),
			$count
		) ) . '</p>';
		echo '<p style="margin:0;">';
		echo '<button type="button" class="button button-primary" id="cbd-jobs-demo-generate"' . disabled( $count > 0, true, false ) . '>' . esc_html__( 'Generate demo jobs', 'community-business-directory' ) . '</button> ';
		echo '<button type="button" class="button" id="cbd-jobs-demo-remove"' . disabled( 0 === $count, true, false ) . '>' . esc_html__( 'Remove demo jobs', 'community-business-directory' ) . '</button> ';
		echo '<span class="spinner" id="cbd-jobs-demo-spinner" style="float:none;margin:0;vertical-align:middle;"></span>';
		echo '</p>';
		echo '<div class="cbd-demo-result notice inline" id="cbd-jobs-demo-result" hidden></div>';
		echo '</div>';
	}

	/** wp_ajax_cbd_jobs_demo_generate — create the standalone demo job set. */
	public function ajax_jobs_demo_generate(): void {
		$this->verify_jobs_demo_ajax();
		$r = \CBD\Modules\DemoData::generate_demo_jobs();
		if ( 0 === $r['created'] && $r['existed'] > 0 ) {
			wp_send_json_error( [ 'message' => __( 'Demo jobs already exist. Remove them first.', 'community-business-directory' ) ] );
		}
		wp_send_json_success( [
			'created' => $r['created'],
			'message' => sprintf(
				/* translators: %d: number of demo jobs created */
				_n( 'Created %d demo job.', 'Created %d demo jobs.', $r['created'], 'community-business-directory' ),
				$r['created']
			),
		] );
	}

	/** wp_ajax_cbd_jobs_demo_remove — delete every demo job. */
	public function ajax_jobs_demo_remove(): void {
		$this->verify_jobs_demo_ajax();
		$n = \CBD\Modules\DemoData::remove_demo_jobs();
		wp_send_json_success( [
			'removed' => $n,
			'message' => sprintf(
				/* translators: %d: number of demo jobs removed */
				_n( 'Removed %d demo job.', 'Removed %d demo jobs.', $n, 'community-business-directory' ),
				$n
			),
		] );
	}

	private function verify_jobs_demo_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ], 403 );
		}
		check_ajax_referer( 'cbd_jobs_demo', 'nonce' );
	}


	// ── PAGE: Plans ───────────────────────────────────────────────

	public function page_plans(): void {
		global $wpdb;
		$this->show_notice();

		// Handle plan save
		if ( isset( $_POST['cbd_save_plan'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cbd_save_plan' ) ) {
			$slug = sanitize_key( $_POST['plan_slug'] ?? '' );
			if ( $slug ) {
				$data = [
					'name'          => sanitize_text_field( $_POST['plan_name']         ?? '' ),
					'description'   => sanitize_textarea_field( $_POST['plan_desc']     ?? '' ),
					'price_monthly' => (float) ( $_POST['plan_price_monthly']           ?? 0 ),
					'price_annual'  => (float) ( $_POST['plan_price_annual']            ?? 0 ),
					'image_limit'   => (int)   ( $_POST['plan_image_limit']             ?? 5 ),
					'event_limit'   => (int)   ( $_POST['plan_event_limit']             ?? 0 ),
					'promo_limit'   => (int)   ( $_POST['plan_promo_limit']             ?? 0 ),
					'is_featured'   => ! empty( $_POST['plan_is_featured'] )    ? 1 : 0,
					'can_promote'   => ! empty( $_POST['plan_can_promote'] )    ? 1 : 0,
					'show_analytics'=> ! empty( $_POST['plan_analytics'] )      ? 1 : 0,
					'stripe_price_id' => sanitize_text_field( $_POST['stripe_price_id'] ?? '' ),
					'is_active'     => ! empty( $_POST['plan_active'] )         ? 1 : 0,
					'sort_order'    => (int)   ( $_POST['plan_sort_order']               ?? 0 ),
				];
				$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cbd_membership_plans WHERE slug=%s", $slug ) );
				if ( $exists ) {
					$wpdb->update( $wpdb->prefix . 'cbd_membership_plans', $data, [ 'slug' => $slug ] );
				} else {
					$wpdb->insert( $wpdb->prefix . 'cbd_membership_plans', array_merge( $data, [ 'slug' => $slug ] ) );
				}
				echo '<div class="notice notice-success is-dismissible"><p><strong>Community Directory:</strong> Plan saved.</p></div>';
			}
		}

		$plans   = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cbd_membership_plans ORDER BY sort_order ASC" );
		$sym     = get_option( 'cbd_currency_symbol', '£' );
		$editing = sanitize_key( $_GET['edit_plan'] ?? '' );
		$edit    = $editing ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_membership_plans WHERE slug=%s", $editing ) ) : null;

		$seed_url = wp_nonce_url( add_query_arg( 'cbd_action', 'seed_plans', admin_url( 'admin.php?page=cbd-plans' ) ), 'cbd_seed_plans' );

		// Handle seed
		if ( isset( $_GET['cbd_action'] ) && $_GET['cbd_action'] === 'seed_plans'
			&& isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cbd_seed_plans' ) ) {
			\CBD\Core\Activator::seed_plans_public();
			echo '<div class="notice notice-success"><p><strong>Community Directory:</strong> Default plans seeded.</p></div>';
			$plans = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}cbd_membership_plans ORDER BY sort_order ASC" );
		}

		echo '<div class="wrap cbd-admin">';
		echo '<h1>Membership Plans <a href="' . esc_url( $seed_url ) . '" class="page-title-action">Reseed Defaults</a></h1>';
		echo '<p>Configure your membership plans. Use <code>[cbd_plans]</code> to display them on any page.</p>';

		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">';

		// Plan list
		echo '<div>';
		echo '<h2>Current Plans</h2>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Plan</th><th>Monthly</th><th>Annual</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		if ( $plans ) {
			foreach ( $plans as $plan ) {
				$monthly = (float) $plan->price_monthly;
				$annual  = (float) $plan->price_annual;
				echo '<tr>';
				echo '<td><strong>' . esc_html( $plan->name ) . '</strong> <code style="font-size:10px;">' . esc_html( $plan->slug ) . '</code></td>';
				echo '<td>' . ( $monthly > 0 ? esc_html( $sym . number_format( $monthly, 2 ) ) : '<em>Free</em>' ) . '</td>';
				echo '<td>' . ( $annual  > 0 ? esc_html( $sym . number_format( $annual,  2 ) ) : '—' ) . '</td>';
				echo '<td><span style="background:' . ( $plan->is_active ? '#2b6344' : '#888' ) . ';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;">' . ( $plan->is_active ? 'Active' : 'Inactive' ) . '</span></td>';
				echo '<td><a href="' . esc_url( add_query_arg( 'edit_plan', $plan->slug, admin_url( 'admin.php?page=cbd-plans' ) ) ) . '" class="button button-small">Edit</a></td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="5" style="text-align:center;padding:20px;color:#888;">No plans. <a href="' . esc_url( $seed_url ) . '">Seed defaults</a>.</td></tr>';
		}
		echo '</tbody></table>';

		// Business count per plan
		$plan_counts = $wpdb->get_results( "SELECT plan, COUNT(*) as cnt FROM {$wpdb->prefix}cbd_businesses WHERE status='active' GROUP BY plan" );
		if ( $plan_counts ) {
			echo '<h3 style="margin-top:20px;">Active Businesses by Plan</h3>';
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Plan</th><th>Businesses</th></tr></thead><tbody>';
			foreach ( $plan_counts as $pc ) {
				echo '<tr><td>' . esc_html( ucfirst( $pc->plan ) ) . '</td><td>' . esc_html( $pc->cnt ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		// Edit/Add form
		echo '<div>';
		echo '<h2>' . ( $edit ? 'Edit Plan: ' . esc_html( $edit->name ) : 'Add New Plan' ) . '</h2>';
		echo '<form method="post" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">';
		echo wp_nonce_field( 'cbd_save_plan', '_wpnonce', true, false );
		echo '<table class="form-table" style="margin:0;">';
		$v = fn( string $field, string $default = '' ) => $edit ? ( $edit->$field ?? $default ) : $default;
		$this->settings_row( 'Slug <span style="color:red">*</span>', '<input type="text" name="plan_slug" value="' . esc_attr( $v( 'slug' ) ) . '" required' . ( $edit ? ' readonly style="background:#f8f8f6;"' : '' ) . ' placeholder="e.g. pro">' );
		$this->settings_row( 'Name <span style="color:red">*</span>', '<input type="text" name="plan_name" value="' . esc_attr( $v( 'name' ) ) . '" required>' );
		$this->settings_row( 'Description', '<textarea name="plan_desc" rows="2" class="large-text">' . esc_textarea( $v( 'description' ) ) . '</textarea>' );
		$this->settings_row( 'Monthly Price (' . esc_html( $sym ) . ')', '<input type="number" name="plan_price_monthly" value="' . esc_attr( $v( 'price_monthly', '0' ) ) . '" min="0" step="0.01" style="width:100px;">' );
		$this->settings_row( 'Annual Price ('  . esc_html( $sym ) . ')', '<input type="number" name="plan_price_annual"  value="' . esc_attr( $v( 'price_annual',  '0' ) ) . '" min="0" step="0.01" style="width:100px;"> <small>0 = not available</small>' );
		$this->settings_row( 'Photo Limit', '<input type="number" name="plan_image_limit" value="' . esc_attr( $v( 'image_limit', '5' ) ) . '" min="0" style="width:80px;"> <small>0 = unlimited</small>' );
		$this->settings_row( 'Event Limit/month', '<input type="number" name="plan_event_limit" value="' . esc_attr( $v( 'event_limit', '0' ) ) . '" min="0" style="width:80px;"> <small>0 = unlimited</small>' );
		$this->settings_row( 'Promo Limit', '<input type="number" name="plan_promo_limit" value="' . esc_attr( $v( 'promo_limit', '0' ) ) . '" min="0" style="width:80px;"> <small>0 = unlimited</small>' );
		$this->settings_row( 'Sort Order', '<input type="number" name="plan_sort_order" value="' . esc_attr( $v( 'sort_order', '0' ) ) . '" style="width:80px;">' );
		$this->settings_row( 'Stripe Price ID', '<input type="text" name="stripe_price_id" value="' . esc_attr( $v( 'stripe_price_id' ) ) . '" class="regular-text" placeholder="price_...">' );
		$this->settings_row( 'Options', '
			<label><input type="checkbox" name="plan_is_featured" value="1"' . checked( $v( 'is_featured' ), '1', false ) . '> Featured listing badge</label><br>
			<label><input type="checkbox" name="plan_can_promote" value="1"' . checked( $v( 'can_promote' ), '1', false ) . '> Can create promotions</label><br>
			<label><input type="checkbox" name="plan_analytics"   value="1"' . checked( $v( 'show_analytics' ), '1', false ) . '> Full analytics access</label><br>
			<label><input type="checkbox" name="plan_active"      value="1"' . ( $v( 'is_active', '1' ) ? ' checked' : '' ) . '> Plan is active</label>
		' );
		echo '</table>';
		echo '<p style="margin-top:12px;"><button type="submit" name="cbd_save_plan" class="button button-primary">' . ( $edit ? 'Update Plan' : 'Add Plan' ) . '</button>';
		if ( $edit ) { echo ' <a href="' . esc_url( admin_url( 'admin.php?page=cbd-plans' ) ) . '" class="button">Cancel</a>'; }
		echo '</p></form></div>';
		echo '</div></div>';
	}

	// ── PAGE: Reports ─────────────────────────────────────────────

	public function page_reports(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		echo '<div class="wrap cbd-admin"><h1>Reports</h1>';

		// Summary cards
		$total_biz     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_businesses WHERE status='active'" );
		$total_events  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_events" );
		$total_promos  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_promotions" );
		$total_reviews = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_reviews WHERE status='approved'" );
		$total_views   = (int) $wpdb->get_var( "SELECT SUM(views) FROM {$p}cbd_analytics" );
		$total_follows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cbd_follows" );

		echo '<div class="cbd-admin-stats">';
		foreach ( [
			[ 'Active Businesses', $total_biz,     '#2b6344' ],
			[ 'Total Events',      $total_events,  '#1e6b7a' ],
			[ 'Active Promos',     $total_promos,  '#c47b1a' ],
			[ 'Total Reviews',     $total_reviews, '#6b2ba8' ],
			[ 'Total Views',       $total_views,   '#2b6387' ],
			[ 'Total Follows',     $total_follows, '#b84a35' ],
		] as [ $label, $val, $color ] ) {
			echo '<div class="cbd-admin-stat" style="border-top-color:' . esc_attr( $color ) . ';">'
				. '<span class="cbd-stat-num" style="color:' . esc_attr( $color ) . ';">' . esc_html( number_format( (int) $val ) ) . '</span>'
				. '<span class="cbd-stat-label">' . esc_html( $label ) . '</span></div>';
		}
		echo '</div>';

		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;">';

		// Businesses by category
		$by_cat = $wpdb->get_results(
			"SELECT t.name, COUNT(tr.object_id) as cnt
			 FROM {$wpdb->term_taxonomy} tt
			 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->posts} po ON po.ID = tr.object_id
			 WHERE tt.taxonomy = 'cbd_category' AND po.post_type = 'cbd_business' AND po.post_status = 'publish'
			 GROUP BY t.term_id ORDER BY cnt DESC LIMIT 10"
		);
		echo '<div class="cbd-admin-info"><h2>Businesses by Category</h2>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Category</th><th>Count</th><th>% of Total</th></tr></thead><tbody>';
		foreach ( $by_cat as $row ) {
			$pct = $total_biz > 0 ? round( ( $row->cnt / $total_biz ) * 100, 1 ) : 0;
			echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->cnt ) . '</td>'
				. '<td><div style="background:#eee;border-radius:4px;overflow:hidden;"><div style="width:' . esc_attr( $pct ) . '%;background:#2b6344;height:14px;border-radius:4px;"></div></div> ' . esc_html( $pct ) . '%</td></tr>';
		}
		echo '</tbody></table></div>';

		// Businesses by plan
		$by_plan = $wpdb->get_results( "SELECT plan, COUNT(*) as cnt FROM {$p}cbd_businesses WHERE status='active' GROUP BY plan ORDER BY cnt DESC" );
		echo '<div class="cbd-admin-info"><h2>Businesses by Plan</h2>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Plan</th><th>Businesses</th></tr></thead><tbody>';
		foreach ( $by_plan as $row ) {
			echo '<tr><td>' . esc_html( ucfirst( $row->plan ) ) . '</td><td>' . esc_html( $row->cnt ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Top businesses by views
		echo '<h2 style="margin-top:20px;">Top Businesses by Views</h2>';
		$top = $wpdb->get_results(
			"SELECT b.post_id, p.post_title, b.view_count, b.follower_count, b.rating_avg, b.review_count
			 FROM {$p}cbd_businesses b
			 INNER JOIN {$wpdb->posts} p ON p.ID = b.post_id
			 WHERE b.status = 'active'
			 ORDER BY b.view_count DESC LIMIT 10"
		);
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Business</th><th>Views</th><th>Followers</th><th>Rating</th></tr></thead><tbody>';
		foreach ( $top as $row ) {
			echo '<tr><td><a href="' . esc_url( get_permalink( $row->post_id ) ) . '" target="_blank">' . esc_html( $row->post_title ) . '</a></td>'
				. '<td>' . esc_html( number_format( (int) $row->view_count ) ) . '</td>'
				. '<td>' . esc_html( number_format( (int) $row->follower_count ) ) . '</td>'
				. '<td>' . ( $row->rating_avg > 0 ? esc_html( number_format( (float) $row->rating_avg, 1 ) ) . ' ★' : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		// Recent activity — last 30 days
		echo '<div class="cbd-admin-info"><h2>Activity — Last 30 Days</h2>';
		$daily = $wpdb->get_results(
			"SELECT DATE(created_at) as day, COUNT(*) as cnt
			 FROM {$p}cbd_businesses
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY DATE(created_at) ORDER BY day ASC"
		);
		if ( $daily ) {
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Date</th><th>New Businesses</th></tr></thead><tbody>';
			foreach ( $daily as $d ) {
				echo '<tr><td>' . esc_html( gmdate( 'D j M', strtotime( $d->day ) ) ) . '</td><td>' . esc_html( $d->cnt ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p style="color:#888;">No new businesses in the last 30 days.</p>';
		}
		echo '</div></div></div>';
	}

	// ── PAGE: Diagnostics ─────────────────────────────────────────

	public function page_diagnostics(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		echo '<div class="wrap cbd-admin"><h1>Diagnostics</h1>';

		// ── Email delivery self-test ──────────────────────────────────
		// Sends through the exact same path as verification + delegate-invite
		// emails (EmailVerification::send → wp_mail), capturing any failure so
		// "emails aren't arriving" can be diagnosed as code vs. transport.
		$mail_result = null;
		if ( isset( $_POST['cbd_test_email'] ) && check_admin_referer( 'cbd_test_email' ) ) {
			$to  = sanitize_email( wp_unslash( $_POST['cbd_test_to'] ?? '' ) );
			$to  = $to && is_email( $to ) ? $to : (string) get_option( 'admin_email' );
			$err = '';
			$listener = static function ( $wp_error ) use ( &$err ) {
				$err = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '';
			};
			add_action( 'wp_mail_failed', $listener );
			$ok = \CBD\Frontend\EmailVerification::send(
				$to,
				sprintf( '[%s] Test email', get_bloginfo( 'name' ) ),
				'<p>This is a test email from the Community Business Directory plugin. If you received it, <code>wp_mail()</code> delivery is working.</p>',
				'This is a test email from the Community Business Directory plugin. If you received it, wp_mail() delivery is working.'
			);
			remove_action( 'wp_mail_failed', $listener );
			$mail_result = [ 'ok' => (bool) $ok, 'to' => $to, 'err' => $err ];
		}

		$checks = [];
		$checks[] = [ version_compare( PHP_VERSION, '7.4', '>=' ) ? 'pass' : 'fail', 'PHP Version', PHP_VERSION, 'Requires 7.4+' ];
		$checks[] = [ version_compare( get_bloginfo( 'version' ), '6.0', '>=' ) ? 'pass' : 'warn', 'WordPress Version', get_bloginfo( 'version' ), 'Requires 6.0+' ];

		foreach ( [ 'cbd_businesses', 'cbd_events', 'cbd_promotions', 'cbd_reviews', 'cbd_follows', 'cbd_favorites', 'cbd_analytics', 'cbd_membership_plans', 'cbd_reactions' ] as $tbl ) {
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$p}{$tbl}'" ) === "{$p}{$tbl}";
			$checks[] = [ $exists ? 'pass' : 'fail', "DB: {$tbl}", $exists ? 'EXISTS' : 'MISSING — deactivate and reactivate plugin', '' ];
		}

		foreach ( [ 'cbd_business', 'cbd_event', 'cbd_promotion', 'cbd_review' ] as $cpt ) {
			$checks[] = [ post_type_exists( $cpt ) ? 'pass' : 'fail', "CPT: {$cpt}", post_type_exists( $cpt ) ? 'Registered' : 'NOT registered', '' ];
		}

		global $shortcode_tags;
		foreach ( [ 'cbd_directory','cbd_register_business','cbd_events','cbd_promotions','cbd_reviews','cbd_calendar','cbd_business_feed','cbd_search','cbd_business_map','cbd_activity_feed' ] as $sc ) {
			$checks[] = [ isset( $shortcode_tags[$sc] ) ? 'pass' : 'fail', "[{$sc}]", isset( $shortcode_tags[$sc] ) ? 'Registered' : 'NOT registered', '' ];
		}

		$cat_count = wp_count_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false ] );
		$checks[] = [ ! is_wp_error( $cat_count ) && (int) $cat_count > 0 ? 'pass' : 'warn', 'Categories', is_wp_error( $cat_count ) ? '0' : (int) $cat_count . ' categories', 'Go to Categories page to seed defaults' ];
		$checks[] = [ get_option( 'cbd_google_maps_api_key' ) ? 'pass' : 'warn', 'Google Maps API Key', get_option( 'cbd_google_maps_api_key' ) ? 'Set' : 'Not set', 'Optional — required for map view' ];
		$from_addr = \CBD\Frontend\EmailVerification::from_address();
		$checks[] = [ is_email( $from_addr ) ? 'pass' : 'warn', 'Email From Address', $from_addr ?: 'Not set', 'Settings → Email — used for all plugin email' ];
		$checks[] = [ get_option( 'cbd_db_version' ) === CBD_DB_VERSION ? 'pass' : 'warn', 'DB Schema Version', get_option( 'cbd_db_version', 'Not set' ), 'Expected: ' . CBD_DB_VERSION ];

		echo '<table class="widefat" style="margin-top:16px;"><thead><tr><th style="width:40px;">Status</th><th>Check</th><th>Result</th><th>Notes</th></tr></thead><tbody>';
		foreach ( $checks as [ $status, $check, $result, $note ] ) {
			$icon  = $status === 'pass' ? '✅' : ( $status === 'warn' ? '⚠️' : '❌' );
			$style = $status === 'fail' ? 'background:#fff5f5;' : ( $status === 'warn' ? 'background:#fffbf0;' : '' );
			echo '<tr style="' . esc_attr( $style ) . '"><td style="text-align:center;font-size:16px;">' . $icon . '</td><td><strong>' . esc_html( $check ) . '</strong></td><td><code>' . esc_html( $result ) . '</code></td><td style="color:#666;font-size:12px;">' . esc_html( $note ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// ── Email delivery test card ──────────────────────────────────
		echo '<div style="margin-top:20px;padding:16px;background:#f8f8f6;border:1px solid #ddd;border-radius:8px;">';
		echo '<h3 style="margin-top:0;">Email delivery test</h3>';
		echo '<p style="color:#666;font-size:13px;margin:0 0 12px;">Sends a test email via <code>wp_mail()</code> — the same path used for account-verification and delegate-invitation emails. Use this to tell whether email isn\'t arriving because of the code (it isn\'t) or the mail transport.</p>';
		if ( is_array( $mail_result ) ) {
			if ( $mail_result['ok'] ) {
				echo '<div style="padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:12px;">✅ <strong>wp_mail() accepted the message</strong> for <code>' . esc_html( $mail_result['to'] ) . '</code>. If it does not arrive, the message was handed off but not delivered — see the note below.</div>';
			} else {
				echo '<div style="padding:10px 12px;background:#fff5f5;border:1px solid #fecaca;border-radius:6px;margin-bottom:12px;">❌ <strong>wp_mail() failed.</strong>' . ( $mail_result['err'] ? ' Reason: <code>' . esc_html( $mail_result['err'] ) . '</code>' : '' ) . ' Email cannot be sent from this server until mail delivery (SMTP) is configured.</div>';
			}
		}
		echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
		wp_nonce_field( 'cbd_test_email' );
		echo '<input type="email" name="cbd_test_to" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" style="min-width:260px;padding:6px 8px;">';
		echo '<button type="submit" name="cbd_test_email" value="1" class="button button-primary">Send test email</button>';
		echo '</form>';
		echo '<p style="color:#666;font-size:12px;margin:12px 0 0;line-height:1.6;"><strong>On Local (Flywheel):</strong> outgoing mail is captured by Local\'s built-in mail tool (Mailpit/MailHog) and is <em>not</em> delivered to real inboxes — open it from the site\'s <em>Tools</em> tab in the Local app to view the message. To deliver to a real address (e.g. Gmail), install an SMTP plugin such as <code>WP Mail SMTP</code> or <code>FluentSMTP</code> and point it at a real provider (or a Mailtrap test inbox).</p>';
		echo '</div>';

		echo '<div style="margin-top:20px;padding:16px;background:#f8f8f6;border:1px solid #ddd;border-radius:8px;">';
		echo '<h3 style="margin-top:0;">How to read the error log</h3>';
		echo '<ol style="margin-left:20px;line-height:2;">';
		echo '<li>Add to wp-config.php: <code>define("WP_DEBUG", true); define("WP_DEBUG_LOG", true); define("WP_DEBUG_DISPLAY", false);</code></li>';
		echo '<li>Open cPanel → File Manager → wp-content → debug.log</li>';
		echo '<li>Or via FTP: <code>' . esc_html( WP_CONTENT_DIR ) . '/debug.log</code></li>';
		echo '<li><strong>Do NOT</strong> access debug.log via browser URL — 403 Forbidden is correct and intentional.</li>';
		echo '</ol></div></div>';
	}

	// ── Helpers ───────────────────────────────────────────────────

	private function settings_row( string $label, string $control ): void {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $control . '</td></tr>';
	}

	private function checkbox( string $name, string $option, string $value, string $label ): string {
		return '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
			. checked( get_option( $option ), $value, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	private function select_option( string $value, string $label, string $current ): string {
		return '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}

	private function currency_options(): string {
		$currencies = [ 'GBP' => 'GBP — British Pound', 'USD' => 'USD — US Dollar', 'EUR' => 'EUR — Euro', 'CAD' => 'CAD — Canadian Dollar', 'AUD' => 'AUD — Australian Dollar' ];
		$current    = get_option( 'cbd_currency', 'GBP' );
		$out = '';
		foreach ( $currencies as $code => $label ) {
			$out .= '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out;
	}
}
