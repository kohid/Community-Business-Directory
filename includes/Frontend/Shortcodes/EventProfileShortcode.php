<?php

/**
 * Event Profile Shortcode — [cbd_event_profile]
 *
 * Renders the single-event detail for the CURRENT post, reusing the exact
 * markup of single-event.php (parts/profile-event.php). Intended for an
 * Elementor Pro Theme Builder "Single" template conditioned on Events.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class EventProfileShortcode
{
	public function render(array $atts): string
	{
		if ('cbd_event' !== get_post_type(get_the_ID())) {
			return '';
		}
		return \cbd_profile_part('event');
	}
}
