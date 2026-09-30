<?php
/**
 * Dashboard Shortcode — [cbd_dashboard]
 * Frontend business owner control panel.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

class DashboardShortcode {

	public function render( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="cbd-wrap"><div class="cbd-notice cbd-notice-info">'
				. sprintf(
					wp_kses( __( 'Please <a href="%s">log in</a> to access your business dashboard.', 'community-business-directory' ), [ 'a' => [ 'href' => [] ] ] ),
					esc_url( wp_login_url( get_permalink() ) )
				) . '</div></div>';
		}

		global $wpdb;
		$user_id  = get_current_user_id();
		$tab      = sanitize_key( $_GET['cbd_tab'] ?? 'overview' );
		$base_url = get_permalink();

		$business = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.*, p.post_title FROM {$wpdb->prefix}cbd_businesses b
			 INNER JOIN {$wpdb->posts} p ON p.ID = b.post_id
			 WHERE b.owner_id = %d LIMIT 1",
			$user_id
		) );

		ob_start();
		$this->render_layout_open( $tab, $base_url, $business );

		switch ( $tab ) {
			case 'overview':    $this->tab_overview( $business, $base_url );   break;
			case 'profile':     $this->tab_profile( $business );               break;
			case 'posts':       $this->tab_posts( $user_id, $business );       break;
			case 'events':      $this->tab_events( $user_id );                 break;
			case 'promotions':  $this->tab_promotions( $user_id );             break;
			case 'gallery':     $this->tab_gallery( $business );               break;
			case 'reviews':     $this->tab_reviews( $business );               break;
			case 'analytics':   $this->tab_analytics( $business );             break;
			case 'membership':  $this->tab_membership( $business );            break;
			default:            $this->tab_overview( $business, $base_url );
		}

		$this->render_layout_close();
		return ob_get_clean();
	}

	// ── Layout shell ──────────────────────────────────────────────

	private function render_layout_open( string $tab, string $base_url, ?object $business ): void {
		$tabs = [
			'overview'   => [ '📊', __( 'Overview',    'community-business-directory' ) ],
			'profile'    => [ '🏢', __( 'My Profile',  'community-business-directory' ) ],
			'posts'      => [ '📣', __( 'Posts',        'community-business-directory' ) ],
			'events'     => [ '📅', __( 'Events',      'community-business-directory' ) ],
			'promotions' => [ '🏷️', __( 'Promotions', 'community-business-directory' ) ],
			'gallery'    => [ '🖼️', __( 'Gallery',    'community-business-directory' ) ],
			'reviews'    => [ '⭐', __( 'Reviews',     'community-business-directory' ) ],
			'analytics'  => [ '📈', __( 'Analytics',  'community-business-directory' ) ],
			'membership' => [ '💳', __( 'Membership', 'community-business-directory' ) ],
		];
		?>
<div class="cbd-wrap cbd-dashboard">
  <div class="cbd-dashboard-layout">
    <aside class="cbd-dashboard-sidebar">
      <div class="cbd-dash-brand">
        <?php if ( $business ) : ?>
        <strong><?php echo esc_html( $business->post_title ); ?></strong>
        <span class="cbd-dash-plan"><?php echo esc_html( ucfirst( $business->plan ) ); ?> <?php esc_html_e( 'Plan', 'community-business-directory' ); ?></span>
        <?php if ( $business->post_id ) : ?>
        <a href="<?php echo esc_url( get_permalink( $business->post_id ) ); ?>" class="cbd-btn cbd-btn-sm" style="margin-top:8px;display:inline-block;" target="_blank">
          👁 <?php esc_html_e( 'View Listing', 'community-business-directory' ); ?>
        </a>
        <?php endif; ?>
        <?php else : ?>
        <strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong>
        <?php endif; ?>
      </div>
      <nav class="cbd-dash-nav">
        <?php foreach ( $tabs as $slug => [ $icon, $label ] ) : ?>
        <a href="<?php echo esc_url( add_query_arg( 'cbd_tab', $slug, $base_url ) ); ?>"
           class="cbd-dash-nav-item <?php echo $tab === $slug ? 'active' : ''; ?>">
          <?php echo $icon; ?> <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
        <hr style="border:none;border-top:1px solid rgba(255,255,255,.1);margin:12px 12px;">
        <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cbd-dash-nav-item" style="color:rgba(255,255,255,.4);">
          🚪 <?php esc_html_e( 'Log Out', 'community-business-directory' ); ?>
        </a>
      </nav>
    </aside>
    <main class="cbd-dashboard-main">
		<?php
	}

	private function render_layout_close(): void {
		echo '</main></div></div>';
	}

	// ── Tab: Overview ─────────────────────────────────────────────

	private function tab_overview( ?object $business, string $base_url ): void {
		echo '<h2>' . esc_html__( 'Dashboard Overview', 'community-business-directory' ) . '</h2>';

		if ( ! $business ) {
			echo '<div class="cbd-notice cbd-notice-info">';
			echo '<strong>' . esc_html__( 'No business listing yet.', 'community-business-directory' ) . '</strong> ';
			echo '<p style="margin-top:8px;">' . esc_html__( 'Add the shortcode', 'community-business-directory' ) . ' <code>[cbd_register_business]</code> '
				. esc_html__( 'to any page to register your business.', 'community-business-directory' ) . '</p>';
			echo '</div>';
			return;
		}

		// Stats
		$stats = [
			[ (int) $business->view_count,     __( 'Profile Views', 'community-business-directory' ) ],
			[ (int) $business->follower_count,  __( 'Followers',     'community-business-directory' ) ],
			[ $business->rating_avg > 0 ? number_format( (float) $business->rating_avg, 1 ) : '—', __( 'Avg Rating', 'community-business-directory' ) ],
			[ (int) $business->review_count,    __( 'Reviews',       'community-business-directory' ) ],
		];
		echo '<div class="cbd-dash-stats">';
		foreach ( $stats as [ $val, $label ] ) {
			echo '<div class="cbd-dash-stat"><div class="cbd-dash-stat-val">' . esc_html( $val ) . '</div>'
				. '<div class="cbd-dash-stat-label">' . esc_html( $label ) . '</div></div>';
		}
		echo '</div>';

		// Quick actions
		echo '<div class="cbd-dash-quick-actions"><h3>' . esc_html__( 'Quick Actions', 'community-business-directory' ) . '</h3>'
			. '<div class="cbd-quick-grid">';
		$quick = [
			[ add_query_arg( 'cbd_tab', 'posts',      $base_url ), '📣', __( 'New Post',      'community-business-directory' ) ],
			[ add_query_arg( 'cbd_tab', 'events',     $base_url ), '📅', __( 'Create Event',  'community-business-directory' ) ],
			[ add_query_arg( 'cbd_tab', 'promotions', $base_url ), '🏷️', __( 'Add Promotion','community-business-directory' ) ],
			[ add_query_arg( 'cbd_tab', 'gallery',    $base_url ), '🖼️', __( 'Add Photos',   'community-business-directory' ) ],
			[ add_query_arg( 'cbd_tab', 'profile',    $base_url ), '✏️', __( 'Edit Profile',  'community-business-directory' ) ],
			[ add_query_arg( 'cbd_tab', 'reviews',    $base_url ), '⭐', __( 'See Reviews',   'community-business-directory' ) ],
		];
		foreach ( $quick as [ $url, $icon, $label ] ) {
			echo '<a href="' . esc_url( $url ) . '" class="cbd-quick-card">' . $icon . ' ' . esc_html( $label ) . '</a>';
		}
		echo '</div></div>';

		// Status badge
		$status_colors = [ 'active' => '#2b6344', 'pending' => '#c47b1a', 'rejected' => '#b84a35', 'suspended' => '#888' ];
		$color = $status_colors[ $business->status ] ?? '#888';
		echo '<div class="cbd-notice" style="margin-top:20px;background:#fff;border:1px solid #eee;">';
		echo '<strong>' . esc_html__( 'Listing Status:', 'community-business-directory' ) . '</strong> ';
		echo '<span style="background:' . esc_attr( $color ) . ';color:#fff;padding:3px 12px;border-radius:40px;font-size:12px;font-weight:700;">' . esc_html( ucfirst( $business->status ) ) . '</span>';
		if ( $business->status === 'pending' ) {
			echo '<p style="margin:8px 0 0;font-size:13px;color:#666;">' . esc_html__( 'Your listing is awaiting admin approval.', 'community-business-directory' ) . '</p>';
		}
		echo '</div>';
	}

	// ── Tab: Profile ──────────────────────────────────────────────

	private function tab_profile( ?object $business ): void {
		echo '<h2>' . esc_html__( 'Edit Business Profile', 'community-business-directory' ) . '</h2>';

		if ( ! $business ) {
			echo '<div class="cbd-notice cbd-notice-info">' . esc_html__( 'No business listing found.', 'community-business-directory' ) . '</div>';
			return;
		}

		$social = ! empty( $business->social_links ) ? json_decode( $business->social_links, true ) : [];
		$hours  = ! empty( $business->opening_hours ) ? json_decode( $business->opening_hours, true ) : [];
		$cats   = get_terms( [ 'taxonomy' => 'cbd_category', 'hide_empty' => false ] );
		?>
		<form id="cbd-edit-profile-form" class="cbd-form" enctype="multipart/form-data">
			<?php
			wp_nonce_field( 'cbd_nonce', 'cbd_nonce' );
			$logo_url  = get_the_post_thumbnail_url( $business->post_id, 'thumbnail' ) ?: '';
			$cover_id  = (int) get_post_meta( $business->post_id, '_cbd_cover_id', true );
			$cover_url = $cover_id ? ( wp_get_attachment_image_url( $cover_id, 'large' ) ?: '' ) : '';
			?>
			<input type="hidden" name="action"  value="cbd_update_profile">
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $business->post_id ); ?>">

			<div class="cbd-form-section">
				<h3><?php esc_html_e( 'Branding', 'community-business-directory' ); ?></h3>
				<div class="cbd-form-cols">
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Logo', 'community-business-directory' ); ?></label>
						<div class="cbd-media-field">
							<div class="cbd-media-thumb cbd-media-logo">
								<?php if ( $logo_url ) : ?><img src="<?php echo esc_url( $logo_url ); ?>" alt=""><?php else : ?><span><?php echo esc_html( mb_strtoupper( mb_substr( $business->post_title, 0, 1 ) ) ); ?></span><?php endif; ?>
							</div>
							<input type="file" name="biz_logo" accept="image/*" data-crop-aspect="1" data-crop-w="400" data-crop-h="400">
						</div>
						<small><?php esc_html_e( 'Square image works best (shown as the profile logo).', 'community-business-directory' ); ?></small>
					</div>
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Cover Photo', 'community-business-directory' ); ?></label>
						<div class="cbd-media-field">
							<div class="cbd-media-thumb cbd-media-cover" <?php echo $cover_url ? 'style="background-image:url(' . esc_url( $cover_url ) . ')"' : ''; ?>>
								<?php if ( ! $cover_url ) : ?><span><?php esc_html_e( 'No cover yet', 'community-business-directory' ); ?></span><?php endif; ?>
							</div>
							<input type="file" name="biz_cover" accept="image/*" data-crop-aspect="3" data-crop-w="1200" data-crop-h="400">
						</div>
						<small><?php esc_html_e( 'Wide image (≈1600×500) shown as the banner at the top of your profile.', 'community-business-directory' ); ?></small>
					</div>
				</div>
			</div>

			<div class="cbd-form-section">
				<h3><?php esc_html_e( 'Business Details', 'community-business-directory' ); ?></h3>
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Business Name', 'community-business-directory' ); ?> <span class="req">*</span></label>
					<input type="text" name="biz_name" value="<?php echo esc_attr( $business->post_title ); ?>" required>
				</div>
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Category', 'community-business-directory' ); ?></label>
					<select name="biz_category">
						<option value=""><?php esc_html_e( '— Select —', 'community-business-directory' ); ?></option>
						<?php if ( ! is_wp_error( $cats ) ) foreach ( $cats as $cat ) : ?>
						<?php $current = wp_get_post_terms( $business->post_id, 'cbd_category', [ 'fields' => 'ids' ] ); ?>
						<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php echo in_array( $cat->term_id, (array) $current, true ) ? 'selected' : ''; ?>><?php echo esc_html( \cbd_label( $cat->name ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="cbd-form-section">
				<h3><?php esc_html_e( 'Contact Details', 'community-business-directory' ); ?></h3>
				<div class="cbd-form-cols">
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Email', 'community-business-directory' ); ?></label>
						<input type="email" name="biz_email" value="<?php echo esc_attr( $business->email ?? '' ); ?>">
					</div>
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Phone', 'community-business-directory' ); ?></label>
						<input type="tel" name="biz_phone" value="<?php echo esc_attr( $business->phone ?? '' ); ?>">
					</div>
				</div>
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Website', 'community-business-directory' ); ?></label>
					<input type="url" name="biz_website" value="<?php echo esc_attr( $business->website ?? '' ); ?>">
				</div>
				<div class="cbd-form-cols">
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'City', 'community-business-directory' ); ?></label>
						<input type="text" name="biz_city" value="<?php echo esc_attr( $business->city ?? '' ); ?>">
					</div>
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Postcode / ZIP', 'community-business-directory' ); ?></label>
						<input type="text" name="biz_postcode" value="<?php echo esc_attr( $business->postal_code ?? '' ); ?>">
					</div>
				</div>
			</div>

			<div class="cbd-form-section">
				<h3><?php esc_html_e( 'Social Media', 'community-business-directory' ); ?></h3>
				<div class="cbd-form-cols">
					<?php foreach ( [ 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'X / Twitter', 'linkedin' => 'LinkedIn' ] as $key => $label ) : ?>
					<div class="cbd-form-row">
						<label><?php echo esc_html( $label ); ?></label>
						<input type="url" name="social_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $social[ $key ] ?? '' ); ?>" placeholder="https://...">
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="cbd-form-actions">
				<button type="submit" class="cbd-btn cbd-btn-primary">
					<span class="btn-text"><?php esc_html_e( 'Save Changes', 'community-business-directory' ); ?></span>
					<span class="btn-loading" style="display:none;"><?php esc_html_e( 'Saving…', 'community-business-directory' ); ?></span>
				</button>
			</div>
			<div class="cbd-form-msg" style="display:none;"></div>
		</form>
		<?php
	}

	// ── Tab: Posts ────────────────────────────────────────────────

	private function tab_posts( int $user_id, ?object $business ): void {
		echo '<h2>' . esc_html__( 'Business Posts', 'community-business-directory' ) . '</h2>';

		if ( ! $business || $business->status !== 'active' ) {
			echo '<div class="cbd-notice cbd-notice-info">' . esc_html__( 'You need an active business listing to create posts.', 'community-business-directory' ) . '</div>';
			return;
		}
		?>
		<!-- Create Post Form -->
		<div class="cbd-post-create-box">
			<h3><?php esc_html_e( 'Create a Post', 'community-business-directory' ); ?></h3>
			<form id="cbd-business-post-form" class="cbd-form">
				<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
				<input type="hidden" name="action" value="cbd_create_business_post">
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Post Type', 'community-business-directory' ); ?></label>
					<div class="cbd-post-type-pills">
						<?php foreach ( [
							'update'      => '📢 ' . __( 'Update',      'community-business-directory' ),
							'news'        => '📰 ' . __( 'News',        'community-business-directory' ),
							'product'     => '🛍 ' .  __( 'New Product', 'community-business-directory' ),
							'event_promo' => '🎉 ' . __( 'Promotion',  'community-business-directory' ),
							'community'   => '🤝 ' . __( 'Community',  'community-business-directory' ),
						] as $value => $label ) : ?>
						<label class="cbd-type-pill">
							<input type="radio" name="post_type_label" value="<?php echo esc_attr( $value ); ?>" <?php echo $value === 'update' ? 'checked' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Title', 'community-business-directory' ); ?> <span class="req">*</span></label>
					<input type="text" name="post_title" required placeholder="<?php esc_attr_e( 'What\'s the headline?', 'community-business-directory' ); ?>">
				</div>
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Content', 'community-business-directory' ); ?> <span class="req">*</span></label>
					<textarea name="post_content" rows="5" required placeholder="<?php esc_attr_e( 'Share your update with the community…', 'community-business-directory' ); ?>"></textarea>
				</div>
				<div class="cbd-form-row">
					<label><?php esc_html_e( 'Featured Image', 'community-business-directory' ); ?></label>
					<input type="file" name="post_image" accept="image/*">
				</div>
				<div class="cbd-form-actions">
					<button type="submit" class="cbd-btn cbd-btn-primary">
						<span class="btn-text"><?php esc_html_e( 'Publish Post', 'community-business-directory' ); ?></span>
						<span class="btn-loading" style="display:none;"><?php esc_html_e( 'Publishing…', 'community-business-directory' ); ?></span>
					</button>
				</div>
				<div class="cbd-form-msg" style="display:none;"></div>
			</form>
		</div>

		<!-- Existing Posts -->
		<?php
		$posts = get_posts( [
			'post_type'      => 'cbd_business_post',
			'post_status'    => [ 'publish', 'draft' ],
			'author'         => $user_id,
			'posts_per_page' => 20,
		] );

		if ( $posts ) :
			echo '<h3 style="margin-top:32px;">' . esc_html__( 'Your Posts', 'community-business-directory' ) . '</h3>';
			echo '<div class="cbd-posts-list">';
			foreach ( $posts as $p ) :
				$type_label = get_post_meta( $p->ID, '_cbd_post_type', true ) ?: 'update';
				$type_icons = [ 'update' => '📢', 'news' => '📰', 'product' => '🛍', 'event_promo' => '🎉', 'community' => '🤝' ];
				$icon = $type_icons[ $type_label ] ?? '📌';
				?>
				<div class="cbd-feed-item" style="margin-bottom:12px;">
					<?php if ( has_post_thumbnail( $p->ID ) ) : ?>
					<a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="cbd-feed-img-wrap">
						<?php echo get_the_post_thumbnail( $p->ID, 'thumbnail', [ 'class' => 'cbd-feed-img', 'style' => 'width:120px;height:80px;object-fit:cover;' ] ); ?>
					</a>
					<?php endif; ?>
					<div class="cbd-feed-body">
						<div class="cbd-feed-meta">
							<span class="cbd-feed-type"><?php echo $icon; ?> <?php echo esc_html( ucfirst( $type_label ) ); ?></span>
							<span class="cbd-feed-time"><?php echo esc_html( human_time_diff( get_post_time( 'U', false, $p->ID ), current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'community-business-directory' ); ?></span>
						</div>
						<h4 class="cbd-feed-title"><a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( $p->post_title ); ?></a></h4>
						<div style="display:flex;gap:8px;margin-top:8px;">
							<a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="cbd-btn cbd-btn-sm" target="_blank"><?php esc_html_e( 'View', 'community-business-directory' ); ?></a>
							<a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" class="cbd-btn cbd-btn-sm cbd-btn-outline"><?php esc_html_e( 'Edit', 'community-business-directory' ); ?></a>
							<button class="cbd-btn cbd-btn-sm cbd-btn-outline cbd-delete-post" data-post-id="<?php echo esc_attr( $p->ID ); ?>" style="color:#b84a35;border-color:#b84a35;">
								<?php esc_html_e( 'Delete', 'community-business-directory' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php
			endforeach;
			echo '</div>';
		else :
			echo '<div class="cbd-empty-state" style="padding:32px 0;"><span class="cbd-empty-icon">📣</span>';
			echo '<p>' . esc_html__( 'No posts yet. Share your first update above!', 'community-business-directory' ) . '</p></div>';
		endif;
	}

	// ── Tab: Events ───────────────────────────────────────────────

	private function tab_events( int $user_id ): void {
		echo '<h2>' . esc_html__( 'My Events', 'community-business-directory' ) . '</h2>';
		echo '<button class="cbd-btn cbd-btn-primary" id="cbd-new-event-btn" style="margin-bottom:20px;">+ '
			. esc_html__( 'New Event', 'community-business-directory' ) . '</button>';

		$this->render_event_modal();

		$events = get_posts( [ 'post_type' => 'cbd_event', 'post_status' => [ 'publish', 'draft' ], 'author' => $user_id, 'posts_per_page' => 20 ] );
		if ( $events ) {
			echo '<table class="cbd-dash-table"><thead><tr>'
				. '<th>' . esc_html__( 'Event', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Date', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Status', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Actions', 'community-business-directory' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $events as $ev ) {
				global $wpdb;
				$ev_meta = $wpdb->get_row( $wpdb->prepare( "SELECT start_date FROM {$wpdb->prefix}cbd_events WHERE post_id = %d", $ev->ID ), ARRAY_A );
				$start = $ev_meta ? gmdate( 'j M Y, g:ia', strtotime( $ev_meta['start_date'] ) ) : '—';
				echo '<tr>'
					. '<td><strong>' . esc_html( $ev->post_title ) . '</strong></td>'
					. '<td>' . esc_html( $start ) . '</td>'
					. '<td><span class="cbd-status-' . esc_attr( $ev->post_status ) . '">' . esc_html( $ev->post_status ) . '</span></td>'
					. '<td><a href="' . esc_url( get_permalink( $ev->ID ) ) . '" target="_blank" class="cbd-btn cbd-btn-sm">' . esc_html__( 'View', 'community-business-directory' ) . '</a> '
					. '<a href="' . esc_url( get_edit_post_link( $ev->ID ) ) . '" class="cbd-btn cbd-btn-sm cbd-btn-outline">' . esc_html__( 'Edit', 'community-business-directory' ) . '</a></td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="cbd-empty-state" style="padding:32px 0;"><span class="cbd-empty-icon">📅</span>'
				. '<p>' . esc_html__( 'No events yet.', 'community-business-directory' ) . '</p></div>';
		}
	}

	// ── Tab: Promotions ───────────────────────────────────────────

	private function tab_promotions( int $user_id ): void {
		echo '<h2>' . esc_html__( 'My Promotions', 'community-business-directory' ) . '</h2>';
		echo '<button class="cbd-btn cbd-btn-primary" id="cbd-new-promo-btn" style="margin-bottom:20px;">+ '
			. esc_html__( 'New Promotion', 'community-business-directory' ) . '</button>';

		$this->render_promo_modal();

		$promos = get_posts( [ 'post_type' => 'cbd_promotion', 'post_status' => [ 'publish', 'draft' ], 'author' => $user_id, 'posts_per_page' => 20 ] );
		if ( $promos ) {
			echo '<table class="cbd-dash-table"><thead><tr>'
				. '<th>' . esc_html__( 'Promotion', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Code', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Expires', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Actions', 'community-business-directory' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $promos as $pr ) {
				global $wpdb;
				$pr_meta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_promotions WHERE post_id = %d", $pr->ID ), ARRAY_A );
				$expiry  = ! empty( $pr_meta['expiry_date'] ) ? gmdate( 'j M Y', strtotime( $pr_meta['expiry_date'] ) ) : __( 'No expiry', 'community-business-directory' );
				echo '<tr>'
					. '<td><strong>' . esc_html( $pr->post_title ) . '</strong></td>'
					. '<td>' . ( ! empty( $pr_meta['coupon_code'] ) ? '<code>' . esc_html( $pr_meta['coupon_code'] ) . '</code>' : '—' ) . '</td>'
					. '<td>' . esc_html( $expiry ) . '</td>'
					. '<td><a href="' . esc_url( get_edit_post_link( $pr->ID ) ) . '" class="cbd-btn cbd-btn-sm">' . esc_html__( 'Edit', 'community-business-directory' ) . '</a></td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="cbd-empty-state" style="padding:32px 0;"><span class="cbd-empty-icon">🏷️</span>'
				. '<p>' . esc_html__( 'No promotions yet.', 'community-business-directory' ) . '</p></div>';
		}
	}

	// ── Tab: Gallery ──────────────────────────────────────────────

	private function tab_gallery( ?object $business ): void {
		echo '<h2>' . esc_html__( 'Business Gallery', 'community-business-directory' ) . '</h2>';

		if ( ! $business ) {
			echo '<div class="cbd-notice cbd-notice-info">' . esc_html__( 'No business listing found.', 'community-business-directory' ) . '</div>';
			return;
		}

		$post_id = $business->post_id;
		$albums  = \cbd_gallery_albums( $post_id );
		$total   = array_sum( array_map( 'count', $albums ) );
		?>
		<div class="cbd-gallery-upload">
			<h3><?php esc_html_e( 'Upload Photos', 'community-business-directory' ); ?></h3>
			<form id="cbd-gallery-form" class="cbd-form" enctype="multipart/form-data">
				<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
				<input type="hidden" name="action"  value="cbd_upload_gallery">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
				<div class="cbd-form-cols">
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Album', 'community-business-directory' ); ?></label>
						<input type="text" name="album" list="cbd-album-list" autocomplete="off"
							placeholder="<?php esc_attr_e( 'e.g. Storefront, Menu, Events…', 'community-business-directory' ); ?>">
						<datalist id="cbd-album-list">
							<?php foreach ( array_keys( $albums ) as $album_name ) : ?>
							<option value="<?php echo esc_attr( $album_name ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
						<small><?php esc_html_e( 'Pick an existing album or type a new name. Blank = “General”.', 'community-business-directory' ); ?></small>
					</div>
					<div class="cbd-form-row">
						<label><?php esc_html_e( 'Select Images', 'community-business-directory' ); ?></label>
						<input type="file" name="gallery_images[]" accept="image/*" multiple>
						<small><?php esc_html_e( 'JPG, PNG, WebP — max 5 images at once.', 'community-business-directory' ); ?></small>
					</div>
				</div>
				<div class="cbd-form-actions">
					<button type="submit" class="cbd-btn cbd-btn-primary">
						<span class="btn-text"><?php esc_html_e( 'Upload Photos', 'community-business-directory' ); ?></span>
						<span class="btn-loading" style="display:none;"><?php esc_html_e( 'Uploading…', 'community-business-directory' ); ?></span>
					</button>
				</div>
				<div class="cbd-form-msg" style="display:none;"></div>
			</form>
		</div>

		<?php if ( $albums ) : ?>
		<h3 style="margin-top:28px;"><?php esc_html_e( 'Current Photos', 'community-business-directory' ); ?> <small style="font-weight:400;color:#888;">(<?php echo (int) $total; ?>)</small></h3>
		<?php foreach ( $albums as $album_name => $images ) : ?>
		<div class="cbd-gallery-album">
			<h4 class="cbd-album-title"><?php echo esc_html( $album_name ); ?> <span class="cbd-album-count"><?php echo count( $images ); ?></span></h4>
			<div class="cbd-gallery-grid">
				<?php foreach ( $images as $img ) :
					$thumb = wp_get_attachment_image_src( $img->ID, 'medium' );
					$full  = wp_get_attachment_image_src( $img->ID, 'large' );
				?>
				<div class="cbd-gallery-item">
					<a href="<?php echo esc_url( $full[0] ?? '' ); ?>" target="_blank">
						<img src="<?php echo esc_url( $thumb[0] ?? '' ); ?>" alt="<?php echo esc_attr( $img->post_title ); ?>" loading="lazy">
					</a>
					<button class="cbd-gallery-delete" data-attachment-id="<?php echo esc_attr( $img->ID ); ?>" title="<?php esc_attr_e( 'Delete', 'community-business-directory' ); ?>">✕</button>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endforeach; ?>
		<?php else : ?>
		<div class="cbd-empty-state" style="padding:32px 0;">
			<span class="cbd-empty-icon">🖼️</span>
			<p><?php esc_html_e( 'No photos uploaded yet.', 'community-business-directory' ); ?></p>
		</div>
		<?php endif;
	}

	// ── Tab: Reviews ──────────────────────────────────────────────

	private function tab_reviews( ?object $business ): void {
		echo '<h2>' . esc_html__( 'Customer Reviews', 'community-business-directory' ) . '</h2>';

		if ( ! $business ) {
			echo '<p>' . esc_html__( 'No business found.', 'community-business-directory' ) . '</p>';
			return;
		}

		global $wpdb;
		$reviews = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cbd_reviews WHERE business_id = %d ORDER BY created_at DESC",
			$business->post_id
		) );

		if ( $reviews ) {
			$status_colors = [ 'approved' => '#2b6344', 'pending' => '#c47b1a', 'rejected' => '#b84a35' ];
			foreach ( $reviews as $r ) {
				$color = $status_colors[ $r->status ] ?? '#888';
				echo '<div class="cbd-review-item">'
					. '<div class="cbd-review-header">'
					. '<div class="cbd-review-avatar">' . esc_html( mb_strtoupper( mb_substr( $r->author_name ?: 'A', 0, 1 ) ) ) . '</div>'
					. '<div><strong>' . esc_html( $r->author_name ) . '</strong>'
					. '<div class="cbd-stars">' . str_repeat( '★', (int) $r->rating ) . '</div>'
					. '<span style="background:' . esc_attr( $color ) . ';color:#fff;padding:1px 8px;border-radius:4px;font-size:11px;">' . esc_html( $r->status ) . '</span>'
					. '</div></div><p>' . esc_html( $r->content ) . '</p></div>';
			}
		} else {
			echo '<div class="cbd-empty-state" style="padding:32px 0;"><span class="cbd-empty-icon">⭐</span>'
				. '<p>' . esc_html__( 'No reviews yet.', 'community-business-directory' ) . '</p></div>';
		}
	}

	// ── Tab: Analytics ────────────────────────────────────────────

	private function tab_analytics( ?object $business ): void {
		echo '<h2>' . esc_html__( 'Analytics', 'community-business-directory' ) . '</h2>';

		if ( ! $business ) {
			echo '<p>' . esc_html__( 'No business found.', 'community-business-directory' ) . '</p>';
			return;
		}

		$stats = [
			[ (int) $business->view_count,    __( 'Total Profile Views', 'community-business-directory' ) ],
			[ (int) $business->follower_count, __( 'Total Followers',    'community-business-directory' ) ],
			[ (int) $business->review_count,   __( 'Total Reviews',      'community-business-directory' ) ],
			[ $business->rating_avg > 0 ? number_format( (float) $business->rating_avg, 1 ) : '—', __( 'Average Rating', 'community-business-directory' ) ],
		];

		echo '<div class="cbd-dash-stats">';
		foreach ( $stats as [ $val, $label ] ) {
			echo '<div class="cbd-dash-stat"><div class="cbd-dash-stat-val">' . esc_html( $val ) . '</div>'
				. '<div class="cbd-dash-stat-label">' . esc_html( $label ) . '</div></div>';
		}
		echo '</div>';

		// Last 7 days analytics
		global $wpdb;
		$daily = $wpdb->get_results( $wpdb->prepare(
			"SELECT date, views, new_followers, new_reviews FROM {$wpdb->prefix}cbd_analytics
			 WHERE business_id = %d AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
			 ORDER BY date ASC",
			$business->post_id
		) );

		if ( $daily ) {
			echo '<h3 style="margin-top:24px;">' . esc_html__( 'Last 7 Days', 'community-business-directory' ) . '</h3>';
			echo '<table class="cbd-dash-table"><thead><tr>'
				. '<th>' . esc_html__( 'Date', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'Views', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'New Followers', 'community-business-directory' ) . '</th>'
				. '<th>' . esc_html__( 'New Reviews', 'community-business-directory' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $daily as $d ) {
				echo '<tr><td>' . esc_html( gmdate( 'D j M', strtotime( $d->date ) ) ) . '</td>'
					. '<td>' . esc_html( $d->views ) . '</td>'
					. '<td>' . esc_html( $d->new_followers ) . '</td>'
					. '<td>' . esc_html( $d->new_reviews ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( $business->plan === 'free' || $business->plan === 'basic' ) {
			echo '<div class="cbd-notice cbd-notice-info" style="margin-top:20px;">'
				. esc_html__( 'Upgrade to Premium or Elite for detailed charts and click tracking analytics.', 'community-business-directory' )
				. '</div>';
		}
	}

	// ── Tab: Membership ───────────────────────────────────────────
	private function tab_membership( ?object $business ): void {
		echo '<h2>' . esc_html__( 'Membership', 'community-business-directory' ) . '</h2>';

		if ( ! $business ) {
			echo '<div class="cbd-notice cbd-notice-info">'
				. esc_html__( 'Register your business to choose a membership plan.', 'community-business-directory' )
				. '</div>';
			return;
		}

		global $wpdb;
		$plan = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cbd_membership_plans WHERE slug = %s",
			$business->plan
		) );

		$sym       = get_option( 'cbd_currency_symbol', '£' );
		$plan_name = $plan ? $plan->name : ucfirst( $business->plan );
		$is_paid   = $plan && ( (float) $plan->price_monthly > 0 || (float) $plan->price_annual > 0 );
		$expires   = $business->plan_expires_at && $business->plan_expires_at !== '0000-00-00 00:00:00'
			? strtotime( $business->plan_expires_at )
			: 0;
		?>
		<div class="cbd-current-plan">
			<div class="cbd-current-plan-head">
				<div>
					<span class="cbd-current-plan-label"><?php esc_html_e( 'Current plan', 'community-business-directory' ); ?></span>
					<div class="cbd-current-plan-name"><?php echo esc_html( $plan_name ); ?></div>
				</div>
				<div class="cbd-current-plan-price">
					<?php if ( $is_paid ) : ?>
						<strong><?php echo esc_html( $sym . number_format( (float) $plan->price_monthly, 2 ) ); ?></strong>
						<span><?php esc_html_e( '/month', 'community-business-directory' ); ?></span>
					<?php else : ?>
						<strong><?php esc_html_e( 'Free', 'community-business-directory' ); ?></strong>
					<?php endif; ?>
				</div>
			</div>

			<div class="cbd-current-plan-meta">
				<?php if ( $is_paid && $expires ) : ?>
					<span class="cbd-plan-status cbd-plan-status-active">●</span>
					<?php
					printf(
						/* translators: %s: formatted renewal date */
						esc_html__( 'Renews on %s', 'community-business-directory' ),
						'<strong>' . esc_html( date_i18n( get_option( 'date_format' ), $expires ) ) . '</strong>'
					);
					?>
				<?php elseif ( $is_paid ) : ?>
					<span class="cbd-plan-status cbd-plan-status-active">●</span>
					<?php esc_html_e( 'Active subscription', 'community-business-directory' ); ?>
				<?php else : ?>
					<span class="cbd-plan-status">●</span>
					<?php esc_html_e( 'No subscription — upgrade any time below.', 'community-business-directory' ); ?>
				<?php endif; ?>
			</div>

			<?php if ( $is_paid ) : ?>
			<div class="cbd-current-plan-actions">
				<button type="button"
						class="cbd-btn cbd-btn-outline cbd-btn-sm cbd-upgrade-btn"
						data-plan="free"
						data-plan-name="<?php esc_attr_e( 'Free', 'community-business-directory' ); ?>"
						data-price-monthly="0"
						data-price-annual="0"
						data-post-id="<?php echo esc_attr( $business->post_id ); ?>">
					<?php esc_html_e( 'Cancel & downgrade to Free', 'community-business-directory' ); ?>
				</button>
			</div>
			<?php endif; ?>
		</div>

		<h3 style="margin-top:28px;"><?php esc_html_e( 'Change your plan', 'community-business-directory' ); ?></h3>
		<?php
		// Reuse the canonical plans grid — it marks the current plan and emits
		// the .cbd-upgrade-btn buttons the frontend JS wires to cbd_change_plan.
		echo do_shortcode( '[cbd_plans]' );
	}

	// ── Modal helpers ─────────────────────────────────────────────

	private function render_event_modal(): void { ?>
		<div id="cbd-event-form-wrap" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal">
				<button class="cbd-modal-close" id="cbd-event-form-close">✕</button>
				<h3><?php esc_html_e( 'Create New Event', 'community-business-directory' ); ?></h3>
				<form id="cbd-event-form" class="cbd-form">
					<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
					<input type="hidden" name="action" value="cbd_create_event">
					<div class="cbd-form-row"><label><?php esc_html_e( 'Event Title', 'community-business-directory' ); ?> <span class="req">*</span></label><input type="text" name="event_title" required></div>
					<div class="cbd-form-row"><label><?php esc_html_e( 'Description', 'community-business-directory' ); ?></label><textarea name="event_description" rows="3"></textarea></div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e( 'Start', 'community-business-directory' ); ?> <span class="req">*</span></label><input type="datetime-local" name="event_start" required></div>
						<div class="cbd-form-row"><label><?php esc_html_e( 'End', 'community-business-directory' ); ?> <span class="req">*</span></label><input type="datetime-local" name="event_end" required></div>
					</div>
					<div class="cbd-form-row"><label><?php esc_html_e( 'Venue', 'community-business-directory' ); ?></label><input type="text" name="event_venue"></div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e( 'Ticket URL', 'community-business-directory' ); ?></label><input type="url" name="event_ticket_url"></div>
						<div class="cbd-form-row"><label><?php esc_html_e( 'Price', 'community-business-directory' ); ?></label><input type="number" name="event_ticket_price" min="0" step="0.01" value="0"></div>
					</div>
					<div class="cbd-form-row"><label><input type="checkbox" name="event_is_free" value="1" checked> <?php esc_html_e( 'Free event', 'community-business-directory' ); ?></label></div>
					<div class="cbd-form-row"><label><?php esc_html_e( 'Image', 'community-business-directory' ); ?></label><input type="file" name="event_image" accept="image/*"></div>
					<div class="cbd-form-actions">
						<button type="submit" class="cbd-btn cbd-btn-primary"><span class="btn-text"><?php esc_html_e( 'Create Event', 'community-business-directory' ); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e( 'Creating…', 'community-business-directory' ); ?></span></button>
					</div>
					<div class="cbd-form-msg" style="display:none;"></div>
				</form>
			</div>
		</div>
	<?php }

	private function render_promo_modal(): void {
		$today = gmdate( 'Y-m-d' );
		$sym   = get_option( 'cbd_currency_symbol', '£' ); ?>
		<div id="cbd-promo-form-wrap" class="cbd-modal-wrap" style="display:none;">
			<div class="cbd-modal">
				<button class="cbd-modal-close" id="cbd-promo-form-close">✕</button>
				<h3><?php esc_html_e( 'Create Promotion', 'community-business-directory' ); ?></h3>
				<form id="cbd-promo-form" class="cbd-form">
					<?php wp_nonce_field( 'cbd_nonce', 'cbd_nonce' ); ?>
					<input type="hidden" name="action" value="cbd_create_promotion">
					<div class="cbd-form-row"><label><?php esc_html_e( 'Offer Title', 'community-business-directory' ); ?> <span class="req">*</span></label><input type="text" name="promo_title" required></div>
					<div class="cbd-form-row"><label><?php esc_html_e( 'Description', 'community-business-directory' ); ?> <span class="req">*</span></label><textarea name="promo_description" rows="3" required></textarea></div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e( 'Coupon Code', 'community-business-directory' ); ?></label><input type="text" name="promo_code" placeholder="SAVE20"></div>
						<div class="cbd-form-row"><label><?php esc_html_e( 'Discount', 'community-business-directory' ); ?></label><div style="display:flex;gap:8px;"><input type="number" name="promo_discount_value" min="0" placeholder="20" style="flex:1;"><select name="promo_discount_type"><option value="percent">%</option><option value="fixed"><?php echo esc_html( $sym ); ?></option></select></div></div>
					</div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e( 'Start Date', 'community-business-directory' ); ?></label><input type="date" name="promo_start" value="<?php echo esc_attr( $today ); ?>"></div>
						<div class="cbd-form-row"><label><?php esc_html_e( 'Expiry Date', 'community-business-directory' ); ?></label><input type="date" name="promo_expiry"></div>
					</div>
					<div class="cbd-form-cols">
						<div class="cbd-form-row"><label><?php esc_html_e( 'CTA Text', 'community-business-directory' ); ?></label><input type="text" name="promo_cta_text" value="Get Offer"></div>
						<div class="cbd-form-row"><label><?php esc_html_e( 'CTA URL', 'community-business-directory' ); ?></label><input type="url" name="promo_cta_url"></div>
					</div>
					<div class="cbd-form-actions">
						<button type="submit" class="cbd-btn cbd-btn-primary"><span class="btn-text"><?php esc_html_e( 'Publish Promotion', 'community-business-directory' ); ?></span><span class="btn-loading" style="display:none;"><?php esc_html_e( 'Saving…', 'community-business-directory' ); ?></span></button>
					</div>
					<div class="cbd-form-msg" style="display:none;"></div>
				</form>
			</div>
		</div>
	<?php }
}
