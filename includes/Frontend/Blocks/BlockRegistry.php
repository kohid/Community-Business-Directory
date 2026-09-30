<?php
/**
 * Gutenberg Block Registry — registers all CBD blocks as server-side rendered blocks.
 * Each block maps to its equivalent shortcode so the same logic is reused.
 *
 * @package CBD\Frontend\Blocks
 */

namespace CBD\Frontend\Blocks;

defined( 'ABSPATH' ) || exit;

class BlockRegistry {

	/**
	 * Map of block name → shortcode tag.
	 * Adding a new shortcode here automatically makes it a Gutenberg block.
	 */
	private array $blocks = [
		'cbd/directory'            => 'cbd_directory',
		'cbd/featured-businesses'  => 'cbd_featured_businesses',
		'cbd/events-grid'          => 'cbd_events',
		'cbd/promotions'           => 'cbd_promotions',
		'cbd/search-bar'           => 'cbd_search',
		'cbd/activity-feed'        => 'cbd_activity_feed',
		'cbd/business-map'         => 'cbd_business_map',
		'cbd/reviews'              => 'cbd_reviews',
		'cbd/register-business'    => 'cbd_register_business',
		'cbd/account-menu'         => 'cbd_account_menu',
		'cbd/facebook-page'        => 'cbd_facebook_page',
		'cbd/instagram-feed'       => 'cbd_instagram_feed',
		'cbd/jobs'                 => 'cbd_jobs',
	];

	public function register_all(): void {
		foreach ( $this->blocks as $block_name => $shortcode ) {
			$this->register_block( $block_name, $shortcode );
		}
	}

	private function register_block( string $block_name, string $shortcode ): void {
		// Avoid re-registering if block already registered (e.g. on Multisite).
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
			return;
		}

		register_block_type( $block_name, [
			'render_callback' => static function ( array $attributes ) use ( $shortcode ): string {
				// Build shortcode attribute string from block attributes.
				$atts = '';
				$safe = [ 'count', 'category', 'view', 'per_page', 'featured', 'orderby', 'show_search', 'show_map', 'business_id', 'mode', 'tabs', 'height', 'page', 'layout', 'columns', 'username', 'workplace', 'company', 'show_filters' ];
				foreach ( $safe as $key ) {
					if ( isset( $attributes[ $key ] ) && $attributes[ $key ] !== '' ) {
						$atts .= ' ' . esc_attr( $key ) . '="' . esc_attr( $attributes[ $key ] ) . '"';
					}
				}
				return do_shortcode( '[' . $shortcode . $atts . ']' );
			},
			'attributes'      => [
				'count'       => [ 'type' => 'number',  'default' => 6   ],
				'category'    => [ 'type' => 'string',  'default' => ''  ],
				'view'        => [ 'type' => 'string',  'default' => 'grid' ],
				'per_page'    => [ 'type' => 'number',  'default' => 20  ],
				'featured'    => [ 'type' => 'boolean', 'default' => false ],
				'orderby'     => [ 'type' => 'string',  'default' => 'newest' ],
				'show_search' => [ 'type' => 'boolean', 'default' => true ],
				'show_map'    => [ 'type' => 'boolean', 'default' => false ],
				'business_id' => [ 'type' => 'number',  'default' => 0   ],
				'mode'        => [ 'type' => 'string',  'default' => ''  ],
				'tabs'        => [ 'type' => 'string',  'default' => ''  ],
				'height'      => [ 'type' => 'number',  'default' => 0   ],
				'page'        => [ 'type' => 'string',  'default' => ''  ],
				'layout'      => [ 'type' => 'string',  'default' => ''  ],
				'columns'     => [ 'type' => 'number',  'default' => 0   ],
				'username'    => [ 'type' => 'string',  'default' => ''  ],
				'workplace'   => [ 'type' => 'string',  'default' => ''  ],
				'company'     => [ 'type' => 'string',  'default' => ''  ],
				'show_filters'=> [ 'type' => 'string',  'default' => ''  ],
			],
		] );
	}
}
