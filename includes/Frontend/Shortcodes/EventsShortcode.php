<?php

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class EventsShortcode
{

  use LoadMore;

  /** Time-status segments shown as filter tabs (and accepted as ?event_status=). */
  private const STATUSES = ['upcoming', 'current', 'past', 'all'];

  /** Tab + heading copy for each status segment. */
  private static function status_labels(): array
  {
    return [
      'upcoming' => ['tab' => __('Upcoming', 'community-business-directory'),      'heading' => __('Upcoming Events', 'community-business-directory')],
      'current'  => ['tab' => __('Happening Now', 'community-business-directory'), 'heading' => __('Happening Now', 'community-business-directory')],
      'past'     => ['tab' => __('Past', 'community-business-directory'),          'heading' => __('Past Events', 'community-business-directory')],
      'all'      => ['tab' => __('All', 'community-business-directory'),           'heading' => __('All Events', 'community-business-directory')],
    ];
  }

  /**
   * Classify an event by its start/end against "now" (site timezone):
   * upcoming (not started) | current (in progress) | past (ended).
   *
   * @return array{0:string,1:string} [status key, translated label]
   */
  private static function event_status(?\DateTime $start, ?\DateTime $end): array
  {
    if (! $start) {
      return ['', ''];
    }
    $now = current_datetime();
    $end = $end ?: $start;
    if ($now < $start) {
      return ['upcoming', __('Upcoming', 'community-business-directory')];
    }
    if ($now <= $end) {
      return ['current', __('Happening now', 'community-business-directory')];
    }
    return ['past', __('Past', 'community-business-directory')];
  }

  public function render(array $atts): string
  {
    $atts = shortcode_atts([
      'count'    => 6,
      'upcoming' => 'true',
      'category' => '',
      'view'     => 'grid',
      'status'   => '',
    ], $atts);

    // One combined, priority-grouped stream by default (Happening Now → Upcoming
    // → Past), grouped with headers in items(). An explicit status="" att still
    // narrows to a single segment.
    $status = in_array($atts['status'], self::STATUSES, true) ? $atts['status'] : 'all';
    $atts['status'] = $status;

    $per_page = max(1, (int) $atts['count']);
    $first    = $this->items($atts, 1, $per_page);
    $total    = (int) $first['total'];

    ob_start(); ?>
    <div class="cbd-wrap cbd-events" id="cbd-events">
      <div class="cbd-section-header">
        <h2><?php esc_html_e('Events', 'community-business-directory'); ?></h2>
      </div>

      <?php if (! is_user_logged_in() || current_user_can('cbd_create_event')) : ?>
        <div class="cbd-events-toolbar">
          <?php if (is_user_logged_in()) : ?>
            <button class="cbd-btn cbd-btn-primary" id="cbd-new-event-btn">
              + <?php esc_html_e('Create Event', 'community-business-directory'); ?>
            </button>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (is_user_logged_in()) : ?>
        <!-- Create Event Form (hidden by default) -->
        <div id="cbd-event-form-wrap" class="cbd-modal-wrap" style="display:none;">
          <div class="cbd-modal">
            <button class="cbd-modal-close" id="cbd-event-form-close">✕</button>
            <h3><?php esc_html_e('Create New Event', 'community-business-directory'); ?></h3>
            <form id="cbd-event-form" class="cbd-form">
              <?php wp_nonce_field('cbd_nonce', 'cbd_nonce'); ?>
              <input type="hidden" name="action" value="cbd_create_event">

              <div class="cbd-form-row">
                <label><?php esc_html_e('Event Title', 'community-business-directory'); ?> <span class="req">*</span></label>
                <input type="text" name="event_title" required placeholder="<?php esc_attr_e('Event name', 'community-business-directory'); ?>">
              </div>
              <div class="cbd-form-row">
                <label><?php esc_html_e('Description', 'community-business-directory'); ?> <span class="req">*</span></label>
                <textarea name="event_description" rows="4" required placeholder="<?php esc_attr_e('Describe your event…', 'community-business-directory'); ?>"></textarea>
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Start Date & Time', 'community-business-directory'); ?> <span class="req">*</span></label>
                  <input type="datetime-local" name="event_start" required>
                </div>
                <div class="cbd-form-row">
                  <label><?php esc_html_e('End Date & Time', 'community-business-directory'); ?> <span class="req">*</span></label>
                  <input type="datetime-local" name="event_end" required>
                </div>
              </div>
              <div class="cbd-form-row">
                <label><?php esc_html_e('Venue Name', 'community-business-directory'); ?></label>
                <input type="text" name="event_venue" placeholder="<?php esc_attr_e('e.g. City Hall, Main Street', 'community-business-directory'); ?>">
              </div>
              <div class="cbd-form-row">
                <label><?php esc_html_e('Venue Address', 'community-business-directory'); ?></label>
                <input type="text" name="event_venue_addr" placeholder="<?php esc_attr_e('Full address', 'community-business-directory'); ?>">
              </div>
              <div class="cbd-form-cols">
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Ticket URL', 'community-business-directory'); ?></label>
                  <input type="url" name="event_ticket_url" placeholder="https://tickets.example.com">
                </div>
                <div class="cbd-form-row">
                  <label><?php esc_html_e('Ticket Price', 'community-business-directory'); ?></label>
                  <input type="number" name="event_ticket_price" min="0" step="0.01" placeholder="0.00">
                </div>
              </div>
              <div class="cbd-form-row">
                <label>
                  <input type="checkbox" name="event_is_free" value="1" checked>
                  <?php esc_html_e('This is a free event', 'community-business-directory'); ?>
                </label>
              </div>
              <div class="cbd-form-row">
                <label><?php esc_html_e('Event Image', 'community-business-directory'); ?></label>
                <input type="file" name="event_image" accept="image/*">
              </div>
              <div class="cbd-form-actions">
                <button type="submit" class="cbd-btn cbd-btn-primary">
                  <span class="btn-text"><?php esc_html_e('Create Event', 'community-business-directory'); ?></span>
                  <span class="btn-loading" style="display:none;"><?php esc_html_e('Creating…', 'community-business-directory'); ?></span>
                </button>
              </div>
              <div class="cbd-form-msg" style="display:none;"></div>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <?php echo $this->loadmore_open('events', ['count' => $per_page, 'category' => $atts['category'], 'upcoming' => $atts['upcoming'], 'view' => $atts['view'], 'status' => $status], $per_page, $total, 'cbd-lm-autoload'); ?>
      <div class="cbd-events-grid cbd-lm-list">
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
   * Render one page of event cards.
   *
   * @return array{html:string,total:int}
   */
  public function items(array $atts, int $page, int $per_page): array
  {
    global $wpdb;
    $status = isset($atts['status']) && in_array($atts['status'], self::STATUSES, true)
      ? $atts['status']
      : (('false' === ($atts['upcoming'] ?? 'true')) ? 'all' : 'upcoming');
    $now = current_time('mysql');

    $args = [
      'post_type'      => 'cbd_event',
      'post_status'    => 'publish',
      'posts_per_page' => $per_page,
      'paged'          => $page,
    ];
    if (! empty($atts['category'])) {
      $args['tax_query'] = [['taxonomy' => 'cbd_event_cat', 'field' => 'slug', 'terms' => $atts['category']]];
    }

    // Join cbd_events to filter/order by the actual event dates — the CPT post
    // date is the creation date, not when the event happens. Upcoming sorts
    // soonest-first, past sorts most-recent-first.
    $clauses = function (array $c) use ($wpdb, $now, $status): array {
      $c['join'] .= " INNER JOIN {$wpdb->prefix}cbd_events cbdev ON cbdev.post_id = {$wpdb->posts}.ID ";
      if ('upcoming' === $status) {
        $c['where']  .= $wpdb->prepare(' AND cbdev.start_date > %s ', $now);
        $c['orderby'] = ' cbdev.start_date ASC ';
      } elseif ('current' === $status) {
        $c['where']  .= $wpdb->prepare(' AND cbdev.start_date <= %s AND cbdev.end_date >= %s ', $now, $now);
        $c['orderby'] = ' cbdev.start_date ASC ';
      } elseif ('past' === $status) {
        $c['where']  .= $wpdb->prepare(' AND cbdev.end_date < %s ', $now);
        $c['orderby'] = ' cbdev.start_date DESC ';
      } else {
        // Combined stream: Happening Now (0) → Upcoming (1) → Past (2); within a
        // group, upcoming/current sort soonest-first and past most-recent-first.
        $c['orderby'] = $wpdb->prepare(
          ' (CASE WHEN cbdev.start_date <= %s AND cbdev.end_date >= %s THEN 0
                  WHEN cbdev.start_date > %s THEN 1 ELSE 2 END) ASC,
            (CASE WHEN cbdev.end_date < %s THEN -UNIX_TIMESTAMP(cbdev.start_date)
                  ELSE UNIX_TIMESTAMP(cbdev.start_date) END) ASC ',
          $now,
          $now,
          $now,
          $now
        );
      }
      return $c;
    };
    add_filter('posts_clauses', $clauses);
    $query = new \WP_Query($args);
    remove_filter('posts_clauses', $clauses);

    ob_start();
    $glabels    = self::status_labels();
    $prev_group = null; // Emit a full-row group heading whenever the status changes.
    if ($query->have_posts()) :
      while ($query->have_posts()) : $query->the_post();
        global $wpdb;
        $ev = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cbd_events WHERE post_id = %d", get_the_ID()), ARRAY_A) ?? [];
        $tz    = wp_timezone();
        $start = ! empty($ev['start_date']) ? new \DateTime($ev['start_date'], $tz) : null;
        $end   = ! empty($ev['end_date'])   ? new \DateTime($ev['end_date'], $tz)   : null;
        [$st_key, $st_label] = self::event_status($start, $end);
        if ($st_key && $st_key !== $prev_group) :
          $prev_group = $st_key;
    ?>
        <h3 class="cbd-lm-group" data-group="<?php echo esc_attr($st_key); ?>"><?php echo esc_html($glabels[$st_key]['tab']); ?></h3>
    <?php endif; ?>
        <div class="cbd-event-card" data-status="<?php echo esc_attr($st_key); ?>">
          <?php if (has_post_thumbnail()) : ?>
            <a href="<?php the_permalink(); ?>" class="cbd-event-img-wrap">
              <?php the_post_thumbnail('medium', ['class' => 'cbd-event-img', 'loading' => 'lazy']); ?>
            </a>
          <?php else : ?>
            <div class="cbd-event-img cbd-event-img-ph"></div>
          <?php endif; ?>

          <?php if ($start) : ?>
            <div class="cbd-event-date-badge">
              <span class="cbd-event-month"><?php echo esc_html($start->format('M')); ?></span>
              <span class="cbd-event-day"><?php echo esc_html($start->format('d')); ?></span>
            </div>
          <?php endif; ?>

          <?php if (! empty($ev['is_free'])) : ?>
            <span class="cbd-badge cbd-badge-free"><?php esc_html_e('Free', 'community-business-directory'); ?></span>
          <?php elseif (! empty($ev['ticket_price'])) : ?>
            <span class="cbd-badge cbd-badge-paid"><?php echo esc_html(get_option('cbd_currency_symbol', '£') . number_format((float) $ev['ticket_price'], 2)); ?></span>
          <?php endif; ?>

          <?php echo \cbd_card_business_info((int) ($ev['business_id'] ?? 0)); ?>

          <div class="cbd-event-body">
            <?php if ($st_key) : ?>
              <span class="cbd-event-status cbd-event-status-<?php echo esc_attr($st_key); ?>"><?php echo esc_html($st_label); ?></span>
            <?php endif; ?>

            <h3 class="cbd-event-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>

            <?php if ($start) : ?>
              <p class="cbd-event-meta">
                <span><?php echo \cbd_icon('clock-outline'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span><span><?php echo esc_html($start->format('D j M Y, g:ia')); ?></span>
              </p>
            <?php endif; ?>

            <?php if (! empty($ev['venue_name'])) : ?>
              <p class="cbd-event-venue"><span><?php echo \cbd_icon('map-marker'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span><span><?php echo esc_html($ev['venue_name']); ?></span></p>
            <?php endif; ?>


            <p class="cbd-event-excerpt"><?php echo wp_trim_words(get_the_excerpt(), 15); ?></p>

            <div class="cbd-event-footer">
              <a href="<?php the_permalink(); ?>" class="cbd-btn cbd-btn-sm"><?php esc_html_e('Learn More', 'community-business-directory'); ?></a>
              <?php if (! empty($ev['ticket_url'])) : ?>
                <a href="<?php echo esc_url($ev['ticket_url']); ?>" class="cbd-btn cbd-btn-sm cbd-btn-outline" target="_blank" rel="noopener">
                  <?php esc_html_e('Get Tickets', 'community-business-directory'); ?>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php
      endwhile;
      wp_reset_postdata();
    elseif (1 === $page) : ?>
      <div class="cbd-empty-state">
        <span class="cbd-empty-icon">📅</span>
        <h3><?php
          $empty = [
            'upcoming' => __('No upcoming events', 'community-business-directory'),
            'current'  => __('Nothing happening right now', 'community-business-directory'),
            'past'     => __('No past events', 'community-business-directory'),
            'all'      => __('No events yet', 'community-business-directory'),
          ];
          echo esc_html($empty[$status] ?? $empty['all']);
        ?></h3>
        <p><?php esc_html_e('Check back soon or create the first event!', 'community-business-directory'); ?></p>
      </div>
<?php endif;

    return ['html' => (string) ob_get_clean(), 'total' => (int) $query->found_posts];
  }
}
