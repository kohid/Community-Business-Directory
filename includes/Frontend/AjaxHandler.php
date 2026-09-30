<?php
namespace CBD\Frontend;
defined( 'ABSPATH' ) || exit;

class AjaxHandler {

    private function verify( string $action ): void {
        if ( ! check_ajax_referer( 'cbd_nonce', 'cbd_nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'community-business-directory' ) ], 403 );
        }
    }

    // ── Register Business ─────────────────────────────────────────
    public function cbd_register_business(): void {
        $this->verify( 'cbd_register_business' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'community-business-directory' ) ], 401 );
        }

        $name = sanitize_text_field( $_POST['biz_name'] ?? '' );
        $desc = wp_kses_post( $_POST['biz_description'] ?? '' );

        if ( ! $name ) {
            wp_send_json_error( [ 'message' => __( 'Business name is required.', 'community-business-directory' ) ] );
        }

        $needs_approval = (bool) get_option( 'cbd_registration_requires_approval', true );
        $user_id        = get_current_user_id();

        $post_id = wp_insert_post( [
            'post_type'    => 'cbd_business',
            'post_title'   => $name,
            'post_content' => $desc,
            'post_excerpt' => sanitize_textarea_field( $_POST['biz_tagline'] ?? '' ),
            // Hidden from the public directory until approved; the owner still
            // sees it in their dashboard (which reads the custom table directly).
            'post_status'  => $needs_approval ? 'pending' : 'publish',
            'post_author'  => $user_id,
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        // Category
        $cat_id = (int) ( $_POST['biz_category'] ?? 0 );
        if ( $cat_id ) wp_set_post_terms( $post_id, [ $cat_id ], 'cbd_category' );

        // Opening hours
        $hours = [];
        if ( ! empty( $_POST['hours'] ) && is_array( $_POST['hours'] ) ) {
            foreach ( $_POST['hours'] as $day => $data ) {
                $hours[ sanitize_key( $day ) ] = [
                    'open'  => ! empty( $data['open'] ),
                    'from'  => sanitize_text_field( $data['from'] ?? '09:00' ),
                    'to'    => sanitize_text_field( $data['to']   ?? '17:00' ),
                ];
            }
        }

        // Social links
        $social = [
            'facebook'  => esc_url_raw( $_POST['social_facebook']  ?? '' ),
            'instagram' => esc_url_raw( $_POST['social_instagram'] ?? '' ),
            'twitter'   => esc_url_raw( $_POST['social_twitter']   ?? '' ),
            'linkedin'  => esc_url_raw( $_POST['social_linkedin']  ?? '' ),
        ];

        // Handle logo + cover uploads
        if ( ! empty( $_FILES['biz_logo']['name'] ) || ! empty( $_FILES['biz_cover']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            if ( ! empty( $_FILES['biz_logo']['name'] ) ) {
                $logo_id = media_handle_upload( 'biz_logo', $post_id );
                if ( ! is_wp_error( $logo_id ) ) set_post_thumbnail( $post_id, $logo_id );
            }
            if ( ! empty( $_FILES['biz_cover']['name'] ) ) {
                $cover_id = media_handle_upload( 'biz_cover', $post_id );
                if ( ! is_wp_error( $cover_id ) ) update_post_meta( $post_id, '_cbd_cover_id', (int) $cover_id );
            }
        }

        // Insert into custom table
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'cbd_businesses', [
            'post_id'      => $post_id,
            'owner_id'     => $user_id,
            'status'       => $needs_approval ? 'pending' : 'active',
            'email'        => sanitize_email( $_POST['biz_email']    ?? '' ),
            'phone'        => sanitize_text_field( $_POST['biz_phone']    ?? '' ),
            'website'      => esc_url_raw( $_POST['biz_website']  ?? '' ),
            'address_line1'=> sanitize_text_field( $_POST['biz_address']  ?? '' ),
            'city'         => sanitize_text_field( $_POST['biz_city']     ?? '' ),
            'postal_code'  => sanitize_text_field( $_POST['biz_postcode'] ?? '' ),
            'country'      => get_option( 'cbd_default_country', 'GB' ),
            'social_links' => wp_json_encode( $social ),
            'opening_hours'=> wp_json_encode( $hours ),
        ] );

        // Admin notification is handled by CBD\Modules\Notifications on this hook.
        do_action( 'cbd_business_submitted', $post_id );

        // No approval gate → the listing is live now, so promote the owner.
        if ( ! $needs_approval ) {
            \cbd_promote_business_owner( $post_id );
        }

        wp_send_json_success( [
            'message'  => $needs_approval
                ? __( 'Your business has been submitted and is pending review. We will notify you by email.', 'community-business-directory' )
                : __( 'Your business is now live!', 'community-business-directory' ),
            'post_id'  => $post_id,
            'url'      => get_permalink( $post_id ),
            'redirect' => ! empty( $_POST['redirect'] ) ? esc_url_raw( $_POST['redirect'] ) : '',
        ] );
    }

    // ── Create Event ──────────────────────────────────────────────
    public function cbd_create_event(): void {
        $this->verify( 'cbd_create_event' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'community-business-directory' ) ], 401 );
        }

        $title = sanitize_text_field( $_POST['event_title'] ?? '' );
        $desc  = wp_kses_post( $_POST['event_description'] ?? '' );
        $start = sanitize_text_field( $_POST['event_start'] ?? '' );
        $end   = sanitize_text_field( $_POST['event_end']   ?? '' );

        if ( ! $title || ! $start || ! $end ) {
            wp_send_json_error( [ 'message' => __( 'Title, start and end date are required.', 'community-business-directory' ) ] );
        }

        $user_id = get_current_user_id();
        $post_id = wp_insert_post( [
            'post_type'    => 'cbd_event',
            'post_title'   => $title,
            'post_content' => $desc,
            'post_status'  => 'publish',
            'post_author'  => $user_id,
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        // Handle image upload
        if ( ! empty( $_FILES['event_image']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $img_id = media_handle_upload( 'event_image', $post_id );
            if ( ! is_wp_error( $img_id ) ) set_post_thumbnail( $post_id, $img_id );
        }

        // Tie the event to a specific business (passed from its profile, else
        // the owner's first listing) so it only shows on that business's page.
        global $wpdb;
        $business_id = (int) ( $_POST['business_id'] ?? 0 );
        if ( ! $business_id || ! \cbd_user_can( $business_id, 'events', $user_id ) ) {
            $biz = $wpdb->get_row( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}cbd_businesses WHERE owner_id = %d LIMIT 1", $user_id ) );
            $business_id = $biz ? (int) $biz->post_id : 0;
        }
        if ( $business_id ) {
            update_post_meta( $post_id, '_cbd_business_id', $business_id );
        }

        $wpdb->insert( $wpdb->prefix . 'cbd_events', [
            'post_id'      => $post_id,
            'business_id'  => $business_id,
            'owner_id'     => $user_id,
            'start_date'   => date( 'Y-m-d H:i:s', strtotime( $start ) ),
            'end_date'     => date( 'Y-m-d H:i:s', strtotime( $end ) ),
            'venue_name'   => sanitize_text_field( $_POST['event_venue']       ?? '' ),
            'venue_addr'   => sanitize_textarea_field( $_POST['event_venue_addr'] ?? '' ),
            'ticket_url'   => esc_url_raw( $_POST['event_ticket_url']   ?? '' ),
            'ticket_price' => (float) ( $_POST['event_ticket_price'] ?? 0 ),
            'is_free'      => ! empty( $_POST['event_is_free'] ) ? 1 : 0,
            'status'       => 'published',
        ] );

        wp_send_json_success( [
            'message' => __( 'Event created successfully!', 'community-business-directory' ),
            'url'     => get_permalink( $post_id ),
        ] );
    }

    // ── Create Promotion ──────────────────────────────────────────
    public function cbd_create_promotion(): void {
        $this->verify( 'cbd_create_promotion' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'community-business-directory' ) ], 401 );
        }

        $title = sanitize_text_field( $_POST['promo_title'] ?? '' );
        $desc  = wp_kses_post( $_POST['promo_description'] ?? '' );

        if ( ! $title ) {
            wp_send_json_error( [ 'message' => __( 'Promotion title is required.', 'community-business-directory' ) ] );
        }

        $user_id = get_current_user_id();
        $post_id = wp_insert_post( [
            'post_type'    => 'cbd_promotion',
            'post_title'   => $title,
            'post_content' => $desc,
            'post_excerpt' => $desc,
            'post_status'  => 'publish',
            'post_author'  => $user_id,
        ] );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        global $wpdb;
        $business_id = (int) ( $_POST['business_id'] ?? 0 );
        if ( ! $business_id || ! \cbd_user_can( $business_id, 'promotions', $user_id ) ) {
            $biz = $wpdb->get_row( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}cbd_businesses WHERE owner_id = %d LIMIT 1", $user_id ) );
            $business_id = $biz ? (int) $biz->post_id : 0;
        }
        if ( $business_id ) {
            update_post_meta( $post_id, '_cbd_business_id', $business_id );
        }

        $wpdb->insert( $wpdb->prefix . 'cbd_promotions', [
            'post_id'        => $post_id,
            'business_id'    => $business_id,
            'owner_id'       => $user_id,
            'coupon_code'    => strtoupper( sanitize_text_field( $_POST['promo_code']           ?? '' ) ),
            'discount_type'  => sanitize_text_field( $_POST['promo_discount_type']  ?? 'percent' ),
            'discount_value' => (float) ( $_POST['promo_discount_value'] ?? 0 ),
            'start_date'     => sanitize_text_field( $_POST['promo_start']  ?? gmdate( 'Y-m-d' ) ),
            'expiry_date'    => sanitize_text_field( $_POST['promo_expiry'] ?? '' ) ?: null,
            'cta_text'       => sanitize_text_field( $_POST['promo_cta_text'] ?? 'Get Offer' ),
            'cta_url'        => esc_url_raw( $_POST['promo_cta_url'] ?? '' ),
            'status'         => 'active',
        ] );

        wp_send_json_success( [
            'message' => __( 'Promotion published!', 'community-business-directory' ),
        ] );
    }

    // ── Submit Review ─────────────────────────────────────────────
    public function cbd_submit_review(): void {
        $this->verify( 'cbd_submit_review' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in to leave a review.', 'community-business-directory' ) ], 401 );
        }

        $business_id = (int) ( $_POST['business_id'] ?? 0 );
        $rating      = (int) ( $_POST['review_rating'] ?? 5 );
        $content     = sanitize_textarea_field( $_POST['review_content'] ?? '' );

        if ( ! $business_id || ! $content ) {
            wp_send_json_error( [ 'message' => __( 'Business and review content are required.', 'community-business-directory' ) ] );
        }

        $rating      = max( 1, min( 5, $rating ) );
        $user        = wp_get_current_user();
        $needs_mod   = (bool) get_option( 'cbd_reviews_require_moderation', true );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'cbd_reviews', [
            'business_id'  => $business_id,
            'author_id'    => $user->ID,
            'author_name'  => $user->display_name,
            'author_email' => $user->user_email,
            'rating'       => $rating,
            'title'        => sanitize_text_field( $_POST['review_title'] ?? '' ),
            'content'      => $content,
            'status'       => $needs_mod ? 'pending' : 'approved',
        ] );

        // Recalculate rating average if auto-approved
        if ( ! $needs_mod ) {
            $avg = $wpdb->get_var( $wpdb->prepare(
                "SELECT AVG(rating) FROM {$wpdb->prefix}cbd_reviews WHERE business_id = %d AND status = 'approved'",
                $business_id
            ) );
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_reviews WHERE business_id = %d AND status = 'approved'",
                $business_id
            ) );
            $wpdb->update( $wpdb->prefix . 'cbd_businesses',
                [ 'rating_avg' => round( (float) $avg, 2 ), 'review_count' => (int) $count ],
                [ 'post_id' => $business_id ]
            );
        }

        // Render the row so the front end can insert it without a reload.
        $row = (object) [
            'author_id'   => $user->ID,
            'author_name' => $user->display_name,
            'rating'      => $rating,
            'title'       => sanitize_text_field( $_POST['review_title'] ?? '' ),
            'content'     => $content,
            'created_at'  => current_time( 'mysql' ),
            'response'    => '',
        ];

        wp_send_json_success( [
            'message' => $needs_mod
                ? __( 'Thank you! Your review is pending moderation.', 'community-business-directory' )
                : __( 'Review published. Thank you!', 'community-business-directory' ),
            'pending' => $needs_mod,
            'html'    => \CBD\Frontend\Shortcodes\ReviewsShortcode::render_item( $row, $needs_mod ),
        ] );
    }

    // ── Follow Business ───────────────────────────────────────────
    public function cbd_follow_business(): void {
        $this->verify( 'cbd_follow_business' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Log in to follow businesses.', 'community-business-directory' ) ], 401 );
        }

        $business_id = (int) ( $_POST['business_id'] ?? 0 );
        $action_type = sanitize_text_field( $_POST['follow_action'] ?? 'follow' );
        $user_id     = get_current_user_id();

        global $wpdb;
        if ( $action_type === 'unfollow' ) {
            $wpdb->delete( $wpdb->prefix . 'cbd_follows', [ 'user_id' => $user_id, 'business_id' => $business_id ] );
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}cbd_businesses SET follower_count = GREATEST(0, follower_count - 1) WHERE post_id = %d", $business_id ) );
            wp_send_json_success( [ 'following' => false, 'message' => __( 'Unfollowed.', 'community-business-directory' ) ] );
        } else {
            $wpdb->replace( $wpdb->prefix . 'cbd_follows', [ 'user_id' => $user_id, 'business_id' => $business_id ] );
            $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}cbd_businesses SET follower_count = follower_count + 1 WHERE post_id = %d", $business_id ) );
            wp_send_json_success( [ 'following' => true, 'message' => __( 'Following!', 'community-business-directory' ) ] );
        }
    }

    // ── Live Search ───────────────────────────────────────────────
    public function cbd_live_search(): void {
        $q = sanitize_text_field( $_GET['q'] ?? '' );
        if ( strlen( $q ) < 2 ) wp_send_json_success( [] );

        $query = new \WP_Query( [
            'post_type'      => [ 'cbd_business', 'cbd_event' ],
            'post_status'    => 'publish',
            's'              => $q,
            'posts_per_page' => 8,
            'fields'         => 'ids',
        ] );

        $results = array_map( fn( $id ) => [
            'id'    => $id,
            'title' => \cbd_label( get_the_title( $id ) ),
            'type'  => get_post_type( $id ),
            'url'   => get_permalink( $id ),
            'img'   => get_the_post_thumbnail_url( $id, 'thumbnail' ) ?: '',
        ], $query->posts );

        wp_send_json_success( $results );
    }

    /**
     * Public read: render one month of the [cbd_calendar] (grid + agenda) for the
     * requested month/search/category. No nonce — read-only, like cbd_live_search.
     */
    public function cbd_calendar(): void {
        // Focus date (Y-m-d) drives month grid + agenda + day timeline. Falls
        // back to today on anything malformed or out of range.
        $date  = sanitize_text_field( wp_unslash( $_GET['date'] ?? '' ) );
        $parts = explode( '-', $date );
        $y = (int) ( $parts[0] ?? 0 );
        $m = (int) ( $parts[1] ?? 0 );
        $d = (int) ( $parts[2] ?? 0 );
        if ( $y < 1970 || $y > 2200 || $m < 1 || $m > 12 || $d < 1 || $d > 31 ) {
            $date = current_time( 'Y-m-d' );
        } else {
            $date = sprintf( '%04d-%02d-%02d', $y, $m, $d );
        }
        $search   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
        $category = sanitize_text_field( wp_unslash( $_GET['cat'] ?? '' ) );

        wp_send_json_success( \CBD\Frontend\Shortcodes\CalendarShortcode::view_data( $date, $search, $category ) );
    }

    // ── Update Profile ────────────────────────────────────────────
    public function cbd_update_profile(): void {
        $this->verify( 'cbd_update_profile' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );
        if ( ! $post || ! \cbd_user_can( $post_id, 'edit' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
        }

        $update = [
            'ID'           => $post_id,
            'post_title'   => sanitize_text_field( $_POST['biz_name']        ?? $post->post_title ),
            'post_content' => wp_kses_post( $_POST['biz_description']        ?? '' ),
        ];
        // Tagline / slogan (stored as the excerpt) — only overwrite if sent.
        if ( isset( $_POST['biz_tagline'] ) ) {
            $update['post_excerpt'] = sanitize_textarea_field( $_POST['biz_tagline'] );
        }
        wp_update_post( $update );

        // Category
        $cat_id = (int) ( $_POST['biz_category'] ?? 0 );
        if ( $cat_id ) {
            wp_set_post_terms( $post_id, [ $cat_id ], 'cbd_category' );
        }

        // Social links
        $social = [
            'facebook'  => esc_url_raw( $_POST['social_facebook']  ?? '' ),
            'instagram' => esc_url_raw( $_POST['social_instagram'] ?? '' ),
            'twitter'   => esc_url_raw( $_POST['social_twitter']   ?? '' ),
            'linkedin'  => esc_url_raw( $_POST['social_linkedin']  ?? '' ),
        ];

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'cbd_businesses', [
            'email'        => sanitize_email( $_POST['biz_email']        ?? '' ),
            'phone'        => sanitize_text_field( $_POST['biz_phone']   ?? '' ),
            'website'      => esc_url_raw( $_POST['biz_website']         ?? '' ),
            'address_line1'=> sanitize_text_field( $_POST['biz_address'] ?? '' ),
            'city'         => sanitize_text_field( $_POST['biz_city']    ?? '' ),
            'postal_code'  => sanitize_text_field( $_POST['biz_postcode']?? '' ),
            'social_links' => wp_json_encode( $social ),
        ], [ 'post_id' => $post_id ] );

        // Handle logo + cover uploads
        if ( ! empty( $_FILES['biz_logo']['name'] ) || ! empty( $_FILES['biz_cover']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            if ( ! empty( $_FILES['biz_logo']['name'] ) ) {
                $logo_id = media_handle_upload( 'biz_logo', $post_id );
                if ( ! is_wp_error( $logo_id ) ) {
                    set_post_thumbnail( $post_id, $logo_id );
                }
            }

            // Cover photo is kept distinct from the logo, stored as its own meta.
            if ( ! empty( $_FILES['biz_cover']['name'] ) ) {
                $cover_id = media_handle_upload( 'biz_cover', $post_id );
                if ( ! is_wp_error( $cover_id ) ) {
                    update_post_meta( $post_id, '_cbd_cover_id', (int) $cover_id );
                }
            }
        }

        // Log activity update
        do_action( 'cbd_business_updated', $post_id );

        wp_send_json_success( [ 'message' => __( 'Profile updated successfully!', 'community-business-directory' ) ] );
    }

    // ── Update the logged-in user's own account (NOT the business) ─
    /**
     * Edit the current user's personal profile: display name, first/last name,
     * and an optional profile photo. Email is deliberately read-only — it is
     * the account's identity key (and for social accounts it's owned by the
     * provider), so it is never changed here.
     */
    public function cbd_update_account(): void {
        $this->verify( 'cbd_update_account' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
        }

        $user_id    = get_current_user_id();
        $first      = sanitize_text_field( wp_unslash( $_POST['first_name']   ?? '' ) );
        $last       = sanitize_text_field( wp_unslash( $_POST['last_name']    ?? '' ) );
        $display    = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );

        $update = [
            'ID'         => $user_id,
            'first_name' => $first,
            'last_name'  => $last,
        ];
        // Fall back to a sensible display name if the field was cleared.
        $update['display_name'] = $display ?: ( trim( "$first $last" ) ?: wp_get_current_user()->user_login );

        $res = wp_update_user( $update );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ] );
        }

        // Optional profile photo. Stored as cbd_custom_avatar (URL) and given
        // priority over the social photo by SocialAuth::filter_avatar_data().
        if ( ! empty( $_FILES['account_avatar']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $att_id = media_handle_upload( 'account_avatar', 0 );
            if ( is_wp_error( $att_id ) ) {
                wp_send_json_error( [ 'message' => $att_id->get_error_message() ] );
            }
            update_user_meta( $user_id, 'cbd_custom_avatar', esc_url_raw( (string) wp_get_attachment_url( $att_id ) ) );
            update_user_meta( $user_id, 'cbd_custom_avatar_id', (int) $att_id );
        }

        // Optional password change. Both fields blank = leave unchanged (so the
        // form can save name/avatar without touching the password).
        $current_pass = (string) ( $_POST['current_password'] ?? '' );
        $new_pass     = (string) ( $_POST['new_password']     ?? '' );
        $confirm_pass = (string) ( $_POST['confirm_password'] ?? '' );
        $pass_changed = false;
        if ( '' !== $new_pass || '' !== $confirm_pass ) {
            // Verify the current password — confirms it's really the account owner
            // and not a hijacked session.
            $current_user = wp_get_current_user();
            if ( '' === $current_pass || ! wp_check_password( $current_pass, $current_user->user_pass, $user_id ) ) {
                wp_send_json_error( [ 'message' => __( 'Your current password is incorrect.', 'community-business-directory' ) ] );
            }
            if ( strlen( $new_pass ) < 8 ) {
                wp_send_json_error( [ 'message' => __( 'Your new password must be at least 8 characters.', 'community-business-directory' ) ] );
            }
            if ( $new_pass !== $confirm_pass ) {
                wp_send_json_error( [ 'message' => __( 'The two passwords do not match.', 'community-business-directory' ) ] );
            }
            wp_set_password( $new_pass, $user_id );
            // wp_set_password destroys the session — re-authenticate so the user
            // isn't logged out after changing it.
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true, is_ssl() );
            $pass_changed = true;
        }

        wp_send_json_success( [
            'message'      => $pass_changed
                ? __( 'Your profile and password have been updated.', 'community-business-directory' )
                : __( 'Your profile has been updated.', 'community-business-directory' ),
            'name'         => $update['display_name'],
            'avatar'       => get_avatar_url( $user_id, [ 'size' => 96 ] ),
            'pass_changed' => $pass_changed,
        ] );
    }

    // ── Generic "Load more" paging for list shortcodes ───────────
    /**
     * Returns the next page of a list shortcode (featured / events / promotions
     * / activity / feed). Public read endpoint — no nonce, mirroring
     * cbd_load_directory. Each shortcode implements items( atts, page, per_page ).
     */
    public function cbd_load_more(): void {
        $sc       = sanitize_key( $_POST['sc'] ?? '' );
        $page     = max( 2, (int) ( $_POST['page']     ?? 2 ) );
        $per_page = max( 1, min( 50, (int) ( $_POST['per_page'] ?? 6 ) ) );

        $atts = [];
        if ( ! empty( $_POST['atts'] ) ) {
            $decoded = json_decode( wp_unslash( (string) $_POST['atts'] ), true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $k => $v ) {
                    if ( is_scalar( $v ) ) {
                        $atts[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
                    }
                }
            }
        }

        $map = [
            'featured'   => \CBD\Frontend\Shortcodes\FeaturedShortcode::class,
            'events'     => \CBD\Frontend\Shortcodes\EventsShortcode::class,
            'promotions' => \CBD\Frontend\Shortcodes\PromotionsShortcode::class,
            'activity'   => \CBD\Frontend\Shortcodes\ActivityShortcode::class,
            'feed'       => \CBD\Frontend\Shortcodes\FeedShortcode::class,
        ];
        if ( ! isset( $map[ $sc ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unknown list.', 'community-business-directory' ) ], 400 );
        }

        $obj = new $map[ $sc ]();
        if ( ! method_exists( $obj, 'items' ) ) {
            wp_send_json_error( [ 'message' => __( 'This list does not support paging.', 'community-business-directory' ) ], 400 );
        }

        $res   = (array) $obj->items( $atts, $page, $per_page );
        $total = (int) ( $res['total'] ?? 0 );
        wp_send_json_success( [
            'html'     => (string) ( $res['html'] ?? '' ),
            'has_more' => ( $page * $per_page ) < $total,
        ] );
    }

    // ── List the current user's own activity (content + reviews) ──
    /**
     * HTML list of everything the logged-in user has created — their events,
     * promotions and news posts (any status) plus the reviews they have written
     * — newest first. Loaded lazily into the account-menu "Activities" modal.
     * Read-only, nonce-protected, login-gated.
     */
    public function cbd_my_activities(): void {
        $this->verify( 'cbd_my_activities' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
        }

        global $wpdb;
        $uid     = get_current_user_id();
        $biz_url = \cbd_user_business_url( $uid );
        $items   = [];

        // 1. Authored content: events, promotions, news posts (any status).
        $posts = get_posts( [
            'post_type'      => [ 'cbd_event', 'cbd_promotion', 'cbd_business_post' ],
            'author'         => $uid,
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        $meta = [
            'cbd_event'         => [ 'icon' => '📅', 'label' => __( 'Event', 'community-business-directory' ) ],
            'cbd_promotion'     => [ 'icon' => '🏷️', 'label' => __( 'Promotion', 'community-business-directory' ) ],
            'cbd_business_post' => [ 'icon' => '📝', 'label' => __( 'Post', 'community-business-directory' ) ],
        ];
        foreach ( $posts as $p ) {
            $info    = $meta[ $p->post_type ] ?? [ 'icon' => '📌', 'label' => __( 'Item', 'community-business-directory' ) ];
            $url     = 'cbd_business_post' === $p->post_type ? $biz_url : get_permalink( $p );
            $items[] = [
                'group'  => 'content',
                'ts'     => (int) get_post_time( 'U', true, $p ),
                'icon'   => $info['icon'],
                'type'   => $info['label'],
                'title'  => get_the_title( $p ) ?: __( '(untitled)', 'community-business-directory' ),
                'url'    => (string) $url,
                'status' => $p->post_status,
            ];
        }

        // 2. Reviews the user has written.
        $reviews = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.business_id, r.title, r.rating, r.status, r.created_at, p.post_title
               FROM {$wpdb->prefix}cbd_reviews r
               LEFT JOIN {$wpdb->posts} p ON p.ID = r.business_id
              WHERE r.author_id = %d
              ORDER BY r.created_at DESC
              LIMIT 50",
            $uid
        ) );
        foreach ( (array) $reviews as $r ) {
            $items[] = [
                'group'  => 'reviews',
                'ts'     => $r->created_at ? (int) strtotime( $r->created_at ) : 0,
                'icon'   => '⭐',
                /* translators: %d: star rating 1-5 */
                'type'   => sprintf( __( 'Review (%d★)', 'community-business-directory' ), (int) $r->rating ),
                'title'  => $r->post_title
                    /* translators: %s: business name */
                    ? sprintf( __( 'Review of %s', 'community-business-directory' ), $r->post_title )
                    : ( $r->title ?: __( 'Review', 'community-business-directory' ) ),
                'url'    => $r->business_id ? (string) get_permalink( (int) $r->business_id ) : '',
                'status' => (string) $r->status,
            ];
        }

        // 3. Businesses the user follows.
        $follows = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.business_id, f.created_at, p.post_title
               FROM {$wpdb->prefix}cbd_follows f
               LEFT JOIN {$wpdb->posts} p ON p.ID = f.business_id
              WHERE f.user_id = %d ORDER BY f.created_at DESC LIMIT 50",
            $uid
        ) );
        foreach ( (array) $follows as $f ) {
            $items[] = [
                'group'  => 'follows',
                'ts'     => $f->created_at ? (int) strtotime( $f->created_at ) : 0,
                'icon'   => '🔔',
                'type'   => __( 'Followed', 'community-business-directory' ),
                'title'  => $f->post_title ?: __( 'A business', 'community-business-directory' ),
                'url'    => $f->business_id ? (string) get_permalink( (int) $f->business_id ) : '',
                'status' => '',
            ];
        }

        // 4. Businesses the user has favourited.
        $favs = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.business_id, f.created_at, p.post_title
               FROM {$wpdb->prefix}cbd_favorites f
               LEFT JOIN {$wpdb->posts} p ON p.ID = f.business_id
              WHERE f.user_id = %d ORDER BY f.created_at DESC LIMIT 50",
            $uid
        ) );
        foreach ( (array) $favs as $f ) {
            $items[] = [
                'group'  => 'favorites',
                'ts'     => $f->created_at ? (int) strtotime( $f->created_at ) : 0,
                'icon'   => '❤️',
                'type'   => __( 'Favourited', 'community-business-directory' ),
                'title'  => $f->post_title ?: __( 'A business', 'community-business-directory' ),
                'url'    => $f->business_id ? (string) get_permalink( (int) $f->business_id ) : '',
                'status' => '',
            ];
        }

        // 5. Reactions left on any post (business, event, promotion, news post).
        $emoji     = [ 'like' => '👍', 'love' => '❤️', 'haha' => '😄', 'wow' => '😮', 'sad' => '😢', 'angry' => '😠' ];
        $reactions = $wpdb->get_results( $wpdb->prepare(
            "SELECT x.post_id, x.reaction, x.created_at, p.post_title
               FROM {$wpdb->prefix}cbd_reactions x
               LEFT JOIN {$wpdb->posts} p ON p.ID = x.post_id
              WHERE x.user_id = %d ORDER BY x.created_at DESC LIMIT 50",
            $uid
        ) );
        foreach ( (array) $reactions as $x ) {
            $items[] = [
                'group'  => 'reactions',
                'ts'     => $x->created_at ? (int) strtotime( $x->created_at ) : 0,
                'icon'   => $emoji[ $x->reaction ] ?? '👍',
                'type'   => __( 'Reacted', 'community-business-directory' ),
                'title'  => $x->post_title ?: __( 'A post', 'community-business-directory' ),
                'url'    => $x->post_id ? (string) get_permalink( (int) $x->post_id ) : '',
                'status' => '',
            ];
        }

        // Newest first across every source.
        usort( $items, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );

        // Tabs: "All" plus one per activity type, each with a count.
        $tabs = [
            'all'       => __( 'All',        'community-business-directory' ),
            'content'   => __( 'Content',    'community-business-directory' ),
            'reviews'   => __( 'Reviews',    'community-business-directory' ),
            'follows'   => __( 'Follows',    'community-business-directory' ),
            'favorites' => __( 'Favourites', 'community-business-directory' ),
            'reactions' => __( 'Reactions',  'community-business-directory' ),
        ];
        $counts = [ 'all' => count( $items ) ];
        foreach ( $items as $it ) {
            $counts[ $it['group'] ] = ( $counts[ $it['group'] ] ?? 0 ) + 1;
        }

        ob_start();
        echo '<div class="cbd-act-tabs" role="tablist">';
        $first = true;
        foreach ( $tabs as $key => $label ) {
            printf(
                '<button type="button" class="cbd-act-tab%1$s" role="tab" data-cbd-act-tab="%2$s" aria-selected="%3$s">%4$s <span class="cbd-act-count">%5$d</span></button>',
                $first ? ' is-active' : '',
                esc_attr( $key ),
                $first ? 'true' : 'false',
                esc_html( $label ),
                (int) ( $counts[ $key ] ?? 0 )
            );
            $first = false;
        }
        echo '</div>';

        $first = true;
        foreach ( array_keys( $tabs ) as $key ) {
            $subset = 'all' === $key
                ? $items
                : array_values( array_filter( $items, static fn( $i ) => $i['group'] === $key ) );
            printf(
                '<div class="cbd-act-panel%s" data-cbd-act-panel="%s"%s>',
                $first ? ' is-active' : '',
                esc_attr( $key ),
                $first ? '' : ' hidden'
            );
            echo $this->render_activity_rows( $subset ); // phpcs:ignore — escaped within
            echo '</div>';
            $first = false;
        }

        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    /** Render a <ul> of activity rows (already escaped), or an empty state. */
    private function render_activity_rows( array $items ): string {
        if ( ! $items ) {
            return '<div class="cbd-empty-state"><span class="cbd-empty-icon">📭</span><p>'
                . esc_html__( 'Nothing here yet.', 'community-business-directory' ) . '</p></div>';
        }
        ob_start();
        echo '<ul class="cbd-activities-list">';
        foreach ( $items as $it ) {
            $when = $it['ts']
                /* translators: %s: human-readable time difference, e.g. "3 days" */
                ? sprintf( __( '%s ago', 'community-business-directory' ), human_time_diff( $it['ts'], time() ) )
                : '';
            echo '<li class="cbd-activity-row">';
            echo '<span class="cbd-activity-ico" aria-hidden="true">' . esc_html( $it['icon'] ) . '</span>';
            echo '<span class="cbd-activity-main">';
            if ( $it['url'] ) {
                echo '<a class="cbd-activity-link" href="' . esc_url( $it['url'] ) . '">' . esc_html( $it['title'] ) . '</a>';
            } else {
                echo '<span class="cbd-activity-link">' . esc_html( $it['title'] ) . '</span>';
            }
            echo '<span class="cbd-activity-sub">' . esc_html( $it['type'] ) . ( $when ? ' · ' . esc_html( $when ) : '' ) . '</span>';
            echo '</span>';
            if ( ! empty( $it['status'] ) ) {
                echo '<span class="cbd-activity-status cbd-status-' . esc_attr( $it['status'] ) . '">' . esc_html( ucfirst( $it['status'] ) ) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        return ob_get_clean();
    }

    // ── Change / Upgrade Membership Plan ──────────────────────────
    /**
     * Self-service plan switch for a business owner (or delegate / admin).
     * Validates the target plan against {prefix}cbd_membership_plans, updates
     * the owner's business to that plan, recomputes the expiry from the chosen
     * billing cycle, and keeps the mirrored `_cbd_is_featured` meta in sync so
     * WP_Query ordering tracks the plan (per the hybrid-data-model convention).
     *
     * NOTE: there is no payment gateway wired yet — the switch applies
     * immediately. The plan's `stripe_price_id` is returned so a checkout step
     * can be inserted ahead of the DB write later without changing this contract.
     */
    public function cbd_change_plan(): void {
        $this->verify( 'cbd_change_plan' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Please log in to change your plan.', 'community-business-directory' ) ], 401 );
        }

        global $wpdb;
        $user_id = get_current_user_id();
        $slug    = sanitize_key( $_POST['plan'] ?? '' );
        $cycle   = ( ( $_POST['cycle'] ?? 'monthly' ) === 'annual' ) ? 'annual' : 'monthly';

        // 1. The target plan must exist and be active.
        $plan = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cbd_membership_plans WHERE slug = %s AND is_active = 1",
            $slug
        ) );
        if ( ! $plan ) {
            wp_send_json_error( [ 'message' => __( 'That plan is not available.', 'community-business-directory' ) ] );
        }

        // 2. Resolve the business. An explicit post_id supports delegates /
        //    owners with more than one listing; otherwise use the owner's first.
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id ) {
            if ( ! \cbd_user_can_manage_business( $post_id ) ) {
                wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
            }
            $business = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d",
                $post_id
            ) );
        } else {
            $business = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE owner_id = %d ORDER BY id ASC LIMIT 1",
                $user_id
            ) );
        }

        if ( ! $business ) {
            $reg_page = (int) get_option( 'cbd_register_page_id' );
            wp_send_json_error( [
                'message'  => __( 'Register your business first, then pick a plan.', 'community-business-directory' ),
                'redirect' => $reg_page ? get_permalink( $reg_page ) : home_url( '/' ),
            ] );
        }

        $post_id  = (int) $business->post_id;
        $old_plan = (string) $business->plan;

        if ( $old_plan === $slug ) {
            wp_send_json_error( [ 'message' => __( 'You are already on this plan.', 'community-business-directory' ) ] );
        }

        // 3. Expiry: free plans never expire; paid plans renew one cycle out.
        $is_paid = ( (float) $plan->price_monthly > 0 ) || ( (float) $plan->price_annual > 0 );
        $expires = null;
        if ( $is_paid ) {
            $expires = gmdate( 'Y-m-d H:i:s', strtotime( $cycle === 'annual' ? '+1 year' : '+1 month' ) );
        }

        // 4. Apply the change to the source-of-truth custom table.
        $wpdb->update(
            $wpdb->prefix . 'cbd_businesses',
            [
                'plan'            => $slug,
                'plan_expires_at' => $expires,
                'is_featured'     => (int) $plan->is_featured,
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ 'post_id' => $post_id ]
        );

        // 5. Mirror the featured flag into post meta so WP_Query ordering matches.
        update_post_meta( $post_id, '_cbd_is_featured', (int) $plan->is_featured );

        // 6. Broadcast for notifications / activity logging side effects.
        do_action( 'cbd_business_plan_changed', $post_id, $slug, $old_plan );

        wp_send_json_success( [
            'message'  => sprintf(
                /* translators: %s: plan name */
                __( 'Your business is now on the %s plan.', 'community-business-directory' ),
                $plan->name
            ),
            'plan'      => $slug,
            'planName'  => $plan->name,
            'expiresAt' => $expires,
            'stripeId'  => $plan->stripe_price_id, // reserved for future checkout step
            'reload'    => true,
        ] );
    }

	// ── Toggle Favourite ──────────────────────────────────────────
	public function cbd_toggle_favorite(): void {
		$this->verify( 'cbd_toggle_favorite' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Log in to save favourites.', 'community-business-directory' ) ], 401 );
		}

		$business_id = (int) ( $_POST['business_id'] ?? 0 );
		$user_id     = get_current_user_id();

		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}cbd_favorites WHERE user_id = %d AND business_id = %d",
			$user_id, $business_id
		) );

		if ( $exists ) {
			$wpdb->delete( $wpdb->prefix . 'cbd_favorites', [ 'user_id' => $user_id, 'business_id' => $business_id ] );
			wp_send_json_success( [ 'favorited' => false, 'message' => __( 'Removed from favourites.', 'community-business-directory' ) ] );
		} else {
			$wpdb->replace( $wpdb->prefix . 'cbd_favorites', [ 'user_id' => $user_id, 'business_id' => $business_id ] );
			wp_send_json_success( [ 'favorited' => true,  'message' => __( 'Saved to favourites!', 'community-business-directory' ) ] );
		}
	}

	// ── Create Business Post ──────────────────────────────────────
	public function cbd_create_business_post(): void {
		$this->verify( 'cbd_create_business_post' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'community-business-directory' ) ], 401 );
		}

		$user_id = get_current_user_id();
		$title   = sanitize_text_field( $_POST['post_title']   ?? '' );
		$content = wp_kses_post( $_POST['post_content']        ?? '' );
		$type    = sanitize_key( $_POST['post_type_label']     ?? 'update' );

		if ( ! $title || ! $content ) {
			wp_send_json_error( [ 'message' => __( 'Title and content are required.', 'community-business-directory' ) ] );
		}

		// Resolve which business this post belongs to. A post is tied to one
		// business so it only appears on that business's News Feed.
		global $wpdb;
		$business_id = (int) ( $_POST['business_id'] ?? 0 );
		if ( $business_id ) {
			if ( ! \cbd_user_can( $business_id, 'posts', $user_id ) ) {
				wp_send_json_error( [ 'message' => __( 'You cannot post for this business.', 'community-business-directory' ) ] );
			}
		} else {
			$biz = $wpdb->get_row( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}cbd_businesses WHERE owner_id = %d AND status = 'active' LIMIT 1",
				$user_id
			) );
			if ( ! $biz && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => __( 'You need an active business listing to create posts.', 'community-business-directory' ) ] );
			}
			$business_id = $biz ? (int) $biz->post_id : 0;
		}

		$post_id = wp_insert_post( [
			'post_type'    => 'cbd_business_post',
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => wp_trim_words( strip_tags( $content ), 30 ),
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		] );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		// Handle image upload.
		if ( ! empty( $_FILES['post_image']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$img_id = media_handle_upload( 'post_image', $post_id );
			if ( ! is_wp_error( $img_id ) ) {
				set_post_thumbnail( $post_id, $img_id );
			}
		}

		// Store post type label + owning business as meta.
		update_post_meta( $post_id, '_cbd_post_type', $type );
		if ( $business_id ) {
			update_post_meta( $post_id, '_cbd_business_id', $business_id );
		}

		wp_send_json_success( [
			'message' => __( 'Post published!', 'community-business-directory' ),
			'url'     => get_permalink( $post_id ),
			'post_id' => $post_id,
			'html'    => \CBD\Frontend\Shortcodes\FeedShortcode::render_item( $post_id ),
		] );
	}

	// ── Delete Business Post ──────────────────────────────────────
	public function cbd_delete_business_post(): void {
		$this->verify( 'cbd_delete_business_post' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || (int) $post->post_author !== get_current_user_id() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
		}

		wp_delete_post( $post_id, true );
		wp_send_json_success( [ 'message' => __( 'Post deleted.', 'community-business-directory' ) ] );
	}


	// ── Upload Gallery Images ─────────────────────────────────────
	public function cbd_upload_gallery(): void {
		$this->verify( 'cbd_upload_gallery' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || ! \cbd_user_can( $post_id, 'gallery' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$uploaded = [];
		$errors   = [];

		if ( empty( $_FILES['gallery_images'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No files received.', 'community-business-directory' ) ] );
		}

		// Album the photos belong to (free text — owners pick or create one).
		$album = sanitize_text_field( wp_unslash( $_POST['album'] ?? '' ) );
		$album = $album !== '' ? mb_substr( $album, 0, 80 ) : __( 'General', 'community-business-directory' );

		$files = $_FILES['gallery_images'];
		$count = is_array( $files['name'] ) ? count( $files['name'] ) : 1;
		$count = min( $count, 5 ); // max 5 at a time

		for ( $i = 0; $i < $count; $i++ ) {
			$_FILES['gallery_image_single'] = [
				'name'     => is_array( $files['name'] )     ? $files['name'][$i]     : $files['name'],
				'type'     => is_array( $files['type'] )     ? $files['type'][$i]     : $files['type'],
				'tmp_name' => is_array( $files['tmp_name'] ) ? $files['tmp_name'][$i] : $files['tmp_name'],
				'error'    => is_array( $files['error'] )    ? $files['error'][$i]    : $files['error'],
				'size'     => is_array( $files['size'] )     ? $files['size'][$i]     : $files['size'],
			];
			$attachment_id = media_handle_upload( 'gallery_image_single', $post_id );
			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = $attachment_id->get_error_message();
			} else {
				update_post_meta( $attachment_id, '_cbd_album', $album );
				$uploaded[] = $attachment_id;
			}
		}

		if ( $uploaded ) {
			wp_send_json_success( [
				'message'  => sprintf( __( '%d photo(s) uploaded successfully!', 'community-business-directory' ), count( $uploaded ) ),
				'ids'      => $uploaded,
			] );
		} else {
			wp_send_json_error( [ 'message' => implode( ', ', $errors ) ?: __( 'Upload failed.', 'community-business-directory' ) ] );
		}
	}

	// ── Upload an image from the WYSIWYG editor ───────────────────
	public function cbd_upload_editor_image(): void {
		$this->verify( 'cbd_upload_editor_image' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
		}
		if ( empty( $_FILES['image'] ) || ! empty( $_FILES['image']['error'] ) ) {
			wp_send_json_error( [ 'message' => __( 'No image received.', 'community-business-directory' ) ], 400 );
		}

		// Images only, capped at 5 MB.
		$name  = isset( $_FILES['image']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['image']['name'] ) ) : '';
		$check = wp_check_filetype( $name );
		if ( strpos( (string) $check['type'], 'image/' ) !== 0 ) {
			wp_send_json_error( [ 'message' => __( 'Only image files are allowed.', 'community-business-directory' ) ], 400 );
		}
		if ( (int) ( $_FILES['image']['size'] ?? 0 ) > 5 * MB_IN_BYTES ) {
			wp_send_json_error( [ 'message' => __( 'Images must be 5 MB or smaller.', 'community-business-directory' ) ], 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'image', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ], 500 );
		}

		update_post_meta( $attachment_id, '_cbd_editor_upload', 1 );

		wp_send_json_success( [
			'url' => wp_get_attachment_url( $attachment_id ),
			'id'  => (int) $attachment_id,
		] );
	}

	// ── Delete Gallery Image ──────────────────────────────────────
	public function cbd_delete_gallery_image(): void {
		$this->verify( 'cbd_delete_gallery_image' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
		}

		$attachment_id = (int) ( $_POST['attachment_id'] ?? 0 );
		$attachment    = get_post( $attachment_id );

		if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
			wp_send_json_error( [ 'message' => __( 'Image not found.', 'community-business-directory' ) ] );
		}

		// Verify the parent post is a business this user can manage.
		$parent = get_post( $attachment->post_parent );
		if ( ! $parent || ! \cbd_user_can( $parent->ID, 'gallery' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
		}

		wp_delete_attachment( $attachment_id, true );
		wp_send_json_success( [ 'message' => __( 'Photo deleted.', 'community-business-directory' ) ] );
	}

	// ── Delegate Access ───────────────────────────────────────────
	public function cbd_save_delegates(): void {
		$this->verify( 'cbd_save_delegates' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
		}

		$business_id = (int) ( $_POST['business_id'] ?? 0 );
		// Managing delegates is owner-only — delegates cannot add/remove others
		// or change permissions, even ones with full access.
		if ( ! $business_id || ! \cbd_is_business_owner( $business_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
		}

		$op    = sanitize_key( $_POST['op'] ?? 'add' );
		$owner = (int) get_post_field( 'post_author', $business_id );
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) get_post_meta( $business_id, '_cbd_delegates', true ) ) ) ) );
		$perms = (array) get_post_meta( $business_id, '_cbd_delegate_perms', true );
		$caps  = array_keys( \cbd_delegate_capabilities() );

		// ── Invitation operations (no direct add — access is granted on accept) ──
		if ( $op === 'add' ) {
			$login = sanitize_text_field( wp_unslash( $_POST['user'] ?? '' ) );
			if ( ! $login ) {
				wp_send_json_error( [ 'message' => __( 'Enter an email address to invite.', 'community-business-directory' ) ] );
			}
			// Resolve to an email — accept an email directly, or an existing
			// member's username (we'll invite them at their account email).
			if ( is_email( $login ) ) {
				$email = sanitize_email( $login );
			} else {
				$u     = get_user_by( 'login', $login );
				$email = $u ? $u->user_email : '';
			}
			if ( ! $email || ! is_email( $email ) ) {
				wp_send_json_error( [ 'message' => __( 'Enter a valid email address to send an invitation.', 'community-business-directory' ) ] );
			}
			if ( strcasecmp( $email, (string) ( get_userdata( $owner )->user_email ?? '' ) ) === 0 ) {
				wp_send_json_error( [ 'message' => __( 'You already have full access as the owner.', 'community-business-directory' ) ] );
			}
			$existing = get_user_by( 'email', $email );
			if ( $existing && in_array( (int) $existing->ID, $ids, true ) ) {
				wp_send_json_error( [ 'message' => __( 'That person already has delegate access.', 'community-business-directory' ) ] );
			}
			// Invite with every capability granted by default; the owner can adjust
			// once the invite is accepted and the delegate appears in the list.
			$sent = \CBD\Frontend\DelegateInvite::invite( $business_id, $email, $caps, get_current_user_id() );
			if ( ! $sent ) {
				wp_send_json_error( [ 'message' => __( 'Could not send the invitation email — check the site mail settings.', 'community-business-directory' ) ] );
			}
			wp_send_json_success( [
				/* translators: %s: invitee email */
				'message' => sprintf( __( 'Invitation sent to %s.', 'community-business-directory' ), $email ),
				'html'    => self::render_delegate_list( $business_id ),
			] );
		}

		if ( $op === 'cancel_invite' ) {
			\CBD\Frontend\DelegateInvite::cancel( $business_id, sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
			wp_send_json_success( [
				'message' => __( 'Invitation cancelled.', 'community-business-directory' ),
				'html'    => self::render_delegate_list( $business_id ),
			] );
		}

		// ── Existing-delegate operations ────────────────────────────────────
		if ( $op === 'remove' ) {
			$uid = (int) ( $_POST['user_id'] ?? 0 );
			$ids = array_values( array_diff( $ids, [ $uid ] ) );
			unset( $perms[ $uid ] );
		} elseif ( $op === 'perms' ) {
			// Update one delegate's granted capabilities.
			$uid = (int) ( $_POST['user_id'] ?? 0 );
			if ( ! $uid || ! in_array( $uid, $ids, true ) ) {
				wp_send_json_error( [ 'message' => __( 'Unknown delegate.', 'community-business-directory' ) ] );
			}
			$granted = array_map( 'sanitize_key', (array) ( $_POST['perms'] ?? [] ) );
			$map     = [];
			foreach ( $caps as $cap ) {
				$map[ $cap ] = in_array( $cap, $granted, true ) ? 1 : 0;
			}
			$perms[ $uid ] = $map;
		} else {
			wp_send_json_error( [ 'message' => __( 'Unknown action.', 'community-business-directory' ) ] );
		}

		// Drop perm entries for anyone no longer a delegate.
		$perms = array_intersect_key( $perms, array_flip( $ids ) );

		update_post_meta( $business_id, '_cbd_delegates', $ids );
		update_post_meta( $business_id, '_cbd_delegate_perms', $perms );
		wp_send_json_success( [
			'message' => __( 'Delegate access updated.', 'community-business-directory' ),
			'html'    => self::render_delegate_list( $business_id ),
		] );
	}

	// ── Save Opening Hours ────────────────────────────────────────
	public function cbd_save_hours(): void {
		$this->verify( 'cbd_save_hours' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Not logged in.', 'community-business-directory' ) ], 401 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! \cbd_user_can( $post_id, 'edit' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'community-business-directory' ) ] );
		}

		$hours = [];
		if ( ! empty( $_POST['hours'] ) && is_array( $_POST['hours'] ) ) {
			foreach ( $_POST['hours'] as $day => $data ) {
				$hours[ sanitize_key( $day ) ] = [
					'open' => ! empty( $data['open'] ),
					'from' => sanitize_text_field( $data['from'] ?? '09:00' ),
					'to'   => sanitize_text_field( $data['to']   ?? '17:00' ),
				];
			}
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'cbd_businesses',
			[ 'opening_hours' => wp_json_encode( $hours ) ],
			[ 'post_id' => $post_id ]
		);

		wp_send_json_success( [ 'message' => __( 'Opening hours updated.', 'community-business-directory' ) ] );
	}

	/**
	 * Render the delegate list with per-delegate permission toggles (owner-only
	 * surface). Shared by the Delegate Access tab + the cbd_save_delegates AJAX
	 * response.
	 */
	public static function render_delegate_list( int $post_id ): string {
		$ids  = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, '_cbd_delegates', true ) ) );
		$caps = \cbd_delegate_capabilities();

		// Pending invitations (emailed, not yet accepted).
		$invites      = \CBD\Frontend\DelegateInvite::all( $post_id );
		$invites_html = '';
		if ( $invites ) {
			$invites_html = '<div class="cbd-delegate-invites"><h4 class="cbd-delegate-subhead">' . esc_html__( 'Pending invitations', 'community-business-directory' ) . '</h4><ul class="cbd-delegate-list">';
			foreach ( $invites as $row ) {
				$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
				if ( ! $email ) {
					continue;
				}
				$invites_html .= '<li class="cbd-delegate-row cbd-delegate-invite" data-email="' . esc_attr( $email ) . '">'
					. '<div class="cbd-delegate-head">'
					. '<span class="cbd-delegate-person">'
					. get_avatar( $email, 40, '', $email, [ 'class' => 'cbd-delegate-avatar' ] )
					. '<span class="cbd-delegate-id"><strong>' . esc_html( $email ) . '</strong><small>' . esc_html__( 'Invitation sent — awaiting acceptance', 'community-business-directory' ) . '</small></span>'
					. '</span>'
					. '<span class="cbd-delegate-invite-actions">'
					. '<button type="button" class="cbd-btn cbd-btn-sm cbd-btn-ghost cbd-invite-resend" data-email="' . esc_attr( $email ) . '">' . esc_html__( 'Resend', 'community-business-directory' ) . '</button>'
					. '<button type="button" class="cbd-btn cbd-btn-sm cbd-btn-ghost cbd-invite-cancel" data-email="' . esc_attr( $email ) . '">' . esc_html__( 'Cancel', 'community-business-directory' ) . '</button>'
					. '</span></div></li>';
			}
			$invites_html .= '</ul></div>';
		}

		if ( ! $ids ) {
			$empty = '<p class="cbd-delegate-empty">' . esc_html__( 'No delegates yet — invite a team member to let them help manage this business.', 'community-business-directory' ) . '</p>';
			return $empty . $invites_html;
		}
		$html = '<ul class="cbd-delegate-list">';
		foreach ( $ids as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			// Legacy delegate (no stored perms) → treat every capability as granted.
			$perms   = \cbd_delegate_perms( $post_id, (int) $uid );
			$has_map = ! empty( $perms );

			$html .= '<li class="cbd-delegate-row" data-user-id="' . (int) $uid . '">'
				. '<div class="cbd-delegate-head">'
				. '<span class="cbd-delegate-person">'
				. get_avatar( (int) $uid, 40, '', $u->display_name, [ 'class' => 'cbd-delegate-avatar' ] )
				. '<span class="cbd-delegate-id"><strong>' . esc_html( $u->display_name ) . '</strong><small>' . esc_html( $u->user_email ) . '</small></span>'
				. '</span>'
				. '<button type="button" class="cbd-btn cbd-btn-sm cbd-btn-ghost cbd-delegate-remove" data-user-id="' . (int) $uid . '">' . esc_html__( 'Remove', 'community-business-directory' ) . '</button>'
				. '</div>';

			$html .= '<div class="cbd-delegate-perms" data-user-id="' . (int) $uid . '">'
				. '<span class="cbd-delegate-perms-label">' . esc_html__( 'Can access:', 'community-business-directory' ) . '</span>';
			foreach ( $caps as $cap => $label ) {
				$on = $has_map ? ! empty( $perms[ $cap ] ) : true;
				$html .= '<label class="cbd-delegate-perm">'
					. '<input type="checkbox" class="cbd-delegate-perm-cb" value="' . esc_attr( $cap ) . '"' . checked( $on, true, false ) . '>'
					. '<span>' . esc_html( $label ) . '</span>'
					. '</label>';
			}
			$html .= '</div></li>';
		}
		return $html . '</ul>' . $invites_html;
	}


	/**
	 * Compose a single-line full address from a cbd_businesses table row.
	 * Skips empty parts so we never render stray commas.
	 *
	 * @param array $meta Row from {prefix}cbd_businesses (ARRAY_A).
	 */
	public static function format_address( array $meta ): string {
		$parts = array_filter( [
			$meta['address_line1']  ?? '',
			$meta['city']           ?? '',
			$meta['state_province'] ?? '',
			$meta['postal_code']    ?? '',
		], static fn( $v ) => is_string( $v ) && trim( $v ) !== '' );

		return \cbd_label( implode( ', ', $parts ) );
	}

	// ── AJAX Directory Loader ─────────────────────────────────────
	public function cbd_load_directory(): void {
		// No nonce needed — public endpoint, GET data only.
		$page     = max( 1, (int) ( $_POST['page']     ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $_POST['per_page'] ?? get_option( 'cbd_items_per_page', 20 ) ) ) );
		$keyword  = sanitize_text_field( $_POST['keyword']  ?? '' );
		$cat_slug = sanitize_text_field( $_POST['category'] ?? '' );
		$loc_slug = sanitize_text_field( $_POST['location'] ?? '' );
		$orderby  = sanitize_key( $_POST['orderby']         ?? 'newest' );

		$tax_q = [];
		if ( $cat_slug ) {
			$tax_q[] = [ 'taxonomy' => 'cbd_category', 'field' => 'slug', 'terms' => $cat_slug ];
		}
		if ( $loc_slug ) {
			$tax_q[] = [ 'taxonomy' => 'cbd_location',  'field' => 'slug', 'terms' => $loc_slug ];
		}

		$args = [
			'post_type'      => 'cbd_business',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'tax_query'      => $tax_q ?: [],
		];

		if ( $keyword ) {
			$args['s'] = $keyword;
		}

		switch ( $orderby ) {
			case 'rating':
				$args['meta_key'] = '_cbd_rating_avg';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			case 'popular':
				$args['meta_key'] = '_cbd_view_count';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			case 'alpha':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
		}

		global $wpdb;
		// Also filter by featured if requested
		if ( ! empty( $_POST['featured_only'] ) ) {
			$args['meta_query'][] = [ 'key' => '_cbd_is_featured', 'value' => '1' ];
		}

		$query   = new \WP_Query( $args );
		$user_id = get_current_user_id();
		$cards   = [];

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
			$meta    = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d", $post_id
			), ARRAY_A ) ?? [];
			$cats    = get_the_terms( $post_id, 'cbd_category' );
			$cat_name = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

			$is_fav = $user_id ? $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cbd_favorites WHERE user_id=%d AND business_id=%d", $user_id, $post_id
			) ) : false;
			$is_fol = $user_id ? $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cbd_follows WHERE user_id=%d AND business_id=%d", $user_id, $post_id
			) ) : false;

			// Full address — composed from the custom table, empty parts skipped.
			$address = self::format_address( $meta );

			// Decode entities here: the JS escapes again on render, so passing
			// the raw text avoids the double-encoding that turned "&" into "&#038;".
			$cards[] = [
				'id'          => $post_id,
				'title'       => \cbd_label( get_the_title() ),
				'url'         => get_permalink(),
				'excerpt'     => \cbd_label( wp_trim_words( get_the_excerpt(), 18 ) ),
				'thumb'       => get_the_post_thumbnail_url( $post_id, 'medium' ) ?: '',
				'category'    => \cbd_label( $cat_name ),
				'city'        => $meta['city']         ?? '',
				'address'     => $address,
				'rating_avg'  => (float) ( $meta['rating_avg']  ?? 0 ),
				'review_count'=> (int)   ( $meta['review_count']?? 0 ),
				'is_featured' => (bool)  ( $meta['is_featured'] ?? false ),
				'is_verified' => (bool)  ( $meta['is_verified'] ?? false ),
				'is_fav'      => (bool) $is_fav,
				'is_fol'      => (bool) $is_fol,
				'logged_in'   => (bool) $user_id,
			];
		}
		wp_reset_postdata();

		wp_send_json_success( [
			'cards'       => $cards,
			'total'       => $query->found_posts,
			'pages'       => $query->max_num_pages,
			'page'        => $page,
			'keyword'     => $keyword,
			'category'    => $cat_slug,
		] );
	}

	// ── Reactions ─────────────────────────────────────────────────
	public function cbd_react(): void {
		$this->verify( 'cbd_react' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Please log in to react.', 'community-business-directory' ) ], 401 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$type    = sanitize_key( $_POST['reaction'] ?? '' );

		if ( ! $post_id || ! get_post( $post_id ) || ! \CBD\Frontend\Reactions::is_valid( $type ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid reaction.', 'community-business-directory' ) ] );
		}

		$mine   = \CBD\Frontend\Reactions::toggle( $post_id, get_current_user_id(), $type );
		$counts = \CBD\Frontend\Reactions::counts( $post_id );

		wp_send_json_success( [
			'reaction' => $mine,
			'counts'   => $counts,
			'total'    => array_sum( $counts ),
		] );
	}

	public function cbd_get_reactions(): void {
		// Public — anyone can see who reacted.
		$post_id = (int) ( $_REQUEST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing post.', 'community-business-directory' ) ] );
		}
		wp_send_json_success( [
			'reactors' => \CBD\Frontend\Reactions::reactors( $post_id ),
			'counts'   => \CBD\Frontend\Reactions::counts( $post_id ),
		] );
	}

	// ── Login (email + password) ──────────────────────────────────
	public function cbd_login(): void {
		$this->verify( 'cbd_login' );

		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$password = (string) ( $_POST['password'] ?? '' );
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) );

		if ( ! $email || ! $password ) {
			wp_send_json_error( [ 'message' => __( 'Email and password are required.', 'community-business-directory' ) ], 400 );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			wp_send_json_error( [ 'message' => __( 'Invalid email or password.', 'community-business-directory' ) ], 401 );
		}

		// Block sign-in for accounts that have not confirmed their email yet.
		// `code` lets the UI offer a one-click "resend verification" link.
		if ( \CBD\Frontend\EmailVerification::is_pending( (int) $user->ID ) ) {
			wp_send_json_error( [
				'message' => __( 'Please confirm your email address first. Check your inbox for the activation link.', 'community-business-directory' ),
				'code'    => 'unverified',
				'email'   => $email,
			], 403 );
		}

		$signed_in = wp_signon( [
			'user_login'    => $user->user_login,
			'user_password' => $password,
			'remember'      => true,
		], is_ssl() );

		if ( is_wp_error( $signed_in ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid email or password.', 'community-business-directory' ) ], 401 );
		}

		wp_send_json_success( [
			'redirect' => $redirect ?: $this->login_redirect_for( $signed_in ),
		] );
	}

	// ── Sign up (create account + send verification email) ────────
	public function cbd_signup(): void {
		$this->verify( 'cbd_signup' );

		$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$pass    = (string) ( $_POST['password'] ?? '' );
		$confirm = (string) ( $_POST['password_confirm'] ?? '' );

		if ( '' === $first || '' === $email || '' === $pass ) {
			wp_send_json_error( [ 'message' => __( 'Please fill in your name, email and password.', 'community-business-directory' ) ], 400 );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'community-business-directory' ) ], 400 );
		}
		if ( strlen( $pass ) < 8 ) {
			wp_send_json_error( [ 'message' => __( 'Your password must be at least 8 characters.', 'community-business-directory' ) ], 400 );
		}
		if ( $pass !== $confirm ) {
			wp_send_json_error( [ 'message' => __( 'The two passwords do not match.', 'community-business-directory' ) ], 400 );
		}
		if ( email_exists( $email ) ) {
			$existing = get_user_by( 'email', $email );
			// An account that was created but never verified (e.g. a previous
			// attempt where the activation email was missed) shouldn't dead-end on
			// "already exists" — resend a fresh activation link so they can finish.
			if ( $existing && EmailVerification::is_pending( (int) $existing->ID ) ) {
				$sent = ( new EmailVerification() )->send_verification_email( (int) $existing->ID );
				wp_send_json_success( [
					'message' => $sent
						? __( 'That email is already registered but not yet verified — we\'ve sent a fresh activation link. Please check your inbox (and spam folder).', 'community-business-directory' )
						: __( 'That email is already registered but not yet verified. We could not send the activation email — please contact the site owner.', 'community-business-directory' ),
				] );
			}
			wp_send_json_error( [ 'message' => __( 'An account with that email already exists. Try signing in instead.', 'community-business-directory' ) ], 409 );
		}

		$display = trim( $first . ' ' . $last );
		$user_id = wp_insert_user( [
			'user_login'   => $this->unique_login_from_email( $email ),
			'user_email'   => $email,
			'user_pass'    => $pass,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $display ?: $first,
			// Plain member until a business listing is approved (see cbd_promote_business_owner()).
			'role'         => 'subscriber',
		] );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( [ 'message' => $user_id->get_error_message() ], 500 );
		}

		// Mark pending + email the activation link. Do NOT sign the user in.
		$sent = ( new EmailVerification() )->send_verification_email( (int) $user_id );

		do_action( 'cbd_account_registered', (int) $user_id );

		wp_send_json_success( [
			'message' => $sent
				? __( 'Account created! Check your email for a link to activate it (it may be in your spam folder).', 'community-business-directory' )
				: __( 'Account created, but we could not send the activation email. Use "Resend verification" or contact the site owner.', 'community-business-directory' ),
		] );
	}

	// ── Resend the verification email ─────────────────────────────
	public function cbd_resend_verification(): void {
		$this->verify( 'cbd_resend_verification' );

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		// Only resend for a genuinely pending account, but always return the same
		// generic success so the endpoint can't be used to probe which emails exist.
		if ( $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user && EmailVerification::is_pending( (int) $user->ID ) ) {
				( new EmailVerification() )->send_verification_email( (int) $user->ID );
			}
		}

		wp_send_json_success( [
			'message' => __( 'If that account needs verifying, a fresh link is on its way.', 'community-business-directory' ),
		] );
	}

	// ── Forgot password (email a reset link) ──────────────────────
	public function cbd_forgot_password(): void {
		$this->verify( 'cbd_forgot_password' );

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		// Only act for a real account, but always return the same generic success
		// so the endpoint can't be used to probe which emails are registered.
		if ( $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$reset_url = \cbd_set_password_url( $user );
				if ( $reset_url ) {
					EmailVerification::send(
						$user->user_email,
						sprintf( /* translators: %s: site name */ __( '[%s] Reset your password', 'community-business-directory' ), get_bloginfo( 'name' ) ),
						$this->password_reset_html( $user, $reset_url ),
						$this->password_reset_text( $user, $reset_url )
					);
				}
			}
		}

		wp_send_json_success( [
			'message' => __( 'If an account exists for that email, a password reset link is on its way. Please check your inbox (and spam folder).', 'community-business-directory' ),
		] );
	}

	/** Branded HTML body for the password-reset email. */
	private function password_reset_html( \WP_User $user, string $url ): string {
		$site   = get_bloginfo( 'name' );
		$name   = $user->first_name ?: ( $user->display_name ?: $user->user_login );
		$logo   = EmailVerification::logo_url();
		$brand  = trim( (string) get_option( 'cbd_email_header_color', '' ) ) ?: '#0e2436';
		$header = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '" style="max-width:200px;max-height:72px;height:auto;width:auto;border:0;display:inline-block;">'
			: '<span style="font-size:22px;font-weight:700;color:#fff;">' . esc_html( $site ) . '</span>';

		ob_start(); ?>
<div style="background:#f3f4f6;padding:24px 0;">
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;color:#181c32;">
	<div style="text-align:center;background:<?php echo esc_attr( $brand ); ?>;border-radius:10px;padding:22px 16px;margin:0 0 24px;"><?php echo $header; // phpcs:ignore WordPress.Security.EscapeOutput — escaped parts ?></div>
	<h2 style="margin:0 0 16px;font-size:22px;color:#181c32;"><?php echo esc_html( sprintf( __( 'Hi %s,', 'community-business-directory' ), $name ) ); ?></h2>
	<p style="font-size:15px;line-height:1.6;color:#374151;margin:0 0 24px;"><?php echo esc_html( sprintf( __( 'We received a request to reset the password for your %s account. Click the button below to choose a new one.', 'community-business-directory' ), $site ) ); ?></p>
	<p style="text-align:center;margin:0 0 24px;">
		<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#29ABE1;color:#fff;text-decoration:none;font-weight:600;font-size:15px;padding:12px 28px;border-radius:8px;"><?php esc_html_e( 'Reset my password', 'community-business-directory' ); ?></a>
	</p>
	<p style="font-size:13px;line-height:1.6;color:#6b7280;margin:0 0 8px;"><?php esc_html_e( 'Or paste this link into your browser:', 'community-business-directory' ); ?></p>
	<p style="font-size:13px;line-height:1.5;word-break:break-all;margin:0 0 24px;"><a href="<?php echo esc_url( $url ); ?>" style="color:#29ABE1;"><?php echo esc_html( $url ); ?></a></p>
	<p style="font-size:12px;line-height:1.6;color:#9ca3af;margin:0;border-top:1px solid #e5e7eb;padding-top:16px;"><?php esc_html_e( 'If you did not request this, you can safely ignore this email — your password will not change.', 'community-business-directory' ); ?></p>
</div>
</div>
<?php
		return (string) ob_get_clean();
	}

	/** Plain-text alternative for the password-reset email. */
	private function password_reset_text( \WP_User $user, string $url ): string {
		$site = get_bloginfo( 'name' );
		$name = $user->first_name ?: ( $user->display_name ?: $user->user_login );
		return sprintf(
			/* translators: 1: name, 2: site, 3: reset URL */
			__( "Hi %1\$s,\n\nWe received a request to reset the password for your %2\$s account. Open the link below to choose a new one:\n\n%3\$s\n\nIf you did not request this, you can safely ignore this email — your password will not change.\n\n— %2\$s", 'community-business-directory' ),
			$name,
			$site,
			$url
		);
	}

	// ── Set / reset password (branded [cbd_set_password] page) ────
	public function cbd_set_password(): void {
		$this->verify( 'cbd_set_password' );

		$key     = sanitize_text_field( wp_unslash( $_POST['key']   ?? '' ) );
		$login   = sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) );
		$pass    = (string) ( $_POST['password']         ?? '' );
		$confirm = (string) ( $_POST['password_confirm'] ?? '' );

		if ( strlen( $pass ) < 8 ) {
			wp_send_json_error( [ 'message' => __( 'Your password must be at least 8 characters.', 'community-business-directory' ) ] );
		}
		if ( $pass !== $confirm ) {
			wp_send_json_error( [ 'message' => __( 'The two passwords do not match.', 'community-business-directory' ) ] );
		}

		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			wp_send_json_error( [ 'message' => __( 'This link is invalid or has expired. Please request a new one.', 'community-business-directory' ) ] );
		}

		reset_password( $user, $pass );

		// Setting the password via an emailed link also proves email ownership —
		// clear any pending-verification flag so they aren't blocked from signing in.
		if ( EmailVerification::is_pending( (int) $user->ID ) ) {
			delete_user_meta( $user->ID, EmailVerification::META_PENDING );
			delete_user_meta( $user->ID, EmailVerification::META_KEY );
			delete_user_meta( $user->ID, EmailVerification::META_REQUESTED );
		}

		// Sign them straight in.
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );

		wp_send_json_success( [
			'message'  => __( 'Password saved — signing you in…', 'community-business-directory' ),
			'redirect' => \cbd_login_redirect( $user ),
		] );
	}

	// ── Social login (Google ID-token / Facebook access-token) ────
	public function cbd_social_login(): void {
		$this->verify( 'cbd_social_login' );

		$provider = sanitize_key( $_POST['provider'] ?? '' );
		$token    = (string) ( $_POST['token'] ?? '' );
		$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ?? '' ) );

		if ( ! $token || ! in_array( $provider, [ 'google', 'facebook' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid social sign-in request.', 'community-business-directory' ) ], 400 );
		}

		$profile = 'google' === $provider
			? $this->verify_google_token( $token )
			: $this->verify_facebook_token( $token );

		if ( is_wp_error( $profile ) ) {
			wp_send_json_error( [ 'message' => $profile->get_error_message() ], 401 );
		}

		$email = sanitize_email( $profile['email'] );
		if ( ! $email ) {
			wp_send_json_error( [ 'message' => __( 'Social provider did not return an email address.', 'community-business-directory' ) ], 401 );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			$user_id = wp_insert_user( [
				'user_login'   => $this->unique_login_from_email( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $profile['name'] ?: $email,
				'first_name'   => $profile['given_name'] ?? '',
				'last_name'    => $profile['family_name'] ?? '',
				// Plain member until a business listing is approved (see cbd_promote_business_owner()).
				'role'         => 'subscriber',
			] );
			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( [ 'message' => $user_id->get_error_message() ], 500 );
			}
			update_user_meta( $user_id, 'cbd_social_provider', $provider );
			$user = get_user_by( 'id', $user_id );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );

		wp_send_json_success( [
			'redirect' => $redirect ?: $this->login_redirect_for( $user ),
		] );
	}

	private function login_redirect_for( \WP_User $user ): string {
		return \cbd_login_redirect( $user );
	}

	private function unique_login_from_email( string $email ): string {
		$base  = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'user';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		return $login;
	}

	/**
	 * Verify a Google ID token by hitting the public tokeninfo endpoint.
	 * Returns ['email','name','given_name','family_name'] or WP_Error.
	 */
	private function verify_google_token( string $id_token ) {
		$client_id = trim( (string) get_option( 'cbd_google_client_id', '' ) );
		if ( ! $client_id ) {
			return new \WP_Error( 'cbd_no_google', __( 'Google sign-in is not configured.', 'community-business-directory' ) );
		}

		$res = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $id_token ), [ 'timeout' => 8 ] );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'cbd_google_verify', __( 'Could not verify Google sign-in.', 'community-business-directory' ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['email'] ) ) {
			return new \WP_Error( 'cbd_google_verify', __( 'Google did not return a profile.', 'community-business-directory' ) );
		}
		if ( ( $body['aud'] ?? '' ) !== $client_id ) {
			return new \WP_Error( 'cbd_google_aud', __( 'Google token was issued to a different app.', 'community-business-directory' ) );
		}
		if ( empty( $body['email_verified'] ) || 'true' !== (string) $body['email_verified'] ) {
			return new \WP_Error( 'cbd_google_unverified', __( 'Google email is not verified.', 'community-business-directory' ) );
		}

		return [
			'email'       => (string) $body['email'],
			'name'        => (string) ( $body['name'] ?? '' ),
			'given_name'  => (string) ( $body['given_name']  ?? '' ),
			'family_name' => (string) ( $body['family_name'] ?? '' ),
		];
	}

	/**
	 * Verify a Facebook access token. Cheap path: ask Graph for the user's
	 * own profile with the token; if Graph accepts it, the user authenticated.
	 * If `cbd_facebook_app_secret` is set we additionally call /debug_token
	 * so we can also confirm the token was issued to *our* app.
	 */
	private function verify_facebook_token( string $access_token ) {
		$app_id = trim( (string) get_option( 'cbd_facebook_app_id', '' ) );
		if ( ! $app_id ) {
			return new \WP_Error( 'cbd_no_fb', __( 'Facebook sign-in is not configured.', 'community-business-directory' ) );
		}

		$secret = trim( (string) get_option( 'cbd_facebook_app_secret', '' ) );
		if ( $secret ) {
			$debug = wp_remote_get(
				'https://graph.facebook.com/debug_token?input_token=' . rawurlencode( $access_token )
				. '&access_token=' . rawurlencode( $app_id . '|' . $secret ),
				[ 'timeout' => 8 ]
			);
			if ( is_wp_error( $debug ) || 200 !== (int) wp_remote_retrieve_response_code( $debug ) ) {
				return new \WP_Error( 'cbd_fb_verify', __( 'Could not verify Facebook sign-in.', 'community-business-directory' ) );
			}
			$dbody = json_decode( wp_remote_retrieve_body( $debug ), true );
			$data  = $dbody['data'] ?? [];
			if ( empty( $data['is_valid'] ) || ( $data['app_id'] ?? '' ) !== $app_id ) {
				return new \WP_Error( 'cbd_fb_app', __( 'Facebook token is not valid for this app.', 'community-business-directory' ) );
			}
		}

		$me = wp_remote_get(
			'https://graph.facebook.com/me?fields=id,name,first_name,last_name,email&access_token=' . rawurlencode( $access_token ),
			[ 'timeout' => 8 ]
		);
		if ( is_wp_error( $me ) || 200 !== (int) wp_remote_retrieve_response_code( $me ) ) {
			return new \WP_Error( 'cbd_fb_me', __( 'Could not load your Facebook profile.', 'community-business-directory' ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $me ), true );
		if ( ! is_array( $body ) || empty( $body['email'] ) ) {
			return new \WP_Error( 'cbd_fb_email', __( 'Facebook did not return an email — grant the email permission and try again.', 'community-business-directory' ) );
		}

		return [
			'email'       => (string) $body['email'],
			'name'        => (string) ( $body['name'] ?? '' ),
			'given_name'  => (string) ( $body['first_name'] ?? '' ),
			'family_name' => (string) ( $body['last_name']  ?? '' ),
		];
	}

}
