<?php
/**
 * Single Job Detail Template
 * Override: mytheme/community-business-directory/single-cbd_job.php
 *
 * The detail body lives in parts/profile-job.php so the same markup powers the
 * [cbd_job_profile] shortcode (e.g. inside an Elementor Theme Builder single
 * template). Edit the partial, not this wrapper.
 */

defined( 'ABSPATH' ) || exit;

get_header();

echo cbd_profile_part( 'job' ); // phpcs:ignore WordPress.Security.EscapeOutput — partial escapes at source

get_footer();
