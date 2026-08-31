<?php
declare(strict_types=1);

namespace EventTicketScanner\Registration;

use EventTicketScanner\Attendees\AttendeeMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Box-office walk-up registration: create a real provider attendee (Tickets
 * Commerce or RSVP) for cash or comp payment taken at the door. Card
 * payments never come through here — the app sends those to the site's own
 * checkout so the configured gateway processes them.
 */
final class Registrar {

	public const WALKUP_META = '_event_ticket_scanner_walkup';

	public function __construct( private AttendeeMapper $mapper ) {
	}

	/**
	 * Sellable tickets for an event, both providers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function tickets_for_event( int $event_id ): array {
		$tickets = [];

		$sources = [
			// [ post_type, event meta key, provider slug ]
			[ 'tec_tc_ticket', '_tec_tickets_commerce_event', 'tickets-commerce' ],
			[ 'tribe_rsvp_tickets', '_tribe_rsvp_for_event', 'rsvp' ],
		];

		foreach ( $sources as [ $post_type, $event_key, $slug ] ) {
			$posts = get_posts(
				[
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'numberposts' => -1,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'  => [
						[
							'key'   => $event_key,
							'value' => $event_id,
						],
					],
				]
			);

			foreach ( $posts as $post ) {
				$tickets[] = [
					'id'       => (int) $post->ID,
					'name'     => (string) $post->post_title,
					'provider' => $slug,
					'price'    => (float) get_post_meta( $post->ID, '_price', true ),
				];
			}
		}

		return $tickets;
	}

	/**
	 * Create the attendee (and, for Tickets Commerce, a completed backing
	 * order) for a cash/comp walk-up. Returns the contract-shaped attendee
	 * row, or a WP_Error for a bad ticket.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function register( int $event_id, int $ticket_id, string $name, string $email, string $payment, bool $check_in, string $device_id ) {
		$ticket = get_post( $ticket_id );

		if ( ! $ticket || 'publish' !== $ticket->post_status ) {
			return new \WP_Error( 'event_ticket_scanner_ticket_not_found', __( 'Ticket not found.', 'event-ticket-scanner' ), [ 'status' => 404 ] );
		}

		$belongs = match ( $ticket->post_type ) {
			'tec_tc_ticket'       => (int) get_post_meta( $ticket_id, '_tec_tickets_commerce_event', true ) === $event_id,
			'tribe_rsvp_tickets'  => (int) get_post_meta( $ticket_id, '_tribe_rsvp_for_event', true ) === $event_id,
			default               => false,
		};

		if ( ! $belongs ) {
			return new \WP_Error( 'event_ticket_scanner_ticket_mismatch', __( 'Ticket does not belong to this event.', 'event-ticket-scanner' ), [ 'status' => 422 ] );
		}

		$attendee_id = 'tribe_rsvp_tickets' === $ticket->post_type
			? $this->create_rsvp_attendee( $event_id, $ticket_id, $name, $email, $payment )
			: $this->create_tc_attendee( $event_id, $ticket_id, $name, $email, $payment );

		if ( $check_in ) {
			$checkin_key = 'tribe_rsvp_attendees' === get_post_type( $attendee_id )
				? '_tribe_rsvp_checkedin'
				: '_tec_tickets_commerce_checked_in';

			update_post_meta( $attendee_id, $checkin_key, 1 );
			update_post_meta(
				$attendee_id,
				$checkin_key . '_details',
				[
					'date'      => current_time( 'mysql' ),
					'source'    => 'app',
					'author'    => (string) wp_get_current_user()->user_login,
					'device_id' => $device_id,
				]
			);
		}

		return $this->mapper->format_one( $attendee_id );
	}

	private function create_tc_attendee( int $event_id, int $ticket_id, string $name, string $email, string $payment ): int {
		$order_id = (int) wp_insert_post(
			[
				'post_type'   => 'tec_tc_order',
				'post_status' => 'tec-tc-completed',
				'post_title'  => sprintf( 'Walk-up (%s)', $payment ),
				'meta_input'  => [ self::WALKUP_META => $payment ],
			]
		);

		return (int) wp_insert_post(
			[
				'post_type'   => 'tec_tc_attendee',
				'post_status' => 'publish',
				'post_title'  => $name,
				// Tickets Commerce resolves the backing order via post_parent.
				'post_parent' => $order_id,
				'meta_input'  => [
					self::WALKUP_META                     => $payment,
					'_tec_tickets_commerce_event'         => $event_id,
					'_tec_tickets_commerce_ticket'        => $ticket_id,
					'_tec_tickets_commerce_order'         => $order_id,
					'_tec_tickets_commerce_security_code' => self::security_code(),
					'_tec_tickets_commerce_status'        => 'completed',
					'_tec_tickets_commerce_full_name'     => $name,
					'_tec_tickets_commerce_email'         => $email,
					'_tribe_tickets_full_name'            => $name,
					'_tribe_tickets_email'                => $email,
				],
			]
		);
	}

	private function create_rsvp_attendee( int $event_id, int $ticket_id, string $name, string $email, string $payment ): int {
		return (int) wp_insert_post(
			[
				'post_type'   => 'tribe_rsvp_attendees',
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => [
					self::WALKUP_META           => $payment,
					'_tribe_rsvp_event'         => $event_id,
					'_tribe_rsvp_product'       => $ticket_id,
					'_tribe_rsvp_security_code' => self::security_code(),
					'_tribe_rsvp_status'        => 'yes',
					'_tribe_rsvp_full_name'     => $name,
					'_tribe_rsvp_email'         => $email,
				],
			]
		);
	}

	private static function security_code(): string {
		return substr( md5( wp_generate_uuid4() ), 0, 8 );
	}
}
