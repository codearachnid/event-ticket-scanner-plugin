<?php
declare(strict_types=1);

namespace TEC_Scanner;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/**
	 * Roles granted the check-in capability by default.
	 *
	 * @return string[]
	 */
	public static function default_roles(): array {
		/**
		 * Filter which roles receive the event_ticket_scanner_checkin capability.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'event_ticket_scanner_checkin_roles', [ 'administrator', 'editor' ] );
	}

	/**
	 * Roles that may scan every event regardless of per-user assignments.
	 *
	 * @return string[]
	 */
	public static function unrestricted_roles(): array {
		/**
		 * Filter which roles bypass per-event scanner assignments.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'event_ticket_scanner_scan_all_events_roles', [ 'administrator', 'editor' ] );
	}

	/**
	 * Roles that may create scanner users and assign them to events.
	 *
	 * @return string[]
	 */
	public static function manager_roles(): array {
		/**
		 * Filter which roles may manage scanner users and their assignments.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'event_ticket_scanner_manager_roles', [ 'administrator' ] );
	}

	/** Capabilities of the dedicated scanner role. */
	public static function scanner_role_caps(): array {
		/**
		 * Filter the capability set of the Event Scanner role. Deliberately
		 * minimal: it can log in and check attendees in, nothing else. Event
		 * visibility comes from assignments, never from this cap set.
		 *
		 * @param array<string,bool> $caps Capability map.
		 */
		return (array) apply_filters(
			'event_ticket_scanner_role_caps',
			[
				'read'             => true,
				Plugin::CAP_CHECKIN => true,
			]
		);
	}

	public static function grant(): void {
		self::register_role();
		self::migrate_legacy_role();
		self::migrate_legacy_caps();

		foreach ( self::default_roles() as $role_slug ) {
			self::add_cap_to_role( $role_slug, Plugin::CAP_CHECKIN );
		}

		foreach ( self::unrestricted_roles() as $role_slug ) {
			self::add_cap_to_role( $role_slug, Plugin::CAP_SCAN_ALL );
		}

		foreach ( self::manager_roles() as $role_slug ) {
			self::add_cap_to_role( $role_slug, Plugin::CAP_MANAGE );
		}
	}

	/**
	 * Create (or repair) the Event Scanner role. add_role() is a no-op when the
	 * role exists, so the cap set is re-asserted separately.
	 */
	public static function register_role(): void {
		$caps = self::scanner_role_caps();

		add_role( Plugin::ROLE_SCANNER, __( 'Event Scanner', 'event-ticket-scanner' ), $caps );

		$role = get_role( Plugin::ROLE_SCANNER );

		if ( ! $role ) {
			return;
		}

		foreach ( $caps as $cap => $granted ) {
			if ( $granted && ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}

		// The scanner role must never be unrestricted — that is the whole point.
		if ( $role->has_cap( Plugin::CAP_SCAN_ALL ) ) {
			$role->remove_cap( Plugin::CAP_SCAN_ALL );
		}
	}

	/**
	 * Move anyone still holding the old `tec_scanner` role onto the current one.
	 *
	 * The role slug is stored in each user's capabilities meta, so renaming it
	 * would silently strip check-in access from every scanner account. Runs
	 * whenever capabilities are (re-)granted and is a no-op once the old role
	 * is gone.
	 */
	public static function migrate_legacy_role(): void {
		$legacy = get_role( Plugin::ROLE_SCANNER_LEGACY );

		if ( ! $legacy || Plugin::ROLE_SCANNER_LEGACY === Plugin::ROLE_SCANNER ) {
			return;
		}

		$user_ids = get_users(
			[
				'role'   => Plugin::ROLE_SCANNER_LEGACY,
				'fields' => 'ID',
				'number' => 1000,
			]
		);

		foreach ( $user_ids as $user_id ) {
			$user = new \WP_User( (int) $user_id );

			$user->add_role( Plugin::ROLE_SCANNER );
			$user->remove_role( Plugin::ROLE_SCANNER_LEGACY );
		}

		remove_role( Plugin::ROLE_SCANNER_LEGACY );
	}

	/**
	 * Swap the pre-1.1 `tec_scanner_*` capabilities for their current names,
	 * on every role that holds them and on every user granted one directly.
	 */
	public static function migrate_legacy_caps(): void {
		foreach ( wp_roles()->roles as $role_slug => $unused ) {
			$role = get_role( $role_slug );

			if ( ! $role ) {
				continue;
			}

			foreach ( Plugin::LEGACY_CAPS as $legacy => $current ) {
				if ( $legacy === $current || ! $role->has_cap( $legacy ) ) {
					continue;
				}

				$role->add_cap( $current );
				$role->remove_cap( $legacy );
			}
		}

		// Users given the check-in capability directly (event authors, organizer
		// accounts) carry it in their own meta rather than through a role.
		$granted = get_users(
			[
				'meta_key' => Assignments::META_CAP_GRANTED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => 'ID',
				'number'   => 1000,
			]
		);

		foreach ( $granted as $user_id ) {
			$user = new \WP_User( (int) $user_id );

			foreach ( Plugin::LEGACY_CAPS as $legacy => $current ) {
				if ( $legacy !== $current && ! empty( $user->caps[ $legacy ] ) ) {
					$user->add_cap( $current );
					$user->remove_cap( $legacy );
				}
			}
		}
	}

	/** Re-assert on init so new roles added via the filters pick the caps up. */
	public static function ensure_granted(): void {
		if ( get_option( 'tec_scanner_caps_granted' ) === TEC_SCANNER_VERSION && get_role( Plugin::ROLE_SCANNER ) ) {
			return;
		}

		self::grant();
		update_option( 'tec_scanner_caps_granted', TEC_SCANNER_VERSION, false );
	}

	public static function revoke_all(): void {
		$caps = array_merge(
			[ Plugin::CAP_CHECKIN, Plugin::CAP_SCAN_ALL, Plugin::CAP_MANAGE ],
			array_keys( Plugin::LEGACY_CAPS )
		);

		foreach ( wp_roles()->roles as $role_slug => $unused ) {
			$role = get_role( $role_slug );

			if ( ! $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		remove_role( Plugin::ROLE_SCANNER );
		remove_role( Plugin::ROLE_SCANNER_LEGACY );
	}

	private static function add_cap_to_role( string $role_slug, string $cap ): void {
		$role = get_role( $role_slug );

		if ( $role && ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}
