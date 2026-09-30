<?php
namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * [cbd_calendar] — an interactive month/agenda event calendar.
 *
 * A toolbar (live search + Month/List switch) sits above a Monday-first month
 * grid that fills whole weeks (adjacent-month days are muted), colour-codes each
 * event by its category, shows time-stamped chips with multi-day connectors and a
 * "+N more" expander, and an agenda (List) view grouped by day. Month navigation
 * and search re-render through the public `cbd_calendar` AJAX action (no reload);
 * the same build() powers the initial server render so markup stays identical.
 */
class CalendarShortcode {

	/** Visible event chips per day before the "+N more" expander appears. */
	private const MAX_PER_DAY = 3;

	/** Category-accent palette (deterministic per category name). */
	private const PALETTE = [ '#2b6344', '#1e6b7a', '#c47b1a', '#6b2ba8', '#b84a35', '#2b6387', '#9c2c4b', '#3a7d5a', '#7a5b1e', '#4c5bd4' ];

	public function render( array $atts ): string {
		$atts = shortcode_atts( [ 'category' => '', 'month' => '' ], $atts );

		// Local "now" components (no date(timestamp) double-offset).
		$year  = (int) current_time( 'Y' );
		$month = (int) current_time( 'n' );

		// Deep-linkable month (?cbd_cal_month=YYYY-M) so a shared URL lands right.
		if ( isset( $_GET['cbd_cal_month'] ) ) {
			$parts = explode( '-', sanitize_text_field( wp_unslash( $_GET['cbd_cal_month'] ) ) );
			if ( count( $parts ) === 2 && (int) $parts[1] >= 1 && (int) $parts[1] <= 12 ) {
				$year  = (int) $parts[0];
				$month = (int) $parts[1];
			}
		}

		// Focus day drives the Day view + the picker selection: today when it
		// falls in the shown month, else the 1st.
		$today = current_time( 'Y-m-d' );
		$focus = ( (int) substr( $today, 0, 4 ) === $year && (int) substr( $today, 5, 2 ) === $month )
			? $today
			: sprintf( '%04d-%02d-01', $year, $month );

		$data = self::view_data( $focus, '', (string) $atts['category'] );

		ob_start(); ?>
		<div class="cbd-wrap cbd-cal" data-date="<?php echo esc_attr( $data['date'] ); ?>" data-ym="<?php echo esc_attr( $data['ym'] ); ?>" data-month-title="<?php echo esc_attr( $data['monthTitle'] ); ?>" data-day-title="<?php echo esc_attr( $data['dayTitle'] ); ?>" data-cat="<?php echo esc_attr( (string) $atts['category'] ); ?>" data-view="month">
			<div class="cbd-cal-toolbar">
				<form class="cbd-cal-search" role="search" onsubmit="return false;">
					<span class="cbd-cal-search-ic"><?php echo \cbd_icon( 'magnify' ); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?></span>
					<input type="search" class="cbd-cal-q" placeholder="<?php esc_attr_e( 'Search for events', 'community-business-directory' ); ?>" autocomplete="off">
					<button type="submit" class="cbd-btn cbd-btn-primary cbd-cal-find"><?php esc_html_e( 'Find Events', 'community-business-directory' ); ?></button>
				</form>
				<div class="cbd-cal-views" role="tablist" aria-label="<?php esc_attr_e( 'Calendar view', 'community-business-directory' ); ?>">
					<button type="button" class="cbd-cal-view" data-view="list" role="tab" aria-selected="false"><?php esc_html_e( 'List', 'community-business-directory' ); ?></button>
					<button type="button" class="cbd-cal-view is-active" data-view="month" role="tab" aria-selected="true"><?php esc_html_e( 'Month', 'community-business-directory' ); ?></button>
					<button type="button" class="cbd-cal-view" data-view="day" role="tab" aria-selected="false"><?php esc_html_e( 'Day', 'community-business-directory' ); ?></button>
				</div>
			</div>

			<div class="cbd-cal-nav">
				<div class="cbd-cal-nav-btns">
					<button type="button" class="cbd-cal-arrow cbd-cal-prev" aria-label="<?php esc_attr_e( 'Previous', 'community-business-directory' ); ?>"><?php echo \cbd_icon( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
					<button type="button" class="cbd-cal-arrow cbd-cal-next" aria-label="<?php esc_attr_e( 'Next', 'community-business-directory' ); ?>"><?php echo \cbd_icon( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				</div>
				<button type="button" class="cbd-cal-today-btn"><?php esc_html_e( 'Today', 'community-business-directory' ); ?></button>
				<div class="cbd-cal-titlewrap">
					<button type="button" class="cbd-cal-title" aria-haspopup="dialog" aria-expanded="false">
						<span class="cbd-cal-title-text"><?php echo esc_html( $data['monthTitle'] ); ?></span>
						<svg class="cbd-cal-title-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
					</button>
					<div class="cbd-cal-picker"></div>
				</div>
			</div>

			<div class="cbd-cal-body">
				<div class="cbd-cal-month"><?php echo $data['grid']; // phpcs:ignore WordPress.Security.EscapeOutput — built with esc_* in grid_html() ?></div>
				<div class="cbd-cal-agenda" hidden><?php echo $data['agenda']; // phpcs:ignore WordPress.Security.EscapeOutput — built with esc_* in agenda_html() ?></div>
				<div class="cbd-cal-day" hidden><?php echo $data['day']; // phpcs:ignore WordPress.Security.EscapeOutput — built with esc_* in day_html() ?></div>
				<div class="cbd-cal-loader" hidden aria-hidden="true"><span class="cbd-cal-spinner"></span></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Assemble everything every view needs for one focus date: the month grid +
	 * agenda for the date's month, and the day timeline for the date itself.
	 * Shared by the shortcode (initial render) and the AJAX endpoint.
	 *
	 * @return array{date:string,ym:string,monthTitle:string,dayTitle:string,grid:string,agenda:string,day:string,count:int}
	 */
	public static function view_data( string $date, string $search, string $category ): array {
		$ts = strtotime( $date );
		if ( ! $ts ) {
			$ts = strtotime( current_time( 'Y-m-d' ) );
		}
		$month = self::build( (int) date( 'Y', $ts ), (int) date( 'n', $ts ), $search, $category );
		$day   = self::build_day( date( 'Y-m-d', $ts ), $search, $category );

		return [
			'date'       => date( 'Y-m-d', $ts ),
			'ym'         => $month['ym'],
			'monthTitle' => $month['title'],
			'dayTitle'   => $day['title'],
			'grid'       => $month['grid'],
			'agenda'     => $month['agenda'],
			'day'        => $day['day'],
			'count'      => $month['count'],
		];
	}

	/**
	 * Build the single-day timeline payload.
	 *
	 * @return array{date:string,title:string,day:string,count:int}
	 */
	public static function build_day( string $date, string $search, string $category ): array {
		$ts = strtotime( $date ) ?: strtotime( current_time( 'Y-m-d' ) );
		$dk = date( 'Y-m-d', $ts );
		$by = self::fetch_events( $dk, $dk, $search, $category );

		return [
			'date'  => $dk,
			'title' => date( 'F j, Y', $ts ),
			'day'   => self::day_html( $ts, $by[ $dk ] ?? [] ),
			'count' => count( $by[ $dk ] ?? [] ),
		];
	}

	/**
	 * Build everything one month-view needs. Shared by the shortcode and the
	 * AJAX endpoint so server + client renders are byte-identical.
	 *
	 * @return array{ym:string,title:string,grid:string,agenda:string,count:int}
	 */
	public static function build( int $year, int $month, string $search, string $category ): array {
		$month = max( 1, min( 12, $month ) );
		$first = mktime( 0, 0, 0, $month, 1, $year );

		// Whole-week grid: back up to the Monday on/before the 1st, run to the
		// Sunday on/after the last day, so every row is a full week.
		$lead       = ( (int) date( 'N', $first ) ) - 1;             // 0=Mon … 6=Sun
		$grid_start = strtotime( "-{$lead} day", $first );
		$days_in    = (int) date( 't', $first );
		$last       = mktime( 0, 0, 0, $month, $days_in, $year );
		$trail      = 7 - (int) date( 'N', $last );
		$grid_end   = strtotime( "+{$trail} day", $last );

		$by_date = self::fetch_events(
			date( 'Y-m-d', $grid_start ),
			date( 'Y-m-d', $grid_end ),
			$search,
			$category
		);

		return [
			'ym'     => $year . '-' . $month,
			'title'  => date( 'F Y', $first ),
			'grid'   => self::grid_html( $grid_start, $grid_end, $month, $by_date ),
			'agenda' => self::agenda_html( $month, $year, $by_date, $search ),
			'count'  => array_sum( array_map( 'count', $by_date ) ),
		];
	}

	/**
	 * Fetch events overlapping [from,to] and bucket them onto every date they
	 * cover within that window. Each entry carries display + placement fields.
	 *
	 * @return array<string,array<int,array<string,mixed>>> date 'Y-m-d' → events
	 */
	private static function fetch_events( string $from, string $to, string $search, string $category ): array {
		global $wpdb;

		$sql  = "SELECT e.post_id, e.start_date, e.end_date, e.venue_name, p.post_title, p.post_excerpt, p.post_content
				 FROM {$wpdb->prefix}cbd_events e
				 INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
				 WHERE p.post_status = 'publish'
				   AND e.start_date <= %s AND e.end_date >= %s";
		$args = [ $to . ' 23:59:59', $from . ' 00:00:00' ];

		if ( $search !== '' ) {
			$sql   .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( $category !== '' ) {
			$sql   .= " AND p.ID IN (
				SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = 'cbd_category' AND ( t.slug = %s OR t.name = %s ) )";
			$args[] = $category;
			$args[] = $category;
		}
		$sql .= ' ORDER BY e.start_date ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$by_date = [];
		foreach ( (array) $rows as $r ) {
			$s_ts = strtotime( $r->start_date );
			$e_ts = strtotime( $r->end_date ) ?: $s_ts;
			if ( $e_ts < $s_ts ) {
				$e_ts = $s_ts;
			}
			$s_day  = date( 'Y-m-d', $s_ts );
			$e_day  = date( 'Y-m-d', $e_ts );
			$multi  = $s_day !== $e_day;
			$cats   = get_the_terms( (int) $r->post_id, 'cbd_category' );
			$catnm  = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

			// Per-event display fields — computed once here, then reused on every
			// day the event covers (a multi-day event is bucketed on several days).
			$title   = \cbd_label( (string) $r->post_title );
			$url     = (string) get_permalink( (int) $r->post_id );
			$thumb   = (string) get_the_post_thumbnail_url( (int) $r->post_id, 'medium' );
			$range   = $multi
				? __( 'All day', 'community-business-directory' )
				: date( 'H:i', $s_ts ) . ( $e_ts > $s_ts ? ' – ' . date( 'H:i', $e_ts ) : '' );
			$excerpt = trim( (string) $r->post_excerpt );
			if ( '' === $excerpt ) {
				$excerpt = (string) $r->post_content;
			}
			$excerpt = wp_trim_words( wp_strip_all_tags( $excerpt ), 26 );
			$cal     = self::cal_links( (int) $r->post_id, $title, $s_ts, $e_ts, $multi, (string) $r->venue_name, $excerpt );

			// Place on every covered day inside the window.
			$cursor = max( $s_ts, strtotime( $from . ' 00:00:00' ) );
			$stop   = min( $e_ts, strtotime( $to . ' 23:59:59' ) );
			for ( $d = strtotime( date( 'Y-m-d', $cursor ) ); $d <= $stop; $d = strtotime( '+1 day', $d ) ) {
				$dk = date( 'Y-m-d', $d );
				$by_date[ $dk ][] = [
					'post_id'  => (int) $r->post_id,
					'title'    => $title,
					'url'      => $url,
					'venue'    => (string) $r->venue_name,
					'time'     => $range,
					'range'    => $range,
					'tstart'   => date( 'H:i', $s_ts ),
					'thumb'    => $thumb,
					'excerpt'  => $excerpt,
					'cal'      => $cal,
					'color'    => self::cat_color( $catnm ),
					'multiday' => $multi,
					'is_start' => $dk === $s_day,
					'is_end'   => $dk === $e_day,
					'sort'     => ( $multi ? '0' : '1' ) . $r->start_date,
				];
			}
		}

		// Multi-day events first, then by start time, within each day.
		foreach ( $by_date as &$list ) {
			usort( $list, static fn( $a, $b ) => strcmp( $a['sort'], $b['sort'] ) );
		}
		unset( $list );

		return $by_date;
	}

	/** Render the Monday-first month grid (weekday header + day cells). */
	private static function grid_html( int $grid_start, int $grid_end, int $month, array $by_date ): string {
		$today = current_time( 'Y-m-d' );

		ob_start(); ?>
		<div class="cbd-cal-grid" role="grid">
			<div class="cbd-cal-week cbd-cal-dows" role="row">
				<?php foreach ( [
					[ __( 'Mon', 'community-business-directory' ), __( 'Monday', 'community-business-directory' ) ],
					[ __( 'Tue', 'community-business-directory' ), __( 'Tuesday', 'community-business-directory' ) ],
					[ __( 'Wed', 'community-business-directory' ), __( 'Wednesday', 'community-business-directory' ) ],
					[ __( 'Thu', 'community-business-directory' ), __( 'Thursday', 'community-business-directory' ) ],
					[ __( 'Fri', 'community-business-directory' ), __( 'Friday', 'community-business-directory' ) ],
					[ __( 'Sat', 'community-business-directory' ), __( 'Saturday', 'community-business-directory' ) ],
					[ __( 'Sun', 'community-business-directory' ), __( 'Sunday', 'community-business-directory' ) ],
				] as $dn ) : ?>
					<div class="cbd-cal-dow" role="columnheader"><abbr title="<?php echo esc_attr( $dn[1] ); ?>"><?php echo esc_html( $dn[0] ); ?></abbr></div>
				<?php endforeach; ?>
			</div>
			<?php
			$col = 0;
			echo '<div class="cbd-cal-week" role="row">';
			for ( $ts = $grid_start; $ts <= $grid_end; $ts = strtotime( '+1 day', $ts ) ) :
				if ( $col === 7 ) {
					echo '</div><div class="cbd-cal-week" role="row">';
					$col = 0;
				}
				$col++;
				$dk      = date( 'Y-m-d', $ts );
				$events  = $by_date[ $dk ] ?? [];
				$other   = ( (int) date( 'n', $ts ) !== $month );
				$is_today = $dk === $today;
				$classes  = 'cbd-cal-cell';
				$classes .= $other ? ' is-other' : '';
				$classes .= $is_today ? ' is-today' : '';
				$classes .= $events ? ' has-events' : '';
				?>
				<div class="<?php echo esc_attr( $classes ); ?>" role="gridcell" data-date="<?php echo esc_attr( $dk ); ?>">
					<div class="cbd-cal-cell-head">
						<span class="cbd-cal-daynum"><?php echo esc_html( date( 'j', $ts ) ); ?></span>
					</div>
					<?php if ( $events ) : ?>
						<div class="cbd-cal-cell-events">
							<?php
							$n = 0;
							foreach ( $events as $ev ) :
								$n++;
								$hidden = $n > self::MAX_PER_DAY;
								$mclass = '';
								if ( $ev['multiday'] ) {
									$mclass = ' is-multi' . ( $ev['is_start'] ? ' is-mstart' : '' ) . ( $ev['is_end'] ? ' is-mend' : '' );
								}
								?>
								<a href="<?php echo esc_url( $ev['url'] ); ?>" class="cbd-cal-ev<?php echo esc_attr( $mclass ); ?><?php echo $hidden ? ' is-extra' : ''; ?>" style="--ev:<?php echo esc_attr( $ev['color'] ); ?>" aria-label="<?php echo esc_attr( $ev['range'] . ' · ' . $ev['title'] ); ?>" data-time="<?php echo esc_attr( $ev['range'] ); ?>" data-venue="<?php echo esc_attr( $ev['venue'] ); ?>" data-thumb="<?php echo esc_attr( $ev['thumb'] ); ?>" data-excerpt="<?php echo esc_attr( $ev['excerpt'] ); ?>" data-cal="<?php echo esc_attr( wp_json_encode( $ev['cal'] ) ); ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
									<?php if ( ! $ev['multiday'] ) : ?>
										<span class="cbd-cal-ev-time"><?php echo esc_html( $ev['time'] ); ?></span>
									<?php endif; ?>
									<span class="cbd-cal-ev-title"><?php echo esc_html( $ev['title'] ); ?></span>
								</a>
							<?php endforeach; ?>
							<?php if ( $n > self::MAX_PER_DAY ) : ?>
								<button type="button" class="cbd-cal-more" aria-expanded="false">
									<span class="cbd-cal-more-show">+ <?php echo esc_html( (string) ( $n - self::MAX_PER_DAY ) ); ?> <?php esc_html_e( 'More', 'community-business-directory' ); ?></span>
									<span class="cbd-cal-more-hide" hidden><?php esc_html_e( 'Show less', 'community-business-directory' ); ?></span>
								</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endfor; ?>
			</div><!-- /.cbd-cal-week -->
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Render the agenda (List view): day-grouped event rows for the month. */
	private static function agenda_html( int $month, int $year, array $by_date, string $search ): string {
		// Only this month's days (drop the adjacent-month spill the grid shows).
		$days = array_filter(
			$by_date,
			static fn( $dk ) => (int) substr( $dk, 5, 2 ) === $month && (int) substr( $dk, 0, 4 ) === $year,
			ARRAY_FILTER_USE_KEY
		);
		ksort( $days );

		if ( ! $days ) {
			$msg = $search !== ''
				? sprintf( /* translators: %s: search term */ __( 'No events match “%s” this month.', 'community-business-directory' ), $search )
				: __( 'No events scheduled this month.', 'community-business-directory' );
			return '<div class="cbd-cal-empty"><span class="cbd-cal-empty-ic">📅</span><p>' . esc_html( $msg ) . '</p></div>';
		}

		ob_start(); ?>
		<ul class="cbd-cal-agenda-list">
			<?php foreach ( $days as $dk => $events ) :
				$ts = strtotime( $dk ); ?>
				<li class="cbd-cal-agenda-day">
					<div class="cbd-cal-agenda-date">
						<span class="cbd-cal-agenda-dnum"><?php echo esc_html( date( 'j', $ts ) ); ?></span>
						<span class="cbd-cal-agenda-dow"><?php echo esc_html( date( 'D', $ts ) ); ?></span>
					</div>
					<ul class="cbd-cal-agenda-events">
						<?php foreach ( $events as $ev ) : ?>
							<li>
								<a href="<?php echo esc_url( $ev['url'] ); ?>" class="cbd-cal-agenda-ev" style="--ev:<?php echo esc_attr( $ev['color'] ); ?>" aria-label="<?php echo esc_attr( $ev['range'] . ' · ' . $ev['title'] ); ?>" data-time="<?php echo esc_attr( $ev['range'] ); ?>" data-venue="<?php echo esc_attr( $ev['venue'] ); ?>" data-thumb="<?php echo esc_attr( $ev['thumb'] ); ?>" data-excerpt="<?php echo esc_attr( $ev['excerpt'] ); ?>" data-cal="<?php echo esc_attr( wp_json_encode( $ev['cal'] ) ); ?>">
									<span class="cbd-cal-agenda-time"><?php echo esc_html( $ev['multiday'] ? __( 'All day', 'community-business-directory' ) : $ev['range'] ); ?></span>
									<span class="cbd-cal-agenda-info">
										<span class="cbd-cal-agenda-title"><?php echo esc_html( $ev['title'] ); ?></span>
										<?php if ( $ev['venue'] ) : ?>
											<span class="cbd-cal-agenda-venue"><?php echo \cbd_icon( 'map-marker' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $ev['venue'] ); ?></span>
										<?php endif; ?>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	/** Render the Day view: a time-rail timeline of one day's events. */
	private static function day_html( int $ts, array $events ): string {
		if ( ! $events ) {
			$msg = sprintf(
				/* translators: %s: formatted weekday + date */
				__( 'No events on %s.', 'community-business-directory' ),
				date( 'l, F j, Y', $ts )
			);
			return '<div class="cbd-cal-empty"><span class="cbd-cal-empty-ic">📅</span><p>' . esc_html( $msg ) . '</p></div>';
		}

		ob_start(); ?>
		<ul class="cbd-cal-day-list">
			<?php foreach ( $events as $ev ) : ?>
				<li class="cbd-cal-day-item">
					<div class="cbd-cal-day-rail">
						<span class="cbd-cal-day-time"><?php echo esc_html( $ev['multiday'] ? __( 'All day', 'community-business-directory' ) : $ev['tstart'] ); ?></span>
						<span class="cbd-cal-day-dot" style="--ev:<?php echo esc_attr( $ev['color'] ); ?>"></span>
					</div>
					<a href="<?php echo esc_url( $ev['url'] ); ?>" class="cbd-cal-day-card" style="--ev:<?php echo esc_attr( $ev['color'] ); ?>" aria-label="<?php echo esc_attr( $ev['range'] . ' · ' . $ev['title'] ); ?>" data-time="<?php echo esc_attr( $ev['range'] ); ?>" data-venue="<?php echo esc_attr( $ev['venue'] ); ?>" data-thumb="<?php echo esc_attr( $ev['thumb'] ); ?>" data-excerpt="<?php echo esc_attr( $ev['excerpt'] ); ?>" data-cal="<?php echo esc_attr( wp_json_encode( $ev['cal'] ) ); ?>">
						<span class="cbd-cal-day-info">
							<span class="cbd-cal-day-range"><?php echo esc_html( $ev['range'] ); ?></span>
							<span class="cbd-cal-day-title"><?php echo esc_html( $ev['title'] ); ?></span>
							<?php if ( $ev['venue'] ) : ?>
								<span class="cbd-cal-day-venue"><?php echo \cbd_icon( 'map-marker' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $ev['venue'] ); ?></span>
							<?php endif; ?>
							<?php if ( $ev['excerpt'] ) : ?>
								<span class="cbd-cal-day-ex"><?php echo esc_html( $ev['excerpt'] ); ?></span>
							<?php endif; ?>
						</span>
						<?php if ( $ev['thumb'] ) : ?>
							<span class="cbd-cal-day-thumb" style="background-image:url('<?php echo esc_url( $ev['thumb'] ); ?>')"></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build "Add to calendar" links for one event across providers:
	 * Google, Outlook 365, Outlook Live, and a downloadable iCalendar (.ics)
	 * data URI. Returns [ google, ics, o365, olive ].
	 *
	 * Stored datetimes are floating wall-clock (WP forces PHP's tz to UTC, so
	 * date() prints the same digits that were saved). Google is tagged with the
	 * site timezone via `ctz`; Outlook/ICS use the same local digits. All-day
	 * events are date-only with an exclusive end date.
	 *
	 * @return array{google:string,ics:string,o365:string,olive:string}
	 */
	private static function cal_links( int $post_id, string $title, int $start, int $end, bool $allday, string $location, string $details ): array {
		if ( ! $allday && $end <= $start ) {
			$end = strtotime( '+1 hour', $start );
		}
		$tz = wp_timezone_string();

		// ── Google ──
		$g_dates = $allday
			? date( 'Ymd', $start ) . '/' . date( 'Ymd', strtotime( '+1 day', $end ) )
			: date( 'Ymd\THis', $start ) . '/' . date( 'Ymd\THis', $end );
		$g_args = [ 'action' => 'TEMPLATE', 'text' => $title, 'dates' => $g_dates, 'details' => $details, 'location' => $location ];
		if ( ! $allday && '' !== $tz ) {
			$g_args['ctz'] = $tz;
		}
		$google = 'https://calendar.google.com/calendar/render?' . http_build_query( $g_args );

		// ── Outlook (Live + Office 365 share the deeplink params) ──
		$o_args = [
			'path'    => '/calendar/action/compose',
			'rru'     => 'addevent',
			'subject' => $title,
			'startdt' => $allday ? date( 'Y-m-d', $start ) : date( 'Y-m-d\TH:i:s', $start ),
			'enddt'   => $allday ? date( 'Y-m-d', strtotime( '+1 day', $end ) ) : date( 'Y-m-d\TH:i:s', $end ),
			'body'    => $details,
			'location'=> $location,
		];
		if ( $allday ) {
			$o_args['allday'] = 'true';
		}
		$olive = 'https://outlook.live.com/calendar/0/deeplink/compose?' . http_build_query( $o_args );
		$o365  = 'https://outlook.office.com/calendar/0/deeplink/compose?' . http_build_query( $o_args );

		// ── iCalendar (.ics) as a downloadable data URI ──
		$esc = static function ( string $s ): string {
			return str_replace( [ "\r\n", "\n", "\r" ], '\\n', addcslashes( $s, "\\,;" ) );
		};
		$dt = $allday
			? "DTSTART;VALUE=DATE:" . date( 'Ymd', $start ) . "\r\nDTEND;VALUE=DATE:" . date( 'Ymd', strtotime( '+1 day', $end ) )
			: "DTSTART:" . date( 'Ymd\THis', $start ) . "\r\nDTEND:" . date( 'Ymd\THis', $end );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CBD//Directory//EN\r\nBEGIN:VEVENT\r\n"
			. "UID:cbd-{$post_id}@{$host}\r\nDTSTAMP:" . gmdate( 'Ymd\THis\Z' ) . "\r\n{$dt}\r\n"
			. "SUMMARY:" . $esc( $title ) . "\r\nLOCATION:" . $esc( $location ) . "\r\nDESCRIPTION:" . $esc( $details )
			. "\r\nEND:VEVENT\r\nEND:VCALENDAR";
		$ics_uri = 'data:text/calendar;charset=utf-8,' . rawurlencode( $ics );

		return [ 'google' => $google, 'ics' => $ics_uri, 'o365' => $o365, 'olive' => $olive ];
	}

	/** Deterministic accent colour for a category name (empty → primary). */
	private static function cat_color( string $name ): string {
		if ( $name === '' ) {
			return self::PALETTE[0];
		}
		return self::PALETTE[ abs( crc32( $name ) ) % count( self::PALETTE ) ];
	}
}
