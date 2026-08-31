<?php
declare(strict_types=1);

namespace EventTicketScanner\Rest;

use EventTicketScanner\Assignments;
use EventTicketScanner\Attendees\AttendeeMapper;
use EventTicketScanner\Attendees\Providers;
use EventTicketScanner\Checkins\CheckinProcessor;
use EventTicketScanner\Pairing\PairingService;
use EventTicketScanner\Plugin;
use EventTicketScanner\Registration\Registrar;

defined( 'ABSPATH' ) || exit;

final class Controller {

	public function __construct(
		private AttendeeMapper $mapper,
		private CheckinProcessor $checkins,
		private PairingService $pairing,
		private Registrar $registrar,
	) {
	}

	/* ---------------------------------------------------------------- auth */

	/** Permission callback for every authenticated route. */
	public function can_checkin(): bool|\WP_Error {
		if ( ! Plugin::transport_is_secure() ) {
			return new \WP_Error(
				'event_ticket_scanner_insecure_transport',
				__( 'The scanner API requires HTTPS.', 'event-ticket-scanner' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required (use an Application Password).', 'event-ticket-scanner' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( Plugin::CAP_CHECKIN ) ) {
			return new \WP_Error(
				'event_ticket_scanner_forbidden',
				__( 'You are not allowed to manage check-ins on this site.', 'event-ticket-scanner' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------ /me */

	public function me(): \WP_REST_Response {
		$user         = wp_get_current_user();
		$unrestricted = Assignments::is_unrestricted( (int) $user->ID );

		return rest_ensure_response(
			[
				'site_name'             => get_bloginfo( 'name' ),
				'site_url'              => untrailingslashit( home_url() ),
				'user'                  => [
					'id'           => $user->ID,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
				],
				'capabilities'          => [
					'can_checkin'     => current_user_can( Plugin::CAP_CHECKIN ),
					'scan_all_events' => $unrestricted,
				],
				// null = every event; a list = the only events this user may scan.
				'assigned_event_ids'    => $unrestricted ? null : Assignments::for_user( (int) $user->ID ),
				'plugin_version'        => EVENT_TICKET_SCANNER_VERSION,
				'event_tickets_version' => defined( 'Tribe__Tickets__Main::VERSION' ) ? \Tribe__Tickets__Main::VERSION : '',
				'providers'             => Providers::active_slugs(),
			]
		);
	}

	/* -------------------------------------------------------------- /events */

	public function events( \WP_REST_Request $request ): \WP_REST_Response {
		$upcoming = (bool) $request->get_param( 'upcoming' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );

		// Restricted users see only their assignments; with none, they see nothing.
		if ( ! Assignments::is_unrestricted() ) {
			$assigned = Assignments::for_user( get_current_user_id() );

			if ( ! $assigned ) {
				return rest_ensure_response(
					[
						'events'   => [],
						'total'    => 0,
						'page'     => $page,
						'per_page' => $per_page,
						'has_more' => false,
					]
				);
			}
		}

		$args = [
			'post_type'      => 'tribe_events',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_key'       => '_EventStartDate',
			'order'          => 'ASC',
		];

		if ( isset( $assigned ) ) {
			$args['post__in'] = $assigned;
		}

		if ( $upcoming ) {
			// End date in the future, with a 12h grace window for late scans.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = [
				[
					'key'     => '_EventEndDateUTC',
					'value'   => gmdate( 'Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS ),
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
			];
		}

		$query  = new \WP_Query( $args );
		$events = [];

		foreach ( $query->posts as $post ) {
			$attendee_ids = $this->mapper->attendee_ids_for_event( (int) $post->ID );
			$rows         = $this->mapper->format( $attendee_ids );

			$venue_id = (int) get_post_meta( $post->ID, '_EventVenueID', true );

			$events[] = [
				'id'               => (int) $post->ID,
				'title'            => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
				'start_date'       => (string) get_post_meta( $post->ID, '_EventStartDate', true ),
				'end_date'         => (string) get_post_meta( $post->ID, '_EventEndDate', true ),
				'timezone'         => (string) ( get_post_meta( $post->ID, '_EventTimezone', true ) ?: wp_timezone_string() ),
				'venue'            => $venue_id ? html_entity_decode( get_the_title( $venue_id ), ENT_QUOTES ) : null,
				'allow_walkup'     => '0' !== (string) get_post_meta( $post->ID, '_event_ticket_scanner_allow_walkup', true ),
				'attendee_count'   => count( $rows ),
				'checked_in_count' => count( array_filter( $rows, static fn ( array $r ) => $r['checked_in'] ) ),
			];
		}

		return rest_ensure_response(
			[
				'events'   => $events,
				'total'    => (int) $query->found_posts,
				'page'     => $page,
				'per_page' => $per_page,
				'has_more' => $page * $per_page < (int) $query->found_posts,
			]
		);
	}

	/* ----------------------------------------------- /events/{id}/attendees */

	public function attendees( \WP_REST_Request $request ) {
		$event_id = (int) $request['event_id'];
		$denied   = $this->guard_event( $event_id );

		if ( $denied ) {
			return $denied;
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$since    = $this->parse_since( (string) $request->get_param( 'updated_since' ) );

		$server_time = gmdate( 'Y-m-d\TH:i:s.v\Z' );

		$ids = $this->mapper->attendee_ids_for_event( $event_id );

		if ( $since ) {
			$ids = $this->filter_ids_updated_since( $ids, $since );
		}

		$total     = count( $ids );
		$page_ids  = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
		$attendees = $this->mapper->format( $page_ids );

		return rest_ensure_response(
			[
				'attendees'   => $attendees,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'has_more'    => $page * $per_page < $total,
				'server_time' => $server_time,
			]
		);
	}

	/* --------------------------------------------------- /events/{id}/stats */

	public function stats( \WP_REST_Request $request ) {
		$event_id = (int) $request['event_id'];
		$denied   = $this->guard_event( $event_id );

		if ( $denied ) {
			return $denied;
		}

		$rows    = $this->mapper->format( $this->mapper->attendee_ids_for_event( $event_id ) );
		$by_type = [];

		foreach ( $rows as $row ) {
			$key = $row['ticket_id'];

			$by_type[ $key ] ??= [
				'ticket_id'  => $row['ticket_id'],
				'name'       => $row['ticket_name'],
				'total'      => 0,
				'checked_in' => 0,
			];

			++$by_type[ $key ]['total'];
			$by_type[ $key ]['checked_in'] += $row['checked_in'] ? 1 : 0;
		}

		return rest_ensure_response(
			[
				'event_id'       => $event_id,
				'total'          => count( $rows ),
				'checked_in'     => count( array_filter( $rows, static fn ( array $r ) => $r['checked_in'] ) ),
				'by_ticket_type' => array_values( $by_type ),
				'server_time'    => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
			]
		);
	}

	/* ------------------------------------------------------------ /checkins */

	public function checkins( \WP_REST_Request $request ) {
		$device_id  = sanitize_text_field( (string) $request->get_param( 'device_id' ) );
		$operations = $request->get_param( 'operations' );

		if ( ! is_array( $operations ) || ! $operations || count( $operations ) > 100 ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'operations must be a non-empty array of at most 100 items.', 'event-ticket-scanner' ),
				[ 'status' => 400 ]
			);
		}

		foreach ( $operations as $op ) {
			if ( ! is_array( $op )
				|| ! wp_is_uuid( $op['op_id'] ?? '' )
				|| ! isset( $op['attendee_id'], $op['action'] )
				|| ! in_array( $op['action'], [ 'checkin', 'uncheckin' ], true ) ) {
				return new \WP_Error(
					'rest_invalid_param',
					__( 'Each operation needs a uuid op_id, an attendee_id, and an action of checkin|uncheckin.', 'event-ticket-scanner' ),
					[ 'status' => 400 ]
				);
			}
		}

		return rest_ensure_response(
			[
				'results'     => $this->checkins->process_batch( $operations, $device_id ),
				'server_time' => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
			]
		);
	}

	/* ------------------------------------------------ /events/{id}/tickets */

	public function tickets( \WP_REST_Request $request ) {
		$event_id = (int) $request['event_id'];
		$denied   = $this->guard_event( $event_id );

		if ( $denied ) {
			return $denied;
		}

		return rest_ensure_response( [ 'tickets' => $this->registrar->tickets_for_event( $event_id ) ] );
	}

	/* ----------------------------------------------- /events/{id}/register */

	public function register_walkup( \WP_REST_Request $request ) {
		$event_id = (int) $request['event_id'];
		$denied   = $this->guard_event( $event_id );

		if ( $denied ) {
			return $denied;
		}

		if ( '0' === (string) get_post_meta( $event_id, '_event_ticket_scanner_allow_walkup', true ) ) {
			return new \WP_Error(
				'event_ticket_scanner_walkup_disabled',
				__( 'Walk-up registration is disabled for this event.', 'event-ticket-scanner' ),
				[ 'status' => 403 ]
			);
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new \WP_Error( 'rest_invalid_param', __( 'name is required.', 'event-ticket-scanner' ), [ 'status' => 400 ] );
		}

		$attendee = $this->registrar->register(
			$event_id,
			(int) $request->get_param( 'ticket_id' ),
			$name,
			sanitize_email( (string) $request->get_param( 'email' ) ),
			(string) $request->get_param( 'payment' ),
			(bool) $request->get_param( 'check_in' ),
			sanitize_text_field( (string) $request->get_param( 'device_id' ) )
		);

		if ( is_wp_error( $attendee ) ) {
			return $attendee;
		}

		return rest_ensure_response(
			[
				'attendee'    => $attendee,
				'server_time' => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
			]
		);
	}

	/* ---------------------------------------------------------------- /pair */

	public function pair( \WP_REST_Request $request ) {
		if ( ! Plugin::transport_is_secure() ) {
			return new \WP_Error(
				'event_ticket_scanner_insecure_transport',
				__( 'Pairing requires HTTPS.', 'event-ticket-scanner' ),
				[ 'status' => 403 ]
			);
		}

		$result = $this->pairing->consume_token(
			(string) $request->get_param( 'token' ),
			(string) $request->get_param( 'device_name' ),
			(string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);

		return rest_ensure_response( $result );
	}

	/* -------------------------------------------------------------- helpers */

	/**
	 * 404 for unknown events, 403 for events this user isn't assigned to.
	 * Returns null when the request may proceed.
	 */
	private function guard_event( int $event_id ): ?\WP_Error {
		if ( ! $this->event_exists( $event_id ) ) {
			return $this->event_not_found();
		}

		if ( ! Assignments::current_user_can_access_event( $event_id ) ) {
			return new \WP_Error(
				'event_ticket_scanner_event_forbidden',
				__( 'You are not assigned to scan this event.', 'event-ticket-scanner' ),
				[ 'status' => 403 ]
			);
		}

		return null;
	}

	private function event_exists( int $event_id ): bool {
		$post = get_post( $event_id );

		return $post && 'publish' === $post->post_status;
	}

	private function event_not_found(): \WP_Error {
		return new \WP_Error(
			'event_ticket_scanner_event_not_found',
			__( 'Event not found.', 'event-ticket-scanner' ),
			[ 'status' => 404 ]
		);
	}

	private function parse_since( string $raw ): ?\DateTimeImmutable {
		if ( '' === $raw ) {
			return null;
		}

		try {
			return ( new \DateTimeImmutable( $raw ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}
	}

	/**
	 * Keep only attendees whose touch time (or post_modified_gmt fallback)
	 * is strictly after the cursor.
	 *
	 * @param int[] $ids Attendee post IDs.
	 * @return int[]
	 */
	private function filter_ids_updated_since( array $ids, \DateTimeImmutable $since ): array {
		if ( ! $ids ) {
			return [];
		}

		$touch_times = ( new \EventTicketScanner\TouchIndex() )->times_for( $ids );

		global $wpdb;

		$post_ids = array_map( 'intval', $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$modified = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ID, post_modified_gmt FROM %i WHERE ID IN ('
					. implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')',
				array_merge( [ $wpdb->posts ], $post_ids )
			),
			OBJECT_K
		);

		$cursor = $since->format( 'Y-m-d H:i:s.u' );

		return array_values(
			array_filter(
				$ids,
				static function ( int $id ) use ( $touch_times, $modified, $cursor ): bool {
					$time = $touch_times[ $id ] ?? ( isset( $modified[ $id ] ) ? $modified[ $id ]->post_modified_gmt . '.000000' : null );

					return null !== $time && $time > $cursor;
				}
			)
		);
	}
}
