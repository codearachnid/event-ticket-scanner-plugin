<?php
declare(strict_types=1);

namespace EventTicketScanner;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public static function activate(): void {
		Database\Schema::create_tables();
		Capabilities::grant();

		update_option( 'event_ticket_scanner_version', EVENT_TICKET_SCANNER_VERSION, false );

		// Flush so the REST routes are immediately reachable.
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
