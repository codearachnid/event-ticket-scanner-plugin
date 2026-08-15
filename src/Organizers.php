<?php
declare(strict_types=1);

namespace TEC_Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Organizer → user links.
 *
 * An Organizer post (tribe_organizer) can point at a WordPress user. That user
 * then scans every event that lists the organizer — without anybody having to
 * maintain per-event assignments as the organizer's calendar grows.
 *
 * The link grants *scope*, never *permission*: the user still needs
 * {@see Plugin::CAP_CHECKIN} to use the scanner at all.
 */
final class Organizers {

	/** Post meta on tribe_organizer holding the linked user ID. */
	public const META_USER = '_tec_scanner_user_id';

	public const POST_TYPE = 'tribe_organizer';

	/** Per-request memo — for_user() runs on every REST call. */
	private static array $event_cache = [];

	public static function register_hooks(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ self::class, 'save_meta_box' ], 10, 2 );
		add_action( 'tec_scanner_assignments_updated', [ self::class, 'flush_cache' ] );
	}

	public static function flush_cache(): void {
		self::$event_cache = [];
	}

	/* --------------------------------------------------------------- reads */

	public static function linked_user_id( int $organizer_id ): int {
		return (int) get_post_meta( $organizer_id, self::META_USER, true );
	}

	public static function set_linked_user( int $organizer_id, int $user_id ): void {
		if ( $user_id > 0 ) {
			update_post_meta( $organizer_id, self::META_USER, $user_id );
		} else {
			delete_post_meta( $organizer_id, self::META_USER );
		}

		self::flush_cache();
	}

	/**
	 * Organizer posts linked to a user.
	 *
	 * @return int[]
	 */
	public static function organizer_ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		return array_map(
			'intval',
			get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'post_status'    => [ 'publish', 'draft', 'private' ],
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => self::META_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			)
		);
	}

	/**
	 * Every event ID a user reaches through an organizer link.
	 *
	 * @return int[]
	 */
	public static function event_ids_for_user( int $user_id ): array {
		if ( isset( self::$event_cache[ $user_id ] ) ) {
			return self::$event_cache[ $user_id ];
		}

		$organizer_ids = self::organizer_ids_for_user( $user_id );

		if ( ! $organizer_ids ) {
			return self::$event_cache[ $user_id ] = [];
		}

		/**
		 * Filter the ceiling on organizer-derived events per user. Keeps the
		 * /events scope query bounded on very large calendars.
		 *
		 * @param int $limit Maximum events.
		 */
		$limit = (int) apply_filters( 'tec_scanner_organizer_event_limit', 1000 );

		// Deliberately a direct query: The Events Calendar joins its occurrences
		// table into every WP_Query for tribe_events and silently drops past
		// events. Scope must not depend on the date — a scanner assigned through
		// an organizer keeps access to yesterday's event for late reconciliation.
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $organizer_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_EventOrganizerID'
				   AND pm.meta_value IN ({$placeholders})
				   AND p.post_type = 'tribe_events'
				   AND p.post_status = 'publish'
				 ORDER BY pm.post_id ASC
				 LIMIT %d",
				...array_merge( $organizer_ids, [ $limit ] )
			)
		);

		return self::$event_cache[ $user_id ] = array_map( 'intval', $event_ids );
	}

	/* ------------------------------------------------------------ meta box */

	public static function add_meta_box(): void {
		if ( ! current_user_can( Plugin::CAP_MANAGE ) ) {
			return;
		}

		add_meta_box(
			'tec-scanner-organizer-user',
			__( 'Scanner access', 'wp-tec-ticket-scanner' ),
			[ self::class, 'render_meta_box' ],
			self::POST_TYPE,
			'side'
		);
	}

	public static function render_meta_box( \WP_Post $post ): void {
		$linked = self::linked_user_id( (int) $post->ID );
		$events = $linked ? self::event_ids_for_user( $linked ) : [];

		wp_nonce_field( 'tec_scanner_organizer_user', 'tec_scanner_organizer_nonce' );

		echo '<p>' . esc_html__( 'Link this organizer to a user account. That user can scan every event listing this organizer, on top of any events assigned to them directly.', 'wp-tec-ticket-scanner' ) . '</p>';

		wp_dropdown_users(
			[
				'name'              => 'tec_scanner_organizer_user',
				'selected'          => $linked,
				'include_selected'  => true,
				'show_option_none'  => __( '— No linked user —', 'wp-tec-ticket-scanner' ),
				'option_none_value' => 0,
				'show'              => 'display_name_with_login',
			]
		);

		if ( $linked && ! user_can( $linked, Plugin::CAP_CHECKIN ) ) {
			echo '<p class="description" style="color:#b32d2e">' . esc_html__( 'This user cannot check attendees in yet. Give them the Event Scanner role (Users → Scanner Users) for the link to take effect.', 'wp-tec-ticket-scanner' ) . '</p>';
		} elseif ( $linked ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of events. */
						_n( 'Currently grants access to %d event.', 'Currently grants access to %d events.', count( $events ), 'wp-tec-ticket-scanner' ),
						count( $events )
					)
				)
			);
		}
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['tec_scanner_organizer_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tec_scanner_organizer_nonce'] ) ), 'tec_scanner_organizer_user' ) ) {
			return;
		}

		if ( ! current_user_can( Plugin::CAP_MANAGE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::set_linked_user( $post_id, absint( wp_unslash( $_POST['tec_scanner_organizer_user'] ?? 0 ) ) );
	}
}
