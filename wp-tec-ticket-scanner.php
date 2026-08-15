<?php
/**
 * Plugin Name:       Event Ticket Scanner
 * Plugin URI:        https://github.com/codearachnid/wp-tec-ticket-scanner
 * Description:       Companion REST API for the TEC Ticket Scanner mobile app — offline-first attendee sync, batched check-ins, and QR pairing for Event Tickets.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Timothy Wood (@codearachnid)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-tec-ticket-scanner
 *
 * @package TEC_Scanner
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'TEC_SCANNER_VERSION', '1.1.0' );
define( 'TEC_SCANNER_FILE', __FILE__ );
define( 'TEC_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEC_SCANNER_URL', plugin_dir_url( __FILE__ ) );

/** Minimum Event Tickets version — QR + check-in internals we rely on landed in 5.7.0. */
define( 'TEC_SCANNER_MIN_ET_VERSION', '5.7.0' );

// PSR-4-ish autoloader for the TEC_Scanner\ namespace (no build step needed).
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'TEC_Scanner\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'TEC_Scanner\\' ) );
		$path     = TEC_SCANNER_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, [ TEC_Scanner\Activation::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ TEC_Scanner\Activation::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ TEC_Scanner\Plugin::class, 'boot' ], 20 );
