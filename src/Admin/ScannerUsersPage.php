<?php
declare(strict_types=1);

namespace TEC_Scanner\Admin;

use TEC_Scanner\Assignments;
use TEC_Scanner\Organizers;
use TEC_Scanner\Pairing\AjaxHandler;
use TEC_Scanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Scanner Users" admin page: create check-in-only accounts, assign them to
 * specific events, and pair their devices — without ever giving them access to
 * events they aren't assigned to.
 */
final class ScannerUsersPage {

	public const SLUG = 'tec-scanner-users';

	private const NONCE_CREATE = 'tec_scanner_create_user';

	private const NONCE_ASSIGN = 'tec_scanner_assign_events';

	private string $hook_suffix = '';

	/** Whichever parent menu accepted the page — the page URL depends on it. */
	private static string $parent = 'admin.php';

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 31 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_menu(): void {
		// Sits beside Organizers and Venues in the Events menu — scanners are
		// another thing you relate to an event, so they belong in the same list.
		$parents = [ 'edit.php?post_type=' . EventMetaBox::POST_TYPE, 'tec-tickets', 'users.php' ];
		$hook    = false;

		foreach ( $parents as $parent ) {
			$hook = add_submenu_page(
				$parent,
				__( 'Scanners', 'wp-tec-ticket-scanner' ),
				__( 'Scanners', 'wp-tec-ticket-scanner' ),
				Plugin::CAP_MANAGE,
				self::SLUG,
				[ $this, 'render' ]
			);

			if ( $hook ) {
				self::$parent = $parent;
				break;
			}
		}

		$this->hook_suffix = (string) $hook;

		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'handle_actions' ] );
		}
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => AjaxHandler::ACTION,
				'nonce'   => wp_create_nonce( AjaxHandler::ACTION ),
				'i18n'    => [
					'expired' => __( 'This code expired. Generate a new one.', 'wp-tec-ticket-scanner' ),
					'expires' => __( 'Code expires in %ss', 'wp-tec-ticket-scanner' ),
					'error'   => __( 'Could not generate a pairing code.', 'wp-tec-ticket-scanner' ),
				],
			]
		);
	}

	/* ------------------------------------------------------------- actions */

	public function handle_actions(): void {
		if ( ! current_user_can( Plugin::CAP_MANAGE ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce.
		$action = isset( $_POST['tec_scanner_action'] ) ? sanitize_key( wp_unslash( $_POST['tec_scanner_action'] ) ) : '';

		if ( 'create_user' === $action ) {
			check_admin_referer( self::NONCE_CREATE );
			$this->create_user();
		}

		if ( 'assign_events' === $action ) {
			check_admin_referer( self::NONCE_ASSIGN );
			$this->assign_events();
		}
	}

	private function create_user(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$login = sanitize_user( wp_unslash( $_POST['scanner_login'] ?? '' ), true );
		$email = sanitize_email( wp_unslash( $_POST['scanner_email'] ?? '' ) );
		$name  = sanitize_text_field( wp_unslash( $_POST['scanner_display_name'] ?? '' ) );
		$notify = ! empty( $_POST['scanner_notify'] );
		$events = array_map( 'absint', (array) ( $_POST['scanner_events'] ?? [] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $login ) {
			$this->redirect( [ 'error' => 'login_required' ] );
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $email,
				'display_name' => $name ?: $login,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'role'         => Plugin::ROLE_SCANNER,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			$this->redirect(
				[
					'error'   => 'create_failed',
					'message' => rawurlencode( $user_id->get_error_message() ),
				]
			);
		}

		Assignments::set_for_user( (int) $user_id, $events );

		if ( $notify && $email ) {
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}

		$this->redirect( [ 'created' => (int) $user_id ] );
	}

	private function assign_events(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$user_id = absint( wp_unslash( $_POST['scanner_user_id'] ?? 0 ) );
		$events  = array_map( 'absint', (array) ( $_POST['scanner_events'] ?? [] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$user = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user || ! user_can( $user, Plugin::CAP_CHECKIN ) ) {
			$this->redirect( [ 'error' => 'unknown_user' ] );
		}

		Assignments::set_for_user( $user_id, $events );

		$this->redirect( [ 'assigned' => $user_id ] );
	}

	/** Admin URL of this page, under whichever menu accepted it. */
	public static function page_url(): string {
		$separator = str_contains( self::$parent, '?' ) ? '&' : '?';

		return admin_url( self::$parent . $separator . 'page=' . self::SLUG );
	}

	/** @param array<string,int|string> $args */
	private function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, self::page_url() ) );
		exit;
	}

	/* -------------------------------------------------------------- render */

	public function render(): void {
		$users  = Assignments::scanner_users();
		$assigned_everywhere = [];

		foreach ( $users as $user ) {
			$assigned_everywhere = array_merge( $assigned_everywhere, Assignments::direct_for_user( (int) $user->ID ) );
		}

		$events = Assignments::assignable_events( $assigned_everywhere );
		?>
		<div class="wrap tec-scanner-admin">
			<h1><?php esc_html_e( 'Scanners', 'wp-tec-ticket-scanner' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Scanner accounts can only check attendees in — and only for the events assigned to them. Everything else on the site stays invisible to them, in the app and in the API.', 'wp-tec-ticket-scanner' ); ?>
			</p>

			<?php $this->render_notices(); ?>

			<?php if ( ! $events ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'No upcoming events found to assign. Publish an event first.', 'wp-tec-ticket-scanner' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Add a scanner', 'wp-tec-ticket-scanner' ); ?></h2>
			<form method="post" class="card tec-scanner-create">
				<?php wp_nonce_field( self::NONCE_CREATE ); ?>
				<input type="hidden" name="tec_scanner_action" value="create_user">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="scanner_login"><?php esc_html_e( 'Username', 'wp-tec-ticket-scanner' ); ?></label></th>
						<td><input type="text" id="scanner_login" name="scanner_login" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="scanner_display_name"><?php esc_html_e( 'Display name', 'wp-tec-ticket-scanner' ); ?></label></th>
						<td><input type="text" id="scanner_display_name" name="scanner_display_name" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="scanner_email"><?php esc_html_e( 'Email', 'wp-tec-ticket-scanner' ); ?></label></th>
						<td>
							<input type="email" id="scanner_email" name="scanner_email" class="regular-text">
							<p class="description"><?php esc_html_e( 'Optional — devices are normally paired by QR code, so no login email is needed.', 'wp-tec-ticket-scanner' ); ?></p>
							<label><input type="checkbox" name="scanner_notify" value="1"> <?php esc_html_e( 'Send them an account email', 'wp-tec-ticket-scanner' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Assigned events', 'wp-tec-ticket-scanner' ); ?></th>
						<td><?php $this->render_event_checkboxes( $events, [] ); ?></td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create scanner user', 'wp-tec-ticket-scanner' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Existing scanners', 'wp-tec-ticket-scanner' ); ?></h2>

			<?php if ( ! $users ) : ?>
				<p><?php esc_html_e( 'No users can check attendees in yet.', 'wp-tec-ticket-scanner' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped tec-scanner-users">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'wp-tec-ticket-scanner' ); ?></th>
						<th><?php esc_html_e( 'Events they can scan', 'wp-tec-ticket-scanner' ); ?></th>
						<th><?php esc_html_e( 'Device', 'wp-tec-ticket-scanner' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$user_id      = (int) $user->ID;
					$unrestricted = Assignments::is_unrestricted( $user_id );
					$current      = Assignments::direct_for_user( $user_id );
					$derived      = array_diff( Organizers::event_ids_for_user( $user_id ), $current );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
							<code><?php echo esc_html( $user->user_login ); ?></code><br>
							<span class="description"><?php echo esc_html( implode( ', ', $user->roles ) ); ?></span>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php esc_html_e( 'Edit user', 'wp-tec-ticket-scanner' ); ?></a></span>
							</div>
						</td>
						<td>
							<?php if ( $unrestricted ) : ?>
								<p><em><?php esc_html_e( 'All events — this account has the site-wide scanning capability (administrator or editor). Use a dedicated Event Scanner account to restrict access.', 'wp-tec-ticket-scanner' ); ?></em></p>
							<?php else : ?>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ASSIGN ); ?>
									<input type="hidden" name="tec_scanner_action" value="assign_events">
									<input type="hidden" name="scanner_user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
									<?php $this->render_event_checkboxes( $events, $current ); ?>
									<p><button type="submit" class="button"><?php esc_html_e( 'Save assignments', 'wp-tec-ticket-scanner' ); ?></button></p>
								</form>
								<?php $this->render_organizer_scope( $user_id, $derived ); ?>
							<?php endif; ?>
						</td>
						<td>
							<button
								type="button"
								class="button"
								data-tec-scanner-pair
								data-user-id="<?php echo esc_attr( (string) $user_id ); ?>"
								data-target="tec-scanner-qr-<?php echo esc_attr( (string) $user_id ); ?>"
							><?php esc_html_e( 'Pair a device', 'wp-tec-ticket-scanner' ); ?></button>
							<div class="tec-scanner-qr-wrap">
								<div id="tec-scanner-qr-<?php echo esc_attr( (string) $user_id ); ?>" class="tec-scanner-qr"></div>
								<p class="description" data-status-for="tec-scanner-qr-<?php echo esc_attr( (string) $user_id ); ?>"></p>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param \WP_Post[] $events   Selectable events.
	 * @param int[]      $selected Currently assigned event IDs.
	 */
	private function render_event_checkboxes( array $events, array $selected ): void {
		if ( ! $events ) {
			echo '<p class="description">' . esc_html__( 'No events available.', 'wp-tec-ticket-scanner' ) . '</p>';
			return;
		}

		echo '<fieldset class="tec-scanner-event-list">';

		foreach ( $events as $event ) {
			$event_id = (int) $event->ID;
			$start    = (string) get_post_meta( $event_id, '_EventStartDate', true );

			printf(
				'<label><input type="checkbox" name="scanner_events[]" value="%1$d"%2$s> %3$s <span class="description">%4$s</span></label>',
				$event_id,
				in_array( $event_id, $selected, true ) ? ' checked' : '',
				esc_html( html_entity_decode( get_the_title( $event ), ENT_QUOTES ) ),
				esc_html( $start ? mysql2date( get_option( 'date_format' ), $start ) : '' )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Events this user reaches through an Organizer link — not editable here;
	 * they follow the organizer's calendar.
	 *
	 * @param int[] $derived Organizer-derived event IDs.
	 */
	private function render_organizer_scope( int $user_id, array $derived ): void {
		$organizer_ids = Organizers::organizer_ids_for_user( $user_id );

		if ( ! $organizer_ids ) {
			return;
		}

		$links = [];

		foreach ( $organizer_ids as $organizer_id ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( (string) get_edit_post_link( $organizer_id ) ),
				esc_html( html_entity_decode( get_the_title( $organizer_id ), ENT_QUOTES ) )
			);
		}

		printf(
			'<p class="description tec-scanner-derived">%s<br>%s</p>',
			wp_kses_post(
				sprintf(
					/* translators: %s: organizer links. */
					__( 'Also scans every event of organizer %s.', 'wp-tec-ticket-scanner' ),
					implode( ', ', $links )
				)
			),
			esc_html(
				sprintf(
					/* translators: %d: number of events. */
					_n( '%d additional event right now.', '%d additional events right now.', count( $derived ), 'wp-tec-ticket-scanner' ),
					count( $derived )
				)
			)
		);
	}

	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notices.
		if ( isset( $_GET['created'] ) ) {
			$user = get_userdata( absint( wp_unslash( $_GET['created'] ) ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: user login. */
						__( 'Scanner user %s created. Pair their device with the button below.', 'wp-tec-ticket-scanner' ),
						$user ? $user->user_login : ''
					)
				)
			);
		}

		if ( isset( $_GET['assigned'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Event assignments saved.', 'wp-tec-ticket-scanner' )
			);
		}

		if ( isset( $_GET['error'] ) ) {
			$message = isset( $_GET['message'] )
				? sanitize_text_field( rawurldecode( wp_unslash( $_GET['message'] ) ) )
				: __( 'Could not complete that action.', 'wp-tec-ticket-scanner' );

			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
