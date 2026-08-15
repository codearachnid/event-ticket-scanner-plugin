<?php
/**
 * Uninstall cleanup: drop plugin tables, options, and capabilities.
 *
 * @package EventTicketScanner
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Database/Schema.php';
require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Capabilities.php';

EventTicketScanner\Database\Schema::drop_tables();
EventTicketScanner\Capabilities::revoke_all();

delete_option( 'event_ticket_scanner_version' );
delete_option( 'event_ticket_scanner_caps_granted' );

// Expire any outstanding pairing tokens / rate counters.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%event_ticket_scanner_pair_%'" );
