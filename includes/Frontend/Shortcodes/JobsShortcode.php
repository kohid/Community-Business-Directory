<?php
/**
 * [cbd_jobs] — public Job Listing page.
 *
 * Server-rendered, filterable list of cbd_job posts (search + type + workplace
 * + location) with simple pagination and per-job Apply buttons. Reads structured
 * fields from post meta via CBD\PostTypes\Job. No AJAX — filters/pagination flow
 * through GET params so the page is cache- and bookmark-friendly.
 *
 * Attributes (lock/seed the query; visitors can still narrow within them):
 *   count="10"  type="full-time"  workplace="remote"  location="Inverness"
 *   company="…"  featured="1"  show_filters="1"  orderby="date|title"
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

use CBD\PostTypes\Job;

defined( 'ABSPATH' ) || exit;

class JobsShortcode {

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'count'        => 10,
			'type'         => '',
			'workplace'    => '',
			'location'     => '',
			'company'      => '',
			'featured'     => '',
			'show_filters' => '1',
			'orderby'      => 'date',
		], $atts, 'cbd_jobs' );

		$per_page = max( 1, (int) $atts['count'] );
		$paged    = isset( $_GET['cbd_job_page'] ) ? max( 1, (int) $_GET['cbd_job_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended — read-only listing filter

		// Visitor filters (GET), constrained by any locked shortcode attributes.
		$f_q    = isset( $_GET['cbd_job_q'] ) ? sanitize_text_field( wp_unslash( $_GET['cbd_job_q'] ) ) : '';        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_type = $atts['type'] !== '' ? sanitize_key( $atts['type'] ) : ( isset( $_GET['cbd_job_type'] ) ? sanitize_key( wp_unslash( $_GET['cbd_job_type'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_wp   = $atts['workplace'] !== '' ? sanitize_key( $atts['workplace'] ) : ( isset( $_GET['cbd_job_wp'] ) ? sanitize_key( wp_unslash( $_GET['cbd_job_wp'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_loc  = $atts['location'] !== '' ? sanitize_text_field( $atts['location'] ) : ( isset( $_GET['cbd_job_loc'] ) ? sanitize_text_field( wp_unslash( $_GET['cbd_job_loc'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Build the query.
		$meta_query = [];
		if ( isset( Job::types()[ $f_type ] ) ) {
			$meta_query[] = [ 'key' => Job::M_TYPE, 'value' => $f_type ];
		}
		if ( isset( Job::workplaces()[ $f_wp ] ) ) {
			$meta_query[] = [ 'key' => Job::M_WORKPLACE, 'value' => $f_wp ];
		}
		if ( $f_loc !== '' ) {
			$meta_query[] = [ 'key' => Job::M_LOCATION, 'value' => $f_loc, 'compare' => 'LIKE' ];
		}
		if ( $atts['company'] !== '' ) {
			$meta_query[] = [ 'key' => Job::M_COMPANY, 'value' => sanitize_text_field( $atts['company'] ), 'compare' => 'LIKE' ];
		}
		if ( '1' === (string) $atts['featured'] || 'true' === $atts['featured'] ) {
			$meta_query[] = [ 'key' => Job::M_FEATURED, 'value' => '1' ];
		}

		$args = [
			'post_type'      => 'cbd_job',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title' === $atts['orderby'] ? 'title' : 'date',
			'order'          => 'title' === $atts['orderby'] ? 'ASC' : 'DESC',
		];
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		if ( $f_q !== '' ) {
			$args['s'] = $f_q;
		}

		$query = new \WP_Query( $args );
		$total = (int) $query->found_posts;

		ob_start(); ?>
<div class="cbd-wrap cbd-jobs">

  <div class="cbd-jobs-toolbar">
    <p class="cbd-results-count">
      <?php
      /* translators: %d: number of job vacancies */
      echo esc_html( sprintf( _n( '%d job', '%d jobs', $total, 'community-business-directory' ), $total ) );
      ?>
    </p>
  </div>

  <?php if ( '1' === (string) $atts['show_filters'] ) : ?>
  <form class="cbd-jobs-filters" method="get" action="<?php echo esc_url( get_permalink() ); ?>">
    <div class="cbd-jobs-filter-field cbd-jobs-search">
      <input type="search" name="cbd_job_q" value="<?php echo esc_attr( $f_q ); ?>" placeholder="<?php esc_attr_e( 'Search job title or keyword…', 'community-business-directory' ); ?>">
    </div>
    <?php if ( $atts['type'] === '' ) : ?>
    <div class="cbd-jobs-filter-field">
      <select name="cbd_job_type">
        <option value=""><?php esc_html_e( 'All types', 'community-business-directory' ); ?></option>
        <?php foreach ( Job::types() as $val => $label ) : ?>
          <option value="<?php echo esc_attr( $val ); ?>"<?php selected( $f_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ( $atts['workplace'] === '' ) : ?>
    <div class="cbd-jobs-filter-field">
      <select name="cbd_job_wp">
        <option value=""><?php esc_html_e( 'Anywhere', 'community-business-directory' ); ?></option>
        <?php foreach ( Job::workplaces() as $val => $label ) : ?>
          <option value="<?php echo esc_attr( $val ); ?>"<?php selected( $f_wp, $val ); ?>><?php echo esc_html( $label ); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ( $atts['location'] === '' ) : ?>
    <div class="cbd-jobs-filter-field">
      <input type="text" name="cbd_job_loc" value="<?php echo esc_attr( $f_loc ); ?>" placeholder="<?php esc_attr_e( 'Location', 'community-business-directory' ); ?>">
    </div>
    <?php endif; ?>
    <button type="submit" class="cbd-btn cbd-btn-primary"><?php esc_html_e( 'Search', 'community-business-directory' ); ?></button>
  </form>
  <?php endif; ?>

  <div class="cbd-jobs-list">
    <?php
    if ( $query->have_posts() ) :
        while ( $query->have_posts() ) : $query->the_post();
            echo $this->card( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped within card()
        endwhile;
        wp_reset_postdata();
    else : ?>
      <div class="cbd-empty-state"><span class="cbd-empty-icon">💼</span><p><?php esc_html_e( 'No jobs match your search right now. Please check back soon.', 'community-business-directory' ); ?></p></div>
    <?php endif; ?>
  </div>

  <?php echo $this->pager( $paged, $per_page, $total ); // phpcs:ignore WordPress.Security.EscapeOutput — built from escaped parts ?>
</div>
<?php
		return (string) ob_get_clean();
	}

	/** Render one job row. */
	private function card( int $post_id ): string {
		$m        = Job::get_meta( $post_id );
		$title    = get_the_title( $post_id );
		$permalink = get_permalink( $post_id );
		$apply    = Job::apply_link( $m, $title ) ?: $permalink;
		$expired  = Job::is_expired( $m );
		$company  = $m['company'] ?: get_bloginfo( 'name' );

		ob_start(); ?>
<article class="cbd-job-card<?php echo $m['featured'] ? ' is-featured' : ''; ?><?php echo $expired ? ' is-expired' : ''; ?>">
  <div class="cbd-job-logo" aria-hidden="true">
    <?php if ( has_post_thumbnail( $post_id ) ) : ?>
      <?php echo get_the_post_thumbnail( $post_id, 'thumbnail', [ 'loading' => 'lazy' ] ); ?>
    <?php else : ?>
      <span><?php echo esc_html( $this->monogram( $company ) ); ?></span>
    <?php endif; ?>
  </div>
  <div class="cbd-job-main">
    <h3 class="cbd-job-title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( \cbd_label( $title ) ); ?></a></h3>
    <p class="cbd-job-company">
      <span class="cbd-job-org"><?php echo esc_html( \cbd_label( $company ) ); ?></span>
      <?php if ( $m['location'] ) : ?><span class="cbd-job-loc">📍 <?php echo esc_html( $m['location'] ); ?></span><?php endif; ?>
    </p>
    <div class="cbd-job-tags">
      <?php if ( $m['type'] && Job::type_label( $m['type'] ) ) : ?><span class="cbd-job-tag cbd-job-tag-type"><?php echo esc_html( Job::type_label( $m['type'] ) ); ?></span><?php endif; ?>
      <?php if ( $m['workplace'] && Job::workplace_label( $m['workplace'] ) ) : ?><span class="cbd-job-tag cbd-job-tag-wp"><?php echo esc_html( Job::workplace_label( $m['workplace'] ) ); ?></span><?php endif; ?>
      <?php if ( $m['salary'] ) : ?><span class="cbd-job-tag cbd-job-tag-salary">💷 <?php echo esc_html( $m['salary'] ); ?></span><?php endif; ?>
      <?php if ( $m['featured'] ) : ?><span class="cbd-job-tag cbd-job-tag-featured">★ <?php esc_html_e( 'Featured', 'community-business-directory' ); ?></span><?php endif; ?>
    </div>
    <p class="cbd-job-metaline">
      <span><?php
        /* translators: %s: human-readable time since posting, e.g. "3 days" */
        echo esc_html( sprintf( __( 'Posted %s ago', 'community-business-directory' ), human_time_diff( (int) get_post_time( 'U', true, $post_id ) ) ) );
      ?></span>
      <?php if ( $m['closing'] ) : ?>
        <span class="cbd-job-closing<?php echo $expired ? ' is-past' : ''; ?>">
          <?php echo $expired
            ? esc_html__( 'Applications closed', 'community-business-directory' )
            : esc_html( sprintf( __( 'Closes %s', 'community-business-directory' ), date_i18n( get_option( 'date_format' ), strtotime( $m['closing'] ) ) ) ); ?>
        </span>
      <?php endif; ?>
    </p>
  </div>
  <div class="cbd-job-actions">
    <a href="<?php echo esc_url( $permalink ); ?>" class="cbd-btn cbd-btn-outline"><?php esc_html_e( 'Details', 'community-business-directory' ); ?></a>
    <?php if ( ! $expired ) : ?>
      <a href="<?php echo esc_url( $apply ); ?>" class="cbd-btn cbd-btn-primary"<?php echo Job::apply_link( $m, $title ) && ! empty( $m['apply_url'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php esc_html_e( 'Apply', 'community-business-directory' ); ?></a>
    <?php endif; ?>
  </div>
</article>
<?php
		return (string) ob_get_clean();
	}

	/** Prev/next pager that preserves the current filter query string. */
	private function pager( int $paged, int $per_page, int $total ): string {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages < 2 ) {
			return '';
		}
		$base = static function ( int $p ): string {
			return esc_url( add_query_arg( 'cbd_job_page', $p ) );
		};

		$out  = '<nav class="cbd-jobs-pager" aria-label="' . esc_attr__( 'Jobs pagination', 'community-business-directory' ) . '">';
		if ( $paged > 1 ) {
			$out .= '<a class="cbd-btn cbd-btn-outline" href="' . $base( $paged - 1 ) . '">← ' . esc_html__( 'Previous', 'community-business-directory' ) . '</a>';
		}
		$out .= '<span class="cbd-jobs-pageinfo">' . esc_html( sprintf(
			/* translators: 1: current page, 2: total pages */
			__( 'Page %1$d of %2$d', 'community-business-directory' ),
			$paged,
			$pages
		) ) . '</span>';
		if ( $paged < $pages ) {
			$out .= '<a class="cbd-btn cbd-btn-outline" href="' . $base( $paged + 1 ) . '">' . esc_html__( 'Next', 'community-business-directory' ) . ' →</a>';
		}
		$out .= '</nav>';
		return $out;
	}

	/** Up-to-two-letter company monogram for the logo placeholder. */
	private function monogram( string $name ): string {
		$name  = trim( wp_strip_all_tags( $name ) );
		if ( $name === '' ) {
			return '#';
		}
		$parts = preg_split( '/\s+/', $name );
		$mono  = mb_substr( $parts[0], 0, 1 );
		if ( count( $parts ) > 1 ) {
			$mono .= mb_substr( end( $parts ), 0, 1 );
		}
		return mb_strtoupper( $mono );
	}
}
