<?php
/**
 * Review Custom Post Type.
 *
 * @package CBD\PostTypes
 */

namespace CBD\PostTypes;

defined( 'ABSPATH' ) || exit;

class Review {

	public function register(): void {
		register_post_type(
			'cbd_review',
			[
				'labels'       => [
					'name'               => __( 'Reviews',        'community-business-directory' ),
					'singular_name'      => __( 'Review',         'community-business-directory' ),
					'add_new'            => __( 'Add New',        'community-business-directory' ),
					'add_new_item'       => __( 'Add New Review', 'community-business-directory' ),
					'edit_item'          => __( 'Edit Review',    'community-business-directory' ),
					'view_item'          => __( 'View Review',    'community-business-directory' ),
					'search_items'       => __( 'Search Reviews', 'community-business-directory' ),
					'not_found'          => __( 'No reviews found.', 'community-business-directory' ),
					'not_found_in_trash' => __( 'No reviews found in Trash.', 'community-business-directory' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => false,
				'has_archive'       => false,
				'rewrite'           => false,
				'supports'          => [ 'title', 'editor', 'custom-fields' ],
				'menu_icon'         => 'dashicons-star-filled',
			]
		);
	}
}
