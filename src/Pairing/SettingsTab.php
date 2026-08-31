<?php
declare(strict_types=1);

namespace EventTicketScanner\Pairing;

use EventTicketScanner\Admin\Assets;
use EventTicketScanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Scanner App" tab on the Event Tickets settings screen (Tickets → Settings),
 * where devices are paired by QR code.
 *
 * A tab rather than its own menu entry: pairing is configuration, and it sits
 * next to the rest of the ticketing setup instead of adding a top-level item.
 */
final class SettingsTab {

	public const TAB_ID = 'event-ticket-scanner';

	/** Event Tickets' settings page id — the tab only renders there. */
	private const SETTINGS_PAGE = 'tec-tickets-settings';

	public function register_hooks(): void {
		// Only meaningful with Event Tickets' settings framework present.
		if ( ! class_exists( 'Tribe__Settings_Tab' ) ) {
			return;
		}

		add_action( 'tribe_settings_do_tabs', [ $this, 'register_tab' ], 16 );
		add_filter( 'tec_tickets_settings_tabs_ids', [ $this, 'add_tab_id' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/** @param string $admin_page Settings page currently being built. */
	public function register_tab( $admin_page ): void {
		if ( ! empty( $admin_page ) && self::SETTINGS_PAGE !== $admin_page ) {
			return;
		}

		if ( ! current_user_can( Plugin::CAP_CHECKIN ) ) {
			return;
		}

		new \Tribe__Settings_Tab(
			self::TAB_ID,
			__( 'Scanner App', 'event-ticket-scanner' ),
			[
				'priority'         => 30,
				'show_save'        => false,
				'display_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * @param string[] $tabs Tab ids belonging to the Tickets settings screen.
	 * @return string[]
	 */
	public function add_tab_id( $tabs ): array {
		$tabs   = (array) $tabs;
		$tabs[] = self::TAB_ID;

		return $tabs;
	}

	public function enqueue(): void {
		if ( ! $this->is_current_tab() ) {
			return;
		}

		Assets::enqueue();
	}

	private function is_current_tab(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the current screen, not acting.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return self::SETTINGS_PAGE === $page && self::TAB_ID === $tab;
	}

	public function render(): void {
		$secure = Plugin::transport_is_secure();
		?>
		<div class="event-ticket-scanner-admin">
			<div class="event-ticket-scanner-columns">
				<div class="event-ticket-scanner-panel">
					<h2><?php esc_html_e( 'Pair a scanning device', 'event-ticket-scanner' ); ?></h2>
					<p>
						<?php esc_html_e( 'Open the Event Ticket Scanner app on the phone, choose "Scan pairing code", and point it at the QR code below. The device receives its own application password tied to your account — revoke it any time from your profile.', 'event-ticket-scanner' ); ?>
					</p>

					<?php if ( ! $secure ) : ?>
						<div class="notice notice-error inline"><p>
							<?php esc_html_e( 'This site is not served over HTTPS, so pairing and API access are disabled. Enable HTTPS (or the local-development filter) first.', 'event-ticket-scanner' ); ?>
						</p></div>
					<?php else : ?>
						<p>
							<button type="button" class="button button-primary" data-event-ticket-scanner-pair data-target="event-ticket-scanner-qr">
								<?php esc_html_e( 'Generate pairing code', 'event-ticket-scanner' ); ?>
							</button>
						</p>
						<div class="event-ticket-scanner-qr-wrap" hidden>
							<div id="event-ticket-scanner-qr" class="event-ticket-scanner-qr" aria-label="<?php esc_attr_e( 'Pairing QR code', 'event-ticket-scanner' ); ?>"></div>
						</div>
						<p class="description" data-status-for="event-ticket-scanner-qr"></p>
						<p class="description">
							<?php esc_html_e( 'Codes are single-use and expire after 5 minutes. Anyone who scans one gets check-in access as you — only display it to people you trust.', 'event-ticket-scanner' ); ?>
						</p>
						<p>
							<a href="<?php echo esc_url( \EventTicketScanner\Admin\ScannerUsersPage::page_url() ); ?>">
								<?php esc_html_e( 'Pair a device for someone else, or manage scanner accounts', 'event-ticket-scanner' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<div class="event-ticket-scanner-panel">
					<h2><?php esc_html_e( 'Manual connection', 'event-ticket-scanner' ); ?></h2>
					<p><?php esc_html_e( 'You can also connect the app manually:', 'event-ticket-scanner' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'In wp-admin go to Users → Profile → Application Passwords and create one (e.g. "Door iPhone").', 'event-ticket-scanner' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: site URL. */
								esc_html__( 'In the app choose "Connect a site" and enter %s, your username, and that application password.', 'event-ticket-scanner' ),
								'<code>' . esc_html( untrailingslashit( home_url() ) ) . '</code>'
							);
							?>
						</li>
					</ol>

					<h2><?php esc_html_e( 'Status', 'event-ticket-scanner' ); ?></h2>
					<table class="widefat striped event-ticket-scanner-status">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'REST endpoint', 'event-ticket-scanner' ); ?></td>
								<td><code><?php echo esc_html( rest_url( Plugin::REST_NAMESPACE ) ); ?></code></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Event Tickets', 'event-ticket-scanner' ); ?></td>
								<td><?php echo esc_html( defined( 'Tribe__Tickets__Main::VERSION' ) ? \Tribe__Tickets__Main::VERSION : '—' ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'HTTPS', 'event-ticket-scanner' ); ?></td>
								<td><?php echo $secure ? esc_html__( 'OK', 'event-ticket-scanner' ) : esc_html__( 'Not secure', 'event-ticket-scanner' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
