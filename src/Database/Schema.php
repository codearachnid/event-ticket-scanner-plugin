<?php
declare(strict_types=1);

namespace TEC_Scanner\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function touch_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tec_scanner_touch';
	}

	public static function ops_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'tec_scanner_ops';
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$touch           = self::touch_table();
		$ops             = self::ops_table();

		// Touch index: check-ins only write postmeta and never bump
		// post_modified, so delta sync needs its own last-modified record.
		// DATETIME(6) keeps microsecond precision for cursor comparisons.
		dbDelta(
			"CREATE TABLE {$touch} (
				attendee_id BIGINT UNSIGNED NOT NULL,
				event_id BIGINT UNSIGNED NOT NULL,
				touched_at DATETIME(6) NOT NULL,
				PRIMARY KEY  (attendee_id),
				KEY event_touched (event_id, touched_at)
			) {$charset_collate};"
		);

		// Idempotency ledger: processed op_ids with their stored results, so
		// retried batches return identical outcomes instead of re-applying.
		dbDelta(
			"CREATE TABLE {$ops} (
				op_id CHAR(36) NOT NULL,
				result LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (op_id),
				KEY created_at (created_at)
			) {$charset_collate};"
		);
	}

	public static function drop_tables(): void {
		global $wpdb;

		foreach ( [ self::touch_table(), self::ops_table() ] as $table ) {
			// Dropping our own tables on uninstall is the point of this method.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
