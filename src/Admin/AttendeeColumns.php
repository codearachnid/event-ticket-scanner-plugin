<?php
declare(strict_types=1);

namespace EventTicketScanner\Admin;

use EventTicketScanner\Attendees\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Checked in by" column to Event Tickets' attendee report
 * (Tickets → Attendees), reading the device stamp the scanner app's
 * check-ins write into the `{checkin_key}_details` meta. Lets organizers
 * validate later which device (door / lane / staff phone) admitted whom.
 */
final class AttendeeColumns {

	public function register_hooks(): void {
		add_filter( 'tribe_tickets_attendee_table_columns', [ $this, 'add_column' ] );
		add_filter( 'tribe_events_tickets_attendees_table_column', [ $this, 'render_column' ], 10, 3 );
	}

	public function add_column( array $columns ): array {
		$columns['event_ticket_scanner_device'] = __( 'Checked in by', 'event-ticket-scanner' );

		return $columns;
	}

	/**
	 * @param mixed $value  Current cell value.
	 * @param array $item   Attendee row (has attendee_id + provider info).
	 * @param string $column Column key.
	 * @return mixed
	 */
	public function render_column( $value, $item, $column ) {
		if ( 'event_ticket_scanner_device' !== $column ) {
			return $value;
		}

		$attendee_id = (int) ( $item['attendee_id'] ?? $item['ID'] ?? 0 );

		if ( ! $attendee_id ) {
			return '—';
		}

		$post = get_post( $attendee_id );
		$map  = Providers::map();

		if ( ! $post || empty( $map[ $post->post_type ]['checkin'] ) ) {
			return '—';
		}

		$details = get_post_meta( $attendee_id, $map[ $post->post_type ]['checkin'] . '_details', true );

		if ( ! is_array( $details ) ) {
			return '—';
		}

		$device = (string) ( $details['device_id'] ?? '' );
		$when   = (string) ( $details['date'] ?? '' );

		if ( '' === $device ) {
			return '—';
		}

		return esc_html( $when ? sprintf( '%s · %s', $device, $when ) : $device );
	}
}
