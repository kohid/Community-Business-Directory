<?php

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class PromotionsShortcode
{

  use LoadMore;

  /** Time-status segments: active|upcoming|expired|all (priority: active first). */
  private const STATUSES = ['active', 'upcoming', 'expired', 'all'];

  /** Group-header copy for each status segment. */
  private static function status_labels(): array
  {
    return [
      'active'   => ['tab' => __('Active', 'community-business-directory')],
      'upcoming' => ['tab' => __('Upcoming', 'community-business-directory')],
      'expired'  => ['tab' => __('Expired', 'community-business-directory')],
      'all'      => ['tab' => __('All', 'community-business-directory')],
    ];
  }

  /**
   * Classify a promotion by its start/expiry dates ('Y-m-d', site timezone):
   * upcoming (not started) | expired (past expiry) | active (running / no expiry).
   *
   * @return array{0:string,1:string} [status key, translated label]
   */
  private static function promo_status(?string $start, ?string $expiry): array
  {
    $today = current_time('Y-m-d');
    if ($start && $start > $today) {
      return ['upcoming', __('Upcoming', 'community-business-directory')];
    }
    if ($expiry && $expiry < $today) {
      return ['expired', __('Expired', 'community-business-directory')];
    }
    return ['active', __('Active', 'community-business-directory')];
  }

  public function render(array $atts): string
  {
    $atts = shortcode_atts(['count' => 6, 'featured' => '', 'category' => '', 'status' => ''], $atts);
    $today = gmdate('Y-m-d'); // default start date for the create-promo form.

    // One combined, priority-grouped stream by default (Active → Upcoming →
    // Expired). An explicit status="" att narrows to a single segment.
    $status = in_array($atts['status'], self::STATUSES, true) ? $atts['status'] : 'all';
    $atts['status'] = $status;

    $per_page = max(1, (int) $atts['count']);
    $first    = $this->items($atts, 1, $per_page);
    $total    = (int) $first['total'];

    ob_start(); ?>
    <div class="cbd-wrap cbd-promotions" id="cbd-promotions">
      <div class="cbd-section-header">
        <h2><?php esc_html_e('Offers & Deals', 'community-business-directory'); ?></h2>
        <?php if (is_user_logged_in()) : ?>
          <button class="cbd-btn cbd-btn-primary" id="cbd-new-promo-btn">
            + <?php esc_html_e('Add Promotion', 'community-business-directory'); ?>
          </button>
        <?php endif; ?>
      </div>

      <?php if (is_user_logged_in()) : ?>
        <div id="cbd-promo-form-wrap" class="cbd-modal-wrap" style="display:none;">
          <div class="cbd-modal">
            <button class="cbd-modal-close" id="cbd-promo-form-close">✕</button>
            <h3><?php esc_html_e('Create Promotion', 'community-business-directory'); ?></h3>
            <form id="cbd-promo-form" class="cbd-form">
              <?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
              <input type="hidden" name="action" value="cbd_create_promotion">
              <div class="cbd-form-row">
                <label><?php esc_html_e('Offer Title', 'community-business-directory'); ?> <span class="req">*</span></label>
                <input type="text" name="promo_title" required placeholder="e.g. 20% Off Spring Collection">
              </div>
              <div class="cbd-form-row">
                <label><?php esc_html_e('Description', 'community-business-directory'); ?> <span class="req">*</span></label>
                <textarea name="promo_description" rows="3" required placeholder="Describe the offer…"></textarea>
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Coupon Code', 'community-business-directory'); ?></label>
                  <input type="text" name="promo_code" placeholder="SAVE20" style="text-transform:uppercase;">
                </div>
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Discount', 'community-business-directory'); ?></label>
                  <div style="display:flex;gap:8px;">
                    <input type="number" name="promo_discount_value" min="0" placeholder="20" style="width:80px;">
                    <select name="promo_discount_type">
                      <option value="percent">%</option>
                      <option value="fixed"><?php echo esc_html(get_option('cbd_currency_symbol', '£')); ?></option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Start Date', 'community-business-directory'); ?></label>
                  <input type="date" name="promo_start" value="<?php echo esc_attr($today); ?>">
                </div>
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Expiry Date', 'community-business-directory'); ?></label>
                  <input type="date" name="promo_expiry">
                </div>
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label><?php esc_html_e('CTA Button Text', 'community-business-directory'); ?></label>
                  <input type="text" name="promo_cta_text" value="Get Offer">
                </div>
                <div class="cbd-form-row">
                  <label><?php esc_html_e('CTA URL', 'community-business-directory'); ?></label>
                  <input type="url" name="promo_cta_url" placeholder="https://…">
                </div>
              </div>
              <div class="cbd-form-actions">
                <button type="submit" class="cbd-btn cbd-btn-primary">
                  <span class="btn-text"><?php esc_html_e('Publish Promotion', 'community-business-directory'); ?></span>
                  <span class="btn-loading" style="display:none;"><?php esc_html_e('Saving…', 'community-business-directory'); ?></span>
                </button>
              </div>
              <div class="cbd-form-msg" style="display:none;"></div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php echo $this->loadmore_open('promotions', ['count' => $per_page, 'category' => $atts['category'], 'featured' => $atts['featured'], 'status' => $status], $per_page, $total, 'cbd-lm-autoload'); ?>
      <div class="cbd-promos-grid cbd-lm-list">
        <?php echo $first['html']; // phpcs:ignore — escaped within items() 
        ?>
      </div>
      <?php echo $this->loadmore_button(1, $per_page, $total); // phpcs:ignore 
      ?>
    </div><!-- /.cbd-lm -->
    </div>
    <?php
    return ob_get_clean();
  }

  /**
   * Render one page of promotion cards.
   *
   * @return array{html:string,total:int}
   */
  public function items(array $atts, int $page, int $per_page): array
  {
    global $wpdb;
    $today  = current_time('Y-m-d');
    $status = isset($atts['status']) && in_array($atts['status'], self::STATUSES, true) ? $atts['status'] : 'all';

    $args = [
      'post_type'      => 'cbd_promotion',
      'post_status'    => 'publish',
      'posts_per_page' => $per_page,
      'paged'          => $page,
    ];

    // Join cbd_promotions to filter/order by the offer's real validity window —
    // the CPT post date isn't the start/expiry. active → upcoming → expired.
    $clauses = function (array $c) use ($wpdb, $today, $status): array {
      $c['join'] .= " INNER JOIN {$wpdb->prefix}cbd_promotions cbdpr ON cbdpr.post_id = {$wpdb->posts}.ID ";
      if ('active' === $status) {
        $c['where']  .= $wpdb->prepare(' AND (cbdpr.start_date IS NULL OR cbdpr.start_date <= %s) AND (cbdpr.expiry_date IS NULL OR cbdpr.expiry_date >= %s) ', $today, $today);
        $c['orderby'] = ' cbdpr.expiry_date IS NULL ASC, cbdpr.expiry_date ASC ';
      } elseif ('upcoming' === $status) {
        $c['where']  .= $wpdb->prepare(' AND cbdpr.start_date > %s ', $today);
        $c['orderby'] = ' cbdpr.start_date ASC ';
      } elseif ('expired' === $status) {
        $c['where']  .= $wpdb->prepare(' AND cbdpr.expiry_date IS NOT NULL AND cbdpr.expiry_date < %s ', $today);
        $c['orderby'] = ' cbdpr.expiry_date DESC ';
      } else {
        $c['orderby'] = $wpdb->prepare(
          ' (CASE
               WHEN (cbdpr.start_date IS NULL OR cbdpr.start_date <= %s) AND (cbdpr.expiry_date IS NULL OR cbdpr.expiry_date >= %s) THEN 0
               WHEN cbdpr.start_date > %s THEN 1 ELSE 2 END) ASC,
            (CASE WHEN cbdpr.expiry_date IS NOT NULL AND cbdpr.expiry_date < %s
                  THEN -UNIX_TIMESTAMP(cbdpr.expiry_date)
                  ELSE UNIX_TIMESTAMP(COALESCE(cbdpr.start_date, cbdpr.expiry_date)) END) ASC ',
          $today,
          $today,
          $today,
          $today
        );
      }
      return $c;
    };
    add_filter('posts_clauses', $clauses);
    $query = new \WP_Query($args);
    remove_filter('posts_clauses', $clauses);

    ob_start();
    $glabels    = self::status_labels();
    $prev_group = null; // Full-row group heading whenever the status changes.
    if ($query->have_posts()) :
      while ($query->have_posts()) : $query->the_post();
        $pr = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_promotions WHERE post_id = %d", get_the_ID()), ARRAY_A) ?? [];
        $start_d  = (! empty($pr['start_date'])  && '0000-00-00' !== $pr['start_date'])  ? $pr['start_date']  : null;
        $expiry_d = (! empty($pr['expiry_date']) && '0000-00-00' !== $pr['expiry_date']) ? $pr['expiry_date'] : null;
        [$st_key, $st_label] = self::promo_status($start_d, $expiry_d);
        $expiring = $expiry_d && strtotime($expiry_d) < strtotime('+7 days') && strtotime($expiry_d) >= strtotime($today);
        if ($st_key && $st_key !== $prev_group) :
          $prev_group = $st_key;
    ?>
        <h3 class="cbd-lm-group" data-group="<?php echo esc_attr($st_key); ?>"><?php echo esc_html($glabels[$st_key]['tab']); ?></h3>
    <?php endif; ?>
        <div class="cbd-promo-card <?php echo $expiring ? 'is-expiring' : ''; ?>" data-status="<?php echo esc_attr($st_key); ?>">
          <?php if ($expiring) : ?>
            <span class="cbd-badge cbd-badge-expiring"><?php esc_html_e('Expiring Soon', 'community-business-directory'); ?></span>
          <?php endif; ?>
          <?php if (! empty($pr['discount_value'])) : ?>
            <div class="cbd-promo-discount">
              <?php echo esc_html($pr['discount_value']); ?>
              <?php echo $pr['discount_type'] === 'percent' ? '%' : esc_html(get_option('cbd_currency_symbol', '£')); ?>
              <span><?php esc_html_e('OFF', 'community-business-directory'); ?></span>
            </div>
          <?php endif; ?>
          <?php if ($st_key) : ?>
            <span class="cbd-promo-status cbd-promo-status-<?php echo esc_attr($st_key); ?>"><?php echo esc_html($st_label); ?></span>
          <?php endif; ?>
          <h3 class="cbd-promo-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

          <p class="cbd-promo-desc"><?php echo wp_trim_words(get_the_excerpt(), 15); ?></p>
          <?php if (! empty($pr['coupon_code'])) : ?>
            <div class="cbd-promo-code-wrap">
              <span class="cbd-promo-code"><?php echo esc_html($pr['coupon_code']); ?></span>

              <?php if (! empty($pr['cta_url'])) : ?>
                <a href="<?php echo esc_url($pr['cta_url']); ?>" class="cbd-btn cbd-btn-primary cbd-btn-full" target="_blank" rel="noopener">
                  <?php echo esc_html($pr['cta_text'] ?: __('Get Offer', 'community-business-directory')); ?>
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if ($expiry_d) : ?>
            <p class="cbd-promo-expiry">⏰ <?php printf(esc_html__('Expires %s', 'community-business-directory'), esc_html(date_i18n(get_option('date_format'), strtotime($expiry_d)))); ?></p>
          <?php elseif ('expired' !== $st_key) : ?>
            <p class="cbd-promo-expiry cbd-promo-expiry-none">∞ <?php esc_html_e('No expiry', 'community-business-directory'); ?></p>
          <?php endif; ?>


          <?php echo \cbd_card_business_info((int) ($pr['business_id'] ?? 0)); ?>
        </div>
      <?php
      endwhile;
      wp_reset_postdata();
    elseif (1 === $page) : ?>
      <div class="cbd-empty-state">
        <span class="cbd-empty-icon">🏷️</span>
        <h3><?php
          $empty = [
            'active'   => __('No active offers right now', 'community-business-directory'),
            'upcoming' => __('No upcoming offers', 'community-business-directory'),
            'expired'  => __('No expired offers', 'community-business-directory'),
            'all'      => __('No promotions right now', 'community-business-directory'),
          ];
          echo esc_html($empty[$status] ?? $empty['all']);
        ?></h3>
        <p><?php esc_html_e('Check back soon for great deals from local businesses.', 'community-business-directory'); ?></p>
      </div>
<?php endif;

    return ['html' => (string) ob_get_clean(), 'total' => (int) $query->found_posts];
  }
}
