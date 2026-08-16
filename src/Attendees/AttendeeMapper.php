<?php
declare(strict_types=1);

namespace EventTicketScanner\Attendees;

use EventTicketScanner\TouchIndex;

defined( 'ABSPATH' ) || exit;

/**
 * Maps attendee posts to the contract's Attendee shape (docs/api/openapi.yaml
 * in the mobile repo — the single source of truth both codebases test against).
 */
final class AttendeeMapper {

	public function __construct( private TouchIndex $touch_index ) {
	}

	/**
	 * All attendee post IDs for an event, across providers, oldest first.
	 *
	 * @return int[]
	 */
	public function attendee_ids_for_event( int $event_id ): array {
		$ids = [];

		foreach ( Providers::map() as $post_type => $config ) {
			$query = new \WP_Query(
				[
					'post_type'              => $post_type,
					'post_status'            => 'any',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'             => [
						[
							'key'   => $config['event'],
							'value' => $event_id,
						],
					],
				]
			);

			$ids = array_merge( $ids, array_map( 'intval', $query->posts ) );
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Format a page of attendees.
	 *
	 * @param int[] $attendee_ids Attendee post IDs (single page).
	 * @return array[] Contract-shaped attendee rows.
	 */
	public function format( array $attendee_ids ): array {
		if ( ! $attendee_ids ) {
			return [];
		}

		// Prime caches: one query for posts, one for all their meta.
		_prime_post_caches( $attendee_ids, false, true );

		$touch_times = $this->touch_index->times_for( $attendee_ids );
		$rows        = [];

		foreach ( $attendee_ids as $attendee_id ) {
			$row = $this->format_one( $attendee_id, $touch_times[ $attendee_id ] ?? null );

			if ( $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	public function format_one( int $attendee_id, ?string $touched_at = null ): ?array {
		$post = get_post( $attendee_id );

		if ( ! $post ) {
			return null;
		}

		$config = Providers::for_post_type( $post->post_type );

		if ( ! $config ) {
			return null;
		}

		$ticket_id = (int) get_post_meta( $attendee_id, $config['ticket'], true );
		$details   = get_post_meta( $attendee_id, $config['checkin'] . '_details', true );
		$details   = is_array( $details ) ? $details : [];

		$holder_name = (string) get_post_meta( $attendee_id, $config['holder_name'], true );
		if ( '' === $holder_name ) {
			$holder_name = (string) get_post_meta( $attendee_id, '_tribe_tickets_full_name', true );
		}

		$holder_email = (string) get_post_meta( $attendee_id, $config['holder_email'], true );
		if ( '' === $holder_email ) {
			$holder_email = (string) get_post_meta( $attendee_id, '_tribe_tickets_email', true );
		}

		$checked_in    = (bool) get_post_meta( $attendee_id, $config['checkin'], true );
		$checked_in_at = null;
		$checked_in_by = null;

		if ( $checked_in ) {
			$checked_in_at = isset( $details['date'] ) ? self::to_iso_utc( (string) $details['date'] ) : null;
			$checked_in_by = $details['device_id'] ?? null;

			if ( ! $checked_in_by && isset( $details['author'] ) ) {
				$checked_in_by = (string) $details['author'];
			}
		}

		return [
			'id'            => $attendee_id,
			'event_id'      => (int) get_post_meta( $attendee_id, $config['event'], true ),
			'ticket_id'     => $ticket_id,
			'ticket_name'   => $ticket_id ? (string) get_the_title( $ticket_id ) : '',
			'provider'      => $config['slug'],
			'holder_name'   => $holder_name,
			'holder_email'  => $holder_email,
			'security_code' => (string) get_post_meta( $attendee_id, $config['security'], true ),
			'order_status'  => Providers::normalize_status( $post->post_type, $attendee_id, $config ),
			'checked_in'    => $checked_in,
			'checked_in_at' => $checked_in_at,
			'checked_in_by' => $checked_in_by ? (string) $checked_in_by : null,
			'updated_at'    => self::updated_at( $post, $touched_at ),
		];
	}

	/** Touch-index time wins; fall back to post_modified_gmt for untouched rows. */
	private static function updated_at( \WP_Post $post, ?string $touched_at ): string {
		if ( $touched_at ) {
			return self::mysql_to_iso( $touched_at );
		}

		return self::mysql_to_iso( $post->post_modified_gmt . '.000000' );
	}

	/** MySQL DATETIME(6) (UTC) → ISO-8601 with milliseconds. */
	public static function mysql_to_iso( string $mysql ): string {
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s.u', $mysql, new \DateTimeZone( 'UTC' ) )
			?: \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql, new \DateTimeZone( 'UTC' ) );

		return $dt ? $dt->format( 'Y-m-d\TH:i:s.v\Z' ) : gmdate( 'Y-m-d\TH:i:s.v\Z' );
	}

	/** Check-in detail dates are site-local; convert to UTC ISO. */
	private static function to_iso_utc( string $local_datetime ): ?string {
		try {
			$dt = new \DateTimeImmutable( $local_datetime, wp_timezone() );

			return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.v\Z' );
		} catch ( \Exception ) {
			return null;
		}
	}
}
