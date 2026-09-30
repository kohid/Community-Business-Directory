<?php
namespace CBD\Frontend\Shortcodes;
defined( 'ABSPATH' ) || exit;

class MapShortcode {
	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'height'   => '500px',
			'category' => '',
			'zoom'     => '13',
			'limit'    => 100,
		], $atts );

		$maps_key = get_option( 'cbd_google_maps_api_key', '' );

		// Fetch businesses with coordinates.
		global $wpdb;
		$where = "WHERE b.latitude != 0 AND b.longitude != 0 AND b.status = 'active' AND p.post_status = 'publish'";
		if ( $atts['category'] ) {
			$term = get_term_by( 'slug', sanitize_text_field( $atts['category'] ), 'cbd_category' );
			if ( $term ) {
				$ids = get_posts( [ 'post_type' => 'cbd_business', 'fields' => 'ids', 'posts_per_page' => (int) $atts['limit'],
					'tax_query' => [ [ 'taxonomy' => 'cbd_category', 'field' => 'term_id', 'terms' => $term->term_id ] ] ] );
				$where .= $ids ? ' AND b.post_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' : ' AND 0';
			}
		}

		$businesses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.post_id, b.latitude, b.longitude, b.city, b.phone, b.rating_avg, b.review_count,
				        p.post_title, p.post_name
				 FROM {$wpdb->prefix}cbd_businesses b
				 INNER JOIN {$wpdb->posts} p ON p.ID = b.post_id
				 $where
				 LIMIT %d",
				(int) $atts['limit']
			)
		);

		// Build markers JSON.
		$markers = [];
		foreach ( $businesses as $biz ) {
			$markers[] = [
				'id'     => (int) $biz->post_id,
				'lat'    => (float) $biz->latitude,
				'lng'    => (float) $biz->longitude,
				'name'   => $biz->post_title,
				'url'    => get_permalink( $biz->post_id ),
				'city'   => $biz->city,
				'phone'  => $biz->phone,
				'rating' => (float) $biz->rating_avg,
				'reviews'=> (int) $biz->review_count,
				'thumb'  => get_the_post_thumbnail_url( $biz->post_id, 'thumbnail' ) ?: '',
			];
		}

		$map_id = 'cbd-map-' . wp_rand( 1000, 9999 );

		ob_start(); ?>
<div class="cbd-wrap cbd-map-wrap">
	<?php if ( ! $maps_key ) : ?>
	<div class="cbd-notice cbd-notice-info">
		<?php printf(
			wp_kses( __( 'Add a Google Maps API key in <a href="%s">Community Directory → Settings</a> to enable the map view.', 'community-business-directory' ), [ 'a' => [ 'href' => [] ] ] ),
			esc_url( admin_url( 'admin.php?page=cbd-settings' ) )
		); ?>
	</div>
	<?php else : ?>
	<div id="<?php echo esc_attr( $map_id ); ?>" class="cbd-map-canvas" style="height:<?php echo esc_attr( $atts['height'] ); ?>;width:100%;border-radius:var(--cbd-radius-lg,12px);overflow:hidden;"></div>
	<script>
	(function(){
		var markers = <?php echo wp_json_encode( $markers ); ?>;
		var zoom    = <?php echo (int) $atts['zoom']; ?>;
		var mapId   = '<?php echo esc_js( $map_id ); ?>';

		function initCbdMap_<?php echo esc_js( str_replace('-','_',$map_id) ); ?>(){
			var el = document.getElementById(mapId);
			if(!el || typeof google==='undefined') return;

			var center = markers.length
				? { lat: markers[0].lat, lng: markers[0].lng }
				: { lat: 51.505, lng: -0.09 };

			var map = new google.maps.Map(el, { zoom:zoom, center:center,
				styles:[{"featureType":"poi","elementType":"labels","stylers":[{"visibility":"off"}]}] });

			var infoWindow = new google.maps.InfoWindow();

			markers.forEach(function(m){
				var marker = new google.maps.Marker({ map:map, position:{lat:m.lat,lng:m.lng}, title:m.name,
					icon:{ path:google.maps.SymbolPath.CIRCLE, scale:10,
						fillColor:'#2b6344', fillOpacity:1, strokeColor:'#fff', strokeWeight:2 } });
				marker.addListener('click', function(){
					var stars = m.rating > 0 ? '⭐ ' + m.rating.toFixed(1) + ' (' + m.reviews + ')' : '';
					infoWindow.setContent(
						'<div style="padding:8px;max-width:200px;">'
						+ (m.thumb ? '<img src="'+m.thumb+'" style="width:100%;border-radius:6px;margin-bottom:8px;">' : '')
						+ '<strong style="font-size:14px;">'+m.name+'</strong>'
						+ (m.city ? '<br><small>📍 '+m.city+'</small>' : '')
						+ (stars ? '<br><small>'+stars+'</small>' : '')
						+ (m.phone ? '<br><small>📞 '+m.phone+'</small>' : '')
						+ '<br><a href="'+m.url+'" style="color:#2b6344;font-size:13px;font-weight:600;">View Profile →</a>'
						+ '</div>'
					);
					infoWindow.open(map, marker);
				});
			});

			// Fit bounds to all markers.
			if(markers.length > 1){
				var bounds = new google.maps.LatLngBounds();
				markers.forEach(function(m){ bounds.extend({lat:m.lat,lng:m.lng}); });
				map.fitBounds(bounds);
			}
		}
		window['initCbdMap_<?php echo esc_js( str_replace('-','_',$map_id) ); ?>'] = initCbdMap_<?php echo esc_js( str_replace('-','_',$map_id) ); ?>;
	}());
	</script>
	<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr( $maps_key ); ?>&callback=initCbdMap_<?php echo esc_js( str_replace('-','_',$map_id) ); ?>"></script>
	<p class="cbd-map-count"><?php printf( esc_html( _n( '%d business on map', '%d businesses on map', count( $markers ), 'community-business-directory' ) ), count( $markers ) ); ?></p>
	<?php endif; ?>
</div>
		<?php
		return ob_get_clean();
	}
}
