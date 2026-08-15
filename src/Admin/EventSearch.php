<?php
declare(strict_types=1);

namespace EventTicketScanner\Admin;

use EventTicketScanner\Assignments;
use EventTicketScanner\Plugin;

defined( 'ABSPATH' ) || exit;

/** Admin-ajax event lookup behind the assignment picker's search box. */
final class EventSearch {

	public const ACTION = 'event_ticket_scanner_search_events';

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		check_ajax_referer( self::ACTION );

		if ( ! current_user_can( Plugin::CAP_MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to assign scanners.', 'event-ticket-scanner' ) ], 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		wp_send_json_success( [ 'events' => Assignments::search_events( $term ) ] );
	}
}
