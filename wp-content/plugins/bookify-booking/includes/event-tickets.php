<?php
/**
 * The ticket form (T35).
 *
 * The booking form's counterpart for an event, and shaped the same way on purpose: every string it
 * prints comes from $args, so the shortcode and the Elementor widget both supply their own copy and
 * neither has to change this file to reword the form; it posts to the same
 * `admin_post_bookify_booking` handler through the same nonce, the same honeypot and the same bot
 * guard; and it reuses `bookify_booking_form_field()` so a rejected submission comes back with the
 * message against the field it is about.
 *
 * What it does not have is a day and a time: an event happens once, on the date it stores (D21), so
 * there is nothing to choose but the ticket and how many. The write path reads the date and the time
 * from the event for the same reason — a request cannot name a moment for a ticket to be at.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The copy the ticket form falls back to.
 *
 * 'event' is not copy: it is the event the form sells tickets for, and a form without one renders
 * nothing at all.
 *
 * @return array
 */
function bookify_event_ticket_form_defaults() {
	return array(
		'event'          => 0,
		'title'          => __( 'Tickets', 'bookify-booking' ),
		'intro'          => __( 'Choose your tickets and we will confirm them by email.', 'bookify-booking' ),
		'submit_label'   => __( 'Book tickets', 'bookify-booking' ),
		'success'        => __( 'Thank you — your tickets are booked and the confirmation is on its way.', 'bookify-booking' ),
		'error'          => __( 'That booking could not be accepted. Please try again.', 'bookify-booking' ),
		'empty'          => __( 'Tickets for this event are not on sale.', 'bookify-booking' ),
		'over'           => __( 'This event has finished, so its tickets are no longer for sale.', 'bookify-booking' ),
		'sold_out'       => __( 'Every ticket for this event has gone.', 'bookify-booking' ),
		'label_tier'     => __( 'Ticket', 'bookify-booking' ),
		'label_quantity' => __( 'How many', 'bookify-booking' ),
		'label_name'     => __( 'Your name', 'bookify-booking' ),
		'label_email'    => __( 'Email', 'bookify-booking' ),
		'label_phone'    => __( 'Phone', 'bookify-booking' ),
		// The number is passed in, so a site that reads right-to-left or writes "4 left" differently
		// can say so without this file changing.
		'left'           => __( '%s left', 'bookify-booking' ),
		'tiers'          => array(),
	);
}

/**
 * How many of one tier's tickets a visitor may still ask for, the event's own places included.
 *
 * The tier's inventory is the number the owner gave it; the event's capacity is the second ceiling
 * on the same date (D21). What is offered is the smaller of the two, so the form cannot ask for
 * something the write path would refuse.
 *
 * @param int $tier_id  The tier.
 * @param int $event_id The event.
 * @return int
 */
function bookify_tier_available( $tier_id, $event_id ) {
	$left     = bookify_tier_places_left( $tier_id );
	$capacity = bookify_event_capacity( $event_id );

	if ( $capacity > 0 ) {
		$left = min( $left, bookify_event_places_left( $event_id ) );
	}

	return max( 0, $left );
}

/**
 * The tickets the form offers, as tier id => label.
 *
 * A tier with nothing left is left out rather than shown greyed: the visitor cannot book it, and
 * listing it would only invite a refusal. The label carries the price and what is left, from the
 * same functions the write path counts with.
 *
 * @param int   $event_id The event.
 * @param array $args     The form's copy, for the "left" wording.
 * @return array<int,string>
 */
function bookify_event_tier_choices( $event_id, array $args ) {
	$choices = array();

	foreach ( bookify_event_tiers( $event_id ) as $tier ) {
		$left = bookify_tier_available( $tier->ID, $event_id );

		if ( $left < 1 ) {
			continue;
		}

		$label = bookify_booking_service_row_label( $tier->post_title, bookify_tier_price_label( $tier->ID ), 0 );

		$choices[ $tier->ID ] = sprintf( '%s — %s', $label, sprintf( $args['left'], number_format_i18n( $left ) ) );
	}

	return $choices;
}

/**
 * The wrapper a visitor with nothing to buy is shown.
 *
 * @param string $message What to say.
 * @return string
 */
function bookify_event_tickets_notice_html( $message ) {
	return sprintf(
		'<div class="bookify-booking bookify-tickets"><p class="bookify-booking__empty">%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Render the ticket form for one event.
 *
 * @param array $args Copy overrides; see bookify_event_ticket_form_defaults().
 * @return string
 */
function bookify_event_ticket_form_html( array $args = array() ) {
	$args  = wp_parse_args( $args, bookify_event_ticket_form_defaults() );
	$event = bookify_booking_bookable_event( $args['event'] );

	if ( null === $event ) {
		return bookify_event_tickets_notice_html( $args['empty'] );
	}

	// Tickets stop being sold when the event starts, and the write path refuses one after that; the
	// form says so rather than offering a control that cannot work.
	if ( bookify_event_has_started( $event->ID ) ) {
		return bookify_event_tickets_notice_html( $args['over'] );
	}

	$choices = bookify_event_tier_choices( $event->ID, $args );

	if ( ! $choices ) {
		return bookify_event_tickets_notice_html( $args['sold_out'] );
	}

	$notice = bookify_booking_form_notice( $args );
	$values = bookify_booking_form_values();

	// What the visitor typed, when there is anything of theirs left to show.
	$tier_value   = isset( $values['tier_id'] ) ? absint( $values['tier_id'] ) : 0;
	$party_value  = isset( $values['party_size'] ) ? (string) $values['party_size'] : '1';
	$name_value   = isset( $values['name'] ) ? (string) $values['name'] : '';
	$email_value  = isset( $values['email'] ) ? (string) $values['email'] : '';
	$phone_value  = isset( $values['phone'] ) ? (string) $values['phone'] : '';

	// A tier that is no longer on offer cannot be preselected, so the first one that is takes its place.
	if ( ! isset( $choices[ $tier_value ] ) ) {
		$tier_value = (int) array_key_first( $choices );
	}

	// The ceiling on the quantity: what the chosen tier has left, and what its own script is told
	// about every other tier so it can move the ceiling when the visitor changes their mind. The
	// write path refuses a larger party either way — this is the same rule, printed where the
	// visitor can read it.
	$available = array();

	foreach ( array_keys( $choices ) as $offered_tier ) {
		$available[ (string) $offered_tier ] = bookify_tier_available( $offered_tier, $event->ID );
	}

	$party_max = $available[ (string) $tier_value ];

	wp_enqueue_style( 'bookify-booking-form' );
	wp_enqueue_script( 'bookify-event-tickets' );

	ob_start();
	?>
	<div
		class="bookify-booking bookify-tickets"
		data-bookify-tickets
		data-bookify-tier-available="<?php echo esc_attr( wp_json_encode( $available ) ); ?>"
	>
		<?php if ( $notice ) : ?>
			<div class="bookify-booking__notice <?php echo esc_attr( $notice['class'] ); ?>" role="status" aria-live="polite"><?php echo esc_html( $notice['text'] ); ?></div>
		<?php endif; ?>

		<?php if ( '' !== $args['title'] ) : ?>
			<h2 class="bookify-booking__title"><?php echo esc_html( $args['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( '' !== $args['intro'] ) : ?>
			<p class="bookify-booking__intro"><?php echo esc_html( $args['intro'] ); ?></p>
		<?php endif; ?>

		<form class="bookify-booking__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bookify-ticket-form>
			<input type="hidden" name="action" value="bookify_booking" />
			<?php wp_nonce_field( 'bookify_booking_submit', 'bookify_nonce' ); ?>

			<?php // The event is the page's, not the visitor's: the write path reads the date, the time and the price from it. ?>
			<input type="hidden" name="bookify_event_id" value="<?php echo esc_attr( (string) $event->ID ); ?>" />

			<?php
			/*
			 * One tier on sale is not a choice, so the control becomes a hidden field — the same
			 * judgement T26 made about a session's page — and it keeps the name the write path reads.
			 */
			if ( 1 === count( $choices ) ) {
				printf(
					'<input type="hidden" name="bookify_tier_id" value="%s" data-bookify-tier />',
					esc_attr( (string) $tier_value )
				);
			} else {
				echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					'tier_id',
					$args['label_tier'],
					array(
						'tag'     => 'select',
						'value'   => $tier_value,
						'options' => $choices,
						'attrs'   => array(
							'required'          => true,
							'data-bookify-tier' => true,
						),
					)
				);
			}

			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'party_size',
				$args['label_quantity'],
				array(
					'type'  => 'number',
					'value' => $party_value,
					'attrs' => array(
						'min'                 => '1',
						'max'                 => (string) $party_max,
						'step'                => '1',
						'required'            => true,
						'data-bookify-quantity' => true,
					),
				)
			);

			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'name',
				$args['label_name'],
				array(
					'value' => $name_value,
					'attrs' => array(
						'autocomplete' => 'name',
						'required'     => true,
					),
				)
			);

			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'email',
				$args['label_email'],
				array(
					'type'  => 'email',
					'value' => $email_value,
					'attrs' => array(
						'autocomplete' => 'email',
						'required'     => true,
					),
				)
			);

			echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				'phone',
				$args['label_phone'],
				array(
					'type'  => 'tel',
					'value' => $phone_value,
					'attrs' => array( 'autocomplete' => 'tel' ),
				)
			);
			?>

			<?php // Must stay empty: a bot fills every field it can see. A real visitor never reaches it. ?>
			<p class="bookify-booking__honeypot" aria-hidden="true">
				<input type="text" id="bookify_website" name="bookify_website" tabindex="-1" autocomplete="off" />
			</p>

			<?php
			// The same guard the booking form posts through, printed the same way: nothing at all
			// unless the owner has configured keys for it (T19).
			echo bookify_bots_turnstile_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, from an escaped key.
			?>

			<p class="bookify-booking__submit">
				<button type="submit"><?php echo esc_html( $args['submit_label'] ); ?></button>
			</p>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The ticket form as a shortcode.
 *
 * With no `event` attribute the shortcode sells tickets for the event it is written on, which is what
 * an owner will expect from a page built for one event.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function bookify_event_tickets_shortcode( $atts ) {
	$defaults = bookify_event_ticket_form_defaults();

	$atts = shortcode_atts(
		array(
			'event'          => 0,
			'title'          => $defaults['title'],
			'intro'          => $defaults['intro'],
			'submit_label'   => $defaults['submit_label'],
			'success'        => $defaults['success'],
			'error'          => $defaults['error'],
		),
		$atts,
		'bookify_event_tickets'
	);

	$event_id = absint( $atts['event'] );

	if ( ! $event_id && is_singular( 'bookify_event' ) ) {
		$event_id = (int) get_the_ID();
	}

	return bookify_event_ticket_form_html(
		array(
			'event'        => $event_id,
			'title'        => $atts['title'],
			'intro'        => $atts['intro'],
			'submit_label' => $atts['submit_label'],
			'success'      => $atts['success'],
			'error'        => $atts['error'],
		)
	);
}

/**
 * Register the ticket form's script.
 *
 * Nothing is enqueued here: the render function asks for the handle, so a page without a ticket form
 * never loads the file. There is no datepicker and no calendar — an event has one date — so this is
 * the whole script: it moves the quantity ceiling when the visitor changes tier.
 */
function bookify_event_tickets_register_script() {
	wp_register_script(
		'bookify-event-tickets',
		BOOKIFY_BOOKING_URL . 'assets/event-tickets.js',
		array(),
		BOOKIFY_BOOKING_VERSION,
		true
	);
}

/**
 * Register the shortcode and the script.
 */
function bookify_booking_register_event_tickets() {
	add_shortcode( 'bookify_event_tickets', 'bookify_event_tickets_shortcode' );
	add_action( 'wp_enqueue_scripts', 'bookify_event_tickets_register_script' );
}
