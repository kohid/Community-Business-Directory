<?php
/**
 * Plugin Name:       Community Business Directory
 * Plugin URI:        https://github.com/kohid/Community-Business-Directory
 * Description:       A full-featured business directory — registration, events, promotions, reviews, dashboard and more.
 * Version:           2.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Your Agency
 * License:           GPL v2 or later
 * Text Domain:       community-business-directory
 * Domain Path:       /languages
 *
 * @package CBD
 */

defined( 'ABSPATH' ) || exit;

define( 'CBD_VERSION',    '2.7.0' );
define( 'CBD_FILE',       __FILE__ );
define( 'CBD_DIR',        plugin_dir_path( __FILE__ ) );
define( 'CBD_URL',        plugin_dir_url( __FILE__ ) );
define( 'CBD_REST_NS',    'cbd/v1' );
define( 'CBD_DB_VERSION', '2.1.0' );

if ( ! function_exists( 'cbd_label' ) ) {
    /**
     * Decode a stored label so a single output-escaping pass renders it correctly.
     *
     * WordPress stores term/category names HTML-encoded (e.g. "Sports &amp; Leisure",
     * because `pre_term_name` runs `_wp_specialchars`) and `get_the_title()` texturizes
     * "&" into "&#038;". Passing those straight to `esc_html()` — or to a JS
     * `.text().html()` round-trip — encodes the entity a *second* time, so the page
     * shows the literal "&amp;" / "&#038;". Decode here, then escape on output exactly once.
     *
     * @param string|null $text Raw stored/texturized label.
     * @return string Decoded label, safe to pass to esc_html() / wp_json_encode().
     */
    function cbd_label( ?string $text ): string {
        return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'cbd_kses_embed' ) ) {
    /**
     * Sanitise a pasted third-party feed-widget snippet (Behold, SnapWidget,
     * Curator, Elfsight…). Site administrators normally hold `unfiltered_html`
     * (the WordPress default on single-site), so the raw <script>/<iframe>/<div>
     * markup those services need is kept verbatim — the same trust model as the
     * core Custom HTML widget. Where that capability is absent (multisite, or a
     * security plugin removed it), fall back to wp_kses_post, which strips
     * scripts, and let the surrounding UI flag the gap.
     *
     * Shared by the "Facebook"/"Instagram" admin pages and the [cbd_social_sync]
     * front-end page so both sanitise identically.
     */
    function cbd_kses_embed( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' || current_user_can( 'unfiltered_html' ) ) {
            return $raw;
        }
        return wp_kses_post( $raw );
    }
}

if ( ! function_exists( 'cbd_gallery_albums' ) ) {
    /**
     * Group a business's gallery images into albums.
     *
     * Albums are a lightweight model: each gallery attachment carries an
     * `_cbd_album` meta string, and an album is simply the set of images that
     * share a name. The logo (featured image) and cover photo are excluded so
     * they never leak into the gallery.
     *
     * @param int $post_id Business post ID.
     * @return array<string, \WP_Post[]> Album name => attachment posts, "General" last.
     */
    function cbd_gallery_albums( int $post_id ): array {
        $exclude = array_filter( [
            (int) get_post_thumbnail_id( $post_id ),
            (int) get_post_meta( $post_id, '_cbd_cover_id', true ),
        ] );

        $general = __( 'General', 'community-business-directory' );
        $albums  = [];

        foreach ( get_attached_media( 'image', $post_id ) as $att ) {
            if ( in_array( (int) $att->ID, $exclude, true ) ) {
                continue;
            }
            $name = (string) get_post_meta( $att->ID, '_cbd_album', true );
            $name = '' !== trim( $name ) ? cbd_label( $name ) : $general;
            $albums[ $name ][] = $att;
        }

        // Keep the catch-all album at the end for a tidier display order.
        if ( isset( $albums[ $general ] ) && count( $albums ) > 1 ) {
            $tail = $albums[ $general ];
            unset( $albums[ $general ] );
            $albums[ $general ] = $tail;
        }

        return $albums;
    }
}

if ( ! function_exists( 'cbd_user_can_manage_business' ) ) {
    /**
     * Can the given user manage this business? True for the owner (post author),
     * any delegate stored in `_cbd_delegates`, or a site admin. Drives who sees
     * the profile Settings menu, the "add" buttons, and the post/edit forms.
     *
     * @param int      $post_id Business post ID.
     * @param int|null $user_id Defaults to the current user.
     */
    function cbd_user_can_manage_business( int $post_id, ?int $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id || ! $post_id ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        if ( (int) get_post_field( 'post_author', $post_id ) === (int) $user_id ) {
            return true;
        }
        $delegates = (array) get_post_meta( $post_id, '_cbd_delegates', true );
        return in_array( (int) $user_id, array_map( 'intval', $delegates ), true );
    }
}

if ( ! function_exists( 'cbd_delegate_capabilities' ) ) {
    /**
     * The granular capabilities a business owner can grant a delegate, as
     * key => human label. These gate the matching management surfaces and the
     * mutating AJAX handlers.
     *
     * @return array<string,string>
     */
    function cbd_delegate_capabilities(): array {
        return [
            'edit'       => __( 'Edit page profile & hours', 'community-business-directory' ),
            'posts'      => __( 'Create & manage posts', 'community-business-directory' ),
            'events'     => __( 'Create & manage events', 'community-business-directory' ),
            'promotions' => __( 'Create & manage promotions', 'community-business-directory' ),
            'gallery'    => __( 'Manage gallery photos', 'community-business-directory' ),
            'analytics'  => __( 'View analytics', 'community-business-directory' ),
        ];
    }
}

if ( ! function_exists( 'cbd_is_business_owner' ) ) {
    /**
     * True only for the listing's actual owner (post author) or a site admin —
     * NOT for delegates. Gates owner-only surfaces such as Delegate Access.
     *
     * @param int      $post_id Business post ID.
     * @param int|null $user_id Defaults to the current user.
     */
    function cbd_is_business_owner( int $post_id, ?int $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id || ! $post_id ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        return (int) get_post_field( 'post_author', $post_id ) === (int) $user_id;
    }
}

if ( ! function_exists( 'cbd_delegate_perms' ) ) {
    /**
     * A delegate's stored permission map for one business: [ cap => 1 ]. Empty
     * array when no entry exists (a legacy delegate, treated as full access by
     * cbd_user_can()).
     *
     * @return array<string,int>
     */
    function cbd_delegate_perms( int $post_id, int $user_id ): array {
        $all = get_post_meta( $post_id, '_cbd_delegate_perms', true );
        if ( ! is_array( $all ) || ! isset( $all[ $user_id ] ) || ! is_array( $all[ $user_id ] ) ) {
            return [];
        }
        return array_map( 'intval', $all[ $user_id ] );
    }
}

if ( ! function_exists( 'cbd_user_can' ) ) {
    /**
     * Whether a user may perform a delegate capability on a business. Owners /
     * admins can do everything; a delegate is limited to their granted caps. A
     * legacy delegate with no stored permission map keeps full access (so older
     * data is not silently locked out) until the owner edits their permissions.
     *
     * @param int      $post_id Business post ID.
     * @param string   $cap     One of cbd_delegate_capabilities() keys.
     * @param int|null $user_id Defaults to the current user.
     */
    function cbd_user_can( int $post_id, string $cap, ?int $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id || ! $post_id ) {
            return false;
        }
        if ( cbd_is_business_owner( $post_id, $user_id ) ) {
            return true;
        }
        $delegates = array_map( 'intval', (array) get_post_meta( $post_id, '_cbd_delegates', true ) );
        if ( ! in_array( (int) $user_id, $delegates, true ) ) {
            return false;
        }
        $perms = cbd_delegate_perms( $post_id, (int) $user_id );
        if ( ! $perms ) {
            return true; // Legacy delegate — full access until the owner sets perms.
        }
        return ! empty( $perms[ $cap ] );
    }
}

if ( ! function_exists( 'cbd_plans_url' ) ) {
    /**
     * Resolve the membership-plans page URL ([cbd_plans]). Prefers the page
     * created on activation, otherwise finds any published page using the
     * shortcode (cached for a day), then falls back to /plans/.
     */
    function cbd_plans_url(): string {
        $page_id = (int) get_option( 'cbd_plans_page_id' );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            return (string) get_permalink( $page_id );
        }
        $cached = get_transient( 'cbd_plans_page_url' );
        if ( false !== $cached ) {
            return (string) $cached;
        }
        global $wpdb;
        $found = $wpdb->get_var(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[cbd_plans%' LIMIT 1"
        );
        $url = $found ? (string) get_permalink( (int) $found ) : home_url( '/plans/' );
        set_transient( 'cbd_plans_page_url', $url, DAY_IN_SECONDS );
        return $url;
    }
}

if ( ! function_exists( 'cbd_listing_page_url' ) ) {
    /**
     * Resolve the URL of an auto-created listing Page by its option key
     * (e.g. 'cbd_promotions_page_id' → /offers/), falling back to a
     * home-relative path. Use this for "back to listing" links instead of
     * get_post_type_archive_link(): several CPT archives (cbd_business,
     * cbd_promotion) are intentionally disabled in favour of these branded
     * Pages, so the archive link would resolve to false / a dead URL.
     *
     * @param string $option_key   Option holding the page ID.
     * @param string $fallback_path Home-relative path if the page is missing.
     */
    function cbd_listing_page_url( string $option_key, string $fallback_path ): string {
        $page_id = (int) get_option( $option_key );
        if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
            $url = get_permalink( $page_id );
            if ( $url ) {
                return (string) $url;
            }
        }
        return home_url( $fallback_path );
    }
}

if ( ! function_exists( 'cbd_profile_part' ) ) {
    /**
     * Render a single-post profile partial for the CURRENT post and return its
     * HTML. The partial (templates/parts/profile-<slug>.php) is the single
     * source of truth for the single-<slug>.php template AND the matching
     * [cbd_<slug>_profile] shortcode, so the Elementor Theme Builder workflow
     * and the default template stay pixel-identical. Themes may override a
     * partial at <theme>/community-business-directory/parts/profile-<slug>.php.
     *
     * @param string $slug One of: business | event | promotion.
     */
    function cbd_profile_part( string $slug ): string {
        $file = 'parts/profile-' . preg_replace( '/[^a-z]/', '', $slug ) . '.php';
        $candidates = [
            trailingslashit( get_stylesheet_directory() ) . 'community-business-directory/' . $file,
            trailingslashit( get_template_directory() ) . 'community-business-directory/' . $file,
            CBD_DIR . 'templates/' . $file,
        ];
        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) ) {
                ob_start();
                include $path;
                return (string) ob_get_clean();
            }
        }
        return '';
    }
}

if ( ! function_exists( 'cbd_promote_business_owner' ) ) {
    /**
     * Promote a business's owner to the Business Owner role once their listing
     * goes live (admin approval, or instant when approval isn't required). New
     * accounts start as plain subscribers; this is the single place the
     * cbd_business_owner role is granted. No-op for admins or anyone who
     * already holds the role.
     *
     * @param int $business_post_id Business post ID (cbd_businesses.post_id).
     */
    function cbd_promote_business_owner( int $business_post_id ): void {
        $post = get_post( $business_post_id );
        if ( ! $post || 'cbd_business' !== $post->post_type ) {
            return;
        }
        $user = get_user_by( 'id', (int) $post->post_author );
        if ( ! $user ) {
            return;
        }
        // Leave administrators / directory admins untouched.
        if ( user_can( $user, 'manage_options' ) || in_array( 'cbd_business_owner', (array) $user->roles, true ) ) {
            return;
        }
        $user->set_role( 'cbd_business_owner' );
    }
}

if ( ! function_exists( 'cbd_user_business_url' ) ) {
    /**
     * Permalink of a user's first published business listing, or '' if none.
     * Powers "My Page" links and post-login redirects.
     */
    function cbd_user_business_url( int $user_id ): string {
        if ( ! $user_id ) {
            return '';
        }
        $ids = get_posts( [
            'post_type'      => 'cbd_business',
            'author'         => $user_id,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        return $ids ? (string) get_permalink( (int) $ids[0] ) : '';
    }
}

if ( ! function_exists( 'cbd_user_businesses' ) ) {
    /**
     * Every published business a user can manage — both those they own (authored)
     * and those they've been granted delegate access to (`_cbd_delegates`) — each
     * as [ 'id' => int, 'title' => string, 'url' => string ], ordered by title.
     * Powers the account-menu "My Page" submenu.
     *
     * @return array<int,array{id:int,title:string,url:string}>
     */
    function cbd_user_businesses( int $user_id ): array {
        if ( ! $user_id ) {
            return [];
        }

        // Businesses the user authored.
        $ids = get_posts( [
            'post_type'      => 'cbd_business',
            'author'         => $user_id,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        // Businesses the user is a delegate on. The meta stores a serialized array
        // of int IDs, so a LIKE narrows the candidates and we verify each in PHP
        // (avoids matching a serialized array index instead of a value).
        $candidates = get_posts( [
            'post_type'      => 'cbd_business',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ [
                'key'     => '_cbd_delegates',
                'value'   => 'i:' . $user_id . ';',
                'compare' => 'LIKE',
            ] ],
        ] );
        foreach ( $candidates as $cid ) {
            $delegates = array_map( 'intval', (array) get_post_meta( (int) $cid, '_cbd_delegates', true ) );
            if ( in_array( $user_id, $delegates, true ) ) {
                $ids[] = (int) $cid;
            }
        }

        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

        $out = [];
        foreach ( $ids as $id ) {
            $out[] = [
                'id'    => (int) $id,
                'title' => cbd_label( get_the_title( (int) $id ) ),
                'url'   => (string) get_permalink( (int) $id ),
            ];
        }
        // Sort by title (the merged owned + delegated set is unordered).
        usort( $out, static fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) );
        return $out;
    }
}

if ( ! function_exists( 'cbd_set_password_url' ) ) {
    /**
     * Build a tokenised "set / reset your password" link to the plugin's own
     * branded [cbd_set_password] page (replacing the WordPress reset screen).
     * Uses WordPress's secure reset-key system (hashed + expiring). Returns ''
     * if a key could not be generated.
     */
    function cbd_set_password_url( \WP_User $user ): string {
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            return '';
        }
        $pid  = (int) get_option( 'cbd_setpw_page_id' );
        $base = ( $pid && get_post_status( $pid ) ) ? get_permalink( $pid ) : home_url( '/set-password/' );
        // add_query_arg() URL-encodes the values for us.
        return add_query_arg(
            [ 'key' => $key, 'login' => $user->user_login ],
            $base
        );
    }
}

if ( ! function_exists( 'cbd_login_redirect' ) ) {
    /**
     * Where to land a user after login. Invited site-team members go to the
     * front-end "Social Feeds" page (/socmed) to self-manage the Facebook &
     * Instagram sync — falling back to the WordPress dashboard if that page is
     * missing. Business owners go to their own public business page; everyone
     * else goes home. (The standalone dashboard was removed — owners self-manage
     * from modals on their business page, opened via the account menu.)
     */
    function cbd_login_redirect( \WP_User $user ): string {
        if ( '1' === (string) get_user_meta( $user->ID, 'cbd_site_team', true ) ) {
            $pid = (int) get_option( 'cbd_socmed_page_id' );
            if ( $pid && get_post_status( $pid ) === 'publish' && ( $url = get_permalink( $pid ) ) ) {
                return $url;
            }
            if ( $user->has_cap( 'manage_options' ) ) {
                return admin_url();
            }
        }
        $biz = cbd_user_business_url( (int) $user->ID );
        return $biz ?: home_url( '/' );
    }
}

if ( ! function_exists( 'cbd_auth_logo_html' ) ) {
    /**
     * Branded logo block for the auth surfaces (Sign In, Create Account, Set
     * Password and the Forgot Password modal). Reuses the same logo resolution
     * as the plugin's emails — the Settings → Email logo, else the theme custom
     * logo, else the site icon. Returns '' when no logo is available so the
     * page simply falls back to its text heading.
     */
    function cbd_auth_logo_html(): string {
        $url = class_exists( '\CBD\Frontend\EmailVerification' )
            ? \CBD\Frontend\EmailVerification::logo_url()
            : '';
        if ( ! $url ) {
            return '';
        }
        return '<div class="cbd-login-logo"><img src="' . esc_url( $url ) . '" alt="'
            . esc_attr( get_bloginfo( 'name' ) ) . '"></div>';
    }
}

if ( ! function_exists( 'cbd_icon' ) ) {
    /**
     * Inline an SVG icon from assets/icons/ (Material Design Icons, Apache-2.0 —
     * see assets/icons/LICENSE.md). Returns the raw <svg> so `currentColor` and
     * CSS sizing work exactly like the plugin's hand-coded inline icons.
     *
     * The file's fixed width/height are stripped (CSS controls the size) and a
     * `cbd-icon` class + `aria-hidden` are injected. Unknown / unsafe names
     * return '' so a typo can never expose the filesystem. Results are cached
     * per-request so repeated icons don't re-read from disk.
     *
     * @param string $name  Icon slug, e.g. 'cog' (maps to assets/icons/cog.svg).
     * @param string $class Extra CSS class(es) appended to `cbd-icon`.
     */
    function cbd_icon( string $name, string $class = '' ): string {
        static $cache = [];

        $name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( $name ) );
        if ( '' === $name ) {
            return '';
        }

        if ( ! isset( $cache[ $name ] ) ) {
            // $name is already constrained to [a-z0-9-] above, so no '.', '/' or
            // '\' can survive — path traversal out of assets/icons/ is impossible.
            $file = CBD_DIR . 'assets/icons/' . $name . '.svg';
            if ( ! is_readable( $file ) ) {
                $cache[ $name ] = '';
            } else {
                $svg = (string) file_get_contents( $file );
                // Drop the fixed width/height so CSS (em/px) controls the size.
                $svg = preg_replace( '/\s(?:width|height)="[^"]*"/', '', $svg );
                $cache[ $name ] = $svg;
            }
        }

        if ( '' === $cache[ $name ] ) {
            return '';
        }

        $classes = trim( 'cbd-icon ' . $class );
        return preg_replace(
            '/<svg\b/',
            '<svg class="' . esc_attr( $classes ) . '" aria-hidden="true" focusable="false"',
            $cache[ $name ],
            1
        );
    }
}

if ( ! function_exists( 'cbd_card_business_info' ) ) {
    /**
     * Render a compact "posted by <business>" credit for event / promotion
     * cards, linking to the owning business profile. `$business_id` is a
     * business's WP post ID (the cbd_events / cbd_promotions `business_id`
     * column). Returns '' when the business is missing or unpublished.
     *
     * @param int $business_id Business post ID.
     */
    function cbd_card_business_info( int $business_id ): string {
        if ( ! $business_id ) {
            return '';
        }
        $biz = get_post( $business_id );
        if ( ! $biz || 'cbd_business' !== $biz->post_type || 'publish' !== $biz->post_status ) {
            return '';
        }
        $name = cbd_label( get_the_title( $business_id ) );
        $url  = (string) get_permalink( $business_id );
        $logo = get_the_post_thumbnail_url( $business_id, 'thumbnail' );
        $avatar = $logo
            ? '<img class="cbd-card-biz-logo" src="' . esc_url( $logo ) . '" alt="" loading="lazy">'
            : '<span class="cbd-card-biz-logo cbd-card-biz-logo-ph">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span>';

        return '<a class="cbd-card-biz" href="' . esc_url( $url ) . '">'
            . $avatar
            . '<span class="cbd-card-biz-text"><span class="cbd-card-biz-by">'
            . esc_html__( 'Posted by', 'community-business-directory' )
            . '</span><span class="cbd-card-biz-name">' . esc_html( $name ) . '</span></span>'
            . '</a>';
    }
}

if ( ! function_exists( 'cbd_provider_badge' ) ) {
    /**
     * Render the "Signed in with <provider>" pill for a user, resolving their
     * stored sign-in provider (cbd_social_provider meta). Falls back to an
     * "Email" badge. Returns '' for a guest (user id 0). Shared by the reactors
     * modal and the reviews list; delegates to AccountMenuShortcode so the brand
     * SVGs live in one place.
     *
     * @param int $user_id WP user ID.
     */
    function cbd_provider_badge( int $user_id ): string {
        if ( $user_id < 1 ) {
            return '';
        }
        $provider = (string) get_user_meta( $user_id, 'cbd_social_provider', true );
        return \CBD\Frontend\Shortcodes\AccountMenuShortcode::provider_badge( $provider );
    }
}

spl_autoload_register( static function ( string $class ): void {
    if ( strpos( $class, 'CBD\\' ) !== 0 ) return;
    $path = CBD_DIR . 'includes/' . str_replace( [ 'CBD\\', '\\' ], [ '', '/' ], $class ) . '.php';
    if ( file_exists( $path ) ) require_once $path;
} );

register_activation_hook( __FILE__,   [ 'CBD\\Core\\Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'CBD\\Core\\Deactivator', 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
    CBD\Core\Plugin::get_instance()->init();
} );
