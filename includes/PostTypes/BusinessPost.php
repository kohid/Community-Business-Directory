<?php
/**
 * Business Post Custom Post Type.
 * Used for business updates, announcements, news and community messages.
 *
 * @package CBD\PostTypes
 */

namespace CBD\PostTypes;

defined( 'ABSPATH' ) || exit;

class BusinessPost {

	public function register(): void {
		register_post_type(
			'cbd_business_post',
			[
				'labels'       => [
					'name'               => __( 'Business Posts',    'community-business-directory' ),
					'singular_name'      => __( 'Business Post',     'community-business-directory' ),
					'add_new'            => __( 'Add New',           'community-business-directory' ),
					'add_new_item'       => __( 'Add New Post',      'community-business-directory' ),
					'edit_item'          => __( 'Edit Post',         'community-business-directory' ),
					'view_item'          => __( 'View Post',         'community-business-directory' ),
					'search_items'       => __( 'Search Posts',      'community-business-directory' ),
					'not_found'          => __( 'No posts found.',   'community-business-directory' ),
					'not_found_in_trash' => __( 'No posts in Trash.','community-business-directory' ),
				],
				'public'            => true,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				'has_archive'       => true,
				'rewrite'           => [ 'slug' => 'business-posts', 'with_front' => false ],
				'supports'          => [ 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'custom-fields' ],
				'menu_icon'         => 'dashicons-megaphone',
			]
		);
	}
}
