<?php
/**
 * Event Custom Post Type.
 *
 * @package CBD\PostTypes
 */

namespace CBD\PostTypes;

defined( 'ABSPATH' ) || exit;

class Event {

	public function register(): void {
		register_post_type(
			'cbd_event',
			[
				'labels'       => [
					'name'               => __( 'Events',        'community-business-directory' ),
					'singular_name'      => __( 'Event',         'community-business-directory' ),
					'add_new'            => __( 'Add New',        'community-business-directory' ),
					'add_new_item'       => __( 'Add New Event', 'community-business-directory' ),
					'edit_item'          => __( 'Edit Event',    'community-business-directory' ),
					'view_item'          => __( 'View Event',    'community-business-directory' ),
					'search_items'       => __( 'Search Events', 'community-business-directory' ),
					'not_found'          => __( 'No events found.', 'community-business-directory' ),
					'not_found_in_trash' => __( 'No events found in Trash.', 'community-business-directory' ),
				],
				'public'            => true,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				'has_archive'       => true,
				// Singular 'event' slug so the post-type archive (/event/) does NOT
				// claim '/events/', which a site Page commonly uses for the public
				// [cbd_events] listing. A Page + CPT archive sharing 'events' makes
				// WordPress resolve /events/ to the archive, which breaks editing
				// that Page in Elementor (its preview loads the archive instead).
				'rewrite'           => [ 'slug' => 'event', 'with_front' => false ],
				'supports'          => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
				'menu_icon'         => 'dashicons-calendar',
				'taxonomies'        => [ 'cbd_event_cat' ],
			]
		);
	}
}
