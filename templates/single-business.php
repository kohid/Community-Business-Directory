<?php

/**
 * Single Business Profile Template
 * Override: mytheme/community-business-directory/single-business.php
 *
 * The profile body lives in parts/profile-business.php so the exact same markup
 * powers the [cbd_business_profile] shortcode (e.g. inside an Elementor Theme
 * Builder single template). Edit the partial, not this wrapper.
 *
 * @package CBD
 */

defined('ABSPATH') || exit;

get_header();

echo cbd_profile_part('business'); // phpcs:ignore WordPress.Security.EscapeOutput — partial escapes at source

get_footer();
