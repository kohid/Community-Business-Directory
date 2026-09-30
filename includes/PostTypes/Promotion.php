<?php
/**
 * Promotion Custom Post Type.
 *
 * @package CBD\PostTypes
 */

namespace CBD\PostTypes;

defined( 'ABSPATH' ) || exit;

class Promotion {

	public function register(): void {
		register_post_type(
			'cbd_promotion',
			[
				'labels'       => [
					'name'               => __( 'Promotions',        'community-business-directory' ),
					'singular_name'      => __( 'Promotion',         'community-business-directory' ),
					'add_new'            => __( 'Add New',           'community-business-directory' ),
					'add_new_item'       => __( 'Add New Promotion', 'community-business-directory' ),
					'edit_item'          => __( 'Edit Promotion',    'community-business-directory' ),
					'view_item'          => __( 'View Promotion',    'community-business-directory' ),
					'search_items'       => __( 'Search Promotions', 'community-business-directory' ),
					'not_found'          => __( 'No promotions found.', 'community-business-directory' ),
					'not_found_in_trash' => __( 'No promotions found in Trash.', 'community-business-directory' ),
				],
				'public'            => true,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				// has_archive stays FALSE: the public promotions listing is the
				// auto-created "Offers & Deals" Page (/offers/, [cbd_promotions]
				// shortcode), which is the Elementor-editable canonical browse UI.
				// An enabled archive produces a raw, un-editable duplicate at
				// /promotions/ that only confuses editors. Single promotions keep
				// their /promotions/<slug>/ URLs via the rewrite slug below.
				'has_archive'       => false,
				'rewrite'           => [ 'slug' => 'promotions', 'with_front' => false ],
				'supports'          => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
				'menu_icon'         => 'dashicons-tag',
			]
		);
	}
}
