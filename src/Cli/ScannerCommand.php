<?php
declare(strict_types=1);

namespace TEC_Scanner\Cli;

use TEC_Scanner\Assignments;
use TEC_Scanner\Organizers;
use TEC_Scanner\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage scanner users and their event scope.
 *
 * ## EXAMPLES
 *
 *     wp event-ticket-scanner create doorstaff --events=501,502
 *     wp event-ticket-scanner assign doorstaff 503
 *     wp event-ticket-scanner link-organizer 77 doorstaff
 *     wp event-ticket-scanner list
 */
final class ScannerCommand {

	/**
	 * Create a check-in-only user restricted to the given events.
	 *
	 * ## OPTIONS
	 *
	 * <login>
	 * : Username for the new account.
	 *
	 * [--email=<email>]
	 * : Email address. Optional — devices pair by QR code.
	 *
	 * [--display-name=<name>]
	 * : Display name. Defaults to the login.
	 *
	 * [--events=<ids>]
	 * : Comma-separated event post IDs to assign.
	 *
	 * [--porcelain]
	 * : Output just the new user ID.
	 *
	 * @param string[]              $args       Positional args.
	 * @param array<string,string>  $assoc_args Flags.
	 */
	public function create( array $args, array $assoc_args ): void {
		$login = sanitize_user( (string) ( $args[0] ?? '' ), true );

		if ( '' === $login ) {
			\WP_CLI::error( 'A username is required.' );
		}

		if ( username_exists( $login ) ) {
			\WP_CLI::error( sprintf( 'User "%s" already exists.', $login ) );
		}

		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => sanitize_email( (string) ( $assoc_args['email'] ?? '' ) ),
				'display_name' => (string) ( $assoc_args['display-name'] ?? $login ),
				'user_pass'    => wp_generate_password( 24, true, true ),
				'role'         => Plugin::ROLE_SCANNER,
			]
		);

		if ( is_wp_error( $user_id ) ) {
			\WP_CLI::error( $user_id->get_error_message() );
		}

		$events = $this->parse_ids( (string) ( $assoc_args['events'] ?? '' ) );

		if ( $events ) {
			Assignments::set_for_user( (int) $user_id, $events );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $user_id );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Created scanner user %s (ID %d) assigned to %d event(s).',
				$login,
				$user_id,
				count( Assignments::for_user( (int) $user_id ) )
			)
		);
	}

	/**
	 * Assign events to a scanner user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <events>...
	 * : Event post IDs.
	 *
	 * [--replace]
	 * : Replace the current assignments instead of adding to them.
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function assign( array $args, array $assoc_args ): void {
		$user   = $this->resolve_user( (string) array_shift( $args ) );
		$events = $this->parse_ids( implode( ',', $args ) );

		if ( ! $events ) {
			\WP_CLI::error( 'At least one event ID is required.' );
		}

		$current = empty( $assoc_args['replace'] ) ? Assignments::direct_for_user( (int) $user->ID ) : [];

		Assignments::set_for_user( (int) $user->ID, array_merge( $current, $events ) );

		\WP_CLI::success(
			sprintf( '%s is now assigned to %d event(s).', $user->user_login, count( Assignments::direct_for_user( (int) $user->ID ) ) )
		);
	}

	/**
	 * Remove events from a scanner user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * <events>...
	 * : Event post IDs.
	 *
	 * @param string[] $args Positional args.
	 */
	public function unassign( array $args ): void {
		$user   = $this->resolve_user( (string) array_shift( $args ) );
		$events = $this->parse_ids( implode( ',', $args ) );

		Assignments::set_for_user(
			(int) $user->ID,
			array_diff( Assignments::direct_for_user( (int) $user->ID ), $events )
		);

		\WP_CLI::success( sprintf( 'Updated assignments for %s.', $user->user_login ) );
	}

	/**
	 * Link an Organizer post to a user, granting them every event of that organizer.
	 *
	 * ## OPTIONS
	 *
	 * <organizer>
	 * : Organizer post ID.
	 *
	 * [<user>]
	 * : User ID, login, or email. Omit to clear the link.
	 *
	 * @subcommand link-organizer
	 *
	 * @param string[] $args Positional args.
	 */
	public function link_organizer( array $args ): void {
		$organizer_id = absint( $args[0] ?? 0 );
		$organizer    = $organizer_id ? get_post( $organizer_id ) : null;

		if ( ! $organizer || Organizers::POST_TYPE !== $organizer->post_type ) {
			\WP_CLI::error( sprintf( '%d is not an organizer post.', $organizer_id ) );
		}

		if ( ! isset( $args[1] ) ) {
			Organizers::set_linked_user( $organizer_id, 0 );
			\WP_CLI::success( sprintf( 'Cleared the scanner user link on organizer %d.', $organizer_id ) );
			return;
		}

		$user = $this->resolve_user( (string) $args[1] );

		Organizers::set_linked_user( $organizer_id, (int) $user->ID );

		if ( ! user_can( $user, Plugin::CAP_CHECKIN ) ) {
			\WP_CLI::warning( sprintf( '%s cannot check attendees in — give them the %s role for the link to take effect.', $user->user_login, Plugin::ROLE_SCANNER ) );
		}

		\WP_CLI::success(
			sprintf(
				'%s is linked to organizer "%s" (%d event(s)).',
				$user->user_login,
				get_the_title( $organizer_id ),
				count( Organizers::event_ids_for_user( (int) $user->ID ) )
			)
		);
	}

	/**
	 * List users who can operate the scanner and the events they can see.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default: table.
	 *
	 * @param string[]             $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = [];

		foreach ( Assignments::scanner_users() as $user ) {
			$user_id = (int) $user->ID;
			$direct  = Assignments::direct_for_user( $user_id );
			$derived = array_diff( Organizers::event_ids_for_user( $user_id ), $direct );

			$rows[] = [
				'id'         => $user_id,
				'login'      => $user->user_login,
				'roles'      => implode( ',', $user->roles ),
				'scope'      => Assignments::is_unrestricted( $user_id ) ? 'all events' : 'assigned only',
				'assigned'   => implode( ',', $direct ),
				'organizer'  => implode( ',', $derived ),
			];
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			[ 'id', 'login', 'roles', 'scope', 'assigned', 'organizer' ]
		);
	}

	/** @return int[] */
	private function parse_ids( string $raw ): array {
		$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $raw ) ?: [] ) );

		return array_values( array_unique( $ids ) );
	}

	private function resolve_user( string $identifier ): \WP_User {
		$user = is_numeric( $identifier )
			? get_user_by( 'id', (int) $identifier )
			: ( get_user_by( 'login', $identifier ) ?: get_user_by( 'email', $identifier ) );

		if ( ! $user ) {
			\WP_CLI::error( sprintf( 'No user matching "%s".', $identifier ) );
		}

		return $user;
	}
}
