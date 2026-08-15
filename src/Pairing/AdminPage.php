<?php
declare(strict_types=1);

namespace TEC_Scanner\Pairing;

use TEC_Scanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Scanner App" admin page: pair a phone by scanning a QR code.
 * Registered under the Event Tickets admin menu (falls back to Tools).
 */
final class AdminPage {

	public const SLUG = 'tec-scanner-app';

	private string $hook_suffix = '';

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_menu(): void {
		$hook = add_submenu_page(
			'tec-tickets',
			__( 'Scanner App', 'wp-tec-ticket-scanner' ),
			__( 'Scanner App', 'wp-tec-ticket-scanner' ),
			Plugin::CAP_CHECKIN,
			self::SLUG,
			[ $this, 'render' ]
		);

		if ( ! $hook ) {
			// Event Tickets menu not present (or renamed) — fall back to Tools.
			$hook = add_submenu_page(
				'tools.php',
				__( 'Scanner App', 'wp-tec-ticket-scanner' ),
				__( 'Scanner App', 'wp-tec-ticket-scanner' ),
				Plugin::CAP_CHECKIN,
				self::SLUG,
				[ $this, 'render' ]
			);
		}

		$this->hook_suffix = (string) $hook;
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'tec-scanner-qrcode', TEC_SCANNER_URL . 'assets/qrcode.min.js', [], TEC_SCANNER_VERSION, true );
		wp_enqueue_script( 'tec-scanner-admin', TEC_SCANNER_URL . 'assets/admin.js', [ 'tec-scanner-qrcode' ], TEC_SCANNER_VERSION, true );
		wp_enqueue_style( 'tec-scanner-admin', TEC_SCANNER_URL . 'assets/admin.css', [], TEC_SCANNER_VERSION );

		wp_localize_script(
			'tec-scanner-admin',
			'tecScannerAdmin',
			[
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => AjaxHandler::ACTION,
				'nonce'    => wp_create_nonce( AjaxHandler::ACTION ),
				'i18n'     => [
					'expired' => __( 'This code expired. Generate a new one.', 'wp-tec-ticket-scanner' ),
					'expires' => __( 'Code expires in %ss', 'wp-tec-ticket-scanner' ),
					'error'   => __( 'Could not generate a pairing code.', 'wp-tec-ticket-scanner' ),
				],
			]
		);
	}

	public function render(): void {
		$secure = Plugin::transport_is_secure();
		?>
		<div class="wrap tec-scanner-admin">
			<h1><?php esc_html_e( 'Event Ticket Scanner', 'wp-tec-ticket-scanner' ); ?></h1>

			<div class="tec-scanner-columns">
				<div class="card tec-scanner-pair-card">
					<h2><?php esc_html_e( 'Pair a scanning device', 'wp-tec-ticket-scanner' ); ?></h2>
					<p>
						<?php esc_html_e( 'Open the TEC Ticket Scanner app on the phone, choose "Scan pairing code", and point it at the QR code below. The device receives its own application password tied to your account — revoke it any time from your profile.', 'wp-tec-ticket-scanner' ); ?>
					</p>

					<?php if ( ! $secure ) : ?>
						<div class="notice notice-error inline"><p>
							<?php esc_html_e( 'This site is not served over HTTPS, so pairing and API access are disabled. Enable HTTPS (or the local-development filter) first.', 'wp-tec-ticket-scanner' ); ?>
						</p></div>
					<?php else : ?>
						<div class="tec-scanner-qr-wrap">
							<div id="tec-scanner-qr" class="tec-scanner-qr" aria-label="<?php esc_attr_e( 'Pairing QR code', 'wp-tec-ticket-scanner' ); ?>"></div>
							<p class="description" data-status-for="tec-scanner-qr"></p>
						</div>
						<p>
							<button type="button" class="button button-primary" data-tec-scanner-pair data-target="tec-scanner-qr">
								<?php esc_html_e( 'Generate pairing code', 'wp-tec-ticket-scanner' ); ?>
							</button>
						</p>
						<p class="description">
							<?php esc_html_e( 'Codes are single-use and expire after 5 minutes. Anyone who scans one gets check-in access as you — only display it to people you trust.', 'wp-tec-ticket-scanner' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Manual connection', 'wp-tec-ticket-scanner' ); ?></h2>
					<p><?php esc_html_e( 'You can also connect the app manually:', 'wp-tec-ticket-scanner' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'In wp-admin go to Users → Profile → Application Passwords and create one (e.g. "Door iPhone").', 'wp-tec-ticket-scanner' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: site URL. */
								esc_html__( 'In the app choose "Connect a site" and enter %s, your username, and that application password.', 'wp-tec-ticket-scanner' ),
								'<code>' . esc_html( untrailingslashit( home_url() ) ) . '</code>'
							);
							?>
						</li>
					</ol>

					<h2><?php esc_html_e( 'Status', 'wp-tec-ticket-scanner' ); ?></h2>
					<table class="widefat striped tec-scanner-status">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'REST endpoint', 'wp-tec-ticket-scanner' ); ?></td>
								<td><code><?php echo esc_html( rest_url( Plugin::REST_NAMESPACE ) ); ?></code></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Event Tickets', 'wp-tec-ticket-scanner' ); ?></td>
								<td><?php echo esc_html( defined( 'Tribe__Tickets__Main::VERSION' ) ? \Tribe__Tickets__Main::VERSION : '—' ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'HTTPS', 'wp-tec-ticket-scanner' ); ?></td>
								<td><?php echo $secure ? esc_html__( 'OK', 'wp-tec-ticket-scanner' ) : esc_html__( 'Not secure', 'wp-tec-ticket-scanner' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
