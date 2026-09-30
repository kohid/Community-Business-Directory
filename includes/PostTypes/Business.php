<?php
/**
 * Business Custom Post Type.
 *
 * @package CBD\PostTypes
 */

namespace CBD\PostTypes;

defined( 'ABSPATH' ) || exit;

class Business {

	public function register(): void {
		register_post_type(
			'cbd_business',
			[
				'labels'       => [
					'name'               => __( 'Businesses',        'community-business-directory' ),
					'singular_name'      => __( 'Business',          'community-business-directory' ),
					'add_new'            => __( 'Add New',           'community-business-directory' ),
					'add_new_item'       => __( 'Add New Business',  'community-business-directory' ),
					'edit_item'          => __( 'Edit Business',     'community-business-directory' ),
					'view_item'          => __( 'View Business',     'community-business-directory' ),
					'search_items'       => __( 'Search Businesses', 'community-business-directory' ),
					'not_found'          => __( 'No businesses found.', 'community-business-directory' ),
					'not_found_in_trash' => __( 'No businesses found in Trash.', 'community-business-directory' ),
				],
				'public'            => true,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				// has_archive must stay FALSE: the rewrite slug `directory` is
				// also the slug of the auto-created "Business Directory" page
				// ([cbd_directory] shortcode, the canonical browse UI). With an
				// archive enabled, WP generates a `directory/?$` rule that shadows
				// that page, so /directory/ serves the CPT archive instead of the
				// page — which also breaks "Edit with Elementor" on it. Disabling
				// the archive lets /directory/ resolve to the page, while single
				// listings keep their /directory/<slug>/ URLs.
				'has_archive'       => false,
				'rewrite'           => [ 'slug' => 'directory', 'with_front' => false ],
				'supports'          => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
				'menu_icon'         => 'dashicons-store',
				'taxonomies'        => [ 'cbd_category', 'cbd_location' ],
			]
		);
	}
}
