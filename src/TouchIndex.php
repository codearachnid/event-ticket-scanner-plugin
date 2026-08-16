<?php
declare(strict_types=1);

namespace EventTicketScanner;

use EventTicketScanner\Attendees\Providers;
use EventTicketScanner\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains a per-attendee last-modified index. Event Tickets check-ins only
 * write postmeta (post_modified never changes), so the REST `updated_since`
 * delta sync is driven by this table instead.
 */
final class TouchIndex {

	public function register_hooks(): void {
		// Check-in state changes (all providers route through these actions).
		add_action( 'event_tickets_checkin', [ $this, 'on_checkin' ], 10, 3 );
		add_action( 'event_tickets_uncheckin', [ $this, 'on_uncheckin' ], 10, 1 );
		add_action( 'rsvp_checkin', [ $this, 'on_checkin' ], 10, 2 );
		add_action( 'rsvp_uncheckin', [ $this, 'on_uncheckin' ], 10, 1 );

		// Attendee create/update/delete.
		foreach ( Providers::attendee_post_types() as $post_type ) {
			add_action( "save_post_{$post_type}", [ $this, 'on_save' ], 10, 1 );
		}
		add_action( 'before_delete_post', [ $this, 'on_delete' ], 10, 2 );
	}

	/**
	 * @param int|string $attendee_id Attendee post ID.
	 * @param mixed      $qr          Unused (hook signature).
	 * @param mixed      $event_id    Event ID when the caller provides one.
	 */
	public function on_checkin( $attendee_id, $qr = null, $event_id = null ): void {
		$this->touch( (int) $attendee_id, is_numeric( $event_id ) ? (int) $event_id : null );
	}

	/** @param int|string $attendee_id Attendee post ID. */
	public function on_uncheckin( $attendee_id ): void {
		$this->touch( (int) $attendee_id );
	}

	/** @param int|string $post_id Attendee post ID. */
	public function on_save( $post_id ): void {
		$this->touch( (int) $post_id );
	}

	/**
	 * @param int|string $post_id Post being deleted.
	 * @param \WP_Post|null $post The post object.
	 */
	public function on_delete( $post_id, $post = null ): void {
		$post = $post ?: get_post( (int) $post_id );

		if ( ! $post || ! in_array( $post->post_type, Providers::attendee_post_types(), true ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::touch_table(), [ 'attendee_id' => (int) $post_id ], [ '%d' ] );
	}

	public function touch( int $attendee_id, ?int $event_id = null ): void {
		if ( $attendee_id <= 0 ) {
			return;
		}

		if ( null === $event_id ) {
			$event_id = Providers::event_id_for_attendee( $attendee_id );
		}

		if ( null === $event_id ) {
			return; // Not a (recognizable) attendee post.
		}

		global $wpdb;

		$now = self::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (attendee_id, event_id, touched_at)
				 VALUES (%d, %d, %s)
				 ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), touched_at = VALUES(touched_at)',
				Schema::touch_table(),
				$attendee_id,
				$event_id,
				$now
			)
		);
	}

	/**
	 * Bulk-fetch touch times for a set of attendee IDs.
	 *
	 * @param int[] $attendee_ids Attendee post IDs.
	 * @return array<int, string> attendee_id => touched_at (MySQL DATETIME(6), UTC).
	 */
	public function times_for( array $attendee_ids ): array {
		if ( ! $attendee_ids ) {
			return [];
		}

		global $wpdb;

		$ids = array_map( 'intval', $attendee_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT attendee_id, touched_at FROM %i WHERE attendee_id IN ('
					. implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( [ Schema::touch_table() ], $ids )
			),
			ARRAY_A
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['attendee_id'] ] = (string) $row['touched_at'];
		}

		return $map;
	}

	/** Current UTC time with microseconds, MySQL DATETIME(6) format. */
	public static function now(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' );
	}
}
