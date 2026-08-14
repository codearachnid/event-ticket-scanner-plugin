<?php
declare(strict_types=1);

namespace TEC_Scanner\Attendees;

defined( 'ABSPATH' ) || exit;

/**
 * Per-provider attendee storage details, verified against Event Tickets
 * 5.29.x source (see the mobile repo's PLAN.md "Verified facts"). One
 * attendee = one WP post; everything else is postmeta.
 */
final class Providers {

	/**
	 * @return array<string, array<string, string>> post_type => meta key map.
	 */
	public static function map(): array {
		$map = [
			// Tickets Commerce (free Event Tickets).
			'tec_tc_attendee'      => [
				'slug'          => 'tickets-commerce',
				'event'         => '_tec_tickets_commerce_event',
				'ticket'        => '_tec_tickets_commerce_ticket',
				'security'      => '_tec_tickets_commerce_security_code',
				'checkin'       => '_tec_tickets_commerce_checked_in',
				'status'        => '_tec_tickets_commerce_status',
				'holder_name'   => '_tec_tickets_commerce_full_name',
				'holder_email'  => '_tec_tickets_commerce_email',
			],
			// RSVP (free Event Tickets).
			'tribe_rsvp_attendees' => [
				'slug'          => 'rsvp',
				'event'         => '_tribe_rsvp_event',
				'ticket'        => '_tribe_rsvp_product',
				'security'      => '_tribe_rsvp_security_code',
				'checkin'       => '_tribe_rsvp_checkedin',
				'status'        => '_tribe_rsvp_status',
				'holder_name'   => '_tribe_rsvp_full_name',
				'holder_email'  => '_tribe_rsvp_email',
			],
			// WooCommerce tickets (Event Tickets Plus) — supported when present.
			'tribe_wooticket'      => [
				'slug'          => 'woocommerce',
				'event'         => '_tribe_wooticket_event',
				'ticket'        => '_tribe_wooticket_product',
				'security'      => '_tribe_wooticket_security_code',
				'checkin'       => '_tribe_wooticket_checkedin',
				'status'        => '', // Woo order status lives on the order post.
				'holder_name'   => '_tribe_tickets_full_name',
				'holder_email'  => '_tribe_tickets_email',
			],
		];

		/**
		 * Filter the provider meta map (e.g. to support additional providers).
		 *
		 * @param array $map post_type => meta key map.
		 */
		return (array) apply_filters( 'tec_scanner_provider_map', $map );
	}

	/** @return string[] Attendee post types. */
	public static function attendee_post_types(): array {
		return array_keys( self::map() );
	}

	public static function for_post_type( string $post_type ): ?array {
		return self::map()[ $post_type ] ?? null;
	}

	public static function event_id_for_attendee( int $attendee_id ): ?int {
		$post = get_post( $attendee_id );

		if ( ! $post ) {
			return null;
		}

		$config = self::for_post_type( $post->post_type );

		if ( ! $config ) {
			return null;
		}

		$event_id = (int) get_post_meta( $attendee_id, $config['event'], true );

		return $event_id > 0 ? $event_id : null;
	}

	/**
	 * Normalize a provider-specific status to the contract's order_status enum:
	 * completed | pending | refunded | cancelled | denied.
	 */
	public static function normalize_status( string $post_type, int $attendee_id, array $config ): string {
		if ( 'tribe_rsvp_attendees' === $post_type ) {
			$going = strtolower( (string) get_post_meta( $attendee_id, $config['status'], true ) );

			return in_array( $going, [ 'yes', 'going' ], true ) || '' === $going ? 'completed' : 'denied';
		}

		if ( 'tribe_wooticket' === $post_type ) {
			$order_id = (int) get_post_meta( $attendee_id, '_tribe_wooticket_order', true );
			$status   = $order_id ? str_replace( 'wc-', '', (string) get_post_status( $order_id ) ) : '';

			return match ( $status ) {
				'completed', 'processing' => 'completed',
				'refunded' => 'refunded',
				'cancelled', 'failed' => 'cancelled',
				default => 'pending',
			};
		}

		// Tickets Commerce statuses arrive like "completed", "pending", …
		$status = strtolower( (string) get_post_meta( $attendee_id, $config['status'], true ) );

		return match ( $status ) {
			'completed', 'complete', '' => 'completed',
			'refunded' => 'refunded',
			'voided', 'cancelled', 'denied', 'trash' => 'cancelled',
			default => 'pending',
		};
	}

	/** Active provider slugs for /me (module class name → slug). */
	public static function active_slugs(): array {
		$slugs = [ 'rsvp' ];

		if ( class_exists( 'TEC\\Tickets\\Commerce\\Module' ) && function_exists( 'tec_tickets_commerce_is_enabled' )
			? tec_tickets_commerce_is_enabled()
			: class_exists( 'TEC\\Tickets\\Commerce\\Module' ) ) {
			$slugs[] = 'tickets-commerce';
		}

		if ( class_exists( 'Tribe__Tickets_Plus__Commerce__WooCommerce__Main' ) ) {
			$slugs[] = 'woocommerce';
		}

		return $slugs;
	}
}
