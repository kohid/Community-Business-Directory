<?php
/**
 * Email Notifications — hooks into plugin actions and sends emails.
 *
 * @package CBD\Modules
 */

namespace CBD\Modules;

defined( 'ABSPATH' ) || exit;

class Notifications {

	public function register(): void {
		add_action( 'cbd_business_submitted',  [ $this, 'notify_admin_new_business' ] );
		add_action( 'cbd_business_approved',   [ $this, 'notify_owner_approved'     ] );
		add_action( 'cbd_business_rejected',   [ $this, 'notify_owner_rejected'     ] );
		add_action( 'cbd_review_pending',      [ $this, 'notify_owner_new_review'   ] );
		add_action( 'cbd_review_approved',     [ $this, 'notify_reviewer_approved'  ] );
	}

	// ── Admin notified of new business ────────────────────────────

	public function notify_admin_new_business( int $post_id ): void {
		$admin_email = get_option( 'cbd_admin_email', get_option( 'admin_email' ) );
		$biz_name    = get_the_title( $post_id );
		$review_url  = admin_url( 'admin.php?page=cbd-businesses&filter=pending' );

		$subject = sprintf(
			__( '[%s] New Business Submitted: %s', 'community-business-directory' ),
			get_bloginfo( 'name' ),
			$biz_name
		);

		$message = sprintf(
			__( "Hello,\n\nA new business listing has been submitted and is waiting for your approval.\n\nBusiness: %s\nView listing: %s\n\nReview in admin: %s\n\n---\n%s", 'community-business-directory' ),
			$biz_name,
			get_permalink( $post_id ),
			$review_url,
			get_bloginfo( 'name' )
		);

		$this->send( $admin_email, $subject, $message );
	}

	// ── Business owner notified of approval ───────────────────────

	public function notify_owner_approved( int $post_id ): void {
		global $wpdb;
		$biz = $wpdb->get_row( $wpdb->prepare(
			"SELECT owner_id FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d",
			$post_id
		) );
		if ( ! $biz ) return;

		$owner = get_userdata( (int) $biz->owner_id );
		if ( ! $owner ) return;

		$biz_name = get_the_title( $post_id );
		$subject  = sprintf(
			__( '[%s] Your business listing is live!', 'community-business-directory' ),
			get_bloginfo( 'name' )
		);
		$message  = sprintf(
			__( "Hi %s,\n\nGreat news! Your business listing \"%s\" has been approved and is now live in our directory.\n\nView your listing: %s\n\nManage your business: %s\n\n---\n%s", 'community-business-directory' ),
			$owner->display_name,
			$biz_name,
			get_permalink( $post_id ),
			home_url( '/' ),
			get_bloginfo( 'name' )
		);

		$this->send( $owner->user_email, $subject, $message );
	}

	// ── Business owner notified of rejection ──────────────────────

	public function notify_owner_rejected( int $post_id ): void {
		global $wpdb;
		$biz = $wpdb->get_row( $wpdb->prepare(
			"SELECT owner_id FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d",
			$post_id
		) );
		if ( ! $biz ) return;

		$owner = get_userdata( (int) $biz->owner_id );
		if ( ! $owner ) return;

		$biz_name = get_the_title( $post_id );
		$subject  = sprintf( __( '[%s] Business listing update', 'community-business-directory' ), get_bloginfo( 'name' ) );
		$message  = sprintf(
			__( "Hi %s,\n\nUnfortunately your business listing \"%s\" could not be approved at this time.\n\nPlease contact us if you have any questions.\n\n---\n%s", 'community-business-directory' ),
			$owner->display_name,
			$biz_name,
			get_bloginfo( 'name' )
		);

		$this->send( $owner->user_email, $subject, $message );
	}

	// ── Business owner notified of new review ─────────────────────

	public function notify_owner_new_review( int $business_id ): void {
		global $wpdb;
		$biz = $wpdb->get_row( $wpdb->prepare(
			"SELECT owner_id FROM {$wpdb->prefix}cbd_businesses WHERE post_id = %d",
			$business_id
		) );
		if ( ! $biz ) return;

		$owner = get_userdata( (int) $biz->owner_id );
		if ( ! $owner ) return;

		$subject = sprintf( __( '[%s] New review on your business', 'community-business-directory' ), get_bloginfo( 'name' ) );
		$message = sprintf(
			__( "Hi %s,\n\nA new review has been submitted for \"%s\" and is pending moderation.\n\nView your listing: %s\n\n---\n%s", 'community-business-directory' ),
			$owner->display_name,
			get_the_title( $business_id ),
			get_permalink( $business_id ),
			get_bloginfo( 'name' )
		);

		$this->send( $owner->user_email, $subject, $message );
	}

	// ── Reviewer notified when review approved ────────────────────

	public function notify_reviewer_approved( object $review ): void {
		if ( empty( $review->author_email ) ) return;

		$subject = sprintf( __( '[%s] Your review has been published', 'community-business-directory' ), get_bloginfo( 'name' ) );
		$message = sprintf(
			__( "Hi %s,\n\nYour review for \"%s\" has been approved and is now live.\n\nView it here: %s\n\n---\n%s", 'community-business-directory' ),
			$review->author_name ?: __( 'there', 'community-business-directory' ),
			get_the_title( $review->business_id ),
			get_permalink( $review->business_id ),
			get_bloginfo( 'name' )
		);

		$this->send( $review->author_email, $subject, $message );
	}

	// ── Helper ────────────────────────────────────────────────────

	private function send( string $to, string $subject, string $message ): bool {
		// Use the plugin's configurable From / Reply-To (Settings → Email) so all
		// outbound mail shares one sender identity.
		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', \CBD\Frontend\EmailVerification::from_name(), \CBD\Frontend\EmailVerification::from_address() ),
		];
		$reply = \CBD\Frontend\EmailVerification::reply_to();
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		return wp_mail( $to, $subject, $message, $headers );
	}
}
