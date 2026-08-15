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
