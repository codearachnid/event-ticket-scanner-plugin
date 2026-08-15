<?php
declare(strict_types=1);

namespace TEC_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user event assignments — the visibility gate for scanner accounts.
 *
 * A user who can check in is either:
 *  - unrestricted (holds {@see Plugin::CAP_SCAN_ALL}, e.g. administrators) and
 *    sees every event, or
 *  - restricted, and sees exactly the events in their scope. A restricted user
 *    with an empty scope sees nothing.
 *
 * Scope has two sources, unioned:
 *  1. Direct assignments stored here (one user-meta row per event — not a
 *     serialized array — so "who scans event X" is a plain meta query).
 *  2. Organizer links ({@see Organizers}): every event listing an organizer
 *     that points at this user.
 */
final class Assignments {

	public const META_KEY = '_tec_scanner_event_id';

	/** Marks a check-in capability this plugin added to the user directly. */
	public const META_CAP_GRANTED = '_tec_scanner_cap_granted';

	public static function register_hooks(): void {
		// Don't leave assignments pointing at deleted events.
		add_action( 'before_delete_post', [ self::class, 'purge_event' ] );
	}

	/* --------------------------------------------------------------- reads */

	/** Whether the user bypasses assignments entirely. */
	public static function is_unrestricted( ?int $user_id = null ): bool {
		$user_id = $user_id ?: get_current_user_id();

		return $user_id > 0 && user_can( $user_id, Plugin::CAP_SCAN_ALL );
	}

	/**
	 * Events assigned to a user directly (excludes organizer-derived scope).
	 *
	 * @return int[] Ascending, unique.
	 */
	public static function direct_for_user( int $user_id ): array {
		$ids = array_map( 'intval', (array) get_user_meta( $user_id, self::META_KEY, false ) );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Full event scope for a user: direct assignments ∪ organizer links.
	 * Meaningless for unrestricted users — check {@see self::is_unrestricted()}
	 * first when the answer is "all".
	 *
	 * @return int[] Ascending, unique.
	 */
	public static function for_user( int $user_id ): array {
		$ids = array_merge( self::direct_for_user( $user_id ), Organizers::event_ids_for_user( $user_id ) );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );

		/**
		 * Filter the events a scanner user may scan.
		 *
		 * @param int[] $ids     Event IDs (direct assignments plus organizer links).
		 * @param int   $user_id User ID.
		 */
		$ids = array_values( array_unique( array_map( 'intval', (array) apply_filters( 'tec_scanner_user_event_ids', $ids, $user_id ) ) ) );
		sort( $ids );

		return $ids;
	}

	public static function user_can_access_event( int $user_id, int $event_id ): bool {
		if ( ! $user_id || ! $event_id ) {
			return false;
		}

		if ( self::is_unrestricted( $user_id ) ) {
			return true;
		}

		return in_array( $event_id, self::for_user( $user_id ), true );
	}

	public static function current_user_can_access_event( int $event_id ): bool {
		return self::user_can_access_event( get_current_user_id(), $event_id );
	}

	/**
	 * Users who can scan an event — directly assigned plus users linked through
	 * one of the event's organizers.
	 *
	 * @return \WP_User[]
	 */
	public static function users_for_event( int $event_id ): array {
		$query = new \WP_User_Query(
			[
				'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'display_name',
				'number'     => 500,
			]
		);

		$users = $query->get_results();
		$seen  = array_map( static fn ( \WP_User $user ): int => (int) $user->ID, $users );

		foreach ( (array) get_post_meta( $event_id, '_EventOrganizerID', false ) as $organizer_id ) {
			$linked = Organizers::linked_user_id( (int) $organizer_id );
			$user   = $linked && ! in_array( $linked, $seen, true ) ? get_userdata( $linked ) : null;

			if ( $user ) {
				$users[] = $user;
				$seen[]  = $linked;
			}
		}

		return $users;
	}

	/**
	 * Every user who can operate the scanner (the scanner role plus anything
	 * else granted the check-in cap), for the management screens.
	 *
	 * @return \WP_User[]
	 */
	public static function scanner_users(): array {
		$roles = array_values(
			array_unique(
				array_merge( [ Plugin::ROLE_SCANNER ], Capabilities::default_roles() )
			)
		);

		$query = new \WP_User_Query(
			[
				'role__in' => $roles,
				'orderby'  => 'display_name',
				'number'   => 500,
			]
		);

		$users = array_filter(
			$query->get_results(),
			static fn ( \WP_User $user ): bool => $user->has_cap( Plugin::CAP_CHECKIN )
		);

		return array_values( $users );
	}

	/**
	 * Events offered in the assignment pickers: upcoming events (12h grace,
	 * matching the API's window) plus anything already assigned, so existing
	 * assignments to past events stay visible and removable.
	 *
	 * @param int[] $always_include Event IDs to keep in the list regardless of date.
	 * @return \WP_Post[]
	 */
	public static function assignable_events( array $always_include = [] ): array {
		$upcoming = get_posts(
			[
				'post_type'      => 'tribe_events',
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => 200,
				'orderby'        => 'meta_value',
				'meta_key'       => '_EventStartDate', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => '_EventEndDateUTC',
						'value'   => gmdate( 'Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					],
				],
			]
		);

		$known   = array_map( static fn ( \WP_Post $post ): int => (int) $post->ID, $upcoming );
		$missing = array_diff( array_map( 'intval', $always_include ), $known );

		foreach ( $missing as $event_id ) {
			$post = get_post( $event_id );

			if ( $post instanceof \WP_Post && 'tribe_events' === $post->post_type ) {
				$upcoming[] = $post;
			}
		}

		return $upcoming;
	}

	/* -------------------------------------------------------------- writes */

	/**
	 * Replace a user's assignments.
	 *
	 * @param int[] $event_ids Event post IDs; non-events are dropped.
	 */
	public static function set_for_user( int $user_id, array $event_ids ): void {
		$valid = [];

		foreach ( array_unique( array_map( 'intval', $event_ids ) ) as $event_id ) {
			$post = $event_id ? get_post( $event_id ) : null;

			if ( $post instanceof \WP_Post && 'tribe_events' === $post->post_type ) {
				$valid[] = $event_id;
			}
		}

		$current = array_map( 'intval', (array) get_user_meta( $user_id, self::META_KEY, false ) );

		foreach ( array_diff( $current, $valid ) as $remove ) {
			delete_user_meta( $user_id, self::META_KEY, (string) $remove );
		}

		foreach ( array_diff( $valid, $current ) as $add ) {
			add_user_meta( $user_id, self::META_KEY, (string) $add );
		}

		self::refresh_checkin_cap( $user_id );

		/**
		 * Fires after a scanner user's event assignments change.
		 *
		 * @param int   $user_id   User ID.
		 * @param int[] $event_ids Assigned event IDs after the change.
		 */
		do_action( 'tec_scanner_assignments_updated', $user_id, $valid );
	}

	/**
	 * Giving somebody scope who can't check in yet (an event author, an
	 * organizer's account) would otherwise be a silent no-op, so grant them the
	 * capability directly on the user — scoped, as always, to that scope. The
	 * grant is remembered so it can be withdrawn when the last of it goes, and
	 * so a role-provided capability is never touched.
	 *
	 * Call after any change to assignments or organizer links.
	 */
	public static function refresh_checkin_cap( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$has_scope = (bool) self::direct_for_user( $user_id ) || (bool) Organizers::organizer_ids_for_user( $user_id );
		$granted   = (bool) get_user_meta( $user_id, self::META_CAP_GRANTED, true );

		if ( $has_scope ) {
			if ( ! $granted && ! user_can( $user, Plugin::CAP_CHECKIN ) ) {
				$user->add_cap( Plugin::CAP_CHECKIN );
				update_user_meta( $user_id, self::META_CAP_GRANTED, 1 );
			}

			return;
		}

		// Nothing left in scope. Only ever remove a capability this plugin added.
		if ( $granted ) {
			$user->remove_cap( Plugin::CAP_CHECKIN );
			delete_user_meta( $user_id, self::META_CAP_GRANTED );
		}
	}

	public static function assign( int $user_id, int $event_id ): void {
		self::set_for_user( $user_id, array_merge( self::for_user( $user_id ), [ $event_id ] ) );
	}

	public static function unassign( int $user_id, int $event_id ): void {
		self::set_for_user( $user_id, array_diff( self::for_user( $user_id ), [ $event_id ] ) );
	}

	/** Drop every assignment to an event that is being deleted. */
	public static function purge_event( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || 'tribe_events' !== $post->post_type ) {
			return;
		}

		delete_metadata( 'user', 0, self::META_KEY, (string) $post_id, true );
	}
}
