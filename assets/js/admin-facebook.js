/**
 * Community Business Directory — Facebook Page settings.
 *
 * Drives the "Refresh posts now" button on the Facebook admin page: a single
 * admin-ajax call (cbd_fb_refresh) that forces a fresh Graph API pull and
 * reports how many posts were synced. See AdminController::ajax_fb_refresh()
 * and CBD\Modules\FacebookSync::refresh().
 */
( function ( $ ) {
	'use strict';

	var cfg  = window.cbdFacebook || {};
	var i18n = cfg.i18n || {};

	var $btn = $( '#cbd-fb-refresh' );
	if ( ! $btn.length ) {
		return;
	}

	var $spinner = $( '#cbd-fb-spinner' );
	var $result  = $( '#cbd-fb-result' );

	function show( ok, message ) {
		$result
			.attr( 'hidden', false )
			.removeClass( 'notice-success notice-error' )
			.addClass( ok ? 'notice-success' : 'notice-error' )
			.html( '<p>' + message + '</p>' );
	}

	$btn.on( 'click', function () {
		if ( $btn.prop( 'disabled' ) ) {
			return;
		}
		$btn.prop( 'disabled', true );
		$spinner.addClass( 'is-active' );
		$result.attr( 'hidden', true );

		$.post( cfg.ajaxUrl, { action: 'cbd_fb_refresh', nonce: cfg.nonce } )
			.done( function ( res ) {
				if ( res && res.success ) {
					show( true, ( res.data && res.data.message ) || 'Done.' );
					// Reflect the new counts/last-sync in the status table.
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					show( false, ( res && res.data && res.data.message ) || i18n.failed || 'Failed.' );
				}
			} )
			.fail( function () {
				show( false, i18n.failed || 'Failed.' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
	} );

} )( jQuery );
