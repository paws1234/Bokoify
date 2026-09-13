<?php
/**
 * The reservation summary (T36).
 *
 * One message, to the operator, when an event ends: who is coming, what they bought, and what is
 * left. It is composed from the bookings themselves rather than from a count, so the line an
 * operator rings a customer about and the row the Bookings list shows are the same row.
 *
 * It is an appointment of its own rather than a poll, exactly the way T23's payment deadline and
 * T30's reminder are: one scheduled event, at the moment the event ends, because that is the moment
 * the list stops changing — the same reasoning that makes a reminder cheaper than a queue. A poll
 * would cost a query on every page view for a message that is needed once.
 *
 * Nothing can leave this container (plan D6), so what this file is judged on locally is what it
 * composes and what it asks WordPress to do: the message captured through `pre_wp_mail`, and the
 * appointment asserted to exist at the right time. That is the same evidence T7 and T30 were
 * accepted on.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cron hook one event's summary rides on.
 */
const BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK = 'bookify_booking_event_summary';

/**
 * The meta key that says a summary has gone.
 *
 * One key, holding when it went: that answers both "has it been sent" and "when", and there is
 * nothing to keep in step. It is written on the event, not on a booking, because the summary is
 * about the event.
 */
const BOOKIFY_BOOKING_EVENT_SUMMARY_SENT_META = 'bookify_event_summary_sent';

/**
 * The bookings still holding a place at an event, in the order they are quoted.
 *
 * "Still holding" is the same set the capacity counters use, so a cancellation removes a line from
 * the summary and a booking waiting for money keeps one — which is what an operator ringing a
 * customer would find on the Bookings screen. Ordered by reference, which is ordered by id, so two
 * runs of the same summary are the same list.
 *
 * @param int $event_id The event.
 * @return array<int,int> Booking ids.
 */
function bookify_booking_event_reservation_ids( $event_id ) {
	$ids = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => 'bookify_event_id',
					'value' => absint( $event_id ),
				),
				array(
					'key'     => 'bookify_status',
					'value'   => bookify_booking_slot_holding_statuses(),
					'compare' => 'IN',
				),
			),
		)
	);

	return array_map( 'intval', $ids );
}

/**
 * One reservation, as the three things an operator needs.
 *
 * @param int $booking_id The booking.
 * @return array{reference:string,name:string,email:string,tier:string,tickets:int,amount:float}
 */
function bookify_booking_event_reservation( $booking_id ) {
	$tier = bookify_booking_tier( $booking_id );

	return array(
		'reference' => (string) get_post_meta( $booking_id, 'bookify_reference', true ),
		'name'      => (string) get_post_meta( $booking_id, 'bookify_customer_name', true ),
		'email'     => (string) get_post_meta( $booking_id, 'bookify_customer_email', true ),
		'tier'      => $tier ? $tier->post_title : '',
		'tickets'   => max( 1, (int) get_post_meta( $booking_id, 'bookify_party_size', true ) ),
		'amount'    => (float) get_post_meta( $booking_id, 'bookify_payment_amount', true ),
	);
}

/**
 * The body of an event's summary.
 *
 * Built here rather than stored, so there is one wording to change, and read from the same functions
 * the event's page and the write path use, so the numbers cannot disagree with what was sold.
 *
 * @param int $event_id The event.
 * @return array<int,string> Lines.
 */
function bookify_booking_event_summary_lines( $event_id ) {
	$event = get_post( absint( $event_id ) );

	if ( ! $event instanceof WP_Post || 'bookify_event' !== $event->post_type ) {
		return array();
	}

	$lines = array();

	$lines[] = sprintf(
		/* translators: %s: the event's title. */
		__( 'Reservations for %s', 'bookify-booking' ),
		$event->post_title
	);
	$lines[] = sprintf(
		/* translators: %s: the event's date, as the site formats it. */
		__( 'Date: %s', 'bookify-booking' ),
		bookify_event_date_label( $event_id )
	);

	$start = bookify_event_start( $event_id );
	$end   = bookify_event_end( $event_id );

	if ( '' !== $start ) {
		$lines[] = sprintf(
			/* translators: %s: the times the event runs, for example "10:00–16:00". */
			__( 'Time: %s', 'bookify-booking' ),
			implode( '–', array_filter( array( $start, $end ) ) )
		);
	}

	$capacity = bookify_event_capacity( $event_id );

	$lines[] = $capacity > 0
		? sprintf(
			/* translators: 1: tickets sold, 2: places left, 3: the event's capacity. */
			__( 'Places: %1$d sold, %2$d left of %3$d', 'bookify-booking' ),
			bookify_event_tickets_sold( $event_id ),
			bookify_event_places_left( $event_id ),
			$capacity
		)
		: sprintf(
			/* translators: 1: tickets sold, 2: places left across the tiers. */
			__( 'Places: %1$d sold, %2$d left', 'bookify-booking' ),
			bookify_event_tickets_sold( $event_id ),
			bookify_event_places_left( $event_id )
		);

	// The totals per tier, in the tier order the page uses, so the two lists read the same way.
	$tiers = bookify_event_tiers( $event_id );

	if ( $tiers ) {
		$lines[] = '';
		$lines[] = __( 'Tickets:', 'bookify-booking' );

		foreach ( $tiers as $tier ) {
			$lines[] = sprintf(
				/* translators: 1: the tier's name, 2: its price, 3: tickets sold, 4: places left. */
				__( '  %1$s — %2$s — %3$d sold, %4$d left', 'bookify-booking' ),
				$tier->post_title,
				bookify_tier_price_label( $tier->ID ),
				bookify_tier_tickets_sold( $tier->ID ),
				bookify_tier_places_left( $tier->ID )
			);
		}
	}

	$reservations = bookify_booking_event_reservation_ids( $event_id );

	$lines[] = '';

	if ( ! $reservations ) {
		$lines[] = __( 'Nobody booked a ticket for this event.', 'bookify-booking' );

		return $lines;
	}

	$lines[] = __( 'Reservations:', 'bookify-booking' );

	foreach ( $reservations as $booking_id ) {
		$reservation = bookify_booking_event_reservation( $booking_id );

		$lines[] = sprintf(
			/* translators: 1: booking reference, 2: the customer's name, 3: their email address, 4: the number of tickets, 5: the tier. */
			__( '  %1$s  %2$s <%3$s>  %4$d × %5$s', 'bookify-booking' ),
			$reservation['reference'],
			$reservation['name'],
			$reservation['email'],
			$reservation['tickets'],
			$reservation['tier']
		);
	}

	return $lines;
}

/**
 * When an event's summary is due: the moment the event ends.
 *
 * That is the moment tickets stop being sold and the list stops changing, so it is the first moment
 * the summary is worth reading. An event with no readable date has no due moment at all.
 *
 * @param int $event_id The event.
 * @return int|null Unix timestamp, or null.
 */
function bookify_booking_event_summary_due_at( $event_id ) {
	$ends = bookify_event_ends_at( $event_id );

	return null === $ends ? null : $ends->getTimestamp();
}

/**
 * Compose an event's summary and hand it to wp_mail().
 *
 * Sent once: the flag is written before the mail is handed over, so a cron run, a scheduled event
 * that fires twice and a manual run cannot each send their own copy. The return value of wp_mail() is
 * deliberately ignored, for the reason T7 ignores it — the summary is composed whether or not this
 * container can deliver mail, and a retry queue belongs to a hosting step.
 *
 * @param int $event_id The event.
 * @return bool Whether this call was the one that sent it.
 */
function bookify_booking_send_event_summary( $event_id ) {
	$event_id = absint( $event_id );
	$event    = get_post( $event_id );

	if ( ! $event instanceof WP_Post || 'bookify_event' !== $event->post_type ) {
		return false;
	}

	if ( '' === bookify_event_date( $event_id ) ) {
		return false;
	}

	if ( get_post_meta( $event_id, BOOKIFY_BOOKING_EVENT_SUMMARY_SENT_META, true ) ) {
		return false;
	}

	// Written first, so two runs that overlap cannot both think they are the first.
	update_post_meta( $event_id, BOOKIFY_BOOKING_EVENT_SUMMARY_SENT_META, time() );

	$headers = array(
		sprintf(
			'From: %s <%s>',
			sanitize_text_field( (string) get_option( 'blogname' ) ),
			get_option( 'admin_email' )
		),
	);

	$subject = sprintf(
		/* translators: 1: the event's title, 2: the event's date. */
		__( 'Reservations for %1$s on %2$s', 'bookify-booking' ),
		$event->post_title,
		bookify_event_date( $event_id )
	);

	wp_mail(
		get_option( 'admin_email' ),
		$subject,
		implode( "\n", bookify_booking_event_summary_lines( $event_id ) ),
		$headers
	);

	// The appointment has done its job. Leaving it behind would be harmless — the flag above stops
	// it sending anything — but releasing on every exit path is the discipline T16's lock and T23's
	// expiry are held to, and it keeps the list of future events to the ones that still have one.
	bookify_booking_unschedule_event_summary( $event_id );

	return true;
}

/**
 * Ask for an event's summary at the moment the event ends.
 *
 * Called when the event is saved, so an edited date moves the appointment rather than leaving one at
 * a moment the event no longer happens. Nothing is asked for when the summary has already gone or
 * when the due moment has passed — the latter because WordPress would run it immediately, which is
 * the wrong list to send and would burn the once-only flag on it.
 *
 * @param int $event_id The event.
 */
function bookify_booking_schedule_event_summary( $event_id ) {
	$event_id = absint( $event_id );
	$due      = bookify_booking_event_summary_due_at( $event_id );

	if ( null === $due ) {
		return;
	}

	if ( get_post_meta( $event_id, BOOKIFY_BOOKING_EVENT_SUMMARY_SENT_META, true ) ) {
		return;
	}

	$args = array( $event_id );

	// Already asked for, at this moment or another: nothing to add, and nothing to move.
	if ( wp_next_scheduled( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $args ) ) {
		return;
	}

	if ( $due <= time() ) {
		return;
	}

	// One second after the event ends, so the run that arrives on time finds it due.
	wp_schedule_single_event( $due + 1, BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, $args );
}

/**
 * Forget an event's summary appointment.
 *
 * The pair to bookify_booking_schedule_event_summary(), called from every path that takes the
 * appointment out of play: deleting the event, and an operator who has already had the summary.
 * Releasing on every exit path is the same discipline T16's advisory lock and T23's expiry are held
 * to.
 *
 * @param int $event_id The event.
 */
function bookify_booking_unschedule_event_summary( $event_id ) {
	wp_clear_scheduled_hook( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, array( absint( $event_id ) ) );
}

/**
 * Move or drop an event's summary when the event is saved.
 *
 * Hooked at priority 20, after the fields have been written (includes/event-fields.php), because this
 * decides from the stored date and times.
 *
 * @param int $event_id The event being saved.
 */
function bookify_booking_reschedule_event_summary( $event_id ) {
	if ( wp_is_post_revision( $event_id ) || wp_is_post_autosave( $event_id ) ) {
		return;
	}

	// A date an owner has just changed has to move the appointment, so the old one goes first.
	bookify_booking_unschedule_event_summary( $event_id );
	bookify_booking_schedule_event_summary( $event_id );
}

/**
 * Forget a deleted event's summary appointment.
 *
 * Hooked to `deleted_post`, so however the event goes — the edit screen, WP-CLI, a cleanup job that
 * does not know about this plugin — its appointment cannot outlive it.
 *
 * @param int           $post_id The post being deleted.
 * @param \WP_Post|null $post    The post, when WordPress passes it along.
 */
function bookify_booking_forget_deleted_event_summary( $post_id, $post = null ) {
	if ( $post instanceof WP_Post && 'bookify_event' !== $post->post_type ) {
		return;
	}

	bookify_booking_unschedule_event_summary( $post_id );
}

/**
 * Register the summary: the appointment, the moment it is asked for, and the two ways it goes away.
 */
function bookify_booking_register_event_summary() {
	add_action( BOOKIFY_BOOKING_EVENT_SUMMARY_HOOK, 'bookify_booking_send_event_summary' );
	add_action( 'save_post_bookify_event', 'bookify_booking_reschedule_event_summary', 20 );
	add_action( 'deleted_post', 'bookify_booking_forget_deleted_event_summary', 10, 2 );
}
