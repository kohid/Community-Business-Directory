<?php
namespace CBD\Frontend\Shortcodes;
defined( 'ABSPATH' ) || exit;

class ActivityShortcode {

	use LoadMore;

	/** Resolve the requested `types` att to a list of post types. */
	private function post_types( string $types ): array {
		$allowed = [ 'business' => 'cbd_business', 'event' => 'cbd_event', 'promotion' => 'cbd_promotion' ];
		$out     = [];
		foreach ( array_map( 'trim', explode( ',', $types ) ) as $t ) {
			if ( isset( $allowed[ $t ] ) ) {
				$out[] = $allowed[ $t ];
			}
		}
		return $out ?: array_values( $allowed );
	}

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'count' => 10,
			'types' => 'business,event,promotion',
		], $atts );

		$per_page = max( 1, (int) $atts['count'] );
		$first    = $this->items( $atts, 1, $per_page );
		$total    = (int) $first['total'];

		ob_start(); ?>
<div class="cbd-wrap cbd-activity-feed">
	<?php echo $this->loadmore_open( 'activity', [ 'count' => $per_page, 'types' => $atts['types'] ], $per_page, $total ); ?>
	<div class="cbd-activity-list cbd-lm-list">
		<?php echo $first['html']; // phpcs:ignore — escaped within items() ?>
	</div>
	<?php echo $this->loadmore_button( 1, $per_page, $total ); // phpcs:ignore ?>
	</div><!-- /.cbd-lm -->
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render one page of activity rows.
	 *
	 * @return array{html:string,total:int}
	 */
	public function items( array $atts, int $page, int $per_page ): array {
		$types = $this->post_types( (string) ( $atts['types'] ?? 'business,event,promotion' ) );

		$query = new \WP_Query( [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		$type_labels = [
			'cbd_business'  => [ 'icon' => '🏢', 'label' => __( 'New Business',   'community-business-directory' ) ],
			'cbd_event'     => [ 'icon' => '📅', 'label' => __( 'New Event',      'community-business-directory' ) ],
			'cbd_promotion' => [ 'icon' => '🏷️', 'label' => __( 'New Promotion', 'community-business-directory' ) ],
		];

		ob_start();
		if ( $query->have_posts() ) :
			while ( $query->have_posts() ) : $query->the_post();
				$pt   = get_post_type();
				$info = $type_labels[ $pt ] ?? [ 'icon' => '📌', 'label' => __( 'New', 'community-business-directory' ) ];
				$age  = human_time_diff( get_the_time( 'U' ), current_time( 'timestamp' ) );
		?>
		<div class="cbd-activity-item">
			<div class="cbd-activity-thumb">
				<?php if ( has_post_thumbnail() ) the_post_thumbnail( 'thumbnail', [ 'class' => 'cbd-activity-img' ] );
				else echo '<div class="cbd-activity-img cbd-activity-img-ph">' . $info['icon'] . '</div>'; ?>
			</div>
			<div class="cbd-activity-body">
				<span class="cbd-activity-type"><?php echo $info['icon']; ?> <?php echo esc_html( $info['label'] ); ?></span>
				<h4 class="cbd-activity-title">
					<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
				</h4>
				<p class="cbd-activity-excerpt"><?php echo wp_trim_words( get_the_excerpt(), 12 ); ?></p>
				<span class="cbd-activity-time">⏱ <?php printf( esc_html__( '%s ago', 'community-business-directory' ), $age ); ?></span>
			</div>
		</div>
		<?php
			endwhile; wp_reset_postdata();
		elseif ( 1 === $page ) : ?>
		<div class="cbd-empty-state">
			<span class="cbd-empty-icon">📡</span>
			<p><?php esc_html_e( 'No recent activity yet.', 'community-business-directory' ); ?></p>
		</div>
		<?php endif;

		return [ 'html' => (string) ob_get_clean(), 'total' => (int) $query->found_posts ];
	}
}
