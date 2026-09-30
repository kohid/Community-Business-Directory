/**
 * Community Business Directory — Instagram settings.
 *
 * Drives two buttons on the Instagram admin page:
 *   - "Refresh posts now"  → cbd_ig_refresh  (force a fresh Graph API pull)
 *   - "Detect from Facebook Page" → cbd_ig_discover (resolve the IG account ID
 *     from the linked Facebook Page and fill in the field)
 *
 * See AdminController::ajax_ig_refresh()/ajax_ig_discover() and
 * CBD\Modules\InstagramSync::refresh()/discover().
 */
( function ( $ ) {
	'use strict';

	var cfg  = window.cbdInstagram || {};
	var i18n = cfg.i18n || {};

	if ( ! $( '#cbd-ig-control, #cbd-ig-detect' ).length ) {
		return;
	}

	var $result = $( '#cbd-ig-result' );

	function show( ok, message ) {
		if ( ! $result.length ) {
			window.alert( message );
			return;
		}
		$result
			.attr( 'hidden', false )
			.removeClass( 'notice-success notice-error' )
			.addClass( ok ? 'notice-success' : 'notice-error' )
			.html( '<p>' + message + '</p>' );
	}

	// ── Refresh posts ─────────────────────────────────────────────
	var $refresh = $( '#cbd-ig-refresh' );
	var $spinner = $( '#cbd-ig-spinner' );

	$refresh.on( 'click', function () {
		if ( $refresh.prop( 'disabled' ) ) {
			return;
		}
		$refresh.prop( 'disabled', true );
		$spinner.addClass( 'is-active' );
		if ( $result.length ) {
			$result.attr( 'hidden', true );
		}

		$.post( cfg.ajaxUrl, { action: 'cbd_ig_refresh', nonce: cfg.nonce } )
			.done( function ( res ) {
				if ( res && res.success ) {
					show( true, ( res.data && res.data.message ) || 'Done.' );
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					show( false, ( res && res.data && res.data.message ) || i18n.failed || 'Failed.' );
				}
			} )
			.fail( function () {
				show( false, i18n.failed || 'Failed.' );
			} )
			.always( function () {
				$refresh.prop( 'disabled', false );
				$spinner.removeClass( 'is-active' );
			} );
	} );

	// ── Detect Instagram account from the linked Facebook Page ────
	var $detect  = $( '#cbd-ig-detect' );
	var $dspin   = $( '#cbd-ig-detect-spinner' );

	$detect.on( 'click', function () {
		if ( $detect.prop( 'disabled' ) ) {
			return;
		}
		$detect.prop( 'disabled', true );
		$dspin.addClass( 'is-active' );

		$.post( cfg.ajaxUrl, { action: 'cbd_ig_discover', nonce: cfg.nonce } )
			.done( function ( res ) {
				if ( res && res.success && res.data ) {
					if ( res.data.id ) {
						$( '#cbd-ig-id' ).val( res.data.id );
					}
					if ( res.data.username && ! $( '#cbd-ig-username' ).val() ) {
						$( '#cbd-ig-username' ).val( res.data.username );
					}
					show( true, res.data.message || 'Instagram account linked.' );
					setTimeout( function () { window.location.reload(); }, 1600 );
				} else {
					show( false, ( res && res.data && res.data.message ) || i18n.failed || 'Failed.' );
				}
			} )
			.fail( function () {
				show( false, i18n.failed || 'Failed.' );
			} )
			.always( function () {
				$detect.prop( 'disabled', false );
				$dspin.removeClass( 'is-active' );
			} );
	} );

} )( jQuery );
