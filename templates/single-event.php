<?php

/**
 * Single Event Detail Template
 * Override: mytheme/community-business-directory/single-event.php
 *
 * The detail body lives in parts/profile-event.php so the same markup powers
 * the [cbd_event_profile] shortcode (e.g. inside an Elementor Theme Builder
 * single template). Edit the partial, not this wrapper.
 */
defined('ABSPATH') || exit;

get_header();

echo cbd_profile_part('event'); // phpcs:ignore WordPress.Security.EscapeOutput — partial escapes at source

get_footer();
