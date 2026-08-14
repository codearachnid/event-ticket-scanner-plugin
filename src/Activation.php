<?php
declare(strict_types=1);

namespace TEC_Scanner;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public static function activate(): void {
		Database\Schema::create_tables();
		Capabilities::grant();

		update_option( 'tec_scanner_version', TEC_SCANNER_VERSION, false );

		// Flush so the REST routes are immediately reachable.
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
