<?php
namespace CBD\API;
defined( 'ABSPATH' ) || exit;
class EventEndpoints {
    public function register( string $ns ): void {
        register_rest_route( $ns, '/events', [ 'methods' => 'GET', 'callback' => [ $this, 'get_events' ], 'permission_callback' => '__return_true' ] );
    }
    public function get_events( \WP_REST_Request $r ): \WP_REST_Response {
        $q = new \WP_Query( [ 'post_type' => 'cbd_event', 'post_status' => 'publish', 'posts_per_page' => 20 ] );
        return new \WP_REST_Response( array_map( static fn( $p ) => [
            'id' => $p->ID, 'name' => $p->post_title, 'url' => get_permalink( $p->ID ),
            'image' => get_the_post_thumbnail_url( $p->ID, 'medium' ) ?: '',
        ], $q->posts ), 200 );
    }
}
