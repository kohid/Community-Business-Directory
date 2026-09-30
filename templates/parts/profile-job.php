<?php
/**
 * Job detail body — shared by single-cbd_job.php and the [cbd_job_profile]
 * shortcode (via cbd_profile_part('job')). Renders the CURRENT post; job fields
 * come from post meta via CBD\PostTypes\Job. Edit here, not the wrapper.
 */

defined( 'ABSPATH' ) || exit;

$post_id  = (int) get_the_ID();
$m        = \CBD\PostTypes\Job::get_meta( $post_id );
$title    = get_the_title();
$company  = $m['company'] ?: get_bloginfo( 'name' );
$apply    = \CBD\PostTypes\Job::apply_link( $m, $title );
$expired  = \CBD\PostTypes\Job::is_expired( $m );
$jobs_url = function_exists( 'cbd_listing_page_url' )
	? cbd_listing_page_url( 'cbd_jobs_page_id', '/jobs/' )
	: home_url( '/jobs/' );
?>
<div class="cbd-scope cbd-profile-wrap cbd-job-detail">

	<div class="cbd-profile-header-wrap">
		<div class="cbd-profile-header">
			<div class="cbd-job-hero-logo" aria-hidden="true">
				<?php
				if ( has_post_thumbnail( $post_id ) ) {
					the_post_thumbnail( 'thumbnail' );
				} else {
					$mono = trim( preg_replace( '/[^A-Za-z0-9 ]/', '', $company ) );
					$mono = $mono !== '' ? strtoupper( mb_substr( $mono, 0, 1 ) ) : '#';
					echo '<span>' . esc_html( $mono ) . '</span>';
				}
				?>
			</div>
			<div class="cbd-profile-headline">
				<h1 class="cbd-profile-name"><?php echo esc_html( \cbd_label( $title ) ); ?></h1>
				<p class="cbd-profile-cats">
					<strong><?php echo esc_html( \cbd_label( $company ) ); ?></strong>
					<?php if ( $m['location'] ) echo ' · ' . esc_html( $m['location'] ); ?>
				</p>
				<div class="cbd-job-tags" style="margin-top:10px;">
					<?php if ( $m['type'] && \CBD\PostTypes\Job::type_label( $m['type'] ) ) : ?><span class="cbd-job-tag cbd-job-tag-type"><?php echo esc_html( \CBD\PostTypes\Job::type_label( $m['type'] ) ); ?></span><?php endif; ?>
					<?php if ( $m['workplace'] && \CBD\PostTypes\Job::workplace_label( $m['workplace'] ) ) : ?><span class="cbd-job-tag cbd-job-tag-wp"><?php echo esc_html( \CBD\PostTypes\Job::workplace_label( $m['workplace'] ) ); ?></span><?php endif; ?>
					<?php if ( $m['salary'] ) : ?><span class="cbd-job-tag cbd-job-tag-salary">💷 <?php echo esc_html( $m['salary'] ); ?></span><?php endif; ?>
					<?php if ( $expired ) : ?><span class="cbd-job-tag cbd-job-tag-expired"><?php esc_html_e( 'Closed', 'community-business-directory' ); ?></span><?php endif; ?>
				</div>
			</div>
			<div class="cbd-profile-actions">
				<?php if ( ! $expired && $apply ) : ?>
					<a href="<?php echo esc_url( $apply ); ?>" class="cbd-btn cbd-btn-primary"<?php echo ! empty( $m['apply_url'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php esc_html_e( 'Apply Now', 'community-business-directory' ); ?></a>
				<?php elseif ( $expired ) : ?>
					<span class="cbd-btn cbd-btn-outline is-disabled" aria-disabled="true"><?php esc_html_e( 'Applications closed', 'community-business-directory' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( $jobs_url ); ?>" class="cbd-btn cbd-btn-outline"><?php echo \cbd_icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?> <?php esc_html_e( 'All Jobs', 'community-business-directory' ); ?></a>
			</div>
		</div>
	</div>

	<div class="cbd-profile-body">
		<div class="cbd-profile-main">
			<div class="cbd-profile-section">
				<h2><?php esc_html_e( 'Job Description', 'community-business-directory' ); ?></h2>
				<div class="cbd-profile-description"><?php the_content(); ?></div>
			</div>
		</div>
		<aside class="cbd-profile-sidebar">
			<div class="cbd-sidebar-card">
				<h4><?php esc_html_e( 'Overview', 'community-business-directory' ); ?></h4>
				<?php if ( $m['company'] ) echo '<p>🏢 ' . esc_html( $m['company'] ) . '</p>'; ?>
				<?php if ( $m['location'] ) echo '<p> ' . \cbd_icon( 'map-marker' ) . ' ' . esc_html( $m['location'] ) . '</p>'; ?>
				<?php if ( $m['type'] && \CBD\PostTypes\Job::type_label( $m['type'] ) ) echo '<p> ' . \cbd_icon( 'clock-outline' ) . ' ' . esc_html( \CBD\PostTypes\Job::type_label( $m['type'] ) ) . '</p>'; ?>
				<?php if ( $m['workplace'] && \CBD\PostTypes\Job::workplace_label( $m['workplace'] ) ) echo '<p>🏠 ' . esc_html( \CBD\PostTypes\Job::workplace_label( $m['workplace'] ) ) . '</p>'; ?>
				<?php if ( $m['salary'] ) echo '<p>💷 ' . esc_html( $m['salary'] ) . '</p>'; ?>
				<?php
				if ( $m['closing'] ) {
					$closes = date_i18n( get_option( 'date_format' ), strtotime( $m['closing'] ) );
					echo '<p> ' . \cbd_icon( 'calendar-blank-outline' ) . ' ' . esc_html( ( $expired ? __( 'Closed:', 'community-business-directory' ) : __( 'Closing date:', 'community-business-directory' ) ) . ' ' . $closes ) . '</p>';
				}
				?>
				<?php if ( ! $expired && $apply ) : ?>
					<a href="<?php echo esc_url( $apply ); ?>" class="cbd-btn cbd-btn-primary" style="margin-top:12px;width:100%;justify-content:center;"<?php echo ! empty( $m['apply_url'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php esc_html_e( 'Apply Now', 'community-business-directory' ); ?></a>
				<?php endif; ?>
			</div>
		</aside>
	</div>
</div>
