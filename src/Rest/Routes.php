<?php
declare(strict_types=1);

namespace EventTicketScanner\Rest;

use EventTicketScanner\Attendees\AttendeeMapper;
use EventTicketScanner\Checkins\CheckinProcessor;
use EventTicketScanner\Pairing\PairingService;
use EventTicketScanner\Plugin;
use EventTicketScanner\TouchIndex;

defined( 'ABSPATH' ) || exit;

/**
 * REST surface, namespace event-ticket-scanner/v1. The contract lives in the mobile
 * repo's docs/api/openapi.yaml — response shapes here must match it exactly.
 */
final class Routes {

	private Controller $controller;

	public function __construct() {
		$touch            = new TouchIndex();
		$mapper           = new AttendeeMapper( $touch );
		$this->controller = new Controller( $mapper, new CheckinProcessor( $mapper ), new PairingService() );
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/me',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this->controller, 'me' ],
				'permission_callback' => [ $this->controller, 'can_checkin' ],
			]
		);

		register_rest_route(
			$ns,
			'/events',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this->controller, 'events' ],
				'permission_callback' => [ $this->controller, 'can_checkin' ],
				'args'                => [
					'upcoming' => [ 'type' => 'integer', 'default' => 1, 'enum' => [ 0, 1 ] ],
					'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
				],
			]
		);

		register_rest_route(
			$ns,
			'/events/(?P<event_id>\d+)/attendees',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this->controller, 'attendees' ],
				'permission_callback' => [ $this->controller, 'can_checkin' ],
				'args'                => [
					'updated_since' => [ 'type' => 'string', 'required' => false ],
					'page'          => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					'per_page'      => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 200 ],
				],
			]
		);

		register_rest_route(
			$ns,
			'/events/(?P<event_id>\d+)/stats',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this->controller, 'stats' ],
				'permission_callback' => [ $this->controller, 'can_checkin' ],
			]
		);

		register_rest_route(
			$ns,
			'/checkins',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this->controller, 'checkins' ],
				'permission_callback' => [ $this->controller, 'can_checkin' ],
				'args'                => [
					'device_id'  => [ 'type' => 'string', 'required' => true ],
					'operations' => [ 'type' => 'array', 'required' => true ],
				],
			]
		);

		// Pairing exchange: intentionally unauthenticated — the single-use
		// token (validated + consumed inside) IS the credential.
		register_rest_route(
			$ns,
			'/pair',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this->controller, 'pair' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'token'       => [ 'type' => 'string', 'required' => true ],
					'device_name' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				],
			]
		);
	}
}
