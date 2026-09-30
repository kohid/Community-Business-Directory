<?php
/**
 * Job (vacancy) Custom Post Type.
 *
 * A lightweight domain object: like cbd_business_post it stores its structured
 * fields in POST META rather than a parallel custom table, so no schema change /
 * CBD_DB_VERSION bump is needed. The public listing page is the [cbd_jobs]
 * shortcode; single vacancies render via templates/single-cbd_job.php.
 *
 * Admins create/edit jobs through the native CPT screens (nested under the
 * Community Directory menu) plus the meta box defined here.
 *
 * @package CBD\PostTypes
 */

namespace CBD\PostTypes;

defined( 'ABSPATH' ) || exit;

class Job {

	// Post-meta keys.
	public const M_COMPANY   = '_cbd_job_company';
	public const M_LOCATION  = '_cbd_job_location';
	public const M_TYPE      = '_cbd_job_type';       // key into types()
	public const M_WORKPLACE = '_cbd_job_workplace';  // key into workplaces()
	public const M_SALARY    = '_cbd_job_salary';
	public const M_CLOSING   = '_cbd_job_closing';    // Y-m-d
	public const M_APPLY_URL = '_cbd_job_apply_url';
	public const M_APPLY_EML = '_cbd_job_apply_email';
	public const M_FEATURED  = '_cbd_job_featured';   // '1' | '0'

	// ── Registration ──────────────────────────────────────────────

	public function register(): void {
		register_post_type(
			'cbd_job',
			[
				'labels'       => [
					'name'               => __( 'Jobs',            'community-business-directory' ),
					'singular_name'      => __( 'Job',             'community-business-directory' ),
					'add_new'            => __( 'Add New',         'community-business-directory' ),
					'add_new_item'       => __( 'Add New Job',     'community-business-directory' ),
					'edit_item'          => __( 'Edit Job',        'community-business-directory' ),
					'new_item'           => __( 'New Job',         'community-business-directory' ),
					'view_item'          => __( 'View Job',        'community-business-directory' ),
					'search_items'       => __( 'Search Jobs',     'community-business-directory' ),
					'not_found'          => __( 'No jobs found.',  'community-business-directory' ),
					'not_found_in_trash' => __( 'No jobs found in Trash.', 'community-business-directory' ),
					'menu_name'          => __( 'Jobs',            'community-business-directory' ),
				],
				'public'       => true,
				'show_ui'      => true,
				// Nested under the Community Directory top-level menu.
				'show_in_menu' => 'cbd-dashboard',
				'show_in_rest' => true,
				// Archive stays off: the public listing is the [cbd_jobs] page at
				// /jobs/. The singular 'job' rewrite keeps single vacancies at
				// /job/<slug>/ without shadowing that page.
				'has_archive'  => false,
				'rewrite'      => [ 'slug' => 'job', 'with_front' => false ],
				'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
				'menu_icon'    => 'dashicons-businessperson',
			]
		);
	}

	// ── Vocabulary ────────────────────────────────────────────────

	/** @return array<string,string> value => label */
	public static function types(): array {
		return [
			'full-time'      => __( 'Full-time',      'community-business-directory' ),
			'part-time'      => __( 'Part-time',      'community-business-directory' ),
			'contract'       => __( 'Contract',       'community-business-directory' ),
			'temporary'      => __( 'Temporary',      'community-business-directory' ),
			'internship'     => __( 'Internship',     'community-business-directory' ),
			'apprenticeship' => __( 'Apprenticeship', 'community-business-directory' ),
			'volunteer'      => __( 'Volunteer',      'community-business-directory' ),
		];
	}

	/** @return array<string,string> value => label */
	public static function workplaces(): array {
		return [
			'onsite' => __( 'On-site', 'community-business-directory' ),
			'hybrid' => __( 'Hybrid',  'community-business-directory' ),
			'remote' => __( 'Remote',  'community-business-directory' ),
		];
	}

	public static function type_label( string $key ): string {
		return self::types()[ $key ] ?? '';
	}

	public static function workplace_label( string $key ): string {
		return self::workplaces()[ $key ] ?? '';
	}

	// ── Data accessors ────────────────────────────────────────────

	/**
	 * Normalised job meta for a post.
	 *
	 * @return array{company:string,location:string,type:string,workplace:string,salary:string,closing:string,apply_url:string,apply_email:string,featured:bool}
	 */
	public static function get_meta( int $post_id ): array {
		return [
			'company'     => (string) get_post_meta( $post_id, self::M_COMPANY, true ),
			'location'    => (string) get_post_meta( $post_id, self::M_LOCATION, true ),
			'type'        => (string) get_post_meta( $post_id, self::M_TYPE, true ),
			'workplace'   => (string) get_post_meta( $post_id, self::M_WORKPLACE, true ),
			'salary'      => (string) get_post_meta( $post_id, self::M_SALARY, true ),
			'closing'     => (string) get_post_meta( $post_id, self::M_CLOSING, true ),
			'apply_url'   => (string) get_post_meta( $post_id, self::M_APPLY_URL, true ),
			'apply_email' => (string) get_post_meta( $post_id, self::M_APPLY_EML, true ),
			'featured'    => (string) get_post_meta( $post_id, self::M_FEATURED, true ) === '1',
		];
	}

	/** The best "apply" link for a job: URL, else a mailto, else '' (use the single page). */
	public static function apply_link( array $meta, string $job_title = '' ): string {
		if ( ! empty( $meta['apply_url'] ) ) {
			return esc_url( $meta['apply_url'] );
		}
		if ( ! empty( $meta['apply_email'] ) && is_email( $meta['apply_email'] ) ) {
			$subject = $job_title !== '' ? rawurlencode( 'Application: ' . $job_title ) : '';
			return 'mailto:' . antispambot( $meta['apply_email'] ) . ( $subject ? '?subject=' . $subject : '' );
		}
		return '';
	}

	/** True when the closing date is set and in the past (site-local date compare). */
	public static function is_expired( array $meta ): bool {
		if ( empty( $meta['closing'] ) ) {
			return false;
		}
		return $meta['closing'] < current_time( 'Y-m-d' );
	}

	// ── Admin meta box ────────────────────────────────────────────

	public function add_meta_box(): void {
		add_meta_box(
			'cbd_job_details',
			__( 'Job Details', 'community-business-directory' ),
			[ $this, 'render_meta_box' ],
			'cbd_job',
			'normal',
			'high'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		$m = self::get_meta( $post->ID );
		wp_nonce_field( 'cbd_job_meta', 'cbd_job_meta_nonce' );

		$row = static function ( string $label, string $field ): void {
			echo '<tr><th scope="row" style="text-align:left;width:180px;"><label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
		};
		echo '<table class="form-table" role="presentation"><tbody>';

		$row( __( 'Company', 'community-business-directory' ), 'cbd_job_company' );
		echo '<input type="text" id="cbd_job_company" name="cbd_job_company" class="regular-text" value="' . esc_attr( $m['company'] ) . '"></td></tr>';

		$row( __( 'Location', 'community-business-directory' ), 'cbd_job_location' );
		echo '<input type="text" id="cbd_job_location" name="cbd_job_location" class="regular-text" placeholder="Inverness" value="' . esc_attr( $m['location'] ) . '"></td></tr>';

		$row( __( 'Employment type', 'community-business-directory' ), 'cbd_job_type' );
		echo '<select id="cbd_job_type" name="cbd_job_type">';
		foreach ( self::types() as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $m['type'], $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		$row( __( 'Workplace', 'community-business-directory' ), 'cbd_job_workplace' );
		echo '<select id="cbd_job_workplace" name="cbd_job_workplace">';
		foreach ( self::workplaces() as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $m['workplace'], $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		$row( __( 'Salary', 'community-business-directory' ), 'cbd_job_salary' );
		echo '<input type="text" id="cbd_job_salary" name="cbd_job_salary" class="regular-text" placeholder="£24,000 – £28,000 a year" value="' . esc_attr( $m['salary'] ) . '"></td></tr>';

		$row( __( 'Closing date', 'community-business-directory' ), 'cbd_job_closing' );
		echo '<input type="date" id="cbd_job_closing" name="cbd_job_closing" value="' . esc_attr( $m['closing'] ) . '"></td></tr>';

		$row( __( 'Apply URL', 'community-business-directory' ), 'cbd_job_apply_url' );
		echo '<input type="url" id="cbd_job_apply_url" name="cbd_job_apply_url" class="regular-text" placeholder="https://…" value="' . esc_attr( $m['apply_url'] ) . '">';
		echo '<p class="description">' . esc_html__( 'External application link. Leave blank to use the apply email instead.', 'community-business-directory' ) . '</p></td></tr>';

		$row( __( 'Apply email', 'community-business-directory' ), 'cbd_job_apply_email' );
		echo '<input type="email" id="cbd_job_apply_email" name="cbd_job_apply_email" class="regular-text" placeholder="jobs@example.com" value="' . esc_attr( $m['apply_email'] ) . '"></td></tr>';

		echo '<tr><th scope="row" style="text-align:left;">' . esc_html__( 'Featured', 'community-business-directory' ) . '</th><td>';
		echo '<label><input type="checkbox" name="cbd_job_featured" value="1"' . checked( $m['featured'], true, false ) . '> ' . esc_html__( 'Highlight this job in listings', 'community-business-directory' ) . '</label></td></tr>';

		echo '</tbody></table>';
	}

	/** Persist the meta box fields. Hooked to save_post_cbd_job. */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST['cbd_job_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cbd_job_meta_nonce'] ) ), 'cbd_job_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = sanitize_key( wp_unslash( $_POST['cbd_job_type'] ?? '' ) );
		$wp   = sanitize_key( wp_unslash( $_POST['cbd_job_workplace'] ?? '' ) );

		update_post_meta( $post_id, self::M_COMPANY,   sanitize_text_field( wp_unslash( $_POST['cbd_job_company'] ?? '' ) ) );
		update_post_meta( $post_id, self::M_LOCATION,  sanitize_text_field( wp_unslash( $_POST['cbd_job_location'] ?? '' ) ) );
		update_post_meta( $post_id, self::M_TYPE,      isset( self::types()[ $type ] ) ? $type : 'full-time' );
		update_post_meta( $post_id, self::M_WORKPLACE, isset( self::workplaces()[ $wp ] ) ? $wp : 'onsite' );
		update_post_meta( $post_id, self::M_SALARY,    sanitize_text_field( wp_unslash( $_POST['cbd_job_salary'] ?? '' ) ) );

		$closing = sanitize_text_field( wp_unslash( $_POST['cbd_job_closing'] ?? '' ) );
		update_post_meta( $post_id, self::M_CLOSING, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $closing ) ? $closing : '' );

		update_post_meta( $post_id, self::M_APPLY_URL, esc_url_raw( trim( (string) wp_unslash( $_POST['cbd_job_apply_url'] ?? '' ) ) ) );
		update_post_meta( $post_id, self::M_APPLY_EML, sanitize_email( wp_unslash( $_POST['cbd_job_apply_email'] ?? '' ) ) );
		update_post_meta( $post_id, self::M_FEATURED,  empty( $_POST['cbd_job_featured'] ) ? '0' : '1' );
	}
}
