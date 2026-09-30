<?php

/**
 * Promotion Profile Shortcode — [cbd_promotion_profile]
 *
 * Renders the single-promotion detail for the CURRENT post, reusing the exact
 * markup of single-promotion.php (parts/profile-promotion.php). Intended for an
 * Elementor Pro Theme Builder "Single" template conditioned on Promotions.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class PromotionProfileShortcode
{
	public function render(array $atts): string
	{
		if ('cbd_promotion' !== get_post_type(get_the_ID())) {
			return '';
		}
		return \cbd_profile_part('promotion');
	}
}
