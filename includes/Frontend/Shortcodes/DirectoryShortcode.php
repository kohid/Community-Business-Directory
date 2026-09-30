<?php

/**
 * Directory Shortcode — [cbd_directory]
 * AJAX-powered searchable business directory.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined('ABSPATH') || exit;

class DirectoryShortcode
{

	public function render(array $atts): string
	{
		$atts = shortcode_atts([
			'category'    => '',
			'view'        => 'masonry',
			'per_page'    => 10, // initial + infinite-scroll batch size (10 at a time); override with [cbd_directory per_page="12"]
			'show_search' => 'true',
			'show_map'    => 'false',
			'orderby'     => 'newest',
			'featured'    => '',
		], $atts);

		$categories = get_terms(['taxonomy' => 'cbd_category', 'hide_empty' => false, 'parent' => 0]);
		$locations  = get_terms(['taxonomy' => 'cbd_location',  'hide_empty' => false]);

		$view = in_array($atts['view'], ['grid', 'list', 'slides', 'masonry'], true) ? $atts['view'] : 'grid';

		ob_start(); ?>
		<div class="cbd-wrap cbd-directory cbd-listings-host cbd-lm-autoload" id="cbd-directory-wrap"
			data-per-page="<?php echo esc_attr($atts['per_page']); ?>"
			data-default-cat="<?php echo esc_attr($atts['category']); ?>"
			data-default-view="<?php echo esc_attr($view); ?>"
			data-default-orderby="<?php echo esc_attr($atts['orderby']); ?>">

			<?php if ($atts['show_search'] === 'true') : ?>
				<!-- ── Search Bar ────────────────────────────────────────── -->
				<div class="cbd-search-bar">
					<div class="cbd-search-inner">
						<div class="cbd-search-field">
							<span class="cbd-search-icon">🔍</span>
							<input type="text" id="cbd-live-search" class="cbd-search-input"
								placeholder="<?php esc_attr_e('Search businesses…', 'community-business-directory'); ?>"
								autocomplete="off">
							<div id="cbd-autocomplete" class="cbd-autocomplete" style="display:none;"></div>
						</div>

						<?php if (! is_wp_error($categories) && $categories) : ?>
							<select id="cbd-filter-cat" class="cbd-select">
								<option value=""><?php esc_html_e('All Categories', 'community-business-directory'); ?></option>
								<?php foreach ($categories as $cat) : ?>
									<option value="<?php echo esc_attr($cat->slug); ?>" <?php echo $atts['category'] === $cat->slug ? 'selected' : ''; ?>>
										<?php echo esc_html(\cbd_label($cat->name)); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>

						<?php if (! is_wp_error($locations) && $locations) : ?>
							<select id="cbd-filter-loc" class="cbd-select">
								<option value=""><?php esc_html_e('All Locations', 'community-business-directory'); ?></option>
								<?php foreach ($locations as $loc) : ?>
									<option value="<?php echo esc_attr($loc->slug); ?>"><?php echo esc_html(\cbd_label($loc->name)); ?></option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>

						<select id="cbd-filter-order" class="cbd-select">
							<option value="newest"><?php esc_html_e('Newest',    'community-business-directory'); ?></option>
							<option value="rating"><?php esc_html_e('Top Rated', 'community-business-directory'); ?></option>
							<option value="popular"><?php esc_html_e('Popular',  'community-business-directory'); ?></option>
							<option value="alpha"><?php esc_html_e('A–Z',        'community-business-directory'); ?></option>
						</select>

						<button class="cbd-btn cbd-btn-primary" id="cbd-search-btn">
							<?php esc_html_e('Search', 'community-business-directory'); ?>
						</button>
						<button class="cbd-btn cbd-btn-ghost" id="cbd-clear-btn" style="display:none;">
							<?php esc_html_e('Clear', 'community-business-directory'); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<!-- ── Toolbar ───────────────────────────────────────────── -->
			<div class="cbd-dir-toolbar">
				<p class="cbd-results-count" id="cbd-results-count">
					<?php esc_html_e('Loading…', 'community-business-directory'); ?>
				</p>
				<div class="cbd-view-toggle" role="group" aria-label="<?php esc_attr_e('Choose layout', 'community-business-directory'); ?>">
					<button type="button" class="cbd-view-btn<?php echo $view === 'grid' ? ' active' : ''; ?>" id="cbd-view-grid" data-view="grid" aria-pressed="<?php echo $view === 'grid' ? 'true' : 'false'; ?>" title="<?php esc_attr_e('Grid', 'community-business-directory'); ?>" aria-label="<?php esc_attr_e('Grid view', 'community-business-directory'); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="3" y="3" width="7" height="7" rx="1.5" />
							<rect x="14" y="3" width="7" height="7" rx="1.5" />
							<rect x="3" y="14" width="7" height="7" rx="1.5" />
							<rect x="14" y="14" width="7" height="7" rx="1.5" />
						</svg>
					</button>
					<button type="button" class="cbd-view-btn<?php echo $view === 'list' ? ' active' : ''; ?>" id="cbd-view-list" data-view="list" aria-pressed="<?php echo $view === 'list' ? 'true' : 'false'; ?>" title="<?php esc_attr_e('List', 'community-business-directory'); ?>" aria-label="<?php esc_attr_e('List view', 'community-business-directory'); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<line x1="8" y1="6" x2="21" y2="6" />
							<line x1="8" y1="12" x2="21" y2="12" />
							<line x1="8" y1="18" x2="21" y2="18" />
							<circle cx="3.5" cy="6" r="1.1" fill="currentColor" stroke="none" />
							<circle cx="3.5" cy="12" r="1.1" fill="currentColor" stroke="none" />
							<circle cx="3.5" cy="18" r="1.1" fill="currentColor" stroke="none" />
						</svg>
					</button>
					<button type="button" class="cbd-view-btn<?php echo $view === 'slides' ? ' active' : ''; ?>" id="cbd-view-slides" data-view="slides" aria-pressed="<?php echo $view === 'slides' ? 'true' : 'false'; ?>" title="<?php esc_attr_e('Slides', 'community-business-directory'); ?>" aria-label="<?php esc_attr_e('Slides view', 'community-business-directory'); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="7" y="5" width="10" height="14" rx="1.8" />
							<path d="M4 8.5v7" />
							<path d="M20 8.5v7" />
						</svg>
					</button>
					<button type="button" class="cbd-view-btn<?php echo $view === 'masonry' ? ' active' : ''; ?>" id="cbd-view-masonry" data-view="masonry" aria-pressed="<?php echo $view === 'masonry' ? 'true' : 'false'; ?>" title="<?php esc_attr_e('Masonry', 'community-business-directory'); ?>" aria-label="<?php esc_attr_e('Masonry view', 'community-business-directory'); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<rect x="3" y="3" width="7" height="9" rx="1.5" />
							<rect x="14" y="3" width="7" height="5" rx="1.5" />
							<rect x="3" y="16" width="7" height="5" rx="1.5" />
							<rect x="14" y="12" width="7" height="9" rx="1.5" />
						</svg>
					</button>
				</div>
			</div>

			<!-- ── Results ───────────────────────────────────────────── -->
			<div class="cbd-listings-stage<?php echo $view === 'slides' ? ' is-slides' : ''; ?>" id="cbd-listings-stage">
				<button type="button" class="cbd-slide-arrow cbd-slide-prev" id="cbd-slide-prev" aria-label="<?php esc_attr_e('Previous', 'community-business-directory'); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="15 18 9 12 15 6" />
					</svg>
				</button>
				<div class="cbd-listings cbd-view-<?php echo esc_attr($view); ?>" id="cbd-listings">
					<div class="cbd-loading-state" style="text-align:center;padding:60px 20px;color:var(--cbd-ink-muted);">
						<div class="cbd-spinner"></div>
						<p><?php esc_html_e('Loading businesses…', 'community-business-directory'); ?></p>
					</div>
				</div>
				<button type="button" class="cbd-slide-arrow cbd-slide-next" id="cbd-slide-next" aria-label="<?php esc_attr_e('Next', 'community-business-directory'); ?>">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="9 18 15 12 9 6" />
					</svg>
				</button>
			</div>

			<!-- Slides view auto-loads on horizontal scroll; this strip shows during that. -->
			<div id="cbd-dir-loader" class="cbd-dir-loader" aria-hidden="true">
				<span class="cbd-spinner"></span>
				<span><?php esc_html_e('Loading more businesses…', 'community-business-directory'); ?></span>
			</div>
			<!-- Grid / list / masonry: an explicit "Load more" button (10 at a time). -->
			<div class="cbd-lm-foot" id="cbd-dir-loadmore" style="display:none;">
				<button type="button" class="cbd-btn cbd-btn-outline cbd-lm-btn" id="cbd-dir-loadmore-btn">
					<span class="btn-text"><?php esc_html_e('Load more', 'community-business-directory'); ?></span>
					<span class="btn-loading" style="display:none;"><?php esc_html_e('Loading…', 'community-business-directory'); ?></span>
				</button>
			</div>
			<div id="cbd-dir-end" class="cbd-dir-end" aria-hidden="true"></div>
		</div>
<?php
		return ob_get_clean();
	}
}
