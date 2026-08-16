<?php
declare(strict_types=1);

namespace EventTicketScanner\Admin;

use EventTicketScanner\Assignments;
use EventTicketScanner\Organizers;
use EventTicketScanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Scanner Users" admin page: create check-in-only accounts, assign them to
 * specific events, and pair their devices — without ever giving them access to
 * events they aren't assigned to.
 */
final class ScannerUsersPage {

	public const SLUG = 'event-ticket-scanner-users';

	/** Event Tickets' top-level menu and its Settings entry. */
	private const PARENT_TICKETS = 'tec-tickets';

	private const SETTINGS_SLUG = 'tec-tickets-settings';

	private const NONCE_CREATE = 'event_ticket_scanner_create_user';

	private const NONCE_ASSIGN = 'event_ticket_scanner_assign_events';

	private string $hook_suffix = '';

	/** Whichever parent menu accepted the page — the page URL depends on it. */
	private static string $parent = 'admin.php';

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 31 );
		// Late, so Event Tickets has registered Settings by the time we move above it.
		add_action( 'admin_menu', [ $this, 'reorder_menu' ], 999 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function register_menu(): void {
		// Lives with ticketing, not with the calendar: scanning is what happens to
		// a ticket. Ordered above Settings by reorder_menu().
		$parents = [ self::PARENT_TICKETS, 'edit.php?post_type=' . EventMetaBox::POST_TYPE, 'users.php' ];
		$hook    = false;

		foreach ( $parents as $parent ) {
			$hook = add_submenu_page(
				$parent,
				__( 'Scanners', 'event-ticket-scanner' ),
				__( 'Scanners', 'event-ticket-scanner' ),
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

		Assets::enqueue();
	}

	/* ------------------------------------------------------------- actions */

	public function handle_actions(): void {
		if ( ! current_user_can( Plugin::CAP_MANAGE ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce.
		$action = isset( $_POST['event_ticket_scanner_action'] ) ? sanitize_key( wp_unslash( $_POST['event_ticket_scanner_action'] ) ) : '';

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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$scope = isset( $_POST['scanner_events_scope'] ) ? sanitize_key( wp_unslash( $_POST['scanner_events_scope'] ) ) : 'selected';

		if ( 'all' === $scope ) {
			Assignments::set_unrestricted( (int) $user_id, true );
		} else {
			Assignments::set_for_user( (int) $user_id, $events );
		}

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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$scope = isset( $_POST['scanner_events_scope'] ) ? sanitize_key( wp_unslash( $_POST['scanner_events_scope'] ) ) : 'selected';

		$user = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user || ! user_can( $user, Plugin::CAP_CHECKIN ) ) {
			$this->redirect( [ 'error' => 'unknown_user' ] );
		}

		Assignments::set_unrestricted( $user_id, 'all' === $scope );

		if ( 'all' !== $scope ) {
			Assignments::set_for_user( $user_id, $events );
		}

		$this->redirect( [ 'assigned' => $user_id ] );
	}

	/**
	 * Put Scanners directly above Tickets → Settings.
	 *
	 * Event Tickets numbers its own pages (Home 1, Settings 2, Help 3), so
	 * rather than guessing a position their next release may renumber, splice
	 * ours in ahead of the Settings entry wherever it currently sits.
	 */
	public function reorder_menu(): void {
		global $submenu;

		if ( self::PARENT_TICKETS !== self::$parent || empty( $submenu[ self::PARENT_TICKETS ] ) ) {
			return;
		}

		$ours  = null;
		$items = [];

		foreach ( $submenu[ self::PARENT_TICKETS ] as $item ) {
			if ( isset( $item[2] ) && self::SLUG === $item[2] ) {
				$ours = $item;
				continue;
			}

			$items[] = $item;
		}

		if ( null === $ours ) {
			return;
		}

		$rebuilt  = [];
		$inserted = false;

		foreach ( $items as $item ) {
			if ( ! $inserted && isset( $item[2] ) && self::SETTINGS_SLUG === $item[2] ) {
				$rebuilt[] = $ours;
				$inserted  = true;
			}

			$rebuilt[] = $item;
		}

		if ( ! $inserted ) {
			$rebuilt[] = $ours;
		}

		$submenu[ self::PARENT_TICKETS ] = $rebuilt;
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
		$users = Assignments::scanner_users();
		?>
		<div class="wrap event-ticket-scanner-admin">
			<h1><?php esc_html_e( 'Scanners', 'event-ticket-scanner' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Scanner accounts can only check attendees in — and only for the events assigned to them. Everything else on the site stays invisible to them, in the app and in the API.', 'event-ticket-scanner' ); ?>
			</p>

			<?php $this->render_notices(); ?>

			<h2><?php esc_html_e( 'Add a scanner', 'event-ticket-scanner' ); ?></h2>
			<form method="post" class="card event-ticket-scanner-create">
				<?php wp_nonce_field( self::NONCE_CREATE ); ?>
				<input type="hidden" name="event_ticket_scanner_action" value="create_user">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="scanner_login"><?php esc_html_e( 'Username', 'event-ticket-scanner' ); ?></label></th>
						<td><input type="text" id="scanner_login" name="scanner_login" class="regular-text" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="scanner_display_name"><?php esc_html_e( 'Display name', 'event-ticket-scanner' ); ?></label></th>
						<td><input type="text" id="scanner_display_name" name="scanner_display_name" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="scanner_email"><?php esc_html_e( 'Email', 'event-ticket-scanner' ); ?></label></th>
						<td>
							<input type="email" id="scanner_email" name="scanner_email" class="regular-text">
							<p class="description"><?php esc_html_e( 'Optional — devices are normally paired by QR code, so no login email is needed.', 'event-ticket-scanner' ); ?></p>
							<label><input type="checkbox" name="scanner_notify" value="1"> <?php esc_html_e( 'Send them an account email', 'event-ticket-scanner' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Assigned events', 'event-ticket-scanner' ); ?></th>
						<td><?php EventPicker::render( 'scanner_events', [] ); ?></td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create scanner user', 'event-ticket-scanner' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Existing scanners', 'event-ticket-scanner' ); ?></h2>

			<?php if ( ! $users ) : ?>
				<p><?php esc_html_e( 'No users can check attendees in yet.', 'event-ticket-scanner' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<table class="widefat striped event-ticket-scanner-users">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'event-ticket-scanner' ); ?></th>
						<th><?php esc_html_e( 'Events they can scan', 'event-ticket-scanner' ); ?></th>
						<th><?php esc_html_e( 'Device', 'event-ticket-scanner' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $users as $user ) : ?>
					<?php
					$user_id      = (int) $user->ID;
					$unrestricted = Assignments::is_unrestricted( $user_id );
					$granted_all  = Assignments::unrestricted_is_granted( $user_id );
					$current      = Assignments::direct_for_user( $user_id );
					$derived      = $granted_all ? [] : array_diff( Organizers::event_ids_for_user( $user_id ), $current );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
							<code><?php echo esc_html( $user->user_login ); ?></code><br>
							<span class="description"><?php echo esc_html( implode( ', ', $user->roles ) ); ?></span>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>"><?php esc_html_e( 'Edit user', 'event-ticket-scanner' ); ?></a></span>
							</div>
						</td>
						<td>
							<?php if ( $unrestricted && ! $granted_all ) : ?>
								<p><em><?php esc_html_e( 'All events — this account holds the site-wide scanning capability through its role (administrator or editor). Use a dedicated Event Scanner account to restrict access.', 'event-ticket-scanner' ); ?></em></p>
							<?php else : ?>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ASSIGN ); ?>
									<input type="hidden" name="event_ticket_scanner_action" value="assign_events">
									<input type="hidden" name="scanner_user_id" value="<?php echo esc_attr( (string) $user_id ); ?>">
									<?php EventPicker::render( 'scanner_events', $current, $granted_all ); ?>
									<p><button type="submit" class="button"><?php esc_html_e( 'Save assignments', 'event-ticket-scanner' ); ?></button></p>
								</form>
								<?php $this->render_organizer_scope( $user_id, $derived ); ?>
							<?php endif; ?>
						</td>
						<td>
							<button
								type="button"
								class="button"
								data-event-ticket-scanner-pair
								data-user-id="<?php echo esc_attr( (string) $user_id ); ?>"
								data-target="event-ticket-scanner-qr-<?php echo esc_attr( (string) $user_id ); ?>"
							><?php esc_html_e( 'Pair a device', 'event-ticket-scanner' ); ?></button>
							<div class="event-ticket-scanner-qr-wrap">
								<div id="event-ticket-scanner-qr-<?php echo esc_attr( (string) $user_id ); ?>" class="event-ticket-scanner-qr"></div>
								<p class="description" data-status-for="event-ticket-scanner-qr-<?php echo esc_attr( (string) $user_id ); ?>"></p>
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
			'<p class="description event-ticket-scanner-derived">%s<br>%s</p>',
			wp_kses_post(
				sprintf(
					/* translators: %s: organizer links. */
					__( 'Also scans every event of organizer %s.', 'event-ticket-scanner' ),
					implode( ', ', $links )
				)
			),
			esc_html(
				sprintf(
					/* translators: %d: number of events. */
					_n( '%d additional event right now.', '%d additional events right now.', count( $derived ), 'event-ticket-scanner' ),
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
						__( 'Scanner user %s created. Pair their device with the button below.', 'event-ticket-scanner' ),
						$user ? $user->user_login : ''
					)
				)
			);
		}

		if ( isset( $_GET['assigned'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Event assignments saved.', 'event-ticket-scanner' )
			);
		}

		if ( isset( $_GET['error'] ) ) {
			$raw     = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
			$message = '' !== $raw
				? sanitize_text_field( rawurldecode( $raw ) )
				: __( 'Could not complete that action.', 'event-ticket-scanner' );

			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
