<?php
/**
 * Unified social sign-in via server-side OAuth 2.0 (Authorization Code flow).
 *
 * Supports Google, Facebook, Twitter / X and Instagram from one code path so
 * the [cbd_login] buttons are plain links — no per-provider JS SDK required.
 * Twitter / X uses PKCE (it mandates it); the others use the classic
 * client_id + client_secret exchange. CSRF is covered by a single-use,
 * unguessable `state` stored in a 10-minute transient.
 *
 * Flow:
 *   1. /?cbd_oauth=<provider>           → redirect the visitor to the provider.
 *   2. provider redirects back to
 *      /?cbd_oauth_callback=<provider>  → exchange code, fetch profile, sign in.
 *
 * Email caveat: Google and Facebook return a verified email. Twitter / X and
 * Instagram do NOT expose email through OAuth, so accounts for those are keyed
 * on the provider's user id (saved as `cbd_social_<provider>_id` user meta) and
 * given a non-routable `@users.noreply.<site>` placeholder email.
 *
 * @package CBD\Frontend
 */

namespace CBD\Frontend;

defined( 'ABSPATH' ) || exit;

class SocialAuth {

	/** Providers we know how to talk to. */
	public const PROVIDERS = [ 'google', 'facebook', 'twitter', 'instagram' ];

	/**
	 * Front controller — hooked on `init`. Cheap no-op on normal requests
	 * (just two isset() checks) and only does work on our two query vars.
	 */
	public function maybe_handle(): void {
		// X / Twitter's OAuth 2.0 forbids query strings in redirect_uri, so it
		// uses a clean path-based callback (/cbd-oauth/twitter/) instead of the
		// ?cbd_oauth_callback= form the other providers use. Detect it straight
		// from the request path — no rewrite rule needed, since init fires for
		// every URL before WP's routing/404 kicks in.
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '' !== $path && preg_match( '#/cbd-oauth/twitter/?$#', $path ) ) {
			$this->handle_callback( 'twitter' );
			return;
		}

		if ( isset( $_GET['cbd_oauth_callback'] ) ) {
			$this->handle_callback( sanitize_key( wp_unslash( $_GET['cbd_oauth_callback'] ) ) );
		} elseif ( isset( $_GET['cbd_oauth'] ) ) {
			$this->handle_start( sanitize_key( wp_unslash( $_GET['cbd_oauth'] ) ) );
		}
	}

	// ── Public helpers (used by the shortcode + admin settings) ───────

	/** Is this provider fully configured (both id and secret present)? */
	public static function is_enabled( string $provider ): bool {
		[ $id, $secret ] = self::creds( $provider );
		return '' !== $id && '' !== $secret;
	}

	/** Are *any* providers configured? Drives whether the divider shows. */
	public static function any_enabled(): bool {
		foreach ( self::PROVIDERS as $p ) {
			if ( self::is_enabled( $p ) ) {
				return true;
			}
		}
		return false;
	}

	/** URL that kicks off sign-in for a provider, optionally with a post-login redirect. */
	public static function start_url( string $provider, string $redirect_to = '' ): string {
		$args = [ 'cbd_oauth' => $provider ];
		if ( $redirect_to ) {
			$args['redirect_to'] = $redirect_to;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/** The exact redirect URI to register in each provider's developer console. */
	public static function callback_url( string $provider ): string {
		// X / Twitter rejects query strings in redirect_uri (returns 400 at the
		// authorize step), so it gets a clean path; the rest keep the query form.
		if ( 'twitter' === $provider ) {
			return home_url( '/cbd-oauth/twitter/' );
		}
		return add_query_arg( 'cbd_oauth_callback', $provider, home_url( '/' ) );
	}

	/** Human label for a provider. */
	public static function label( string $provider ): string {
		$labels = [
			'google'    => 'Google',
			'facebook'  => 'Facebook',
			'twitter'   => 'Twitter / X',
			'instagram' => 'Instagram',
		];
		return $labels[ $provider ] ?? ucfirst( $provider );
	}

	// ── Credentials ──────────────────────────────────────────────────

	/** @return array{0:string,1:string} [ client id, client secret ] for a provider. */
	private static function creds( string $provider ): array {
		switch ( $provider ) {
			case 'google':
				return [ trim( (string) get_option( 'cbd_google_client_id', '' ) ), trim( (string) get_option( 'cbd_google_client_secret', '' ) ) ];
			case 'facebook':
				return [ trim( (string) get_option( 'cbd_facebook_app_id', '' ) ), trim( (string) get_option( 'cbd_facebook_app_secret', '' ) ) ];
			case 'twitter':
				return [ trim( (string) get_option( 'cbd_twitter_client_id', '' ) ), trim( (string) get_option( 'cbd_twitter_client_secret', '' ) ) ];
			case 'instagram':
				return [ trim( (string) get_option( 'cbd_instagram_client_id', '' ) ), trim( (string) get_option( 'cbd_instagram_client_secret', '' ) ) ];
		}
		return [ '', '' ];
	}

	// ── Step 1: redirect the visitor to the provider ─────────────────

	private function handle_start( string $provider ): void {
		if ( ! in_array( $provider, self::PROVIDERS, true ) || ! self::is_enabled( $provider ) ) {
			$this->bail( __( 'This sign-in method is not available.', 'community-business-directory' ) );
		}

		[ $client_id ] = self::creds( $provider );

		$state    = wp_generate_password( 24, false );
		$verifier = wp_generate_password( 64, false ); // PKCE verifier (used by Twitter / X).
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

		set_transient(
			'cbd_oauth_' . $state,
			[ 'provider' => $provider, 'verifier' => $verifier, 'redirect' => $redirect ],
			10 * MINUTE_IN_SECONDS
		);

		$args = [
			'client_id'     => $client_id,
			'redirect_uri'  => self::callback_url( $provider ),
			'state'         => $state,
			'response_type' => 'code',
		];

		switch ( $provider ) {
			case 'google':
				$base                = 'https://accounts.google.com/o/oauth2/v2/auth';
				$args['scope']       = 'openid email profile';
				$args['access_type'] = 'online';
				$args['prompt']      = 'select_account';
				break;
			case 'facebook':
				$base          = 'https://www.facebook.com/v18.0/dialog/oauth';
				$args['scope'] = 'email,public_profile';
				break;
			case 'twitter':
				$base                          = 'https://twitter.com/i/oauth2/authorize';
				$args['scope']                 = 'users.read tweet.read';
				$args['code_challenge']        = self::pkce_challenge( $verifier );
				$args['code_challenge_method'] = 'S256';
				break;
			case 'instagram':
				$base          = 'https://api.instagram.com/oauth/authorize';
				$args['scope'] = 'user_profile';
				break;
			default:
				$this->bail( __( 'This sign-in method is not available.', 'community-business-directory' ) );
		}

		// External provider URL — wp_redirect (not wp_safe_redirect, which blocks off-host).
		wp_redirect( $base . '?' . http_build_query( $args ) );
		exit;
	}

	// ── Step 2: handle the provider's callback ───────────────────────

	private function handle_callback( string $provider ): void {
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			$this->bail( __( 'Unknown sign-in provider.', 'community-business-directory' ) );
		}
		if ( ! empty( $_GET['error'] ) ) {
			$this->bail( __( 'Sign-in was cancelled.', 'community-business-directory' ) );
		}

		$code  = isset( $_GET['code'] )  ? sanitize_text_field( wp_unslash( $_GET['code'] ) )  : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! $code || ! $state ) {
			$this->bail( __( 'Sign-in response was incomplete — please try again.', 'community-business-directory' ) );
		}

		$stored = get_transient( 'cbd_oauth_' . $state );
		if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $provider ) {
			$this->bail( __( 'Your sign-in session expired — please try again.', 'community-business-directory' ) );
		}
		delete_transient( 'cbd_oauth_' . $state ); // single use.

		$token = $this->exchange_code( $provider, $code, (string) ( $stored['verifier'] ?? '' ) );
		if ( is_wp_error( $token ) ) {
			$this->bail( $token->get_error_message() );
		}

		$profile = $this->fetch_profile( $provider, $token );
		if ( is_wp_error( $profile ) ) {
			$this->bail( $profile->get_error_message() );
		}

		$user_id = $this->login_or_create( $provider, $profile );
		if ( is_wp_error( $user_id ) ) {
			$this->bail( $user_id->get_error_message() );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		$dest = ! empty( $stored['redirect'] ) ? $stored['redirect'] : $this->default_redirect();
		wp_safe_redirect( $dest );
		exit;
	}

	// ── Token exchange ───────────────────────────────────────────────

	/**
	 * Trade the authorization code for an access token.
	 *
	 * @return array{access_token:string,user_id?:string}|\WP_Error
	 */
	private function exchange_code( string $provider, string $code, string $verifier ) {
		[ $client_id, $client_secret ] = self::creds( $provider );
		$redirect_uri = self::callback_url( $provider );

		$endpoints = [
			'google'    => 'https://oauth2.googleapis.com/token',
			'facebook'  => 'https://graph.facebook.com/v18.0/oauth/access_token',
			'twitter'   => 'https://api.twitter.com/2/oauth2/token',
			'instagram' => 'https://api.instagram.com/oauth/access_token',
		];

		$body = [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirect_uri,
			'client_id'    => $client_id,
		];
		$headers = [ 'Accept' => 'application/json' ];

		if ( 'twitter' === $provider ) {
			// X mandates PKCE; confidential clients also send HTTP Basic auth.
			$body['code_verifier'] = $verifier;
			$headers['Authorization'] = 'Basic ' . base64_encode( $client_id . ':' . $client_secret );
		} else {
			$body['client_secret'] = $client_secret;
		}

		$res = wp_remote_post( $endpoints[ $provider ], [
			'timeout' => 12,
			'headers' => $headers,
			'body'    => $body,
		] );

		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'cbd_oauth_token', __( 'Could not reach the sign-in provider. Please try again.', 'community-business-directory' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$detail = is_array( $data ) ? ( $data['error_description'] ?? $data['error']['message'] ?? $data['error'] ?? '' ) : '';
			return new \WP_Error(
				'cbd_oauth_token',
				$detail
					? sprintf( /* translators: %s: provider error detail */ __( 'Sign-in failed: %s', 'community-business-directory' ), is_string( $detail ) ? $detail : 'invalid response' )
					: __( 'The sign-in provider rejected the request. Check the app credentials and redirect URI.', 'community-business-directory' )
			);
		}

		$out = [ 'access_token' => (string) $data['access_token'] ];
		if ( ! empty( $data['user_id'] ) ) {
			$out['user_id'] = (string) $data['user_id']; // Instagram returns this alongside the token.
		}
		return $out;
	}

	// ── Profile fetch + normalisation ────────────────────────────────

	/**
	 * Fetch the signed-in user's profile and normalise it.
	 *
	 * @param array{access_token:string,user_id?:string} $token
	 * @return array{id:string,email:string,name:string,given_name:string,family_name:string,username:string}|\WP_Error
	 */
	private function fetch_profile( string $provider, array $token ) {
		$access = $token['access_token'];

		switch ( $provider ) {
			case 'google':
				$res = wp_remote_get( 'https://openidconnect.googleapis.com/v1/userinfo', [
					'timeout' => 10,
					'headers' => [ 'Authorization' => 'Bearer ' . $access ],
				] );
				$b = $this->json_or_error( $res, 'Google' );
				if ( is_wp_error( $b ) ) {
					return $b;
				}
				return $this->profile(
					$b['sub'] ?? '',
					! empty( $b['email_verified'] ) ? ( $b['email'] ?? '' ) : '',
					$b['name'] ?? '',
					$b['given_name'] ?? '',
					$b['family_name'] ?? '',
					$b['email'] ?? '',
					$b['picture'] ?? '' // Google returns a direct image URL.
				);

			case 'facebook':
				$res = wp_remote_get(
					'https://graph.facebook.com/v18.0/me?fields=id,name,first_name,last_name,email,picture.width(256).height(256)&access_token=' . rawurlencode( $access ),
					[ 'timeout' => 10 ]
				);
				$b = $this->json_or_error( $res, 'Facebook' );
				if ( is_wp_error( $b ) ) {
					return $b;
				}
				return $this->profile(
					$b['id'] ?? '',
					$b['email'] ?? '',
					$b['name'] ?? '',
					$b['first_name'] ?? '',
					$b['last_name'] ?? '',
					'',
					$b['picture']['data']['url'] ?? '' // FB nests it under picture.data.url.
				);

			case 'twitter':
				$res = wp_remote_get( 'https://api.twitter.com/2/users/me?user.fields=name,username,profile_image_url', [
					'timeout' => 10,
					'headers' => [ 'Authorization' => 'Bearer ' . $access ],
				] );
				$b = $this->json_or_error( $res, 'Twitter / X' );
				if ( is_wp_error( $b ) ) {
					return $b;
				}
				$d = $b['data'] ?? [];
				// X returns the small `_normal` variant; strip the suffix for full size.
				$avatar = isset( $d['profile_image_url'] ) ? str_replace( '_normal', '', (string) $d['profile_image_url'] ) : '';
				return $this->profile( $d['id'] ?? '', '', $d['name'] ?? '', '', '', $d['username'] ?? '', $avatar );

			case 'instagram':
				$res = wp_remote_get(
					'https://graph.instagram.com/me?fields=id,username&access_token=' . rawurlencode( $access ),
					[ 'timeout' => 10 ]
				);
				$b = $this->json_or_error( $res, 'Instagram' );
				if ( is_wp_error( $b ) ) {
					return $b;
				}
				$id = $b['id'] ?? ( $token['user_id'] ?? '' );
				return $this->profile( (string) $id, '', $b['username'] ?? '', '', '', $b['username'] ?? '' );
		}

		return new \WP_Error( 'cbd_oauth_profile', __( 'Unsupported sign-in provider.', 'community-business-directory' ) );
	}

	/** Decode a remote JSON body or return a friendly WP_Error. */
	private function json_or_error( $res, string $provider_label ) {
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error(
				'cbd_oauth_profile',
				sprintf( /* translators: %s: provider name */ __( 'Could not load your %s profile.', 'community-business-directory' ), $provider_label )
			);
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'cbd_oauth_profile',
				sprintf( /* translators: %s: provider name */ __( '%s returned an unexpected response.', 'community-business-directory' ), $provider_label )
			);
		}
		return $body;
	}

	/** Build the normalised profile shape. */
	private function profile( string $id, string $email, string $name, string $given, string $family, string $username, string $avatar = '' ): array {
		return [
			'id'          => (string) $id,
			'email'       => sanitize_email( (string) $email ),
			'name'        => sanitize_text_field( (string) $name ),
			'given_name'  => sanitize_text_field( (string) $given ),
			'family_name' => sanitize_text_field( (string) $family ),
			'username'    => sanitize_text_field( (string) $username ),
			'avatar'      => esc_url_raw( (string) $avatar ),
		];
	}

	// ── Match or create the WordPress user ───────────────────────────

	/**
	 * Find an existing user (by verified email, else by stored provider id) or
	 * create a fresh one. Returns the user ID or a WP_Error.
	 *
	 * @param array{id:string,email:string,name:string,given_name:string,family_name:string,username:string} $profile
	 * @return int|\WP_Error
	 */
	private function login_or_create( string $provider, array $profile ) {
		$id_meta = 'cbd_social_' . $provider . '_id';
		$user    = null;

		if ( ! empty( $profile['email'] ) ) {
			$user = get_user_by( 'email', $profile['email'] ) ?: null;
		}
		if ( ! $user && ! empty( $profile['id'] ) ) {
			$found = get_users( [ 'meta_key' => $id_meta, 'meta_value' => $profile['id'], 'number' => 1, 'fields' => 'ID' ] );
			if ( $found ) {
				$user = get_user_by( 'id', (int) $found[0] ) ?: null;
			}
		}

		if ( $user ) {
			if ( ! empty( $profile['id'] ) ) {
				update_user_meta( $user->ID, $id_meta, $profile['id'] );
			}
			if ( ! empty( $profile['avatar'] ) ) {
				update_user_meta( $user->ID, 'cbd_social_avatar', $profile['avatar'] );
			}
			return $user->ID;
		}

		// No match — create. Synthesize an email for providers that withhold one.
		$email = $profile['email'];
		if ( ! $email ) {
			$host  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';
			$local = $provider . '_' . ( $profile['id'] ?: wp_generate_password( 10, false ) );
			$email = $local . '@users.noreply.' . $host;
		}

		$login_base = $profile['username'] ?: current( explode( '@', $email ) );
		$user_id    = wp_insert_user( [
			'user_login'   => $this->unique_login( (string) $login_base ),
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => $profile['name'] ?: $login_base,
			'first_name'   => $profile['given_name'],
			'last_name'    => $profile['family_name'],
			// Plain member until they register a business that gets approved;
			// promotion to cbd_business_owner happens in cbd_promote_business_owner().
			'role'         => 'subscriber',
		] );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'cbd_social_provider', $provider );
		if ( ! empty( $profile['id'] ) ) {
			update_user_meta( $user_id, $id_meta, $profile['id'] );
		}
		if ( ! empty( $profile['avatar'] ) ) {
			update_user_meta( $user_id, 'cbd_social_avatar', $profile['avatar'] );
		}
		return (int) $user_id;
	}

	// ── Small utilities ──────────────────────────────────────────────

	// ── Avatar integration ───────────────────────────────────────────

	/**
	 * Use a stored social profile photo as the WordPress avatar. Hooked on
	 * `pre_get_avatar_data`, which backs both get_avatar() and get_avatar_url(),
	 * so every avatar across the site (account menu, reviews, reactions) picks
	 * it up automatically. Gravatar remains the fallback for users with no photo.
	 *
	 * @param array $args        Avatar data being assembled by WordPress.
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
	 * @return array
	 */
	public function filter_avatar_data( array $args, $id_or_email ): array {
		$user_id = $this->resolve_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}
		// A photo the user uploaded on their profile wins over the social one.
		$avatar = get_user_meta( $user_id, 'cbd_custom_avatar', true )
			?: get_user_meta( $user_id, 'cbd_social_avatar', true );
		if ( $avatar ) {
			$args['url']          = $avatar;
			$args['found_avatar'] = true;
		}
		return $args;
	}

	/** Best-effort resolution of WordPress' many avatar identifiers to a user ID. */
	private function resolve_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			$u = ! empty( $id_or_email->comment_author_email ) ? get_user_by( 'email', $id_or_email->comment_author_email ) : false;
			return $u ? (int) $u->ID : 0;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u = get_user_by( 'email', $id_or_email );
			return $u ? (int) $u->ID : 0;
		}
		return 0;
	}

	private function unique_login( string $base ): string {
		$base  = sanitize_user( $base, true ) ?: 'user';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i++;
		}
		return $login;
	}

	/** RFC 7636 S256 PKCE challenge from a verifier. */
	private static function pkce_challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function default_redirect(): string {
		// Called after wp_set_current_user(), so the current user is the one
		// that just signed in. Owners → their business page; others → home.
		$user = wp_get_current_user();
		return ( $user && $user->ID ) ? \cbd_login_redirect( $user ) : home_url( '/' );
	}

	/** Abort the flow and bounce back to the login page with a visible error. */
	private function bail( string $message ): void {
		$login = (int) get_option( 'cbd_login_page_id' );
		$base  = ( $login && ( $perma = get_permalink( $login ) ) ) ? $perma : wp_login_url();
		// add_query_arg() URL-encodes the value for us — no rawurlencode here.
		wp_safe_redirect( add_query_arg( 'cbd_login_error', $message, $base ) );
		exit;
	}
}
