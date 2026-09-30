<?php

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

/**
 * [cbd_gallery] — a full-height, site-wide photo wall.
 *
 * Aggregates the gallery photos of every published business (image attachments
 * parented to a cbd_business, excluding each listing's logo/cover) into a bento
 * "collage" that fills the screen height and slides right-to-left forever as one
 * block. Photos are grouped into repeating 7-tile bento units (a 2×2 hero +
 * wide + small tiles on a 4×3 grid) so the wall interlocks like a real mosaic.
 * Each photo is a lightbox trigger and permanently credits its business (logo +
 * name). The wall pauses on hover.
 *
 * Attributes:
 *  - count   (int)    max photos pulled across all businesses (default 84)
 *  - speed   (float)  seconds-per-unit for one loop (default 10)
 *  - height  (string) 'screen' (fill viewport under the header, default) or a
 *                     CSS length like '80vh' / '600px'
 *  - orderby (string) 'rand' (default) | 'date'
 *  - style   (string) 'bento' (default 7-tile feature unit) | 'mosaic' (denser
 *                     14-tile interlocking mosaic — see self::STYLES)
 */
class GalleryShortcode
{
	/**
	 * Tiling presets. Each repeating "unit" tiles a `rows`-tall grid of `cols`
	 * columns; `per` photos fill the hand-placed slots (the `prefix`-N classes in
	 * the CSS), `ratio` (cols/rows) is the unit's aspect so JS can size its width.
	 *
	 *  - bento  : the original 7-tile feature unit (2×2 hero + wide + smalls).
	 *  - mosaic : a 28-tile unit built from 8 column modules, each a uniform 2-col
	 *             × 3-row block, in a fixed left-to-right order —
	 *             2×3 singles · wide+big · wide+2×2 · full · 2×3 singles · wide+big
	 *             · 2×2+wide · full — over a 16-col × 3-row grid (see .cbd-mx-* CSS).
	 */
	private const STYLES = [
		'bento'  => ['per' => 7,  'cols' => 4,  'rows' => 3, 'prefix' => 'cbd-gx-'],
		'mosaic' => ['per' => 28, 'cols' => 16, 'rows' => 3, 'prefix' => 'cbd-mx-'],
	];

	public function render(array $atts): string
	{
		$atts = shortcode_atts([
			'count'   => 84,
			'speed'   => 10,
			'height'  => 'screen',
			'orderby' => 'rand',
			'style'   => 'bento',
		], $atts);

		$style = isset(self::STYLES[$atts['style']]) ? (string) $atts['style'] : 'bento';
		$cfg   = self::STYLES[$style];
		$per   = (int) $cfg['per'];

		global $wpdb;

		$biz_ids = get_posts([
			'post_type'   => 'cbd_business',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		]);
		if (! $biz_ids) {
			return $this->empty_state();
		}

		// Logos (featured image) + covers are excluded so only true gallery
		// photos make the wall. $biz_ids are ints from fields=ids → safe to inline.
		$ids_in   = implode(',', array_map('intval', $biz_ids));
		$excluded = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key IN ('_thumbnail_id', '_cbd_cover_id') AND post_id IN ($ids_in)"
		);
		$excluded = array_values(array_filter(array_map('intval', (array) $excluded)));

		$orderby = in_array($atts['orderby'], ['rand', 'date'], true) ? $atts['orderby'] : 'rand';
		$images  = get_posts([
			'post_type'       => 'attachment',
			'post_mime_type'  => 'image',
			'post_status'     => 'inherit',
			'post_parent__in' => array_map('intval', $biz_ids),
			'post__not_in'    => $excluded,
			'numberposts'     => max($per, (int) $atts['count']),
			'orderby'         => $orderby,
		]);
		if (! $images) {
			return $this->empty_state();
		}

		// Whole units only, so the seam between the real set and its clone is a
		// clean unit boundary (seamless loop at translateX(-50%)).
		$units_n = (int) floor(count($images) / $per);
		if ($units_n >= 1) {
			$images = array_slice($images, 0, $units_n * $per);
		}
		$units = array_chunk($images, $per);
		$dur   = max(20, (int) round(count($units) * max(2.0, (float) $atts['speed'])));

		// Height: 'screen' fills the viewport beneath the header (refined by JS);
		// otherwise accept a safe CSS length, else fall back to screen-fill.
		$fill    = ('screen' === strtolower((string) $atts['height']));
		$h_style = '';
		if (! $fill) {
			$hv = (string) $atts['height'];
			if (preg_match('/^\d+(\.\d+)?(px|vh|svh|%)$/', $hv)) {
				$h_style = 'height:' . $hv . ';';
			} else {
				$fill = true;
			}
		}

		$biz_cache = [];

		ob_start(); ?>
		<div class="cbd-gallery-page">
			<div class="cbd-gallery-collage cbd-gallery-<?php echo esc_attr($style); ?><?php echo $fill ? ' is-fill' : ''; ?>" data-ratio="<?php echo esc_attr((string) round($cfg['cols'] / $cfg['rows'], 4)); ?>" style="--cbd-gal-dur:<?php echo (int) $dur; ?>s;<?php echo esc_attr($h_style); ?>">
				<div class="cbd-gallery-track">
					<?php
					// Render the unit set twice — real triggers, then an aria-hidden
					// clone — so the single slide loops seamlessly at translateX(-50%).
					for ($pass = 0; $pass < 2; $pass++) :
						$clone = (1 === $pass);
						foreach ($units as $unit) : ?>
							<div class="cbd-gallery-unit"<?php echo $clone ? ' aria-hidden="true" inert' : ''; ?>>
								<?php foreach ($unit as $pos => $img) :
									$gx    = ($pos % $per) + 1; // slot index within the unit
									$pid   = (int) $img->post_parent;
									$thumb = wp_get_attachment_image_url($img->ID, 'large') ?: wp_get_attachment_image_url($img->ID, 'full');
									if (! $thumb) {
										continue;
									}
									$full = wp_get_attachment_image_url($img->ID, 'full') ?: $thumb;
									// Reuse the shared "Posted by <business>" credit (.cbd-card-biz)
									// as a bottom overlay — its own <a>, so the photo link and the
									// credit link stay siblings (no nested anchors).
									if (! isset($biz_cache[$pid])) {
										$biz_cache[$pid] = \cbd_card_business_info($pid);
									}
									$credit  = $biz_cache[$pid];
									$caption = \cbd_label(get_the_title($pid));
									// When the photo was uploaded: relative "x ago" (GMT vs now,
									// consistent), full localized date as the hover title.
									$ts_gmt = (int) get_post_time('U', true, $img->ID);
									$ago    = $ts_gmt ? sprintf(
										/* translators: %s: human time difference, e.g. "3 days" */
										__('%s ago', 'community-business-directory'),
										human_time_diff($ts_gmt)
									) : '';
									$ago_full = $ts_gmt ? get_post_time(get_option('date_format') . ' ' . get_option('time_format'), false, $img->ID, true) : '';
								?>
									<div class="cbd-gallery-cell <?php echo esc_attr($cfg['prefix'] . $gx); ?>">
										<?php if ($clone) : ?>
											<span class="cbd-gallery-photo"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"></span>
										<?php else : ?>
											<a class="cbd-gallery-photo cbd-lightbox-trigger" href="<?php echo esc_url($full); ?>" data-caption="<?php echo esc_attr($caption); ?>"><img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($caption); ?>" loading="lazy"></a>
										<?php endif; ?>
										<?php if ($ago) : ?>
											<span class="cbd-gallery-cell-time" title="<?php echo esc_attr($ago_full); ?>"><?php echo \cbd_icon('clock-outline'); // phpcs:ignore WordPress.Security.EscapeOutput — trusted inline SVG ?><span><?php echo esc_html($ago); ?></span></span>
										<?php endif; ?>
										<?php echo $credit; // phpcs:ignore WordPress.Security.EscapeOutput — cbd_card_business_info() returns esc_*'d markup ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach;
					endfor; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function empty_state(): string
	{
		return '<div class="cbd-wrap cbd-gallery-page"><div class="cbd-empty-state">'
			. '<span class="cbd-empty-icon">🖼️</span><p>'
			. esc_html__('No gallery photos yet.', 'community-business-directory')
			. '</p></div></div>';
	}
}
