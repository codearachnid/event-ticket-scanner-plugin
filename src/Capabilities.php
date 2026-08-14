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

	public static function grant(): void {
		foreach ( self::default_roles() as $role_slug ) {
			$role = get_role( $role_slug );

			if ( $role && ! $role->has_cap( Plugin::CAP_CHECKIN ) ) {
				$role->add_cap( Plugin::CAP_CHECKIN );
			}
		}
	}

	/** Re-assert on init so new roles added via the filter pick the cap up. */
	public static function ensure_granted(): void {
		if ( get_option( 'tec_scanner_caps_granted' ) === TEC_SCANNER_VERSION ) {
			return;
		}

		self::grant();
		update_option( 'tec_scanner_caps_granted', TEC_SCANNER_VERSION, false );
	}

	public static function revoke_all(): void {
		foreach ( wp_roles()->roles as $role_slug => $unused ) {
			$role = get_role( $role_slug );

			if ( $role && $role->has_cap( Plugin::CAP_CHECKIN ) ) {
				$role->remove_cap( Plugin::CAP_CHECKIN );
			}
		}
	}
}
