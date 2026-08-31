<?php
declare(strict_types=1);

namespace EventTicketScanner;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrator: wires all plugin services once dependencies are confirmed.
 */
final class Plugin {

	public const CAP_CHECKIN = 'event_ticket_scanner_checkin';

	/** Bypasses per-event assignment — holder scans every event on the site. */
	public const CAP_SCAN_ALL = 'event_ticket_scanner_scan_all_events';

	/** Create scanner users, assign them to events, pair their devices. */
	public const CAP_MANAGE = 'event_ticket_scanner_manage_scanners';


	/** Purpose-built role: check-in only, restricted to assigned events. */
	public const ROLE_SCANNER = 'event_ticket_scanner';


	public const REST_NAMESPACE = 'event-ticket-scanner/v1';

	private static ?Plugin $instance = null;

	public static function boot(): void {
		if ( self::$instance ) {
			return;
		}

		if ( ! self::dependencies_met() ) {
			add_action( 'admin_notices', [ self::class, 'render_dependency_notice' ] );
			return;
		}

		self::$instance = new self();
	}

	private function __construct() {
		( new TouchIndex() )->register_hooks();
		( new Rest\Routes() )->register_hooks();
		( new Pairing\SettingsTab() )->register_hooks();
		( new Pairing\AjaxHandler() )->register_hooks();
		( new Admin\ScannerUsersPage() )->register_hooks();
		( new Admin\UserProfile() )->register_hooks();
		( new Admin\EventMetaBox() )->register_hooks();
		( new Admin\AttendeeColumns() )->register_hooks();
		( new Admin\EventSearch() )->register_hooks();

		Assignments::register_hooks();
		Organizers::register_hooks();

		add_action( 'init', [ Capabilities::class, 'ensure_granted' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Scanner management sits directly on the namespace — `event-ticket-scanner
			// scanner create` would stutter — with seed added as a further subcommand.
			\WP_CLI::add_command( 'event-ticket-scanner', Cli\ScannerCommand::class );
			\WP_CLI::add_command( 'event-ticket-scanner seed', Cli\SeedCommand::class );
		}
	}

	public static function dependencies_met(): bool {
		return class_exists( 'Tribe__Tickets__Main' )
			&& defined( 'Tribe__Tickets__Main::VERSION' )
			&& version_compare( \Tribe__Tickets__Main::VERSION, EVENT_TICKET_SCANNER_MIN_ET_VERSION, '>=' );
	}

	public static function render_dependency_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum Event Tickets version. */
					__( 'TEC Ticket Scanner Companion requires the Event Tickets plugin (version %s or newer) to be installed and active.', 'event-ticket-scanner' ),
					EVENT_TICKET_SCANNER_MIN_ET_VERSION
				)
			)
		);
	}

	/**
	 * Whether the current request context is allowed to talk to the API.
	 * HTTPS is required outside local/dev environments (application
	 * passwords travel as Basic auth).
	 */
	public static function transport_is_secure(): bool {
		$allow_insecure = in_array( wp_get_environment_type(), [ 'local', 'development' ], true );

		/**
		 * Filter whether plain-HTTP API access is allowed (local dev only, normally).
		 *
		 * @param bool $allow_insecure Defaults to true only for local/development environments.
		 */
		$allow_insecure = (bool) apply_filters( 'event_ticket_scanner_allow_insecure_transport', $allow_insecure );

		return is_ssl() || $allow_insecure;
	}
}
