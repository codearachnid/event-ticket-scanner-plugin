<?php
declare(strict_types=1);

namespace EventTicketScanner\Pairing;

use EventTicketScanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * QR pairing: a logged-in admin generates a short-lived, single-use token
 * rendered as a QR code; the app scans it and exchanges it at POST /pair for
 * a freshly-minted WordPress Application Password.
 *
 * Security properties:
 *  - The QR never contains a long-lived credential — only the 5-minute token.
 *  - Tokens are stored hashed (SHA-256) and deleted on first use.
 *  - The minted application password is scoped to the admin who generated the
 *    token and is individually revocable in their profile.
 */
final class PairingService {

	public const TOKEN_TTL = 5 * MINUTE_IN_SECONDS;

	private const TRANSIENT_PREFIX = 'event_ticket_scanner_pair_';

	private const RATE_PREFIX = 'event_ticket_scanner_pair_rate_';

	private const RATE_MAX_ATTEMPTS = 10;

	/**
	 * Issue a pairing token for a user. Returns the QR payload the admin page
	 * renders. Only ever called from an authenticated admin-ajax request.
	 */
	public function issue_token( int $user_id ): array {
		$token = bin2hex( random_bytes( 20 ) );

		set_transient(
			self::TRANSIENT_PREFIX . hash( 'sha256', $token ),
			[
				'user_id' => $user_id,
				'issued'  => time(),
			],
			self::TOKEN_TTL
		);

		$user = get_userdata( $user_id );

		return [
			'v'          => 1,
			'type'       => 'event-ticket-scanner-pair',
			'url'        => untrailingslashit( home_url() ),
			'user'       => $user ? $user->user_login : '',
			'token'      => $token,
			'expires_in' => self::TOKEN_TTL,
		];
	}

	/**
	 * Exchange a scanned token for a new application password.
	 *
	 * @return array|\WP_Error Credentials payload or error.
	 */
	public function consume_token( string $token, string $device_name, string $client_ip ) {
		if ( $this->rate_limited( $client_ip ) ) {
			return new \WP_Error( 'event_ticket_scanner_rate_limited', __( 'Too many pairing attempts. Try again in a few minutes.', 'event-ticket-scanner' ), [ 'status' => 429 ] );
		}

		$this->count_attempt( $client_ip );

		$key    = self::TRANSIENT_PREFIX . hash( 'sha256', $token );
		$record = get_transient( $key );

		if ( ! is_array( $record ) || empty( $record['user_id'] ) ) {
			return new \WP_Error( 'event_ticket_scanner_invalid_token', __( 'This pairing code is invalid or has expired. Generate a fresh one in wp-admin.', 'event-ticket-scanner' ), [ 'status' => 403 ] );
		}

		// Single use — consume before minting anything.
		delete_transient( $key );

		$user = get_userdata( (int) $record['user_id'] );

		if ( ! $user || ! user_can( $user, Plugin::CAP_CHECKIN ) ) {
			return new \WP_Error( 'event_ticket_scanner_user_invalid', __( 'The pairing user no longer exists or lost check-in permission.', 'event-ticket-scanner' ), [ 'status' => 403 ] );
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new \WP_Error( 'event_ticket_scanner_app_passwords_unavailable', __( 'Application passwords are not available for this user on this site.', 'event-ticket-scanner' ), [ 'status' => 501 ] );
		}

		$device_name = sanitize_text_field( $device_name ) ?: __( 'Scanner device', 'event-ticket-scanner' );
		$label       = sprintf(
			/* translators: 1: device name, 2: date. */
			__( 'TEC Ticket Scanner — %1$s (%2$s)', 'event-ticket-scanner' ),
			$device_name,
			gmdate( 'Y-m-d H:i' )
		);

		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			[ 'name' => $label ]
		);

		if ( is_wp_error( $created ) ) {
			return new \WP_Error( 'event_ticket_scanner_password_failed', $created->get_error_message(), [ 'status' => 500 ] );
		}

		[ $plaintext_password ] = $created;

		return [
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => untrailingslashit( home_url() ),
			'username'     => $user->user_login,
			'app_password' => $plaintext_password,
		];
	}

	private function rate_limited( string $client_ip ): bool {
		$count = (int) get_transient( self::RATE_PREFIX . md5( $client_ip ) );

		return $count >= self::RATE_MAX_ATTEMPTS;
	}

	private function count_attempt( string $client_ip ): void {
		$key   = self::RATE_PREFIX . md5( $client_ip );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::TOKEN_TTL );
	}
}
