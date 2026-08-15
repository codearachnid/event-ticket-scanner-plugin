<?php
declare(strict_types=1);

namespace TEC_Scanner\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * `wp event-ticket-scanner seed` — create a deterministic test event with tickets and
 * attendees for exercising the scanner API. Everything it creates is tagged
 * with `_tec_scanner_seed` meta so `--fresh` can wipe and re-create.
 *
 * Attendee posts are written with the exact provider meta keys the plugin
 * (and Event Tickets itself) reads, covering the contract's edge cases:
 * checked-in, refunded, pending, and RSVP "not going".
 */
final class SeedCommand {

	private const SEED_META = '_tec_scanner_seed';

	/**
	 * Seed the test event.
	 *
	 * ## OPTIONS
	 *
	 * [--attendees=<count>]
	 * : Tickets Commerce attendees to create (plus 5 RSVP). Default 20.
	 *
	 * [--fresh]
	 * : Delete previously-seeded content first.
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['fresh'] ) ) {
			$this->wipe();
		}

		$count = max( 1, (int) ( $assoc_args['attendees'] ?? 20 ) );

		$event_id = $this->create_event();
		$ga_id    = $this->create_ticket( $event_id, 'General Admission' );
		$vip_id   = $this->create_ticket( $event_id, 'VIP' );
		$rsvp_id  = $this->create_rsvp_ticket( $event_id );

		// Real tec_tc_order posts: Event Tickets 5.29.2+ validates the
		// backing order's status before allowing a check-in.
		$orders = [
			'completed' => $this->create_order( 'tec-tc-completed' ),
			'refunded'  => $this->create_order( 'tec-tc-refunded' ),
			'pending'   => $this->create_order( 'tec-tc-pending' ),
		];

		$made = 0;

		for ( $i = 1; $i <= $count; $i++ ) {
			$ticket_id = ( $i % 4 === 0 ) ? $vip_id : $ga_id;

			// Deterministic case mix: #3 refunded, #6 pending, every 5th checked in.
			$status     = match ( true ) {
				3 === $i => 'refunded',
				6 === $i => 'pending',
				default  => 'completed',
			};
			$checked_in = 'completed' === $status && 0 === $i % 5;

			$this->create_tc_attendee( $event_id, $ticket_id, $i, $status, $checked_in, $orders[ $status ] );
			++$made;
		}

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_rsvp_attendee( $event_id, $rsvp_id, $i, 5 !== $i ); // #5 is "not going".
			++$made;
		}

		\WP_CLI::success( sprintf( 'Seeded event %d with %d attendees (GA %d / VIP %d / RSVP %d).', $event_id, $made, $ga_id, $vip_id, $rsvp_id ) );
		\WP_CLI::log( 'Sample QR payload for a fresh attendee:' );

		$sample = get_posts(
			[
				'post_type'   => 'tec_tc_attendee',
				'numberposts' => 1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => [
					[ 'key' => self::SEED_META ],
					[
						'key'   => '_tec_tickets_commerce_event',
						'value' => $event_id,
					],
				],
			]
		);

		if ( $sample ) {
			$a = $sample[0];
			\WP_CLI::log(
				add_query_arg(
					[
						'event_qr_code' => 1,
						'ticket_id'     => $a->ID,
						'event_id'      => $event_id,
						'security_code' => get_post_meta( $a->ID, '_tec_tickets_commerce_security_code', true ),
						'path'          => rawurlencode( '/wp-json/tribe/tickets/v1/qr' ),
					],
					home_url( '/' )
				)
			);
		}
	}

	private function wipe(): void {
		// NB: post_type "any" skips non-public types (attendees, orders) —
		// enumerate every type the seeder creates explicitly.
		$posts = get_posts(
			[
				'post_type'   => [ 'tribe_events', 'tec_tc_ticket', 'tec_tc_order', 'tec_tc_attendee', 'tribe_rsvp_tickets', 'tribe_rsvp_attendees' ],
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => [ [ 'key' => self::SEED_META ] ],
			]
		);

		foreach ( $posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		\WP_CLI::log( sprintf( 'Wiped %d previously seeded posts.', count( $posts ) ) );
	}

	private function create_event(): int {
		$start = new \DateTimeImmutable( '+7 days 18:00', wp_timezone() );
		$end   = $start->setTime( 23, 0 );

		$event_id = wp_insert_post(
			[
				'post_type'   => 'tribe_events',
				'post_status' => 'publish',
				'post_title'  => 'Scanner Test Event',
				'meta_input'  => [
					self::SEED_META      => 1,
					'_EventStartDate'    => $start->format( 'Y-m-d H:i:s' ),
					'_EventEndDate'      => $end->format( 'Y-m-d H:i:s' ),
					'_EventStartDateUTC' => $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'_EventEndDateUTC'   => $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
					'_EventTimezone'     => wp_timezone_string(),
					'_EventDuration'     => $end->getTimestamp() - $start->getTimestamp(),
				],
			]
		);

		return (int) $event_id;
	}

	private function create_ticket( int $event_id, string $name ): int {
		return (int) wp_insert_post(
			[
				'post_type'   => 'tec_tc_ticket',
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => [
					self::SEED_META               => 1,
					'_tec_tickets_commerce_event' => $event_id,
					'_price'                      => 25,
				],
			]
		);
	}

	private function create_rsvp_ticket( int $event_id ): int {
		return (int) wp_insert_post(
			[
				'post_type'   => 'tribe_rsvp_tickets',
				'post_status' => 'publish',
				'post_title'  => 'RSVP',
				'meta_input'  => [
					self::SEED_META      => 1,
					'_tribe_rsvp_for_event' => $event_id,
				],
			]
		);
	}

	private function create_order( string $status ): int {
		return (int) wp_insert_post(
			[
				'post_type'   => 'tec_tc_order',
				'post_status' => $status,
				'post_title'  => 'Scanner Seed Order (' . $status . ')',
				'meta_input'  => [ self::SEED_META => 1 ],
			]
		);
	}

	private function create_tc_attendee( int $event_id, int $ticket_id, int $index, string $status, bool $checked_in, int $order_id ): void {
		$name  = sprintf( 'Test Attendee %02d', $index );
		$email = sprintf( 'attendee%02d@example.test', $index );

		$meta = [
			self::SEED_META                        => 1,
			'_tec_tickets_commerce_event'          => $event_id,
			'_tec_tickets_commerce_ticket'         => $ticket_id,
			'_tec_tickets_commerce_order'          => $order_id,
			'_tec_tickets_commerce_security_code'  => substr( md5( 'tec-scanner-seed-' . $index ), 0, 8 ),
			'_tec_tickets_commerce_status'         => $status,
			'_tec_tickets_commerce_full_name'      => $name,
			'_tec_tickets_commerce_email'          => $email,
			'_tribe_tickets_full_name'             => $name,
			'_tribe_tickets_email'                 => $email,
		];

		if ( $checked_in ) {
			$meta['_tec_tickets_commerce_checked_in']          = 1;
			$meta['_tec_tickets_commerce_checked_in_details'] = [
				'date'      => current_time( 'mysql' ),
				'source'    => 'app',
				'author'    => 'seed',
				'device_id' => 'box-office',
			];
		}

		wp_insert_post(
			[
				'post_type'   => 'tec_tc_attendee',
				'post_status' => 'publish',
				'post_title'  => $name,
				// Tickets Commerce resolves the backing order via post_parent
				// (tec_tc_get_order( $attendee->post_parent )).
				'post_parent' => $order_id,
				'meta_input'  => $meta,
			]
		);
	}

	private function create_rsvp_attendee( int $event_id, int $ticket_id, int $index, bool $going ): void {
		$name  = sprintf( 'RSVP Guest %02d', $index );
		$email = sprintf( 'rsvp%02d@example.test', $index );

		wp_insert_post(
			[
				'post_type'   => 'tribe_rsvp_attendees',
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => [
					self::SEED_META               => 1,
					'_tribe_rsvp_event'           => $event_id,
					'_tribe_rsvp_product'         => $ticket_id,
					'_tribe_rsvp_security_code'   => substr( md5( 'tec-scanner-seed-rsvp-' . $index ), 0, 8 ),
					'_tribe_rsvp_status'          => $going ? 'yes' : 'no',
					'_tribe_rsvp_full_name'       => $name,
					'_tribe_rsvp_email'           => $email,
				],
			]
		);
	}
}
