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

		if ( ! current_user_can( Plugin::CAP_CHECKIN ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to pair scanner devices.', 'wp-tec-ticket-scanner' ) ], 403 );
		}

		if ( ! wp_is_application_passwords_available_for_user( wp_get_current_user() ) ) {
			wp_send_json_error( [ 'message' => __( 'Application passwords are unavailable for your account, so pairing cannot work.', 'wp-tec-ticket-scanner' ) ], 501 );
		}

		wp_send_json_success( ( new PairingService() )->issue_token( get_current_user_id() ) );
	}
}
