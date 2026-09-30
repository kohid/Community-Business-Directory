<?php
namespace CBD\Frontend\Shortcodes;
defined( 'ABSPATH' ) || exit;

class ShortcodeRegistry {

	/**
	 * Map of shortcode tag => render callable. Each output is wrapped in a
	 * `.cbd-scope` element (see wrap()) so the plugin's scoped CSS reset can
	 * shield every surface from theme / Elementor style bleed.
	 */
	public function register_all(): void {
		$shortcodes = [
			'cbd_register_business'   => [ new RegisterShortcode(),   'render' ],
			'cbd_directory'           => [ new DirectoryShortcode(),  'render' ],
			'cbd_featured_businesses' => [ new FeaturedShortcode(),   'render' ],
			'cbd_events'              => [ new EventsShortcode(),     'render' ],
			'cbd_promotions'          => [ new PromotionsShortcode(), 'render' ],
			// Single-post profile shortcodes — for Elementor Theme Builder single
			// templates. Each renders the current post's full single view (same
			// markup as the single-*.php templates) via parts/profile-*.php.
			'cbd_business_profile'    => [ new BusinessProfileShortcode(),  'render' ],
			'cbd_event_profile'       => [ new EventProfileShortcode(),     'render' ],
			'cbd_promotion_profile'   => [ new PromotionProfileShortcode(), 'render' ],
			'cbd_job_profile'         => [ new JobProfileShortcode(),       'render' ],
			'cbd_reviews'             => [ new ReviewsShortcode(),    'render' ],
			'cbd_search'              => [ new SearchShortcode(),     'render' ],
			'cbd_business_map'        => [ new MapShortcode(),        'render' ],
			'cbd_activity_feed'       => [ new ActivityShortcode(),   'render' ],
			'cbd_calendar'            => [ new CalendarShortcode(),   'render' ],
			'cbd_business_feed'       => [ new FeedShortcode(),       'render' ],
			'cbd_gallery'             => [ new GalleryShortcode(),    'render' ],
			'cbd_facebook_page'       => [ new FacebookShortcode(),   'render' ],
			'cbd_instagram_feed'      => [ new InstagramShortcode(),  'render' ],
			'cbd_social_sync'         => [ new SocialSyncShortcode(), 'render' ],
			'cbd_jobs'                => [ new JobsShortcode(),       'render' ],
			'cbd_plans'               => [ new PlansShortcode(),      'render' ],
			'cbd_account_menu'        => [ new AccountMenuShortcode(),'render' ],
			'cbd_login'               => [ new LoginShortcode(),      'render' ],
			'cbd_signup'              => [ new SignupShortcode(),     'render' ],
			'cbd_set_password'        => [ new SetPasswordShortcode(),'render' ],
		];

		foreach ( $shortcodes as $tag => $callback ) {
			add_shortcode( $tag, $this->wrap( $callback ) );
		}
	}

	/**
	 * Wrap a shortcode render callback so its HTML is enclosed in a
	 * `.cbd-scope` element. The wrapper is `display:contents` (see frontend.css)
	 * so it adds no layout box — safe even for inline placements like the header
	 * account menu — while still anchoring the scoped style reset and carrying
	 * the plugin's base typography to descendants. Empty output stays empty so
	 * we never emit a stray wrapper for a shortcode that rendered nothing.
	 */
	private function wrap( callable $callback ): callable {
		return static function ( $atts, $content, $tag ) use ( $callback ) {
			$html = (string) call_user_func( $callback, $atts, $content, $tag );
			if ( '' === trim( $html ) ) {
				return '';
			}
			return '<div class="cbd-scope">' . $html . '</div>';
		};
	}
}
