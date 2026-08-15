<?php
declare(strict_types=1);

namespace TEC_Scanner\Pairing;

use TEC_Scanner\Plugin;

defined( 'ABSPATH' ) || exit;

/** Authenticated admin-ajax endpoint that mints pairing tokens for the QR. */
final class AjaxHandler {

	public const ACTION = 'tec_scanner_generate_pair_token';

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		check_ajax_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$target_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : get_current_user_id();

		if ( $target_id !== get_current_user_id() && ! current_user_can( Plugin::CAP_MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to pair devices for other users.', 'event-ticket-scanner' ) ], 403 );
		}

		if ( $target_id === get_current_user_id() && ! current_user_can( Plugin::CAP_CHECKIN ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to pair scanner devices.', 'event-ticket-scanner' ) ], 403 );
		}

		$target = get_userdata( $target_id );

		if ( ! $target || ! user_can( $target, Plugin::CAP_CHECKIN ) ) {
			wp_send_json_error( [ 'message' => __( 'That user cannot check attendees in.', 'event-ticket-scanner' ) ], 403 );
		}

		if ( ! wp_is_application_passwords_available_for_user( $target ) ) {
			wp_send_json_error( [ 'message' => __( 'Application passwords are unavailable for that account, so pairing cannot work.', 'event-ticket-scanner' ) ], 501 );
		}

		wp_send_json_success( ( new PairingService() )->issue_token( $target_id ) );
	}
}
