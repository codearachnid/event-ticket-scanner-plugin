<?php
declare(strict_types=1);

namespace TEC_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Who may be picked as a scanner for a given event.
 *
 * The pool is deliberately bounded — never "every user on the site". It is the
 * union of:
 *  - users who can already check in (the Event Scanner role, administrators,
 *    editors, anything else granted {@see Plugin::CAP_CHECKIN}),
 *  - users linked to an Organizer post,
 *  - users who can edit this event (its author, and roles holding the event
 *    edit capabilities),
 *  - users already assigned to it, so an existing assignment is never hidden.
 */
final class ScannerCandidates {

	/** Hard ceiling on the pool; the UI says so when it bites. */
	public const LIMIT = 500;

	/**
	 * Candidates for an event, each annotated with how they qualify.
	 *
	 * @return array<int, array{user:\WP_User,reasons:string[],assigned:bool,derived:bool,unrestricted:bool,can_checkin:bool,can_edit:bool}>
	 *         Keyed by user ID: users with access first, then alphabetical.
	 */
	public static function for_event( int $event_id ): array {
		$organizer_ids = self::organizer_ids_for_event( $event_id );
		$assigned      = array_map( static fn ( \WP_User $u ): int => (int) $u->ID, Assignments::users_for_event( $event_id ) );

		$pool = array_unique(
			array_merge(
				self::users_with_checkin_or_edit_roles(),
				Organizers::all_linked_user_ids(),
				$assigned,
				array_filter( [ (int) get_post_field( 'post_author', $event_id ) ] )
			)
		);

		$candidates = [];

		foreach ( array_slice( $pool, 0, self::LIMIT ) as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			$reasons      = [];
			$unrestricted = Assignments::is_unrestricted( (int) $user_id );
			$can_checkin  = user_can( $user, Plugin::CAP_CHECKIN );
			$can_edit     = user_can( $user, 'edit_post', $event_id );
			$derived      = (bool) array_intersect( $organizer_ids, Organizers::organizer_ids_for_user( (int) $user_id ) );

			if ( in_array( Plugin::ROLE_SCANNER, (array) $user->roles, true ) ) {
				$reasons[] = __( 'Event Scanner', 'event-ticket-scanner' );
			}

			if ( $unrestricted ) {
				$reasons[] = __( 'scans all events', 'event-ticket-scanner' );
			}

			if ( $derived ) {
				$reasons[] = __( "this event's organizer", 'event-ticket-scanner' );
			} elseif ( Organizers::organizer_ids_for_user( (int) $user_id ) ) {
				$reasons[] = __( 'linked organizer', 'event-ticket-scanner' );
			}

			if ( $can_edit && ! $unrestricted ) {
				$reasons[] = __( 'can edit this event', 'event-ticket-scanner' );
			}

			if ( ! $reasons && ! $can_checkin ) {
				continue;
			}

			$candidates[ (int) $user_id ] = [
				'user'         => $user,
				'reasons'      => $reasons,
				'assigned'     => in_array( $event_id, Assignments::direct_for_user( (int) $user_id ), true ),
				'derived'      => $derived,
				'unrestricted' => $unrestricted,
				'can_checkin'  => $can_checkin,
				'can_edit'     => $can_edit,
			];
		}

		uasort(
			$candidates,
			static function ( array $a, array $b ): int {
				$rank = static fn ( array $c ): int => $c['assigned'] || $c['derived'] || $c['unrestricted'] ? 0 : 1;

				return [ $rank( $a ), strtolower( $a['user']->display_name ) ] <=> [ $rank( $b ), strtolower( $b['user']->display_name ) ];
			}
		);

		/**
		 * Filter the scanner candidates offered for an event.
		 *
		 * @param array $candidates Annotated candidates keyed by user ID.
		 * @param int   $event_id   Event post ID.
		 */
		return (array) apply_filters( 'event_ticket_scanner_event_candidates', $candidates, $event_id );
	}

	/**
	 * Organizer posts attached to an event.
	 *
	 * @return int[]
	 */
	public static function organizer_ids_for_event( int $event_id ): array {
		return array_values(
			array_filter(
				array_map( 'intval', (array) get_post_meta( $event_id, '_EventOrganizerID', false ) )
			)
		);
	}

	/**
	 * User IDs belonging to roles that can either check in or edit events.
	 *
	 * @return int[]
	 */
	private static function users_with_checkin_or_edit_roles(): array {
		$edit_cap = 'edit_tribe_events';
		$post_type = get_post_type_object( 'tribe_events' );

		if ( $post_type && isset( $post_type->cap->edit_posts ) ) {
			$edit_cap = (string) $post_type->cap->edit_posts;
		}

		$roles = [];

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = (array) ( $role['capabilities'] ?? [] );

			if ( ! empty( $caps[ Plugin::CAP_CHECKIN ] ) || ! empty( $caps[ $edit_cap ] ) ) {
				$roles[] = $slug;
			}
		}

		if ( ! $roles ) {
			return [];
		}

		$query = new \WP_User_Query(
			[
				'role__in' => $roles,
				'fields'   => 'ID',
				'orderby'  => 'display_name',
				'number'   => self::LIMIT,
			]
		);

		return array_map( 'intval', (array) $query->get_results() );
	}
}
