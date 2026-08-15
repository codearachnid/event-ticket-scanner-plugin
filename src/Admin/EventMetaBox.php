<?php
declare(strict_types=1);

namespace TEC_Scanner\Admin;

use TEC_Scanner\Assignments;
use TEC_Scanner\Plugin;
use TEC_Scanner\ScannerCandidates;

defined( 'ABSPATH' ) || exit;

/**
 * "Ticket Scanners" meta box on the event edit screen — the event-side view of
 * the same assignments managed per-user under Events → Scanners.
 *
 * Who may use it: anyone who can edit the event. Who they may pick: users who
 * can already check in, plus (for scanner managers only) event authors and
 * organizer-linked users who would need the capability granted. Editing an
 * event therefore lets you staff its door, but not hand check-in rights to
 * arbitrary accounts unless you manage scanners.
 */
final class EventMetaBox {

	public const POST_TYPE = 'tribe_events';

	private const NONCE = 'tec_scanner_event_scanners';

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );
	}

	public function add_meta_box(): void {
		add_meta_box(
			'tec-scanner-event-scanners',
			__( 'Ticket Scanners', 'wp-tec-ticket-scanner' ),
			[ $this, 'render' ],
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public function render( \WP_Post $post ): void {
		$event_id = (int) $post->ID;

		if ( ! current_user_can( 'edit_post', $event_id ) ) {
			echo '<p>' . esc_html__( 'You cannot manage scanners for this event.', 'wp-tec-ticket-scanner' ) . '</p>';
			return;
		}

		$can_grant  = current_user_can( Plugin::CAP_MANAGE );
		$candidates = ScannerCandidates::for_event( $event_id );

		if ( ! $can_grant ) {
			$candidates = array_filter( $candidates, static fn ( array $c ): bool => $c['can_checkin'] );
		}

		wp_nonce_field( self::NONCE, 'tec_scanner_event_scanners_nonce' );

		echo '<p class="description">' . esc_html__( 'Who can scan tickets at the door for this event.', 'wp-tec-ticket-scanner' ) . '</p>';

		if ( ! $candidates ) {
			echo '<p>' . esc_html__( 'Nobody is available to assign yet. Create a scanner account under Events → Scanners.', 'wp-tec-ticket-scanner' ) . '</p>';
			return;
		}

		echo '<ul class="tec-scanner-candidates">';

		foreach ( $candidates as $user_id => $candidate ) {
			$this->render_candidate( (int) $user_id, $candidate, $can_grant );
		}

		echo '</ul>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Unrestricted accounts and this event\'s organizer already have access — they are shown for context and cannot be unassigned here.', 'wp-tec-ticket-scanner' )
		);

		if ( $can_grant ) {
			printf(
				'<p class="description"><a href="%s">%s</a></p>',
				esc_url( ScannerUsersPage::page_url() ),
				esc_html__( 'Manage scanner accounts', 'wp-tec-ticket-scanner' )
			);
		}
	}

	/**
	 * @param array{user:\WP_User,reasons:string[],assigned:bool,derived:bool,unrestricted:bool,can_checkin:bool,can_edit:bool} $candidate Annotated candidate.
	 */
	private function render_candidate( int $user_id, array $candidate, bool $can_grant ): void {
		// Access these users already hold; a checkbox here would be a lie.
		$locked = $candidate['unrestricted'] || $candidate['derived'];

		printf(
			'<li><label><input type="checkbox" name="tec_scanner_event_scanners[]" value="%1$d"%2$s%3$s> %4$s</label>',
			$user_id,
			$locked || $candidate['assigned'] ? ' checked' : '',
			$locked ? ' disabled' : '',
			esc_html( $candidate['user']->display_name )
		);

		if ( $candidate['reasons'] ) {
			printf( '<br><span class="description">%s</span>', esc_html( implode( ' · ', $candidate['reasons'] ) ) );
		}

		if ( $can_grant && ! $candidate['can_checkin'] ) {
			printf(
				'<br><span class="description tec-scanner-grant-note">%s</span>',
				esc_html__( 'Selecting them grants check-in access to their assigned events.', 'wp-tec-ticket-scanner' )
			);
		}

		echo '</li>';
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['tec_scanner_event_scanners_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tec_scanner_event_scanners_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$can_grant = current_user_can( Plugin::CAP_MANAGE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$submitted = array_map( 'absint', (array) ( $_POST['tec_scanner_event_scanners'] ?? [] ) );

		foreach ( ScannerCandidates::for_event( $post_id ) as $user_id => $candidate ) {
			$user_id = (int) $user_id;

			// Disabled checkboxes never post back — skip anyone whose access
			// doesn't come from a direct assignment in the first place.
			if ( $candidate['unrestricted'] || $candidate['derived'] ) {
				continue;
			}

			// Without the manage capability you may only move users who can
			// already check in; nobody gets the capability granted by proxy.
			if ( ! $can_grant && ! $candidate['can_checkin'] ) {
				continue;
			}

			if ( in_array( $user_id, $submitted, true ) ) {
				Assignments::assign( $user_id, $post_id );
			} elseif ( $candidate['assigned'] ) {
				Assignments::unassign( $user_id, $post_id );
			}
		}
	}
}
