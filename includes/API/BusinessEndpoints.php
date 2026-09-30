<?php
namespace CBD\API;
defined( 'ABSPATH' ) || exit;
class BusinessEndpoints {
    public function register( string $ns ): void {
        register_rest_route( $ns, '/businesses', [ [ 'methods' => 'GET', 'callback' => [ $this, 'get_businesses' ], 'permission_callback' => '__return_true' ] ] );
        register_rest_route( $ns, '/businesses/(?P<id>\d+)', [ [ 'methods' => 'GET', 'callback' => [ $this, 'get_business' ], 'permission_callback' => '__return_true' ] ] );
    }
    public function get_businesses( \WP_REST_Request $r ): \WP_REST_Response {
        $q = new \WP_Query( [ 'post_type' => 'cbd_business', 'post_status' => 'publish', 'posts_per_page' => 20 ] );
        return new \WP_REST_Response( array_map( [ $this, 'format' ], $q->posts ), 200 );
    }
    public function get_business( \WP_REST_Request $r ): WP_REST_Response {
        $post = get_post( (int) $r->get_param( 'id' ) );
        if ( ! $post || $post->post_type !== 'cbd_business' ) return new \WP_Error( 'not_found', 'Business not found.', [ 'status' => 404 ] );
        return new \WP_REST_Response( $this->format( $post, true ), 200 );
    }
    private function format( \WP_Post $post, bool $full = false ): array {
        global $wpdb;
        $meta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d", $post->ID ), ARRAY_A ) ?? [];
        return [ 'id' => $post->ID, 'name' => $post->post_title, 'url' => get_permalink( $post->ID ),
                 'city' => $meta['city'] ?? '', 'phone' => $meta['phone'] ?? '', 'email' => $meta['email'] ?? '',
                 'rating_avg' => (float)( $meta['rating_avg'] ?? 0 ), 'review_count' => (int)( $meta['review_count'] ?? 0 ),
                 'is_featured' => (bool)( $meta['is_featured'] ?? false ),
                 'categories' => wp_get_post_terms( $post->ID, 'cbd_category', [ 'fields' => 'names' ] ),
                 'description' => $full ? $post->post_content : '',
                 'website' => $full ? ( $meta['website'] ?? '' ) : '' ];
    }
}
