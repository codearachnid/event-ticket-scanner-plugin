<?php
/**
 * Uninstall cleanup: drop plugin tables, options, and capabilities.
 *
 * @package TEC_Scanner
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Database/Schema.php';
require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Capabilities.php';

TEC_Scanner\Database\Schema::drop_tables();
TEC_Scanner\Capabilities::revoke_all();

delete_option( 'tec_scanner_version' );
delete_option( 'tec_scanner_caps_granted' );

// Expire any outstanding pairing tokens / rate counters.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%tec_scanner_pair_%'" );
