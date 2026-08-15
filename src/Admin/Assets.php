<?php
declare(strict_types=1);

namespace EventTicketScanner\Admin;

use EventTicketScanner\Pairing\AjaxHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The QR pairing bundle, shared by every screen that can pair a device: the
 * Scanners screen and the Scanner App settings tab.
 */
final class Assets {

	public static function enqueue(): void {
		wp_enqueue_script( 'event-ticket-scanner-qrcode', EVENT_TICKET_SCANNER_URL . 'assets/qrcode.min.js', [], EVENT_TICKET_SCANNER_VERSION, true );
		wp_enqueue_script( 'event-ticket-scanner-admin', EVENT_TICKET_SCANNER_URL . 'assets/admin.js', [ 'event-ticket-scanner-qrcode' ], EVENT_TICKET_SCANNER_VERSION, true );
		wp_enqueue_style( 'event-ticket-scanner-admin', EVENT_TICKET_SCANNER_URL . 'assets/admin.css', [], EVENT_TICKET_SCANNER_VERSION );

		wp_localize_script(
			'event-ticket-scanner-admin',
			'eventTicketScannerAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => AjaxHandler::ACTION,
				'nonce'   => wp_create_nonce( AjaxHandler::ACTION ),
				'searchAction' => EventSearch::ACTION,
				'searchNonce'  => wp_create_nonce( EventSearch::ACTION ),
				'i18n'    => [
					'expired' => __( 'This code expired. Generate a new one.', 'event-ticket-scanner' ),
					/* translators: %s: number of seconds remaining before the pairing code expires. */
					'expires' => __( 'Code expires in %ss', 'event-ticket-scanner' ),
					'error'   => __( 'Could not generate a pairing code.', 'event-ticket-scanner' ),
				],
			]
		);
	}
}
