/**
 * Community Business Directory — standalone Demo Jobs controls.
 *
 * Renders on the native Jobs (cbd_job) list screen. Generate / Remove demo
 * vacancies without touching full Demo Mode, via admin-ajax. See
 * AdminController::render_jobs_demo_panel() / ajax_jobs_demo_generate() /
 * ajax_jobs_demo_remove().
 */
( function ( $ ) {
	'use strict';

	var cfg  = window.cbdJobsDemo || {};
	var i18n = cfg.i18n || {};

	var $panel = $( '#cbd-jobs-demo' );
	if ( ! $panel.length ) {
		return;
	}

	var $gen     = $( '#cbd-jobs-demo-generate' );
	var $rm      = $( '#cbd-jobs-demo-remove' );
	var $spinner = $( '#cbd-jobs-demo-spinner' );
	var $result  = $( '#cbd-jobs-demo-result' );

	function busy( on ) {
		$gen.prop( 'disabled', on );
		$rm.prop( 'disabled', on );
		$spinner.toggleClass( 'is-active', on );
	}

	function show( ok, message ) {
		$result
			.attr( 'hidden', false )
			.removeClass( 'notice-success notice-error' )
			.addClass( ok ? 'notice-success' : 'notice-error' )
			.html( '<p>' + message + '</p>' );
	}

	function run( action ) {
		busy( true );
		$result.attr( 'hidden', true );

		$.post( cfg.ajaxUrl, { action: action, nonce: cfg.nonce } )
			.done( function ( res ) {
				if ( res && res.success ) {
					show( true, ( res.data && res.data.message ) || 'Done.' );
					// Reload so the list table + counts reflect the change.
					setTimeout( function () { window.location.reload(); }, 1000 );
				} else {
					show( false, ( res && res.data && res.data.message ) || i18n.failed || 'Failed.' );
					busy( false );
				}
			} )
			.fail( function () {
				show( false, i18n.failed || 'Failed.' );
				busy( false );
			} );
	}

	$gen.on( 'click', function () {
		if ( ! $gen.prop( 'disabled' ) ) {
			run( 'cbd_jobs_demo_generate' );
		}
	} );

	$rm.on( 'click', function () {
		if ( $rm.prop( 'disabled' ) ) {
			return;
		}
		if ( window.confirm( i18n.confirmRm || 'Remove all demo jobs?' ) ) {
			run( 'cbd_jobs_demo_remove' );
		}
	} );

} )( jQuery );
