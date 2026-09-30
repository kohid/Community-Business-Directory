/**
 * Community Business Directory — admin scripts.
 *
 * Demo Mode toggle: flipping the switch streams a generate or teardown run
 * through admin-ajax one "tick" at a time (one business per generate tick, a
 * batch of deletions per teardown tick) and animates a real progress bar from
 * the processed/total the server returns. See AdminController::ajax_demo_* and
 * CBD\Modules\DemoData::generate_tick()/teardown_tick().
 */
( function ( $ ) {
	'use strict';

	var cfg = window.cbdAdmin || {};
	var i18n = cfg.i18n || {};

	function el( id ) {
		return document.getElementById( id );
	}

	var $control = $( '#cbd-demo-control' );
	if ( ! $control.length ) {
		return;
	}

	var $switch  = $( '#cbd-demo-switch' );
	var running  = false;

	function setSwitch( on ) {
		$switch.toggleClass( 'is-on', on ).attr( 'aria-checked', on ? 'true' : 'false' );
		$control.attr( 'data-state', on ? 'on' : 'off' );
		$( '#cbd-demo-state-label' ).text( on ? 'Demo content is live' : 'Demo Mode is off' );
		$( '#cbd-demo-state-help' ).text( on
			? 'Flip the switch to remove all generated sample content.'
			: 'Flip the switch to generate Inverness sample content. No page reload needed.' );
	}

	function showProgress( title ) {
		el( 'cbd-demo-progress-title' ).textContent = title;
		el( 'cbd-demo-progress-pct' ).textContent   = '0%';
		el( 'cbd-demo-bar-fill' ).style.width        = '0%';
		el( 'cbd-demo-progress-detail' ).textContent = '';
		el( 'cbd-demo-progress' ).hidden = false;
		var result = el( 'cbd-demo-result' );
		result.hidden = true;
		result.className = 'cbd-demo-result notice inline';
	}

	function setProgress( processed, total, detail ) {
		// Reserve a little headroom while still working so the bar never sits at
		// 100% before the run actually finishes.
		var pct = total > 0 ? Math.min( 99, Math.round( ( processed / total ) * 100 ) ) : 5;
		el( 'cbd-demo-bar-fill' ).style.width      = pct + '%';
		el( 'cbd-demo-progress-pct' ).textContent  = pct + '%';
		if ( detail ) {
			el( 'cbd-demo-progress-detail' ).textContent = detail;
		}
	}

	function finishProgress( ok, message ) {
		el( 'cbd-demo-bar-fill' ).style.width     = '100%';
		el( 'cbd-demo-progress-pct' ).textContent = '100%';
		var result = el( 'cbd-demo-result' );
		result.hidden = false;
		result.className = 'cbd-demo-result notice inline ' + ( ok ? 'notice-success' : 'notice-error' );
		result.innerHTML = '<p>' + message + '</p>';
	}

	/**
	 * Drive one operation to completion. `action` is the admin-ajax action,
	 * `progressFn` maps a server response to [processed, total, detailText].
	 */
	function runLoop( action, title, progressFn, done ) {
		showProgress( title );

		function tick() {
			$.post( cfg.ajaxUrl, { action: action, nonce: cfg.demoNonce } )
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

	function refreshCounts() {
		// Reload so the WP-rendered toggle state + counts table reflect the DB.
		setTimeout( function () {
			window.location.reload();
		}, 1100 );
	}

	function generate() {
		running = true;
		setSwitch( true );
		runLoop(
			'cbd_demo_generate',
			i18n.generating || 'Generating demo content…',
			function ( d ) {
				var detail = ( ! d.processed && d.label )
					? d.label
					: ( i18n.creating ? i18n.creating.replace( '%s', d.label || '' ) : d.label || '' );
				return [ d.processed || 0, d.total || 0, detail ];
			},
			function ( d ) {
				var c = d.counts || {};
				if ( c.skipped ) {
					finishProgress( true, 'Demo content already exists.' );
				} else {
					finishProgress( true, ( i18n.genDone || 'Demo content generated.' ) +
						' — ' + ( c.businesses || 0 ) + ' businesses, ' + ( c.events || 0 ) + ' events, ' +
						( c.promotions || 0 ) + ' promotions, ' + ( c.posts || 0 ) + ' posts, ' +
						( c.reviews || 0 ) + ' reviews, ' + ( c.reactions || 0 ) + ' reactions, ' +
						( c.images || 0 ) + ' images.' );
				}
				refreshCounts();
			}
		);
	}

	function teardown() {
		running = true;
		setSwitch( false );
		runLoop(
			'cbd_demo_teardown',
			i18n.removing || 'Removing demo content…',
			function ( d ) {
				return [ d.processed || 0, d.total || 0, '' ];
			},
			function ( d ) {
				var r = d.removed || {};
				finishProgress( true, ( i18n.rmDone || 'Demo content removed.' ) +
					' — ' + ( r.posts || 0 ) + ' posts, ' + ( r.images || 0 ) + ' images, ' +
					( r.users || 0 ) + ' users, ' + ( r.rows || 0 ) + ' related rows.' );
				refreshCounts();
			}
		);
	}

	$switch.on( 'click', function () {
		if ( running ) {
			return;
		}
		var isOn = $switch.hasClass( 'is-on' );
		if ( isOn ) {
			// Turning off destroys content — confirm first.
			if ( ! window.confirm( i18n.confirmOff || 'Turn Demo Mode off? This deletes all generated demo content.' ) ) {
				return;
			}
			teardown();
		} else {
			generate();
		}
	} );

} )( jQuery );

/* ── Show / hide password toggle ──────────────────────────────────────────
   Adds an eye button to every secret field on the settings screens (social
   login secrets, Stripe key, SMTP password). Its own IIFE so it runs on every
   settings tab, independent of the Demo Mode control above. */
( function ( $ ) {
	'use strict';

	var EYES =
		'<svg class="cbd-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
		+ '<svg class="cbd-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20C5 20 1 12 1 12a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

	$( function () {
		$( '.cbd-admin input[type="password"]' ).each( function () {
			var input  = this;
			var $input = $( input );
			if ( $input.parent( '.cbd-pass-wrap' ).length ) {
				return;                                   // already enhanced
			}
			$input.wrap( '<span class="cbd-pass-wrap"></span>' );
			var $btn = $( '<button type="button" class="cbd-pass-toggle" aria-label="Show password" aria-pressed="false"></button>' ).html( EYES );
			$input.after( $btn );

			$btn.on( 'click', function ( e ) {
				e.preventDefault();
				var reveal = input.type === 'password';
				input.type = reveal ? 'text' : 'password';
				$btn.toggleClass( 'is-on', reveal )
					.attr( 'aria-pressed', reveal ? 'true' : 'false' )
					.attr( 'aria-label', reveal ? 'Hide password' : 'Show password' );
			} );
		} );
	} );

} )( jQuery );
