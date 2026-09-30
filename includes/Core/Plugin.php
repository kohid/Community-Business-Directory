<?php
/**
 * Main Plugin bootstrap — singleton orchestrator.
 *
 * @package CBD\Core
 */

namespace CBD\Core;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;
	private Loader $loader;

	private function __construct() {
		$this->loader = new Loader();
	}

	public static function get_instance(): self {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	public function init(): void {
		try {
			Activator::maybe_upgrade();
			$this->load_textdomain();
			$this->register_post_types();
			$this->register_taxonomies();
			$this->register_rest_api();
			$this->register_ajax();
			$this->register_shortcodes();
			$this->register_blocks();
			$this->register_template_loader();
			$this->register_social_auth();
			$this->register_email_verification();
			$this->register_notifications();
			$this->register_loqiva();
			$this->register_seo();
			$this->register_assets();
			$this->register_admin_guard();
			$this->register_socmed();

			if ( is_admin() ) {
				$this->register_admin();
			}
		} catch ( \Throwable $e ) {
			// Log error and surface cleanly — never white-screen.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[Community Business Directory] Fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}
			if ( is_admin() ) {
				add_action( 'admin_notices', static function () use ( $e ) {
					echo '<div class="notice notice-error"><p>'
						. '<strong>Community Business Directory error:</strong> '
						. esc_html( $e->getMessage() )
						. ' — Enable WP_DEBUG_LOG and check <code>/wp-content/debug.log</code>.'
						. '</p></div>';
				} );
			}
		} finally {
			// ALWAYS attach whatever hooks were queued before any failure — a
			// single feature throwing during registration must never silently
			// take down core features (AJAX, shortcodes, auth/verification…).
			try {
				$this->loader->run();
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( '[Community Business Directory] Loader run failed: ' . $e->getMessage() );
				}
			}
		}
	}

	// ── Boot helpers ──────────────────────────────────────────────

	private function load_textdomain(): void {
		load_plugin_textdomain( 'community-business-directory', false, CBD_DIR . 'languages/' );
	}

	private function register_post_types(): void {
		foreach ( [
			new \CBD\PostTypes\Business(),
			new \CBD\PostTypes\BusinessPost(),
			new \CBD\PostTypes\Event(),
			new \CBD\PostTypes\Promotion(),
			new \CBD\PostTypes\Review(),
			new \CBD\PostTypes\Job(),
		] as $cpt ) {
			$this->loader->add_action( 'init', $cpt, 'register' );
		}
		// Jobs are managed through the native CPT screens + a meta box (no custom
		// table), so wire the meta box + save handler alongside registration.
		$job = new \CBD\PostTypes\Job();
		$this->loader->add_action( 'add_meta_boxes',      $job, 'add_meta_box' );
		$this->loader->add_action( 'save_post_cbd_job',   $job, 'save' );
		// Self-heal rewrite rules once per version (priority 99 = after every
		// CPT/taxonomy registers at 10). Fixes single-business 404s at
		// /directory/<slug>/ when the rewrite cache is stale after an update.
		$this->loader->add_action( 'init', $this, 'maybe_flush_rewrites', 99 );
		// Self-heal plugin pages once per version. create_pages() only ran on
		// activation, so installs that pre-date a newly-added page (e.g. the
		// Sign In page) never got it. This recreates only missing pages.
		$this->loader->add_action( 'init', $this, 'maybe_create_pages', 99 );
	}

	/**
	 * Create any missing plugin pages a single time after the plugin version
	 * changes. Cheap on every other request (just an option comparison).
	 * create_pages() is idempotent, so existing pages are left untouched.
	 */
	public function maybe_create_pages(): void {
		if ( get_option( 'cbd_pages_version' ) === CBD_VERSION ) {
			return;
		}
		Activator::create_pages();
		update_option( 'cbd_pages_version', CBD_VERSION );
	}

	/**
	 * Flush rewrite rules a single time after the plugin version changes.
	 * Cheap on every other request (just an option comparison).
	 */
	public function maybe_flush_rewrites(): void {
		if ( get_option( 'cbd_rewrite_version' ) === CBD_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'cbd_rewrite_version', CBD_VERSION );
	}

	private function register_taxonomies(): void {
		foreach ( [
			new \CBD\Taxonomies\BusinessCategory(),
			new \CBD\Taxonomies\BusinessLocation(),
		] as $tax ) {
			$this->loader->add_action( 'init', $tax, 'register' );
		}
	}

	private function register_rest_api(): void {
		$this->loader->add_action( 'rest_api_init', new \CBD\API\RestAPI(), 'register_routes' );
	}

	private function register_ajax(): void {
		$ajax    = new \CBD\Frontend\AjaxHandler();
		$actions = [
			'cbd_register_business',
			'cbd_create_event',
			'cbd_create_promotion',
			'cbd_submit_review',
			'cbd_follow_business',
			'cbd_load_directory',
			'cbd_load_more',
			'cbd_live_search',
			'cbd_calendar',
			'cbd_update_profile',
			'cbd_update_account',
			'cbd_my_activities',
			'cbd_change_plan',
			'cbd_toggle_favorite',
			'cbd_create_business_post',
			'cbd_delete_business_post',
			'cbd_upload_gallery',
			'cbd_upload_editor_image',
			'cbd_delete_gallery_image',
			'cbd_save_delegates',
			'cbd_save_hours',
			'cbd_react',
			'cbd_get_reactions',
			'cbd_login',
			'cbd_signup',
			'cbd_resend_verification',
			'cbd_social_login',
			'cbd_forgot_password',
			'cbd_set_password',
		];
		foreach ( $actions as $action ) {
			$this->loader->add_action( 'wp_ajax_'        . $action, $ajax, $action );
			$this->loader->add_action( 'wp_ajax_nopriv_' . $action, $ajax, $action );
		}
	}

	private function register_shortcodes(): void {
		$this->loader->add_action( 'init', new \CBD\Frontend\Shortcodes\ShortcodeRegistry(), 'register_all' );
	}

	private function register_blocks(): void {
		// Only register Gutenberg blocks when the Blocks API is available.
		if ( function_exists( 'register_block_type' ) ) {
			$this->loader->add_action( 'init', new \CBD\Frontend\Blocks\BlockRegistry(), 'register_all' );
		}
	}

	private function register_template_loader(): void {
		$this->loader->add_filter( 'template_include', new \CBD\Frontend\TemplateLoader(), 'filter_template' );
	}

	private function register_social_auth(): void {
		$social = new \CBD\Frontend\SocialAuth();
		// Front controller for the /?cbd_oauth=… and /?cbd_oauth_callback=…
		// OAuth redirect endpoints (Google / Facebook / Twitter / Instagram).
		$this->loader->add_action( 'init', $social, 'maybe_handle' );
		// Surface stored social profile photos as the WP avatar everywhere.
		$this->loader->add_filter( 'pre_get_avatar_data', $social, 'filter_avatar_data', 10, 2 );
	}


	private function register_email_verification(): void {
		$verify = new \CBD\Frontend\EmailVerification();
		// Front controller for the /?cbd_activate=…&cbd_uid=… account-activation
		// links emailed by [cbd_signup] (see CBD\Frontend\EmailVerification).
		$this->loader->add_action( 'init', $verify, 'maybe_handle' );
		// Enforce verification on every auth path (wp-login.php, XML-RPC, our AJAX).
		$this->loader->add_filter( 'authenticate', $verify, 'block_unverified_login', 30, 3 );

		// Route all outgoing mail through SMTP when configured (Settings → Email)
		// — bypasses the host's throttled / spam-prone PHP mail().
		if ( get_option( 'cbd_smtp_enabled' ) === '1' ) {
			$this->loader->add_action( 'phpmailer_init', $verify, 'apply_smtp', 5 );
		}

		// Front controller for delegate-access invitation accept links
		// (/?cbd_accept_invite=…&cbd_biz=… — see CBD\Frontend\DelegateInvite).
		// Guarded with class_exists so that if this newer file is missing on a
		// server (e.g. a partial deploy), it can never throw and take down the
		// rest of the plugin's hook registration.
		if ( class_exists( '\CBD\Frontend\DelegateInvite' ) ) {
			$invite = new \CBD\Frontend\DelegateInvite();
			$this->loader->add_action( 'init', $invite, 'maybe_handle' );
		}

		// Front controller for team-access invitation links
		// (/?cbd_team_invite=… — see CBD\Admin\TeamInvite). Same class_exists
		// guard as above so a partial deploy can't break hook registration.
		if ( class_exists( '\CBD\Admin\TeamInvite' ) ) {
			$team = new \CBD\Admin\TeamInvite();
			$this->loader->add_action( 'init', $team, 'maybe_handle' );
		}

		// Send the WordPress reset-password / lost-password screens to our own
		// branded pages, so users never see wp-login.php (admin "login"/"logout"
		// actions are left untouched).
		$this->loader->add_action( 'login_init', $this, 'redirect_wp_auth_screens' );
	}

	/**
	 * Redirect the user-facing wp-login.php password screens to the plugin's
	 * branded equivalents. Reset links (action=rp/resetpass) → [cbd_set_password]
	 * (carrying the key + login); "lost password" → the Sign In page (which has
	 * the Forgot Password modal). Normal login + logout are not touched.
	 */
	public function redirect_wp_auth_screens(): void {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) $_REQUEST['action'] ) : '';

		if ( in_array( $action, [ 'rp', 'resetpass' ], true ) ) {
			$pid  = (int) get_option( 'cbd_setpw_page_id' );
			$base = ( $pid && get_post_status( $pid ) ) ? get_permalink( $pid ) : home_url( '/set-password/' );
			$args = [];
			if ( isset( $_GET['key'] ) ) {
				$args['key'] = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			}
			if ( isset( $_GET['login'] ) ) {
				$args['login'] = sanitize_text_field( wp_unslash( $_GET['login'] ) );
			}
			wp_safe_redirect( $args ? add_query_arg( $args, $base ) : $base );
			exit;
		}

		if ( in_array( $action, [ 'lostpassword', 'retrievepassword' ], true ) ) {
			$pid = (int) get_option( 'cbd_login_page_id' );
			wp_safe_redirect( ( $pid && get_post_status( $pid ) ) ? get_permalink( $pid ) : home_url( '/' ) );
			exit;
		}

		// Default sign-in screen → our branded Sign In page. Covers a direct hit on
		// wp-login.php and the bounce from wp-admin (auth_redirect() sends logged-out
		// visitors to wp-login.php?redirect_to=…, which lands here). Only the GET
		// form view is redirected, so the POST that actually authenticates still
		// reaches WordPress. The site admin keeps an escape hatch — wp-login.php?
		// cbd_native=1 (and WordPress's own interim-login / reauth re-auth flow) —
		// so the native form is always reachable for wp-admin access.
		if ( '' === $action || 'login' === $action ) {
			$is_get  = 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
			$escape  = isset( $_GET['cbd_native'] ) || isset( $_GET['interim-login'] ) || isset( $_GET['reauth'] );
			$pid     = (int) get_option( 'cbd_login_page_id' );
			if ( $is_get && ! $escape && ! is_user_logged_in() && $pid && get_post_status( $pid ) ) {
				$dest = get_permalink( $pid );
				if ( isset( $_GET['redirect_to'] ) ) {
					// add_query_arg() URL-encodes the value for us.
					$dest = add_query_arg( 'redirect_to', esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), $dest );
				}
				wp_safe_redirect( $dest );
				exit;
			}
		}
	}

	private function register_notifications(): void {
		$this->loader->add_action( 'init', new \CBD\Modules\Notifications(), 'register' );
	}

	/**
	 * Love Inverness (Loqiva) importer: ensure the daily sync is scheduled and
	 * wire the WP-Cron hook to the importer. Admin-side controls (Sync Now /
	 * Remove) are registered in register_admin().
	 */
	private function register_loqiva(): void {
		\CBD\Modules\LoqivaSync::schedule_cron(); // idempotent — no-op once scheduled
		$this->loader->add_action( \CBD\Modules\LoqivaSync::CRON_HOOK, new \CBD\Modules\LoqivaSync(), 'run_cron' );
	}

	private function register_seo(): void {
		$this->loader->add_action( 'wp_head', new \CBD\SEO\SchemaMarkup(), 'output_schema' );
	}

	private function register_assets(): void {
		// Priority 20 so the theme (Hello, priority 10) and Elementor have already
		// registered their handles — enqueue_frontend then declares them as deps so
		// the plugin CSS prints AFTER reset.css and wins equal-specificity ties.
		$this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_frontend', 20 );
	}

	private function register_admin_guard(): void {
		$guard = new AdminGuard();
		// Block wp-admin loads for any logged-in user whose email isn't the site's admin_email.
		$this->loader->add_action( 'admin_init', $guard, 'maybe_block', 1 );
		// And hide the toolbar's "Dashboard" link from those users on the frontend.
		$this->loader->add_filter( 'show_admin_bar', $guard, 'maybe_hide_admin_bar' );
	}

	/**
	 * The front-end [cbd_social_sync] page ("/socmed") — invited team members
	 * self-manage the Facebook/Instagram feeds here instead of wp-admin. Guards
	 * access, keeps the page out of search results, and wires its save endpoint.
	 */
	private function register_socmed(): void {
		if ( ! class_exists( '\CBD\Frontend\Shortcodes\SocialSyncShortcode' ) ) {
			return;
		}
		$sync = new \CBD\Frontend\Shortcodes\SocialSyncShortcode();
		$this->loader->add_action( 'template_redirect', $this, 'guard_socmed_page' );
		$this->loader->add_filter( 'wp_robots', $this, 'noindex_socmed_page' );
		$this->loader->add_filter( 'body_class', $this, 'socmed_body_class' );
		// Hello Elementor renders a "Social Feeds" <h1 class="entry-title"> above
		// the content; the shortcode has its own heading, so drop the theme one
		// on /socmed only. (Filter is Hello-Elementor-specific; the CSS fallback
		// in frontend.css covers other themes with the same .page-header markup.)
		$this->loader->add_filter( 'hello_elementor_page_title', $this, 'socmed_hide_page_title' );
		$this->loader->add_action( 'wp_ajax_cbd_socmed_save', $sync, 'ajax_save' );
	}

	/**
	 * Add a `cbd-socmed-page` class to <body> on /socmed, so the theme's page
	 * title and header can be re-styled from Additional CSS, e.g.
	 * `body.cbd-socmed-page .entry-title { font-size: 2rem; }`.
	 */
	public function socmed_body_class( array $classes ): array {
		$pid = (int) get_option( 'cbd_socmed_page_id' );
		if ( $pid && is_page( $pid ) ) {
			$classes[] = 'cbd-socmed-page';
		}
		return $classes;
	}

	/** Hide Hello Elementor's page-title/header on /socmed (the shortcode has its own). */
	public function socmed_hide_page_title( $show ) {
		$pid = (int) get_option( 'cbd_socmed_page_id' );
		return ( $pid && is_page( $pid ) ) ? false : $show;
	}

	/** Bounce anyone who isn't the owner / an invited team member away from /socmed. */
	public function guard_socmed_page(): void {
		$pid = (int) get_option( 'cbd_socmed_page_id' );
		if ( ! $pid || ! is_page( $pid ) ) {
			return;
		}
		if ( \CBD\Frontend\Shortcodes\SocialSyncShortcode::current_user_allowed() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			$login = (int) get_option( 'cbd_login_page_id' );
			$base  = ( $login && get_permalink( $login ) ) ? get_permalink( $login ) : wp_login_url();
			// add_query_arg() URL-encodes the value for us.
			wp_safe_redirect( add_query_arg( 'redirect_to', get_permalink( $pid ), $base ) );
		} else {
			wp_safe_redirect( home_url( '/' ) );
		}
		exit;
	}

	/** Keep /socmed out of search engines. */
	public function noindex_socmed_page( array $robots ): array {
		$pid = (int) get_option( 'cbd_socmed_page_id' );
		if ( $pid && is_page( $pid ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	private function register_admin(): void {
		$admin = new \CBD\Admin\AdminController();
		$this->loader->add_action( 'admin_menu',            $admin, 'add_menu_pages' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );
		// Demo Mode toggle — streamed generate/teardown progress (admin-only AJAX).
		$this->loader->add_action( 'wp_ajax_cbd_demo_generate', $admin, 'ajax_demo_generate' );
		$this->loader->add_action( 'wp_ajax_cbd_demo_teardown', $admin, 'ajax_demo_teardown' );
		// Love Inverness importer — streamed sync/remove progress (admin-only AJAX).
		$this->loader->add_action( 'wp_ajax_cbd_loqiva_sync',     $admin, 'ajax_loqiva_sync' );
		$this->loader->add_action( 'wp_ajax_cbd_loqiva_teardown', $admin, 'ajax_loqiva_teardown' );
		// Facebook Page — on-demand feed refresh (admin-only AJAX).
		$this->loader->add_action( 'wp_ajax_cbd_fb_refresh', $admin, 'ajax_fb_refresh' );
		// Instagram — on-demand feed refresh + account auto-detect (admin-only AJAX).
		$this->loader->add_action( 'wp_ajax_cbd_ig_refresh',  $admin, 'ajax_ig_refresh' );
		$this->loader->add_action( 'wp_ajax_cbd_ig_discover', $admin, 'ajax_ig_discover' );
		// Jobs list screen — standalone "demo jobs" panel + generate/remove AJAX.
		$this->loader->add_action( 'admin_notices',                    $admin, 'render_jobs_demo_panel' );
		$this->loader->add_action( 'wp_ajax_cbd_jobs_demo_generate',    $admin, 'ajax_jobs_demo_generate' );
		$this->loader->add_action( 'wp_ajax_cbd_jobs_demo_remove',      $admin, 'ajax_jobs_demo_remove' );
	}

	// ── Frontend assets ───────────────────────────────────────────

	public function enqueue_frontend(): void {
		// Type system — Manrope (display) + Inter (body), loaded from Google Fonts.
		wp_enqueue_style(
			'cbd-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap',
			[],
			null
		);
		// Version assets off file mtime so any edit busts the browser cache
		// immediately — no manual CBD_VERSION bump (or Elementor regen) needed.
		$css_path = CBD_DIR . 'assets/css/frontend.css';
		$js_path  = CBD_DIR . 'assets/js/frontend.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : CBD_VERSION;
		$js_ver   = file_exists( $js_path )  ? (string) filemtime( $js_path )  : CBD_VERSION;

		// Emoji picker (ported from the WorkSpace theme) — its own CSS/JS.
		$emoji_css = CBD_DIR . 'assets/css/emoji-picker.css';
		$emoji_js  = CBD_DIR . 'assets/js/emoji-picker.js';
		wp_enqueue_style(
			'cbd-emoji',
			CBD_URL . 'assets/css/emoji-picker.css',
			[],
			file_exists( $emoji_css ) ? (string) filemtime( $emoji_css ) : CBD_VERSION
		);
		wp_enqueue_script(
			'cbd-emoji',
			CBD_URL . 'assets/js/emoji-picker.js',
			[],
			file_exists( $emoji_js ) ? (string) filemtime( $emoji_js ) : CBD_VERSION,
			true
		);

		// Declare the theme reset + Elementor as dependencies (only those actually
		// registered) so WordPress prints frontend.css AFTER them. At equal
		// specificity the later sheet wins, so this is what lets the plugin's
		// classed buttons/inputs/links beat Hello's reset.css `[type=button]`,
		// `input`, `a` rules without a blanket !important.
		$css_deps = [ 'cbd-fonts', 'cbd-emoji' ];
		foreach ( [ 'hello-elementor', 'hello-elementor-theme-style', 'elementor-frontend' ] as $dep ) {
			if ( wp_style_is( $dep, 'registered' ) ) {
				$css_deps[] = $dep;
			}
		}
		wp_enqueue_style(
			'cbd-frontend',
			CBD_URL . 'assets/css/frontend.css',
			$css_deps,
			$css_ver
		);
		wp_enqueue_script(
			'cbd-frontend',
			CBD_URL . 'assets/js/frontend.js',
			[ 'jquery', 'cbd-emoji' ],
			$js_ver,
			true
		);

		// WYSIWYG — Quill (snow) from CDN + our enhancer that upgrades rich
		// .cbd-form textareas in place. Depends on cbd-frontend so cbdData and
		// the emoji picker are available; a blocked CDN degrades to plain textareas.
		wp_enqueue_style( 'cbd-quill', 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css', [], '2.0.3' );
		wp_enqueue_script( 'cbd-quill', 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js', [], '2.0.3', true );
		$wys_js  = CBD_DIR . 'assets/js/wysiwyg.js';
		$wys_ver = file_exists( $wys_js ) ? (string) filemtime( $wys_js ) : CBD_VERSION;
		wp_enqueue_script(
			'cbd-wysiwyg',
			CBD_URL . 'assets/js/wysiwyg.js',
			[ 'jquery', 'cbd-quill', 'cbd-frontend', 'cbd-emoji' ],
			$wys_ver,
			true
		);
		wp_localize_script( 'cbd-frontend', 'cbdData', [
			'restUrl'  => esc_url_raw( rest_url( CBD_REST_NS . '/' ) ),
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'cbd_nonce' ),
			'userId'   => get_current_user_id(),
			'loggedIn' => is_user_logged_in(),
			'loginUrl' => wp_login_url(),
			'currency' => get_option( 'cbd_currency', 'GBP' ),
			'currencySymbol' => get_option( 'cbd_currency_symbol', '£' ),
			'mapsKey'  => get_option( 'cbd_google_maps_api_key', '' ),
			'i18n'     => [
				'loading'        => __( 'Loading…', 'community-business-directory' ),
				'no_results'     => __( 'No results found.', 'community-business-directory' ),
				'saved'          => __( 'Saved!', 'community-business-directory' ),
				'error'          => __( 'Something went wrong.', 'community-business-directory' ),
				'reactions'      => __( 'Reactions', 'community-business-directory' ),
				'confirm_delete' => __( 'Delete this post?', 'community-business-directory' ),
				'visit'          => __( 'Visit', 'community-business-directory' ),
				'crop_image'     => __( 'Crop image', 'community-business-directory' ),
				'apply_crop'     => __( 'Apply crop', 'community-business-directory' ),
				'cancel'         => __( 'Cancel', 'community-business-directory' ),
				'plan_confirm'   => __( 'Confirm plan change', 'community-business-directory' ),
				'plan_confirm_btn' => __( 'Confirm', 'community-business-directory' ),
				'plan_switching' => __( 'Switching…', 'community-business-directory' ),
				'per_month'      => __( '/month', 'community-business-directory' ),
				'per_year'       => __( '/year', 'community-business-directory' ),
				'free'           => __( 'Free', 'community-business-directory' ),
			],
		] );
	}

	private function __clone() {}
}
