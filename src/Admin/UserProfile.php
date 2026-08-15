<?php
declare(strict_types=1);

namespace EventTicketScanner\Admin;

use EventTicketScanner\Assignments;
use EventTicketScanner\Organizers;
use EventTicketScanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Event assignments on the user edit screen, so scanner scope can be managed
 * from the place admins already look.
 */
final class UserProfile {

	private const NONCE = 'event_ticket_scanner_user_events';

	public function register_hooks(): void {
		add_action( 'show_user_profile', [ $this, 'render' ] );
		add_action( 'edit_user_profile', [ $this, 'render' ] );
		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
	}

	public function render( \WP_User $user ): void {
		if ( ! current_user_can( Plugin::CAP_MANAGE ) ) {
			return;
		}

		$user_id = (int) $user->ID;

		if ( ! user_can( $user, Plugin::CAP_CHECKIN ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Ticket scanner access', 'event-ticket-scanner' ) . '</h2>';

		if ( Assignments::is_unrestricted( $user_id ) ) {
			echo '<p>' . esc_html__( 'This account can scan every event on the site (it holds the site-wide scanning capability). Per-event assignments do not apply.', 'event-ticket-scanner' ) . '</p>';
			return;
		}

		$direct  = Assignments::direct_for_user( $user_id );
		$derived = array_diff( Organizers::event_ids_for_user( $user_id ), $direct );

		wp_nonce_field( self::NONCE, 'event_ticket_scanner_user_events_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Assigned events', 'event-ticket-scanner' ); ?></th>
				<td>
					<?php EventPicker::render( 'event_ticket_scanner_events', $direct, false, false ); ?>
					<p class="description">
						<?php esc_html_e( 'The scanner app and API expose only these events to this user.', 'event-ticket-scanner' ); ?>
						<?php if ( $derived ) : ?>
							<br>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of events. */
									_n( 'Plus %d event inherited from a linked Organizer.', 'Plus %d events inherited from a linked Organizer.', count( $derived ), 'event-ticket-scanner' ),
									count( $derived )
								)
							);
							?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save( int $user_id ): void {
		if ( ! current_user_can( Plugin::CAP_MANAGE ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['event_ticket_scanner_user_events_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['event_ticket_scanner_user_events_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		Assignments::set_for_user( $user_id, array_map( 'absint', (array) ( $_POST['event_ticket_scanner_events'] ?? [] ) ) );
	}
}
