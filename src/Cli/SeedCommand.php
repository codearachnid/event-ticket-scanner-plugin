<?php
declare(strict_types=1);

namespace EventTicketScanner\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * `wp event-ticket-scanner seed` — create a deterministic test event with tickets and
 * attendees for exercising the scanner API. Everything it creates is tagged
 * with `_event_ticket_scanner_seed` meta so `--fresh` can wipe and re-create.
 *
 * Attendee posts are written with the exact provider meta keys the plugin
 * (and Event Tickets itself) reads, covering the contract's edge cases:
 * checked-in, refunded, pending, and RSVP "not going".
 */
final class SeedCommand {

	private const SEED_META = '_event_ticket_scanner_seed';

	/**
	 * Seed the test event.
	 *
	 * ## OPTIONS
	 *
	 * [--attendees=<count>]
	 * : Tickets Commerce attendees to create (plus 5 RSVP). Default 20.
	 *
	 * [--catalog]
	 * : Seed the full test catalogue instead: 20 events spanning 45 days back
	 * to 90 days forward, covering past / live / upcoming, an empty event, a
	 * multi-day event, refund-heavy and unicode-name events, and a 500-attendee
	 * event for search and sync paging.
	 *
	 * [--fresh]
	 * : Delete previously-seeded content first.
	 *
	 * [--fresh-all]
	 * : Permanently delete EVERY event, ticket, order and attendee first, not
	 * just seeded ones. Destructive — for disposable dev sites only.
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['fresh-all'] ) ) {
			$this->wipe( false );
		} elseif ( isset( $assoc_args['fresh'] ) ) {
			$this->wipe();
		}

		if ( isset( $assoc_args['catalog'] ) ) {
			$this->seed_catalog();

			return;
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

	/**
	 * The catalogue: one row per event, spanning 45 days back to 90 days
	 * forward. Between them these cover every state the mobile app has to
	 * render — past / live / upcoming, empty, multi-day, refund-heavy,
	 * awkward names, and one event big enough to stress search and sync.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catalog(): array {
		return [
			// [ title, start modifier, hours long, TC attendees, checked-in share, RSVP count ]
			[ 'Spring Kickoff Gala',        '-45 days 19:00', 4,  60,  1.0,  6 ],
			[ 'Volunteer Orientation',      '-30 days 09:00', 3,  0,   0.0,  12 ],
			[ 'Members Mixer',              '-21 days 18:30', 3,  40,  0.85, 5 ],
			[ 'Workshop: Stage Lighting',   '-14 days 13:00', 4,  25,  0.6,  0 ],
			[ 'Community Potluck',          '-7 days 17:00',  5,  80,  0.9,  10 ],
			[ 'Late Night Comedy',          '-3 days 21:00',  3,  150, 0.75, 0 ],
			[ 'Farmers Market Day Pass',    '-1 day 08:00',   10, 300, 0.5,  20 ],
			[ 'LIVE: Afternoon Main Stage', '-2 hours',       6,  500, 0.35, 25 ],
			[ 'Tonight: Doors at 7',        '+3 hours',       5,  120, 0.0,  15 ],
			[ 'Tomorrow Matinee',           '+1 day 14:00',   3,  45,  0.0,  5 ],
			[ 'Two-Day Festival',           '+2 days 11:00',  48, 200, 0.0,  20 ],
			[ 'Family Fun Run',             '+5 days 07:30',  4,  0,   0.0,  0 ],
			[ 'Scanner Test Event',         '+7 days 18:00',  5,  20,  0.2,  5 ],
			[ 'Sold Out Show',              '+10 days 20:00', 3,  200, 0.0,  0 ],
			[ 'Refund Heavy Night',         '+14 days 19:30', 3,  60,  0.1,  8 ],
			[ 'Unicode & Names Test',       '+21 days 18:00', 2,  30,  0.3,  4 ],
			[ 'Monthly Meetup',             '+30 days 18:00', 2,  35,  0.0,  6 ],
			[ 'Autumn Conference Day 1',    '+45 days 08:00', 9,  250, 0.0,  30 ],
			[ 'Charity Auction',            '+60 days 19:00', 4,  90,  0.0,  10 ],
			[ 'New Year Preview',           '+90 days 20:00', 3,  15,  0.0,  3 ],
		];
	}

	/** Seed every catalogue row. */
	private function seed_catalog(): void {
		$events = 0;
		$made   = 0;

		foreach ( $this->catalog() as [ $title, $when, $hours, $tc_count, $checked_in_share, $rsvp_count ] ) {
			$start = new \DateTimeImmutable( $when, wp_timezone() );
			$end   = $start->modify( '+' . $hours . ' hours' );

			$event_id = $this->create_event( $title, $start, $end );
			$made    += $this->seed_event_attendees( $event_id, $title, $tc_count, $checked_in_share, $rsvp_count );
			++$events;

			\WP_CLI::log( sprintf( '  %s — %s (%d attendees)', $start->format( 'Y-m-d H:i' ), $title, $tc_count + $rsvp_count ) );
		}

		\WP_CLI::success( sprintf( 'Seeded %d events with %d attendees.', $events, $made ) );
	}

	/**
	 * Tickets, orders and attendees for one catalogue event.
	 *
	 * "Refund Heavy Night" deliberately skews the order mix so the app has
	 * plenty of RED (order not complete) scans to hit; everywhere else a
	 * fixed slice is refunded or pending so every event has at least one.
	 */
	private function seed_event_attendees( int $event_id, string $title, int $tc_count, float $checked_in_share, int $rsvp_count ): int {
		$made = 0;

		if ( $tc_count > 0 ) {
			$ga_id  = $this->create_ticket( $event_id, 'General Admission' );
			$vip_id = $this->create_ticket( $event_id, 'VIP' );

			$orders = [
				'completed' => $this->create_order( 'tec-tc-completed' ),
				'refunded'  => $this->create_order( 'tec-tc-refunded' ),
				'pending'   => $this->create_order( 'tec-tc-pending' ),
			];

			$refund_every  = 'Refund Heavy Night' === $title ? 4 : 17;
			$pending_every = 'Refund Heavy Night' === $title ? 5 : 23;

			// Group check-in: completed attendees share an order in runs of
			// 1–4 (deterministic cycle), like real multi-ticket purchases.
			$group_order = 0;
			$group_left  = 0;

			for ( $i = 1; $i <= $tc_count; $i++ ) {
				$status = match ( true ) {
					0 === $i % $refund_every  => 'refunded',
					0 === $i % $pending_every => 'pending',
					default                   => 'completed',
				};

				if ( 'completed' === $status && $group_left < 1 ) {
					$group_left  = 1 + ( $i % 4 );
					$group_order = $this->create_order( 'tec-tc-completed' );
				}

				// Deterministic rather than random: the same seed run twice
				// gives the same check-in counts, so screenshots and stats
				// stay comparable between runs.
				$checked_in = 'completed' === $status
					&& $checked_in_share > 0
					&& ( $i % 100 ) < (int) round( $checked_in_share * 100 );

				$order_id = $orders[ $status ];

				if ( 'completed' === $status ) {
					$order_id = $group_order;
					--$group_left;
				}

				$this->create_tc_attendee(
					$event_id,
					0 === $i % 4 ? $vip_id : $ga_id,
					$i,
					$status,
					$checked_in,
					$order_id,
					$this->attendee_name( $event_id, $i, 'Unicode & Names Test' === $title )
				);
				++$made;
			}
		}

		if ( $rsvp_count > 0 ) {
			$rsvp_id = $this->create_rsvp_ticket( $event_id );
		}

		for ( $i = 1; $i <= $rsvp_count; $i++ ) {
			// Every 6th RSVP is "not going" — a RED scan.
			$this->create_rsvp_attendee( $event_id, $rsvp_id, $i, 0 !== $i % 6 );
			++$made;
		}

		return $made;
	}

	/**
	 * Names worth searching for: repeated surnames, a shared first name, and
	 * (on the unicode event) accents, apostrophes and non-Latin scripts that
	 * the attendee search has to survive.
	 */
	private function attendee_name( int $event_id, int $index, bool $awkward ): string {
		$first = [ 'Ada', 'Grace', 'Alan', 'Katherine', 'Linus', 'Barbara', 'Dennis', 'Radia', 'Ken', 'Margaret' ];
		$last  = [ 'Lovelace', 'Hopper', 'Turing', 'Johnson', 'Torvalds', 'Liskov', 'Ritchie', 'Perlman', 'Thompson', 'Hamilton' ];

		if ( $awkward ) {
			$odd = [ "Siobhán O'Brien", 'José Álvarez', '张伟', 'Ægir Þórsson', 'Anne-Marie Le Blanc', 'محمد الأحمد' ];

			return $odd[ $index % count( $odd ) ] . ' ' . $index;
		}

		return sprintf(
			'%s %s',
			$first[ ( $index + $event_id ) % count( $first ) ],
			$last[ ( $index * 3 + $event_id ) % count( $last ) ]
		);
	}

	private function wipe( bool $only_seeded = true ): void {
		// NB: post_type "any" skips non-public types (attendees, orders) —
		// enumerate every type the seeder creates explicitly.
		$posts = get_posts(
			[
				'post_type'   => [ 'tribe_events', 'tec_tc_ticket', 'tec_tc_order', 'tec_tc_attendee', 'tribe_rsvp_tickets', 'tribe_rsvp_attendees' ],
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => $only_seeded ? [ [ 'key' => self::SEED_META ] ] : [],
			]
		);

		foreach ( $posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		\WP_CLI::log(
			sprintf(
				'Wiped %d %s posts.',
				count( $posts ),
				$only_seeded ? 'previously seeded' : 'event / ticket / order / attendee'
			)
		);
	}

	private function create_event( string $title = 'Scanner Test Event', ?\DateTimeImmutable $start = null, ?\DateTimeImmutable $end = null ): int {
		$start = $start ?? new \DateTimeImmutable( '+7 days 18:00', wp_timezone() );
		$end   = $end ?? $start->setTime( 23, 0 );

		$event_id = wp_insert_post(
			[
				'post_type'   => 'tribe_events',
				'post_status' => 'publish',
				'post_title'  => $title,
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

	private function create_tc_attendee( int $event_id, int $ticket_id, int $index, string $status, bool $checked_in, int $order_id, ?string $name = null ): void {
		$name  = $name ?? sprintf( 'Test Attendee %02d', $index );
		$email = sprintf( 'attendee%02d-%d@example.test', $index, $event_id );

		$meta = [
			self::SEED_META                        => 1,
			'_tec_tickets_commerce_event'          => $event_id,
			'_tec_tickets_commerce_ticket'         => $ticket_id,
			'_tec_tickets_commerce_order'          => $order_id,
			'_tec_tickets_commerce_security_code'  => substr( md5( 'event-ticket-scanner-seed-' . $event_id . '-' . $index ), 0, 8 ),
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

	private function create_rsvp_attendee( int $event_id, int $ticket_id, int $index, bool $going, ?string $name = null ): void {
		$name  = $name ?? sprintf( 'RSVP Guest %02d', $index );
		$email = sprintf( 'rsvp%02d-%d@example.test', $index, $event_id );

		wp_insert_post(
			[
				'post_type'   => 'tribe_rsvp_attendees',
				'post_status' => 'publish',
				'post_title'  => $name,
				'meta_input'  => [
					self::SEED_META               => 1,
					'_tribe_rsvp_event'           => $event_id,
					'_tribe_rsvp_product'         => $ticket_id,
					'_tribe_rsvp_security_code'   => substr( md5( 'event-ticket-scanner-seed-rsvp-' . $event_id . '-' . $index ), 0, 8 ),
					'_tribe_rsvp_status'          => $going ? 'yes' : 'no',
					'_tribe_rsvp_full_name'       => $name,
					'_tribe_rsvp_email'           => $email,
				],
			]
		);
	}
}
