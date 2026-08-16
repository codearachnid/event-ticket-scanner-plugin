/* global eventTicketScannerAdmin, QRCode */
( function () {
	'use strict';

	var buttons = document.querySelectorAll( '[data-event-ticket-scanner-pair]' );

	if ( ! buttons.length ) {
		return;
	}

	function bind( button ) {
		var qrEl = document.getElementById( button.getAttribute( 'data-target' ) );

		if ( ! qrEl ) {
			return;
		}

		var statusEl = document.querySelector(
			'[data-status-for="' + button.getAttribute( 'data-target' ) + '"]'
		);
		var countdown = null;

		function setStatus( text ) {
			if ( statusEl ) {
				statusEl.textContent = text;
			}
		}

		function startCountdown( seconds ) {
			clearInterval( countdown );
			var remaining = seconds;

			countdown = setInterval( function () {
				remaining -= 1;

				if ( remaining <= 0 ) {
					clearInterval( countdown );
					qrEl.classList.add( 'is-expired' );
					setStatus( eventTicketScannerAdmin.i18n.expired );
					return;
				}

				setStatus( eventTicketScannerAdmin.i18n.expires.replace( '%s', remaining ) );
			}, 1000 );
		}

		var wrap = qrEl.closest( '.event-ticket-scanner-qr-wrap' );

		function generate() {
			button.disabled = true;
			setStatus( '…' );

			var body = new URLSearchParams();
			body.append( 'action', eventTicketScannerAdmin.action );
			body.append( '_wpnonce', eventTicketScannerAdmin.nonce );

			var userId = button.getAttribute( 'data-user-id' );

			if ( userId ) {
				body.append( 'user_id', userId );
			}

			fetch( eventTicketScannerAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						throw new Error(
							( payload && payload.data && payload.data.message ) || 'failed'
						);
					}

					var data = payload.data;
					var text = JSON.stringify( {
						v: data.v,
						type: data.type,
						url: data.url,
						user: data.user,
						token: data.token,
					} );

					qrEl.classList.remove( 'is-expired' );
					qrEl.innerHTML = '';

					if ( wrap ) {
						wrap.hidden = false;
					}
					new QRCode( qrEl, {
						text: text,
						width: 280,
						height: 280,
						correctLevel: QRCode.CorrectLevel.M,
					} );

					startCountdown( data.expires_in );
				} )
				.catch( function ( error ) {
					setStatus( error.message || eventTicketScannerAdmin.i18n.error );
				} )
				.finally( function () {
					button.disabled = false;
				} );
		}

		button.addEventListener( 'click', generate );
	}

	Array.prototype.forEach.call( buttons, bind );
} )();

/* Event assignment picker: chips + search, so no screen ever renders the
   whole calendar per user. */
( function () {
	'use strict';

	var pickers = document.querySelectorAll( '[data-picker]' );

	if ( ! pickers.length || typeof eventTicketScannerAdmin === 'undefined' ) {
		return;
	}

	function bind( picker ) {
		var chips = picker.querySelector( '[data-chips]' );
		var empty = picker.querySelector( '[data-empty]' );
		var body = picker.querySelector( '.event-ticket-scanner-picker-body' );
		var allNote = picker.querySelector( '[data-all-note]' );
		var search = picker.querySelector( '[data-search]' );
		var results = picker.querySelector( '[data-results]' );
		var field = search ? search.getAttribute( 'data-field' ) : '';
		var timer = null;

		function syncEmpty() {
			if ( empty ) {
				empty.hidden = !! chips.querySelector( '[data-chip]' );
			}
		}

		function has( id ) {
			return !! chips.querySelector( '[data-chip="' + id + '"]' );
		}

		function addChip( event ) {
			if ( has( event.id ) ) {
				return;
			}

			var li = document.createElement( 'li' );
			li.className = 'event-ticket-scanner-chip';
			li.setAttribute( 'data-chip', event.id );

			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = field + '[]';
			input.value = event.id;

			var title = document.createElement( 'span' );
			title.className = 'event-ticket-scanner-chip-title';
			title.textContent = event.title;

			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'event-ticket-scanner-chip-remove';
			remove.setAttribute( 'data-remove', '' );
			remove.textContent = '×';

			li.appendChild( input );
			li.appendChild( title );

			if ( event.date ) {
				var date = document.createElement( 'span' );
				date.className = 'event-ticket-scanner-chip-date';
				date.textContent = event.date;
				li.appendChild( date );
			}

			li.appendChild( remove );
			chips.appendChild( li );
			syncEmpty();
		}

		function closeResults() {
			results.innerHTML = '';
			results.hidden = true;
		}

		function renderResults( events ) {
			results.innerHTML = '';

			if ( ! events.length ) {
				results.hidden = true;
				return;
			}

			events.forEach( function ( event ) {
				if ( has( event.id ) ) {
					return;
				}

				var li = document.createElement( 'li' );
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'event-ticket-scanner-result';
				button.textContent = event.date ? event.title + ' — ' + event.date : event.title;
				button.addEventListener( 'click', function () {
					addChip( event );
					search.value = '';
					closeResults();
					search.focus();
				} );
				li.appendChild( button );
				results.appendChild( li );
			} );

			results.hidden = ! results.children.length;
		}

		function lookup() {
			var body = new URLSearchParams();
			body.append( 'action', eventTicketScannerAdmin.searchAction );
			body.append( '_wpnonce', eventTicketScannerAdmin.searchNonce );
			body.append( 'term', search.value );

			fetch( eventTicketScannerAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					renderResults( ( payload && payload.data && payload.data.events ) || [] );
				} )
				.catch( closeResults );
		}

		chips.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-remove]' );

			if ( ! button ) {
				return;
			}

			button.closest( '[data-chip]' ).remove();
			syncEmpty();
		} );

		if ( search ) {
			search.addEventListener( 'input', function () {
				clearTimeout( timer );

				if ( search.value.trim().length < 2 ) {
					closeResults();
					return;
				}

				timer = setTimeout( lookup, 250 );
			} );

			search.addEventListener( 'blur', function () {
				// Let a result click land before the list disappears.
				setTimeout( closeResults, 200 );
			} );
		}

		picker.querySelectorAll( '[data-scope]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				var all = radio.getAttribute( 'data-scope' ) === 'all' && radio.checked;

				if ( body ) {
					body.hidden = all;
				}

				if ( allNote ) {
					allNote.hidden = ! all;
				}
			} );
		} );
	}

	Array.prototype.forEach.call( pickers, bind );
} )();
