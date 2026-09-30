<?php
namespace CBD\Frontend\Shortcodes;
defined( 'ABSPATH' ) || exit;

class ReviewsShortcode {

    /**
     * Render a single review row. Shared by the shortcode, the single-business
     * template and the AJAX submit response so freshly posted reviews match.
     *
     * @param object $r       Review row (author_name, rating, title, content, created_at, response).
     * @param bool   $pending Show a "pending approval" badge (moderation on).
     */
    public static function render_item( object $r, bool $pending = false ): string {
        $rating = max( 0, min( 5, (int) ( $r->rating ?? 0 ) ) );
        $when   = ! empty( $r->created_at ) ? strtotime( $r->created_at ) : time();
        ob_start(); ?>
<div class="cbd-review-item">
	<div class="cbd-review-header">
		<div class="cbd-review-avatar"><?php echo esc_html( mb_strtoupper( mb_substr( $r->author_name ?: 'A', 0, 1 ) ) ); ?></div>
		<div>
			<strong><?php echo esc_html( $r->author_name ?: __( 'Anonymous', 'community-business-directory' ) ); ?></strong>
			<?php if ( $pending ) : ?><span class="cbd-review-pending"><?php esc_html_e( 'Pending approval', 'community-business-directory' ); ?></span><?php endif; ?>
			<div class="cbd-stars"><?php echo str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ); ?></div>
			<small><?php echo esc_html( date_i18n( get_option( 'date_format' ), $when ) ); ?></small>
		</div>
		<?php // "Signed in with …" pill for the reviewer (top-right), when a real account is attached.
		echo \cbd_provider_badge( (int) ( $r->author_id ?? 0 ) ); ?>
	</div>
	<?php if ( ! empty( $r->title ) ) : ?><h4><?php echo esc_html( $r->title ); ?></h4><?php endif; ?>
	<p><?php echo esc_html( $r->content ); ?></p>
	<?php if ( ! empty( $r->response ) ) : ?>
	<div class="cbd-review-response">
		<strong><?php esc_html_e( 'Business Response:', 'community-business-directory' ); ?></strong>
		<p><?php echo esc_html( $r->response ); ?></p>
	</div>
	<?php endif; ?>
</div>
        <?php
        return ob_get_clean();
    }

    public function render( array $atts ): string {
        $atts = shortcode_atts( [ 'business_id' => 0, 'show_form' => 'true' ], $atts );
        $business_id = (int) $atts['business_id'] ?: (int) get_the_ID();
        if ( ! $business_id ) return '';

        global $wpdb;
        $reviews = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cbd_reviews WHERE business_id = %d AND status = 'approved' ORDER BY created_at DESC",
            $business_id
        ) );

        ob_start(); ?>
<div class="cbd-wrap cbd-reviews">
  <h3><?php esc_html_e( 'Customer Reviews', 'community-business-directory' ); ?></h3>

  <div class="cbd-reviews-list">
    <?php foreach ( (array) $reviews as $r ) echo self::render_item( $r ); ?>
  </div>
  <p class="cbd-no-reviews"<?php echo $reviews ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'No reviews yet. Be the first!', 'community-business-directory' ); ?></p>

  <?php if ( $atts['show_form'] === 'true' && is_user_logged_in() ) : ?>
  <div class="cbd-review-form-wrap">
    <h4><?php esc_html_e( 'Leave a Review', 'community-business-directory' ); ?></h4>
    <form id="cbd-review-form" class="cbd-form">
      <?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
      <input type="hidden" name="action" value="cbd_submit_review">
      <input type="hidden" name="business_id" value="<?php echo esc_attr( $business_id ); ?>">
      <div class="cbd-form-row">
        <label><?php esc_html_e( 'Rating', 'community-business-directory' ); ?> <span class="req">*</span></label>
        <div class="cbd-star-picker" id="cbd-star-picker">
          <?php for ( $i = 5; $i >= 1; $i-- ) : ?>
          <input type="radio" name="review_rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
          <label for="star<?php echo $i; ?>" title="<?php echo $i; ?> stars">★</label>
          <?php endfor; ?>
        </div>
      </div>
      <div class="cbd-form-row">
        <label><?php esc_html_e( 'Review Title', 'community-business-directory' ); ?></label>
        <input type="text" name="review_title" placeholder="<?php esc_attr_e( 'Summary of your experience', 'community-business-directory' ); ?>">
      </div>
      <div class="cbd-form-row">
        <label><?php esc_html_e( 'Your Review', 'community-business-directory' ); ?> <span class="req">*</span></label>
        <textarea name="review_content" rows="4" required data-no-wysiwyg placeholder="<?php esc_attr_e( 'Share your experience…', 'community-business-directory' ); ?>"></textarea>
      </div>
      <button type="submit" class="cbd-btn cbd-btn-primary">
        <span class="btn-text"><?php esc_html_e( 'Submit Review', 'community-business-directory' ); ?></span>
        <span class="btn-loading" style="display:none;"><?php esc_html_e( 'Submitting…', 'community-business-directory' ); ?></span>
      </button>
      <div class="cbd-form-msg" style="display:none;"></div>
    </form>
  </div>
  <?php elseif ( $atts['show_form'] === 'true' && ! is_user_logged_in() ) : ?>
  <p><?php printf( wp_kses( __( '<a href="%s">Log in</a> to leave a review.', 'community-business-directory' ), [ 'a' => [ 'href' => [] ] ] ), esc_url( wp_login_url( get_permalink() ) ) ); ?></p>
  <?php endif; ?>
</div>
<?php
        return ob_get_clean();
    }
}
