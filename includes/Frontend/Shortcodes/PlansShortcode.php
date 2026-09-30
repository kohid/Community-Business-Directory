<?php
/**
 * Plans Shortcode — [cbd_plans]
 * Displays membership plan comparison table.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

class PlansShortcode {

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'highlight' => 'premium',
			'show_annual' => 'true',
		], $atts );

		global $wpdb;
		$plans = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}cbd_membership_plans WHERE is_active = 1 ORDER BY sort_order ASC"
		);

		if ( ! $plans ) {
			return '<div class="cbd-wrap"><p>' . esc_html__( 'No plans available.', 'community-business-directory' ) . '</p></div>';
		}

		$sym      = get_option( 'cbd_currency_symbol', '£' );
		$user_id  = get_current_user_id();
		$register_url = home_url( '/' );

		// Get current user plan if logged in
		$current_plan = '';
		if ( $user_id ) {
			global $wpdb;
			$biz = $wpdb->get_row( $wpdb->prepare(
				"SELECT plan FROM {$wpdb->prefix}cbd_businesses WHERE owner_id = %d LIMIT 1",
				$user_id
			) );
			$current_plan = $biz ? $biz->plan : '';
		}

		ob_start(); ?>
<div class="cbd-wrap cbd-plans">
	<div class="cbd-plans-header">
		<h2 class="cbd-plans-title"><?php esc_html_e( 'Membership Plans', 'community-business-directory' ); ?></h2>
		<p class="cbd-plans-sub"><?php esc_html_e( 'Choose the plan that fits your business', 'community-business-directory' ); ?></p>
		<?php if ( $atts['show_annual'] === 'true' ) : ?>
		<div class="cbd-plans-billing-toggle">
			<span class="cbd-billing-label active" id="cbd-billing-monthly"><?php esc_html_e( 'Monthly', 'community-business-directory' ); ?></span>
			<label class="cbd-toggle-switch">
				<input type="checkbox" id="cbd-billing-toggle">
				<span class="cbd-toggle-slider"></span>
			</label>
			<span class="cbd-billing-label" id="cbd-billing-annual"><?php esc_html_e( 'Annual', 'community-business-directory' ); ?> <span class="cbd-save-badge"><?php esc_html_e( 'Save 25%', 'community-business-directory' ); ?></span></span>
		</div>
		<?php endif; ?>
	</div>

	<div class="cbd-plans-grid">
		<?php foreach ( $plans as $plan ) :
			$features    = ! empty( $plan->features ) ? json_decode( $plan->features, true ) : [];
			$is_current  = $current_plan === $plan->slug;
			$is_highlight= $plan->slug === $atts['highlight'];
			$monthly     = (float) $plan->price_monthly;
			$annual_mo   = $plan->price_annual > 0 ? round( (float) $plan->price_annual / 12, 2 ) : 0;
		?>
		<div class="cbd-plan-card <?php echo $is_highlight ? 'is-featured' : ''; ?> <?php echo $is_current ? 'is-current' : ''; ?>">
			<?php if ( $is_highlight ) : ?>
			<div class="cbd-plan-badge"><?php esc_html_e( 'Most Popular', 'community-business-directory' ); ?></div>
			<?php endif; ?>
			<?php if ( $is_current ) : ?>
			<div class="cbd-plan-badge cbd-plan-badge-current"><?php esc_html_e( 'Current Plan', 'community-business-directory' ); ?></div>
			<?php endif; ?>

			<div class="cbd-plan-header">
				<h3 class="cbd-plan-name"><?php echo esc_html( $plan->name ); ?></h3>
				<div class="cbd-plan-price">
					<?php if ( $monthly > 0 ) : ?>
					<span class="cbd-price-monthly" <?php echo $atts['show_annual'] === 'true' ? 'style="display:block;"' : ''; ?>>
						<span class="cbd-price-amount"><?php echo esc_html( $sym . number_format( $monthly, 2 ) ); ?></span>
						<span class="cbd-price-period"><?php esc_html_e( '/month', 'community-business-directory' ); ?></span>
					</span>
					<?php if ( $atts['show_annual'] === 'true' && $annual_mo > 0 ) : ?>
					<span class="cbd-price-annual" style="display:none;">
						<span class="cbd-price-amount"><?php echo esc_html( $sym . number_format( $annual_mo, 2 ) ); ?></span>
						<span class="cbd-price-period"><?php esc_html_e( '/month', 'community-business-directory' ); ?></span>
						<small style="display:block;color:var(--cbd-ink-muted);font-size:12px;"><?php printf( esc_html__( 'Billed %s/year', 'community-business-directory' ), $sym . number_format( (float) $plan->price_annual, 2 ) ); ?></small>
					</span>
					<?php endif; ?>
					<?php else : ?>
					<span class="cbd-price-amount"><?php esc_html_e( 'Free', 'community-business-directory' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="cbd-plan-desc"><?php echo esc_html( $plan->description ); ?></p>
			</div>

			<ul class="cbd-plan-features">
				<?php if ( $features ) : ?>
				<?php foreach ( $features as $feature ) : ?>
				<li><span class="cbd-feature-check">✓</span> <?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
				<?php endif; ?>
				<?php if ( (int) $plan->image_limit > 0 ) : ?>
				<li><span class="cbd-feature-check">✓</span> <?php printf( esc_html__( 'Up to %d photos', 'community-business-directory' ), (int) $plan->image_limit ); ?></li>
				<?php elseif ( $plan->image_limit == 0 ) : ?>
				<li><span class="cbd-feature-check">✓</span> <?php esc_html_e( 'Unlimited photos', 'community-business-directory' ); ?></li>
				<?php endif; ?>
			</ul>

			<div class="cbd-plan-cta">
				<?php if ( $is_current ) : ?>
				<button class="cbd-btn cbd-btn-outline cbd-btn-full" disabled><?php esc_html_e( 'Current Plan', 'community-business-directory' ); ?></button>
				<?php elseif ( $monthly == 0 ) : ?>
				<a href="<?php echo esc_url( $register_url ); ?>" class="cbd-btn cbd-btn-outline cbd-btn-full">
					<?php esc_html_e( 'Get Started Free', 'community-business-directory' ); ?>
				</a>
				<?php else : ?>
				<button class="cbd-btn <?php echo $is_highlight ? 'cbd-btn-primary' : 'cbd-btn-outline'; ?> cbd-btn-full cbd-upgrade-btn"
						data-plan="<?php echo esc_attr( $plan->slug ); ?>"
						data-price-monthly="<?php echo esc_attr( $plan->price_monthly ); ?>"
						data-price-annual="<?php echo esc_attr( $plan->price_annual ); ?>"
						data-stripe-id="<?php echo esc_attr( $plan->stripe_price_id ); ?>">
					<?php echo $current_plan && $current_plan !== 'free' ? esc_html__( 'Switch Plan', 'community-business-directory' ) : esc_html__( 'Get Started', 'community-business-directory' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<p class="cbd-plans-note"><?php esc_html_e( 'All plans include access to the business dashboard, event creation, and customer reviews. Cancel anytime.', 'community-business-directory' ); ?></p>
</div>
<?php
		return ob_get_clean();
	}
}
