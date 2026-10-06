<?php
/**
 * GitHub release updater.
 *
 * Makes the Plugins screen / Dashboard → Updates detect new versions published
 * as GitHub Releases (tag `vX.Y.Z`) and install them like a wordpress.org update.
 *
 * @package CBD
 */

namespace CBD\Core;

defined( 'ABSPATH' ) || exit;

class GitHubUpdater {

	const REPO          = 'kohid/Community-Business-Directory';
	const CACHE_KEY     = 'cbd_github_release';
	const CACHE_TTL     = 6 * HOUR_IN_SECONDS;
	const ASSET_PATTERN = '/\.zip$/i';

	private string $basename;
	private string $slug;

	public function __construct() {
		$this->basename = plugin_basename( CBD_FILE );
		$this->slug     = dirname( $this->basename );
	}

	public function register( Loader $loader ): void {
		$loader->add_filter( 'pre_set_site_transient_update_plugins', $this, 'inject_update' );
		$loader->add_filter( 'plugins_api', $this, 'plugin_info', 10, 3 );
		$loader->add_filter( 'upgrader_source_selection', $this, 'fix_source_dir', 10, 4 );
		$loader->add_filter( 'http_request_args', $this, 'authorize_download', 10, 2 );
		$loader->add_action( 'upgrader_process_complete', $this, 'clear_cache', 10, 2 );
	}

	/**
	 * Latest release from GitHub (cached), or null when unavailable.
	 *
	 * @return array{version:string,url:string,package:string,body:string,published:string,html_url:string}|null
	 */
	private function latest_release( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached ?: null; // empty array = cached failure
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => $this->api_headers(),
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, [], 15 * MINUTE_IN_SECONDS ); // back off briefly
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			set_site_transient( self::CACHE_KEY, [], self::CACHE_TTL );
			return null;
		}

		// Prefer the uploaded release zip (correct folder name); fall back to GitHub's zipball.
		$package = (string) ( $data['zipball_url'] ?? '' );
		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			if ( preg_match( self::ASSET_PATTERN, (string) ( $asset['name'] ?? '' ) ) ) {
				$package = (string) ( $asset['browser_download_url'] ?? $package );
				break;
			}
		}

		$release = [
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'package'   => $package,
			'body'      => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
			'html_url'  => (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO ),
			'url'       => 'https://github.com/' . self::REPO,
		];

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	private function api_headers(): array {
		$headers = [ 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'CBD-Updater' ];
		if ( defined( 'CBD_GITHUB_TOKEN' ) && CBD_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . CBD_GITHUB_TOKEN;
		}
		return $headers;
	}

	/** Tell WordPress an update exists. */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( ! $release || '' === $release['package'] ) {
			return $transient;
		}

		$item = (object) [
			'id'          => 'github.com/' . self::REPO,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
			'requires'    => '6.4',
			'requires_php' => '8.0',
		];

		if ( version_compare( $release['version'], CBD_VERSION, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/** Populate the "View details" modal. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Community Business Directory',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="' . esc_url( $release['url'] ) . '">Community Business Directory</a>',
			'homepage'      => $release['url'],
			'requires'      => '6.4',
			'requires_php'  => '8.0',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => esc_html__( 'A full-featured business directory with listings, events, promotions, reviews and more.', 'community-business-directory' ),
				'changelog'   => wp_kses_post( wpautop( esc_html( $release['body'] ) ) ),
			],
		];
	}

	/**
	 * GitHub zipballs unpack to "owner-repo-<sha>/"; rename to the installed
	 * plugin folder so the update replaces the plugin instead of duplicating it.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return $desired;
		}

		return new \WP_Error( 'cbd_update_rename', __( 'Could not prepare the plugin update folder.', 'community-business-directory' ) );
	}

	/** Send the token only to GitHub when the repo is private. */
	public function authorize_download( array $args, string $url ): array {
		if ( defined( 'CBD_GITHUB_TOKEN' ) && CBD_GITHUB_TOKEN && false !== strpos( $url, 'github.com/' . self::REPO ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . CBD_GITHUB_TOKEN;
		}
		return $args;
	}

	public function clear_cache( $upgrader, array $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}
}
