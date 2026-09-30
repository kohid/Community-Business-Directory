<?php
namespace CBD\Taxonomies;
defined( 'ABSPATH' ) || exit;
class BusinessCategory {
    public function register(): void {
        register_taxonomy( 'cbd_category', [ 'cbd_business' ], [
            'label' => __( 'Business Categories', 'community-business-directory' ),
            'hierarchical' => true, 'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'business-category' ], 'show_admin_column' => true,
        ] );
        register_taxonomy( 'cbd_event_cat', [ 'cbd_event' ], [
            'label' => __( 'Event Categories', 'community-business-directory' ),
            'hierarchical' => true, 'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'event-category' ],
        ] );
    }
}
