<?php
namespace CBD\Taxonomies;
defined( 'ABSPATH' ) || exit;
class BusinessLocation {
    public function register(): void {
        register_taxonomy( 'cbd_location', [ 'cbd_business' ], [
            'label' => __( 'Locations', 'community-business-directory' ),
            'hierarchical' => true, 'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'location' ], 'show_admin_column' => true,
        ] );
    }
}
