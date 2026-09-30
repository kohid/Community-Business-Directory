<?php
namespace CBD\API;
defined( 'ABSPATH' ) || exit;
class SearchEndpoints {
    public function register( string $ns ): void {
        register_rest_route( $ns, '/search', [ 'methods' => 'GET', 'callback' => [ $this, 'search' ], 'permission_callback' => '__return_true',
            'args' => [ 'q' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ] ] ] );
    }
    public function search( \WP_REST_Request $r ): \WP_REST_Response {
        $q = new \WP_Query( [ 'post_type' => [ 'cbd_business', 'cbd_event', 'cbd_promotion' ], 'post_status' => 'publish', 's' => $r->get_param( 'q' ), 'posts_per_page' => 20 ] );
        return new \WP_REST_Response( array_map( static fn( $p ) => [
            'id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'url' => get_permalink( $p->ID ),
        ], $q->posts ), 200 );
    }
}
