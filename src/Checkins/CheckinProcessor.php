<?php
declare(strict_types=1);

namespace TEC_Scanner\Checkins;

use TEC_Scanner\Assignments;
use TEC_Scanner\Attendees\AttendeeMapper;
use TEC_Scanner\Attendees\Providers;
use TEC_Scanner\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Applies batched check-in / un-check-in operations idempotently.
 *
 * Every operation carries a client UUID (op_id). Processed op_ids and their
 * results are stored; a retried batch returns the stored result instead of
 * re-applying — safe after network failures mid-batch.
 */
final class CheckinProcessor {

	public function __construct( private AttendeeMapper $mapper ) {
	}

	/**
	 * @param array[] $operations Contract CheckinBatch operations.
	 * @return array[] Per-op results, same order.
	 */
	public function process_batch( array $operations, string $device_id ): array {
		$results = [];

		foreach ( $operations as $op ) {
			$op_id = (string) $op['op_id'];

			$stored = $this->stored_result( $op_id );

			if ( null !== $stored ) {
				$results[] = $stored;
				continue;
			}

			$result = $this->apply( $op, $device_id );

			// Terminal outcomes only — a transient `error` (e.g. provider
			// misconfiguration) or a permission denial (the operator may be
			// assigned to the event later) must stay retryable under the op_id.
			$storable = 'error' !== $result['status'] && empty( $result['_no_store'] );
			unset( $result['_no_store'] );

			if ( $storable ) {
				$this->store_result( $op_id, $result );
			}

			$results[] = $result;
		}

		return $results;
	}

	private function apply( array $op, string $device_id ): array {
		$op_id       = (string) $op['op_id'];
		$attendee_id = (int) $op['attendee_id'];
		$action      = (string) $op['action'];

		$post   = get_post( $attendee_id );
		$config = $post ? Providers::for_post_type( $post->post_type ) : null;

		if ( ! $post || ! $config ) {
			return [
				'op_id'    => $op_id,
				'status'   => 'not_found',
				'message'  => sprintf( 'No attendee with ID %d.', $attendee_id ),
				'attendee' => null,
			];
		}

		$event_id = (int) get_post_meta( $attendee_id, $config['event'], true );

		// Assignment gate, for restricted operators only: they may touch just the
		// attendees of events in their scope. Denials stay out of the idempotency
		// ledger so the op still applies if the assignment is granted afterwards.
		if ( ! Assignments::is_unrestricted() ) {
			if ( ! $event_id ) {
				// Attendee has no event to check scope against. That is a data
				// fault, not a permission one — say so, and keep it retryable.
				return $this->result(
					$op_id,
					'error',
					'This attendee is not linked to an event, so scanning permission cannot be verified.',
					$attendee_id
				);
			}

			if ( ! Assignments::current_user_can_access_event( $event_id ) ) {
				$result = $this->result( $op_id, 'not_authorized', 'You are not assigned to scan this event.', $attendee_id );

				$result['_no_store'] = true;

				return $result;
			}
		}

		$checked_in = (bool) get_post_meta( $attendee_id, $config['checkin'], true );

		if ( 'checkin' === $action ) {
			$status = Providers::normalize_status( $post->post_type, $attendee_id, $config );

			if ( 'completed' !== $status ) {
				return $this->result( $op_id, 'not_authorized', sprintf( 'Order status is %s; attendee is not eligible for check-in.', $status ), $attendee_id );
			}

			// Detected from meta BEFORE provider resolution: duplicates must
			// surface as already_checked_in even if the provider module is
			// misconfigured or disabled.
			if ( $checked_in ) {
				$row = $this->mapper->format_one( $attendee_id );

				return [
					'op_id'    => $op_id,
					'status'   => 'already_checked_in',
					'message'  => sprintf(
						'Checked in%s%s.',
						$row['checked_in_at'] ? ' at ' . $row['checked_in_at'] : '',
						$row['checked_in_by'] ? ' by ' . $row['checked_in_by'] : ''
					),
					'attendee' => $row,
				];
			}

			$provider = $this->resolve_provider( $attendee_id );

			if ( ! $provider ) {
				return $this->result( $op_id, 'error', 'Could not resolve the ticket provider for this attendee (is the provider enabled in Event Tickets settings?).', $attendee_id );
			}

			$done = $provider->checkin( $attendee_id, true, $event_id );

			if ( ! $done ) {
				return $this->result( $op_id, 'error', 'Provider refused the check-in.', $attendee_id );
			}

			$this->record_device( $attendee_id, $config['checkin'], $device_id );

			return $this->result( $op_id, 'ok', null, $attendee_id );
		}

		// uncheckin
		if ( ! $checked_in ) {
			return $this->result( $op_id, 'not_checked_in', 'Attendee was not checked in.', $attendee_id );
		}

		$provider = $this->resolve_provider( $attendee_id );

		if ( ! $provider ) {
			return $this->result( $op_id, 'error', 'Could not resolve the ticket provider for this attendee (is the provider enabled in Event Tickets settings?).', $attendee_id );
		}

		$provider->uncheckin( $attendee_id );

		return $this->result( $op_id, 'ok', null, $attendee_id );
	}

	/** @return object|null Ticket provider instance (Tribe__Tickets__Tickets subclass). */
	private function resolve_provider( int $attendee_id ) {
		if ( ! function_exists( 'tribe' ) ) {
			return null;
		}

		try {
			$data_api = tribe( 'tickets.data_api' );
			$provider = $data_api->get_ticket_provider( $attendee_id );

			return $provider ?: null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Stamp the scanning device into the check-in details meta so other
	 * devices (and wp-admin) can show who performed the check-in.
	 */
	private function record_device( int $attendee_id, string $checkin_key, string $device_id ): void {
		if ( '' === $device_id ) {
			return;
		}

		$details = get_post_meta( $attendee_id, $checkin_key . '_details', true );
		$details = is_array( $details ) ? $details : [];

		$details['device_id'] = sanitize_text_field( $device_id );
		$details['source']    = 'app';

		update_post_meta( $attendee_id, $checkin_key . '_details', $details );
	}

	private function result( string $op_id, string $status, ?string $message, int $attendee_id ): array {
		$result = [
			'op_id'    => $op_id,
			'status'   => $status,
			'attendee' => $this->mapper->format_one( $attendee_id ),
		];

		if ( null !== $message ) {
			$result['message'] = $message;
		}

		return $result;
	}

	private function stored_result( string $op_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$json = $wpdb->get_var(
			$wpdb->prepare( 'SELECT result FROM %i WHERE op_id = %s', Schema::ops_table(), $op_id )
		);

		if ( ! $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	private function store_result( string $op_id, array $result ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			Schema::ops_table(),
			[
				'op_id'      => $op_id,
				'result'     => wp_json_encode( $result ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s' ]
		);
	}
}
