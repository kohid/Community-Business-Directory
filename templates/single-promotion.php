<?php

/**
 * Single Promotion Detail Template
 * Override: mytheme/community-business-directory/single-promotion.php
 *
 * The detail body lives in parts/profile-promotion.php so the same markup
 * powers the [cbd_promotion_profile] shortcode (e.g. inside an Elementor Theme
 * Builder single template). Edit the partial, not this wrapper.
 */
defined('ABSPATH') || exit;

get_header();

echo cbd_profile_part('promotion'); // phpcs:ignore WordPress.Security.EscapeOutput — partial escapes at source

get_footer();
