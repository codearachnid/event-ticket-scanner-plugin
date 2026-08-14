/* global tecScannerAdmin, QRCode */
( function () {
	'use strict';

	var qrEl = document.getElementById( 'tec-scanner-qr' );
	var statusEl = document.getElementById( 'tec-scanner-qr-status' );
	var button = document.getElementById( 'tec-scanner-generate' );

	if ( ! qrEl || ! button ) {
		return;
	}

	var qr = null;
	var countdown = null;

	function setStatus( text ) {
		statusEl.textContent = text;
	}

	function startCountdown( seconds ) {
		clearInterval( countdown );
		var remaining = seconds;

		countdown = setInterval( function () {
			remaining -= 1;

			if ( remaining <= 0 ) {
				clearInterval( countdown );
				qrEl.classList.add( 'is-expired' );
				setStatus( tecScannerAdmin.i18n.expired );
				return;
			}

			setStatus( tecScannerAdmin.i18n.expires.replace( '%s', remaining ) );
		}, 1000 );
	}

	function generate() {
		button.disabled = true;
		setStatus( '…' );

		var body = new URLSearchParams();
		body.append( 'action', tecScannerAdmin.action );
		body.append( '_wpnonce', tecScannerAdmin.nonce );

		fetch( tecScannerAdmin.ajaxUrl, {
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
					throw new Error( ( payload && payload.data && payload.data.message ) || 'failed' );
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
				qr = new QRCode( qrEl, {
					text: text,
					width: 280,
					height: 280,
					correctLevel: QRCode.CorrectLevel.M,
				} );

				startCountdown( data.expires_in );
			} )
			.catch( function () {
				setStatus( tecScannerAdmin.i18n.error );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	button.addEventListener( 'click', generate );
} )();
