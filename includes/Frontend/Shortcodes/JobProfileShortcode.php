<?php

/**
 * Job Profile Shortcode — [cbd_job_profile]
 *
 * Renders the single-job detail for the CURRENT post, reusing the exact markup
 * of single-cbd_job.php (parts/profile-job.php). Intended for an Elementor Pro
 * Theme Builder "Single" template conditioned on Jobs — e.g. the view at
 * /job/<slug>/.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

class JobProfileShortcode {

	public function render( array $atts ): string {
		if ( 'cbd_job' !== get_post_type( get_the_ID() ) ) {
			return '';
		}
		return \cbd_profile_part( 'job' );
	}
}
