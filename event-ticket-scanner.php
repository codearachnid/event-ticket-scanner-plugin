<?php
/**
 * Plugin Name:       Event Ticket Scanner
 * Plugin URI:        https://eventticketscanner.com/
 * Description:       Companion REST API for the TEC Ticket Scanner mobile app — offline-first attendee sync, batched check-ins, and QR pairing for Event Tickets.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Timothy Wood (@codearachnid)
 * Author URI:        https://eventticketscanner.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       event-ticket-scanner
 *
 * @package EventTicketScanner
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'EVENT_TICKET_SCANNER_VERSION', '1.1.0' );
define( 'EVENT_TICKET_SCANNER_FILE', __FILE__ );
define( 'EVENT_TICKET_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVENT_TICKET_SCANNER_URL', plugin_dir_url( __FILE__ ) );

/** Minimum Event Tickets version — QR + check-in internals we rely on landed in 5.7.0. */
define( 'EVENT_TICKET_SCANNER_MIN_ET_VERSION', '5.7.0' );

// PSR-4-ish autoloader for the EventTicketScanner\ namespace (no build step needed).
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'EventTicketScanner\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'EventTicketScanner\\' ) );
		$path     = EVENT_TICKET_SCANNER_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, [ EventTicketScanner\Activation::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ EventTicketScanner\Activation::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ EventTicketScanner\Plugin::class, 'boot' ], 20 );
