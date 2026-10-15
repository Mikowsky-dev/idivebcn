/*
 * Closures calendar — client facing page.
 *
 * Ported from the mockup approved on 14-oct-2026 (artifact 5d9b557b): same batching, same
 * day states, same confirmation flow. What changed is where the data comes from — the demo
 * seeded localStorage, this talks to mikowsky/v1 and treats the server as the only truth.
 */
( function () {
	'use strict';

	var CFG = window.MIKOWSKY_CIERRES || {};
	var MONTHS = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
		'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];

	var $ = function ( id ) { return document.getElementById( id ); };
	var pad = function ( n ) { return String( n ).padStart( 2, '0' ); };
	var key = function ( y, m, d ) { return y + '-' + pad( m + 1 ) + '-' + pad( d ); };

	// Today comes from the server, in the dive centre's timezone. A browser with a wrong
	// clock would otherwise grey out the wrong days, or offer one the API then rejects.
	var todayKey = CFG.today;
	var todayParts = todayKey.split( '-' ).map( Number );

	var activities = CFG.activities || [];
	var current = activities.length ? activities[ 0 ].activity : null;
	var state = {};          // activity -> { closed:Set, languages:[], warnings:[] }
	var pending = new Map(); // dateKey -> 'close' | 'open'
	var view = new Date( todayParts[ 0 ], todayParts[ 1 ] - 1, 1 );
	var busy = false;

	function api( path, options ) {
		return fetch( CFG.rest + path, Object.assign( {
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': CFG.nonce,
				'Content-Type': 'application/json'
			}
		}, options || {} ) ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					throw new Error( messageOf( body ) );
				}
				return body;
			} );
		} );
	}

	// WordPress wraps validation failures in rest_invalid_param, keeping the useful text one
	// level down in data.params. Dig for it so the client never sees "parámetro no válido".
	function messageOf( body ) {
		if ( body && body.data && body.data.params ) {
			var detail = Object.keys( body.data.params )
				.map( function ( k ) { return body.data.params[ k ]; } )
				.join( ' ' );
			if ( detail ) {
				return detail;
			}
		}
		return ( body && body.message ) || 'No se ha podido completar la operación.';
	}

	function loadActivity( activity ) {
		return api( '/calendar?activity=' + encodeURIComponent( activity ) )
			.then( function ( body ) {
				state[ activity ] = {
					closed: new Set( body.data.closed ),
					languages: body.data.languages || [],
					warnings: body.data.warnings || []
				};
			} );
	}

	function renderActs() {
		$( 'acts' ).innerHTML = activities.map( function ( a ) {
			var loaded = state[ a.activity ];
			var n = loaded ? loaded.closed.size : 0;
			var foot = ! loaded
				? '<span class="none">cargando…</span>'
				: ( n
					? '<span class="dot"></span><small>' + n + ' día' + ( n > 1 ? 's' : '' ) +
						' cerrado' + ( n > 1 ? 's' : '' ) + '</small>'
					: '<span class="none">sin cierres</span>' );

			return '<button class="act" aria-pressed="' + ( a.activity === current ? 'true' : 'false' ) +
				'" data-activity="' + esc( a.activity ) + '">' +
				'<b>' + esc( a.label ) + '</b>' +
				'<div style="margin-top:6px">' + foot + '</div></button>';
		} ).join( '' );

		Array.prototype.forEach.call( $( 'acts' ).querySelectorAll( '.act' ), function ( button ) {
			button.onclick = function () { pickAct( button.dataset.activity ); };
		} );
	}

	function esc( value ) {
		return String( value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function pickAct( activity ) {
		if ( activity === current || busy ) {
			return;
		}
		if ( pending.size && ! window.confirm( 'Tienes cambios sin aplicar. ¿Descartarlos y cambiar de actividad?' ) ) {
			return;
		}
		current = activity;
		pending.clear();
		renderActs();
		render();
	}

	function render() {
		var y = view.getFullYear();
		var m = view.getMonth();
		var loaded = state[ current ];

		$( 'month' ).textContent = MONTHS[ m ] + ' ' + y;

		var start = ( new Date( y, m, 1 ).getDay() + 6 ) % 7; // monday first
		var days = new Date( y, m + 1, 0 ).getDate();
		var html = '';
		var i;

		for ( i = 0; i < start; i++ ) {
			html += '<div class="day empty"></div>';
		}

		for ( var d = 1; d <= days; d++ ) {
			var k = key( y, m, d );
			var past = k < todayKey;
			var committed = loaded ? loaded.closed.has( k ) : false;
			var pend = pending.has( k );
			var closed = pend ? ( pending.get( k ) === 'close' ) : committed;
			var cls = 'day';

			if ( past ) { cls += ' past'; }
			if ( k === todayKey ) { cls += ' today'; }
			if ( pend ) { cls += ' pend'; } else if ( closed ) { cls += ' closed'; }

			html += '<button class="' + cls + '"' + ( past || ! loaded ? ' disabled' : '' ) +
				' data-day="' + k + '">' + d + '</button>';
		}

		$( 'grid' ).innerHTML = html;
		Array.prototype.forEach.call( $( 'grid' ).querySelectorAll( '[data-day]' ), function ( button ) {
			button.onclick = function () { toggle( button.dataset.day ); };
		} );

		$( 'prev' ).disabled = ( y < todayParts[ 0 ] ) ||
			( y === todayParts[ 0 ] && m + 1 <= todayParts[ 1 ] );

		renderWarnings();
		renderBar();
	}

	function renderWarnings() {
		var loaded = state[ current ];
		var box = $( 'warnings' );
		var list = ( loaded && loaded.warnings ) || [];

		box.hidden = ! list.length;
		box.innerHTML = list.map( function ( w ) { return esc( w ); } ).join( '<br>' );
	}

	function toggle( k ) {
		if ( k < todayKey || busy || ! state[ current ] ) {
			return;
		}
		if ( pending.has( k ) ) {
			pending.delete( k );
		} else {
			pending.set( k, state[ current ].closed.has( k ) ? 'open' : 'close' );
		}
		render();
	}

	function partition() {
		var closes = [];
		var opens = [];
		pending.forEach( function ( action, k ) {
			( action === 'close' ? closes : opens ).push( k );
		} );
		return { closes: closes.sort(), opens: opens.sort() };
	}

	function shortDate( k ) {
		var parts = k.split( '-' );
		return Number( parts[ 2 ] ) + ' ' + MONTHS[ Number( parts[ 1 ] ) - 1 ].slice( 0, 3 );
	}

	function renderBar() {
		if ( ! pending.size ) {
			$( 'bar' ).classList.remove( 'show' );
			return;
		}
		var split = partition();
		var text = '';

		if ( split.closes.length ) {
			text += '<span class="chg c">🔴 Cerrar: <b>' + split.closes.map( shortDate ).join( ', ' ) + '</b></span>';
		}
		if ( split.opens.length ) {
			text += '<span class="chg o">🟢 Reabrir: <b>' + split.opens.map( shortDate ).join( ', ' ) + '</b></span>';
		}

		$( 'summary' ).innerHTML = text;
		$( 'bar' ).classList.add( 'show' );
	}

	function openModal() {
		var activity = activities.filter( function ( a ) { return a.activity === current; } )[ 0 ];
		var split = partition();
		var langs = ( state[ current ].languages || [] ).map( function ( l ) {
			return l.toUpperCase();
		} ).join( ' · ' );

		$( 'modal-act' ).innerHTML = 'Actividad: <b>' + esc( activity.label ) + '</b>';
		$( 'modal-lines' ).innerHTML =
			split.closes.map( function ( k ) { return '<div class="c">🔴 Cerrar — ' + shortDate( k ) + '</div>'; } ).join( '' ) +
			split.opens.map( function ( k ) { return '<div class="o">🟢 Reabrir — ' + shortDate( k ) + '</div>'; } ).join( '' );
		$( 'modal-langs' ).textContent = langs;
		$( 'scrim' ).classList.add( 'show' );
	}

	function closeModal() {
		$( 'scrim' ).classList.remove( 'show' );
	}

	// One request per day: the API takes a single date, which keeps each change atomic and
	// auditable. Sent in sequence so a failure halfway leaves a state we can describe
	// exactly, instead of a pile of parallel requests with an unknown outcome.
	function apply() {
		if ( busy ) {
			return;
		}
		busy = true;
		$( 'apply' ).disabled = true;

		var entries = [];
		pending.forEach( function ( action, date ) { entries.push( { date: date, action: action } ); } );
		entries.sort( function ( a, b ) { return a.date < b.date ? -1 : 1; } );

		var done = 0;
		var notes = [];

		var step = entries.reduce( function ( chain, entry ) {
			return chain.then( function () {
				return api( '/calendar', {
					method: 'POST',
					body: JSON.stringify( {
						activity: current,
						date: entry.date,
						action: entry.action
					} )
				} ).then( function ( body ) {
					done++;
					( body.data.warnings || [] ).forEach( function ( w ) {
						if ( notes.indexOf( w ) === -1 ) { notes.push( w ); }
					} );
				} );
			} );
		}, Promise.resolve() );

		step.then( function () {
			pending.clear();
			toast( '<span class="ok">✓</span> ' + esc( plural( done ) ), 5200 );
		} ).catch( function ( error ) {
			pending.clear();
			toast( '<span class="no">✕</span> ' + esc( error.message ) +
				( done ? ' Se aplicaron ' + done + ' antes del fallo.' : '' ), 7000 );
		} ).then( function () {
			// Always reload from the server: after a partial failure the only trustworthy
			// picture of what is closed is the one the product actually holds.
			return loadActivity( current ).catch( function () {} );
		} ).then( function () {
			if ( notes.length ) {
				state[ current ].warnings = notes.concat( state[ current ].warnings || [] )
					.filter( function ( w, i, all ) { return all.indexOf( w ) === i; } );
			}
			busy = false;
			$( 'apply' ).disabled = false;
			closeModal();
			renderActs();
			render();
		} );
	}

	function plural( n ) {
		return n === 1 ? 'Un día actualizado en todos los idiomas.'
			: n + ' días actualizados en todos los idiomas.';
	}

	var toastTimer = null;
	function toast( html, ms ) {
		var el = $( 'toast' );
		el.innerHTML = html;
		el.classList.add( 'show' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () { el.classList.remove( 'show' ); }, ms || 2600 );
	}

	function fail( message ) {
		var box = $( 'warnings' );
		box.hidden = false;
		box.className = 'notice err';
		box.textContent = message;
	}

	$( 'prev' ).onclick = function () { view.setMonth( view.getMonth() - 1 ); render(); };
	$( 'next' ).onclick = function () { view.setMonth( view.getMonth() + 1 ); render(); };
	$( 'cancel' ).onclick = function () { pending.clear(); render(); };
	$( 'confirm' ).onclick = openModal;
	$( 'back' ).onclick = closeModal;
	$( 'apply' ).onclick = apply;
	$( 'scrim' ).onclick = function ( event ) {
		if ( event.target === $( 'scrim' ) ) { closeModal(); }
	};
	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) { closeModal(); }
	} );

	renderActs();
	render();

	Promise.all( activities.map( function ( a ) { return loadActivity( a.activity ); } ) )
		.then( function () { renderActs(); render(); } )
		.catch( function ( error ) { fail( 'No se ha podido cargar el calendario: ' + error.message ); } );
}() );
