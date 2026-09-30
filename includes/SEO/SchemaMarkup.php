<?php
namespace CBD\SEO;
defined( 'ABSPATH' ) || exit;
class SchemaMarkup {
    public function output_schema(): void {
        if ( ! is_singular( 'cbd_business' ) ) return;
        global $post, $wpdb;
        $m = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d", $post->ID ), ARRAY_A ) ?? [];
        $s = [ '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => get_the_title( $post->ID ),
               'description' => get_the_excerpt( $post->ID ), 'url' => get_permalink( $post->ID ),
               'telephone' => $m['phone'] ?? '', 'email' => $m['email'] ?? '',
               'address' => [ '@type' => 'PostalAddress', 'streetAddress' => $m['address_line1'] ?? '',
                              'addressLocality' => $m['city'] ?? '', 'postalCode' => $m['postal_code'] ?? '', 'addressCountry' => $m['country'] ?? 'GB' ] ];
        if ( ! empty( $m['rating_avg'] ) && (float)$m['rating_avg'] > 0 ) {
            $s['aggregateRating'] = [ '@type' => 'AggregateRating', 'ratingValue' => $m['rating_avg'], 'reviewCount' => $m['review_count'] ];
        }
        echo '<script type="application/ld+json">' . wp_json_encode( $s ) . "</script>\n";
    }
}
