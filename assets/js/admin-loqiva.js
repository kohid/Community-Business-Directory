/**
 * Community Business Directory — Love Inverness importer.
 *
 * Drives the Sync Now / Remove buttons on the "Love Inverness" admin page,
 * streaming an import or removal run through admin-ajax one "tick" at a time and
 * animating a progress bar from the processed/total the server returns. See
 * AdminController::ajax_loqiva_* and CBD\Modules\LoqivaSync::sync_tick()/teardown_tick().
 */
( function ( $ ) {
	'use strict';

	var cfg  = window.cbdLoqiva || {};
	var i18n = cfg.i18n || {};

	var $control = $( '#cbd-loqiva-control' );
	if ( ! $control.length ) {
		return;
	}

	var running = false;

	function el( id ) {
		return document.getElementById( id );
	}

	function showProgress( title ) {
		el( 'cbd-loqiva-progress-title' ).textContent = title;
		el( 'cbd-loqiva-progress-pct' ).textContent   = '0%';
		el( 'cbd-loqiva-bar-fill' ).style.width        = '0%';
		el( 'cbd-loqiva-progress-detail' ).textContent = '';
		el( 'cbd-loqiva-progress' ).hidden = false;
		var result = el( 'cbd-loqiva-result' );
		result.hidden = true;
		result.className = 'cbd-demo-result notice inline';
	}

	function setProgress( processed, total, detail ) {
		var pct = total > 0 ? Math.min( 99, Math.round( ( processed / total ) * 100 ) ) : 5;
		el( 'cbd-loqiva-bar-fill' ).style.width     = pct + '%';
		el( 'cbd-loqiva-progress-pct' ).textContent = pct + '%';
		if ( detail ) {
			el( 'cbd-loqiva-progress-detail' ).textContent = detail;
		}
	}

	function finishProgress( ok, message ) {
		el( 'cbd-loqiva-bar-fill' ).style.width     = '100%';
		el( 'cbd-loqiva-progress-pct' ).textContent = '100%';
		var result = el( 'cbd-loqiva-result' );
		result.hidden = false;
		result.className = 'cbd-demo-result notice inline ' + ( ok ? 'notice-success' : 'notice-error' );
		result.innerHTML = '<p>' + message + '</p>';
	}

	function runLoop( action, title, progressFn, done ) {
		showProgress( title );

		function tick() {
			$.post( cfg.ajaxUrl, { action: action, nonce: cfg.nonce } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						running = false;
						finishProgress( false, i18n.failed || 'Something went wrong.' );
						return;
					}
					var data = res.data || {};
					var p = progressFn( data );
					setProgress( p[0], p[1], p[2] );

					if ( data.done ) {
						running = false;
						done( data );
					} else {
						tick();
					}
				} )
				.fail( function () {
					running = false;
					finishProgress( false, i18n.failed || 'Something went wrong.' );
				} );
		}

		tick();
	}

	function reload() {
		setTimeout( function () {
			window.location.reload();
		}, 1300 );
	}

	function sync() {
		running = true;
		runLoop(
			'cbd_loqiva_sync',
			i18n.syncing || 'Importing…',
			function ( d ) {
				var detail = ( ! d.processed && d.label )
					? d.label
					: ( i18n.importing ? i18n.importing.replace( '%s', d.label || '' ) : d.label || '' );
				return [ d.processed || 0, d.total || 0, detail ];
			},
			function ( d ) {
				var c = d.counts || {};
				if ( c.error ) {
					finishProgress( false, i18n.failed || 'Could not reach the feeds.' );
					return;
				}
				finishProgress( true, ( i18n.syncDone || 'Import complete.' ) +
					' — ' + ( c.businesses || 0 ) + ' businesses, ' +
					( c.events || 0 ) + ' events, ' +
					( c.promotions || 0 ) + ' offers.' );
				reload();
			}
		);
	}

	function remove() {
		running = true;
		runLoop(
			'cbd_loqiva_teardown',
			i18n.removing || 'Removing…',
			function ( d ) {
				return [ d.processed || 0, d.total || 0, '' ];
			},
			function ( d ) {
				var r = d.removed || {};
				finishProgress( true, ( i18n.rmDone || 'Removed.' ) + ' — ' + ( r.posts || 0 ) + ' listings.' );
				reload();
			}
		);
	}

	$( '#cbd-loqiva-sync' ).on( 'click', function () {
		if ( ! running ) {
			sync();
		}
	} );

	$( '#cbd-loqiva-remove' ).on( 'click', function () {
		if ( running ) {
			return;
		}
		if ( window.confirm( i18n.confirmRm || 'Remove all imported listings?' ) ) {
			remove();
		}
	} );

} )( jQuery );
