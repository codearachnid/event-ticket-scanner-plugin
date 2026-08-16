<?php
declare(strict_types=1);

namespace EventTicketScanner\Admin;

use EventTicketScanner\Assignments;

defined( 'ABSPATH' ) || exit;

/**
 * The event assignment control, shared by the Scanners screen and the user
 * profile field.
 *
 * Renders only what a user is actually assigned to — as removable chips — plus
 * a search box. A checkbox list of every event does not survive contact with a
 * real calendar: at 20 events and 10 scanners that is 200 controls on one page,
 * and it grows with both.
 */
final class EventPicker {

	/**
	 * @param string $field    Name for the submitted event IDs, e.g. "scanner_events".
	 * @param int[]  $selected Currently assigned event IDs.
	 * @param bool   $all      Whether the account is in "every event" mode.
	 * @param bool   $can_toggle_all Whether the all-events mode may be changed here.
	 */
	public static function render( string $field, array $selected, bool $all = false, bool $can_toggle_all = true ): void {
		$uid   = wp_unique_id( 'event-ticket-scanner-picker-' );
		$chips = Assignments::event_chips( $selected );
		?>
		<div class="event-ticket-scanner-picker" data-picker id="<?php echo esc_attr( $uid ); ?>">
			<?php if ( $can_toggle_all ) : ?>
				<p class="event-ticket-scanner-scope">
					<label>
						<input type="radio" name="<?php echo esc_attr( $field ); ?>_scope" value="selected" <?php checked( ! $all ); ?> data-scope="selected">
						<?php esc_html_e( 'Specific events', 'event-ticket-scanner' ); ?>
					</label>
					<label>
						<input type="radio" name="<?php echo esc_attr( $field ); ?>_scope" value="all" <?php checked( $all ); ?> data-scope="all">
						<?php esc_html_e( 'All events', 'event-ticket-scanner' ); ?>
					</label>
				</p>
			<?php endif; ?>

			<div class="event-ticket-scanner-picker-body" <?php echo $all ? 'hidden' : ''; ?>>
				<ul class="event-ticket-scanner-chips" data-chips>
					<?php foreach ( $chips as $chip ) : ?>
						<?php self::render_chip( $field, $chip ); ?>
					<?php endforeach; ?>
				</ul>

				<p class="event-ticket-scanner-empty" data-empty <?php echo $chips ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'No events assigned — this account cannot scan anything yet.', 'event-ticket-scanner' ); ?>
				</p>

				<div class="event-ticket-scanner-search">
					<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>-search">
						<?php esc_html_e( 'Search events to assign', 'event-ticket-scanner' ); ?>
					</label>
					<input
						type="search"
						id="<?php echo esc_attr( $uid ); ?>-search"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Search events to add…', 'event-ticket-scanner' ); ?>"
						autocomplete="off"
						data-search
						data-field="<?php echo esc_attr( $field ); ?>"
					>
					<ul class="event-ticket-scanner-results" data-results hidden></ul>
				</div>
			</div>

			<p class="description event-ticket-scanner-all-note" <?php echo $all ? '' : 'hidden'; ?> data-all-note>
				<?php esc_html_e( 'This account scans every event on the site, including events added later.', 'event-ticket-scanner' ); ?>
			</p>
		</div>
		<?php
	}

	/** @param array{id:int,title:string,date:string} $chip Event summary. */
	public static function render_chip( string $field, array $chip ): void {
		printf(
			'<li class="event-ticket-scanner-chip" data-chip="%1$s">
				<input type="hidden" name="%2$s[]" value="%1$s">
				<span class="event-ticket-scanner-chip-title">%3$s</span>
				%4$s
				<button type="button" class="event-ticket-scanner-chip-remove" data-remove aria-label="%5$s">&times;</button>
			</li>',
			esc_attr( (string) $chip['id'] ),
			esc_attr( $field ),
			esc_html( $chip['title'] ),
			$chip['date'] ? '<span class="event-ticket-scanner-chip-date">' . esc_html( $chip['date'] ) . '</span>' : '',
			esc_attr(
				sprintf(
					/* translators: %s: event title. */
					__( 'Remove %s', 'event-ticket-scanner' ),
					$chip['title']
				)
			)
		);
	}
}
