<?php

/**
 * Business Profile Shortcode — [cbd_business_profile]
 *
 * Renders the full single-business profile for the CURRENT post, reusing the
 * exact markup of the single-business.php template (parts/profile-business.php).
 * Intended for an Elementor Pro Theme Builder "Single" template whose display
 * condition is set to Businesses, so the layout around it can be designed in
 * Elementor while the profile itself stays plugin-rendered and in sync.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class BusinessProfileShortcode
{
	public function render(array $atts): string
	{
		if ('cbd_business' !== get_post_type(get_the_ID())) {
			return '';
		}
		return \cbd_profile_part('business');
	}
}
