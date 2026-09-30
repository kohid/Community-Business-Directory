<?php
namespace CBD\Frontend\Shortcodes;

use CBD\Frontend\AjaxHandler;

defined( 'ABSPATH' ) || exit;

class FeaturedShortcode {

    use LoadMore;

    public function render( array $atts ): string {
        $atts = shortcode_atts( [ 'count' => 6, 'category' => '', 'orderby' => 'rating', 'view' => 'grid' ], $atts );
        $view = in_array( $atts['view'], [ 'grid', 'list', 'slides', 'masonry' ], true ) ? $atts['view'] : 'grid';

        $per_page = max( 1, (int) $atts['count'] );
        $first    = $this->items( $atts, 1, $per_page );
        $count    = (int) $first['total'];

        ob_start(); ?>
<div class="cbd-wrap cbd-featured-businesses cbd-listings-host" data-default-view="<?php echo esc_attr( $view ); ?>">

  <!-- ── Toolbar (count + view toggle) ───────────────────────── -->
  <div class="cbd-dir-toolbar">
    <p class="cbd-results-count">
      <?php
      /* translators: %d: number of featured businesses */
      echo esc_html( sprintf( _n( '%d featured business', '%d featured businesses', $count, 'community-business-directory' ), $count ) );
      ?>
    </p>
    <div class="cbd-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Choose layout', 'community-business-directory' ); ?>">
      <button type="button" class="cbd-view-btn<?php echo $view === 'grid' ? ' active' : ''; ?>" data-view="grid" aria-pressed="<?php echo $view === 'grid' ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Grid', 'community-business-directory' ); ?>" aria-label="<?php esc_attr_e( 'Grid view', 'community-business-directory' ); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
      </button>
      <button type="button" class="cbd-view-btn<?php echo $view === 'list' ? ' active' : ''; ?>" data-view="list" aria-pressed="<?php echo $view === 'list' ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'List', 'community-business-directory' ); ?>" aria-label="<?php esc_attr_e( 'List view', 'community-business-directory' ); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1.1" fill="currentColor" stroke="none"/><circle cx="3.5" cy="12" r="1.1" fill="currentColor" stroke="none"/><circle cx="3.5" cy="18" r="1.1" fill="currentColor" stroke="none"/></svg>
      </button>
      <button type="button" class="cbd-view-btn<?php echo $view === 'slides' ? ' active' : ''; ?>" data-view="slides" aria-pressed="<?php echo $view === 'slides' ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Slides', 'community-business-directory' ); ?>" aria-label="<?php esc_attr_e( 'Slides view', 'community-business-directory' ); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="5" width="10" height="14" rx="1.8"/><path d="M4 8.5v7"/><path d="M20 8.5v7"/></svg>
      </button>
      <button type="button" class="cbd-view-btn<?php echo $view === 'masonry' ? ' active' : ''; ?>" data-view="masonry" aria-pressed="<?php echo $view === 'masonry' ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Masonry', 'community-business-directory' ); ?>" aria-label="<?php esc_attr_e( 'Masonry view', 'community-business-directory' ); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/></svg>
      </button>
    </div>
  </div>

  <?php echo $this->loadmore_open( 'featured', [ 'count' => $per_page, 'view' => $view, 'orderby' => $atts['orderby'], 'category' => $atts['category'] ], $per_page, $count ); ?>
  <!-- ── Listings (arrows only render for the slides view) ───── -->
  <div class="cbd-listings-stage<?php echo $view === 'slides' ? ' is-slides' : ''; ?>">
    <button type="button" class="cbd-slide-arrow cbd-slide-prev" aria-label="<?php esc_attr_e( 'Previous', 'community-business-directory' ); ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <div class="cbd-listings cbd-lm-list cbd-view-<?php echo esc_attr( $view ); ?>">
      <?php echo $first['html']; // phpcs:ignore — escaped within items() ?>
    </div>
    <button type="button" class="cbd-slide-arrow cbd-slide-next" aria-label="<?php esc_attr_e( 'Next', 'community-business-directory' ); ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
  </div>
  <?php echo $this->loadmore_button( 1, $per_page, $count ); // phpcs:ignore ?>
  </div><!-- /.cbd-lm -->
</div>
<?php
        return ob_get_clean();
    }

    /**
     * Render one page of featured business cards.
     *
     * @return array{html:string,total:int}
     */
    public function items( array $atts, int $page, int $per_page ): array {
        $query = new \WP_Query( [
            'post_type'      => 'cbd_business',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_key'       => '_cbd_is_featured',
            'meta_value'     => '1',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        ob_start();
        if ( $query->have_posts() ) :
            while ( $query->have_posts() ) : $query->the_post();
                global $wpdb;
                $meta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d", get_the_ID() ), ARRAY_A ) ?? [];
                $cats = get_the_terms( get_the_ID(), 'cbd_category' );
                $cat_name = ( $cats && ! is_wp_error( $cats ) ) ? \cbd_label( $cats[0]->name ) : '';
                $address  = AjaxHandler::format_address( $meta );
        ?>
        <div class="cbd-business-card is-featured">
          <a href="<?php the_permalink(); ?>" class="cbd-card-img-wrap">
            <?php if ( has_post_thumbnail() ) the_post_thumbnail( 'medium', [ 'class' => 'cbd-card-img', 'loading' => 'lazy' ] );
            else echo '<div class="cbd-card-img cbd-card-img-placeholder"><span>' . esc_html( mb_strtoupper( mb_substr( get_the_title(), 0, 1 ) ) ) . '</span></div>'; ?>
            <div class="cbd-badges">
              <span class="cbd-badge cbd-badge-featured">&#9733; <?php esc_html_e( 'Featured', 'community-business-directory' ); ?></span>
              <?php if ( $cat_name ) echo '<span class="cbd-badge cbd-badge-cat">' . esc_html( $cat_name ) . '</span>'; ?>
            </div>
          </a>
          <div class="cbd-card-body">
            <?php if ( $cat_name ) echo '<span class="cbd-card-cat">' . esc_html( $cat_name ) . '</span>'; ?>
            <h3 class="cbd-card-title"><a href="<?php the_permalink(); ?>"><?php echo esc_html( \cbd_label( get_the_title() ) ); ?></a></h3>
            <?php if ( $address ) echo '<p class="cbd-card-location"><span class="cbd-pin">&#128205;</span> ' . esc_html( $address ) . '</p>'; ?>
            <p class="cbd-card-excerpt"><?php echo esc_html( \cbd_label( wp_trim_words( get_the_excerpt(), 15 ) ) ); ?></p>
            <div class="cbd-card-footer">
              <?php if ( ! empty( $meta['rating_avg'] ) ) echo '<span class="cbd-card-rating"><span class="cbd-stars">' . str_repeat( '&#9733;', (int) round( $meta['rating_avg'] ) ) . '</span> <small>(' . (int) $meta['review_count'] . ')</small></span>'; else echo '<span class="cbd-card-rating cbd-card-rating-empty"></span>'; ?>
              <div class="cbd-card-actions">
                <a href="<?php the_permalink(); ?>" class="cbd-card-visit"><?php esc_html_e( 'Visit', 'community-business-directory' ); ?> <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></a>
              </div>
            </div>
          </div>
        </div>
        <?php
            endwhile; wp_reset_postdata();
        elseif ( 1 === $page ) : ?>
        <div class="cbd-empty-state"><span class="cbd-empty-icon">&#11088;</span><p><?php esc_html_e( 'No featured businesses yet.', 'community-business-directory' ); ?></p></div>
        <?php endif;

        return [ 'html' => (string) ob_get_clean(), 'total' => (int) $query->found_posts ];
    }
}
