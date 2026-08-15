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
		 * Filter which roles receive the tec_scanner_checkin capability.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters( 'tec_scanner_checkin_roles', [ 'administrator', 'editor' ] );
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
		return (array) apply_filters( 'tec_scanner_scan_all_events_roles', [ 'administrator', 'editor' ] );
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
		return (array) apply_filters( 'tec_scanner_manager_roles', [ 'administrator' ] );
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
			'tec_scanner_role_caps',
			[
				'read'             => true,
				Plugin::CAP_CHECKIN => true,
			]
		);
	}

	public static function grant(): void {
		self::register_role();

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

		add_role( Plugin::ROLE_SCANNER, __( 'Event Scanner', 'wp-tec-ticket-scanner' ), $caps );

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

	/** Re-assert on init so new roles added via the filters pick the caps up. */
	public static function ensure_granted(): void {
		if ( get_option( 'tec_scanner_caps_granted' ) === TEC_SCANNER_VERSION && get_role( Plugin::ROLE_SCANNER ) ) {
			return;
		}

		self::grant();
		update_option( 'tec_scanner_caps_granted', TEC_SCANNER_VERSION, false );
	}

	public static function revoke_all(): void {
		$caps = [ Plugin::CAP_CHECKIN, Plugin::CAP_SCAN_ALL, Plugin::CAP_MANAGE ];

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
	}

	private static function add_cap_to_role( string $role_slug, string $cap ): void {
		$role = get_role( $role_slug );

		if ( $role && ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}
