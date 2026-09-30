<?php
/**
 * Shared "Load more" support for list shortcodes.
 *
 * A consuming shortcode exposes `items( array $atts, int $page, int $per_page ): array`
 * returning `[ 'html' => string, 'total' => int ]`, wraps its first page inside a
 * `.cbd-lm` element (carrying the data-* attributes from {@see loadmore_open()})
 * whose item container has the class `cbd-lm-list`, and closes it with
 * {@see loadmore_button()}. One JS handler then fetches subsequent pages via the
 * generic `cbd_load_more` AJAX action and appends them.
 *
 * @package CBD\Frontend\Shortcodes
 */

namespace CBD\Frontend\Shortcodes;

defined( 'ABSPATH' ) || exit;

trait LoadMore {

	/**
	 * Opening tag for the load-more wrapper. `$sc` is the key the AJAX router
	 * maps back to this shortcode; `$passthru` are the filter atts (category,
	 * types, …) replayed on each page fetch.
	 */
	protected function loadmore_open( string $sc, array $passthru, int $per_page, int $total, string $class = '' ): string {
		return sprintf(
			'<div class="cbd-lm %s" data-cbd-lm="%s" data-page="1" data-per-page="%d" data-total="%d" data-atts="%s">',
			esc_attr( $class ),
			esc_attr( $sc ),
			(int) $per_page,
			(int) $total,
			esc_attr( (string) wp_json_encode( $passthru ) )
		);
	}

	/** The load-more button — empty string once the last page is shown. */
	protected function loadmore_button( int $page, int $per_page, int $total ): string {
		if ( $page * $per_page >= $total ) {
			return '';
		}
		return '<div class="cbd-lm-foot"><button type="button" class="cbd-btn cbd-btn-outline cbd-lm-btn">'
			. '<span class="btn-text">' . esc_html__( 'Load more', 'community-business-directory' ) . '</span>'
			. '<span class="btn-loading" style="display:none;">' . esc_html__( 'Loading…', 'community-business-directory' ) . '</span>'
			. '</button></div>';
	}
}
