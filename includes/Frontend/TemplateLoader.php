<?php
/**
 * Template Loader — intercepts WordPress template resolution for CBD post types.
 *
 * Covers three cases so CBD content looks like the plugin regardless of the
 * active theme:
 *   • Single posts      → templates/single-<type>.php
 *   • Post-type archives → templates/archive-<type>.php   (/directory/, /events/, /promotions/)
 *   • Taxonomy archives  → templates/taxonomy-<base>.php   (category / location term pages)
 *
 * Without the archive handling, /events/ etc. fall through to the theme's
 * generic archive.php (e.g. Hello Elementor's "Archives: Events" plain list),
 * which never carries the plugin's card markup/classes.
 *
 * Themes can override any of these by dropping a file at:
 *   wp-content/themes/<theme>/community-business-directory/<filename>
 *
 * @package CBD\Frontend
 */

namespace CBD\Frontend;

defined( 'ABSPATH' ) || exit;

class TemplateLoader {

	/** Single post type → template file. */
	private array $singles = [
		'cbd_business'  => 'single-business.php',
		'cbd_event'     => 'single-event.php',
		'cbd_promotion' => 'single-promotion.php',
		'cbd_job'       => 'single-cbd_job.php',
	];

	/** Post-type archive → template file. */
	private array $archives = [
		'cbd_business'  => 'archive-business.php',
		'cbd_event'     => 'archive-event.php',
		'cbd_promotion' => 'archive-promotion.php',
	];

	/** Taxonomy → template file (its term archives reuse the matching grid). */
	private array $taxonomies = [
		'cbd_category'  => 'taxonomy-business.php',
		'cbd_location'  => 'taxonomy-business.php',
		'cbd_event_cat' => 'taxonomy-event.php',
	];

	public function filter_template( string $template ): string {
		// 0. Yield to Elementor Pro Theme Builder. If the user has built a
		// Single/Archive template whose display conditions match this view,
		// step aside and let Elementor render it. Elementor applies its own
		// template via a later template_include hook, so returning the incoming
		// $template unchanged is enough. When no matching Elementor template
		// exists (or Pro/Theme Builder isn't active), we fall through to the
		// plugin's own templates/ files below — preserving the default look.
		if ( $this->elementor_owns_current_view() ) {
			return $template;
		}

		// 1. Single CBD posts.
		if ( is_singular() ) {
			$pt = (string) get_post_type();
			return isset( $this->singles[ $pt ] )
				? $this->locate( $this->singles[ $pt ], $template )
				: $template;
		}

		// 2. CBD post-type archives (/directory/, /events/, /promotions/).
		if ( is_post_type_archive( array_keys( $this->archives ) ) ) {
			$pt = get_query_var( 'post_type' );
			$pt = is_array( $pt ) ? (string) reset( $pt ) : (string) $pt;
			if ( isset( $this->archives[ $pt ] ) ) {
				return $this->locate( $this->archives[ $pt ], $template );
			}
		}

		// 3. CBD taxonomy term archives.
		if ( is_tax( array_keys( $this->taxonomies ) ) ) {
			$term = get_queried_object();
			$tax  = ( $term && isset( $term->taxonomy ) ) ? $term->taxonomy : '';
			if ( isset( $this->taxonomies[ $tax ] ) ) {
				return $this->locate( $this->taxonomies[ $tax ], $template );
			}
		}

		return $template;
	}

	/**
	 * True when Elementor Pro Theme Builder has a Single/Archive template whose
	 * display conditions match the current CBD view — meaning the user has
	 * designed this surface in Elementor and we should not override it.
	 *
	 * Limited to the post types / taxonomies this loader handles so non-CBD
	 * views (which we never touch anyway) are left entirely to Elementor/WP.
	 */
	private function elementor_owns_current_view(): bool {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return false;
		}

		if ( is_singular( array_keys( $this->singles ) ) ) {
			$location = 'single';
		} elseif (
			is_post_type_archive( array_keys( $this->archives ) ) ||
			is_tax( array_keys( $this->taxonomies ) )
		) {
			$location = 'archive';
		} else {
			return false;
		}

		try {
			$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( ! $module ) {
				return false;
			}
			$docs = $module->get_conditions_manager()->get_documents_for_location( $location );
			return ! empty( $docs );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Resolve a template filename, preferring child-theme, then parent-theme,
	 * then the plugin's own templates/ directory; falls back to $default.
	 */
	private function locate( string $filename, string $default ): string {
		$child = trailingslashit( get_stylesheet_directory() ) . 'community-business-directory/' . $filename;
		if ( file_exists( $child ) ) {
			return $child;
		}

		$parent = trailingslashit( get_template_directory() ) . 'community-business-directory/' . $filename;
		if ( file_exists( $parent ) ) {
			return $parent;
		}

		$plugin = CBD_DIR . 'templates/' . $filename;
		if ( file_exists( $plugin ) ) {
			return $plugin;
		}

		return $default;
	}
}
