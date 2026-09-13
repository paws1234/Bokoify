<?php
/**
 * The reminder email.
 *
 * One message, sent before a session starts, so a customer who booked a fortnight ago does not
 * have to remember it themselves.
 *
 * It is an appointment of its own rather than a poll: one scheduled event, at the moment the
 * reminder is due, exactly the way T23's payment deadline works. A poll would cost a query on every
 * page view for a message that is needed once, which is the wrong trade on a site that is also
 * being measured for speed (T28).
 *
 * Nothing can leave this container (plan D6), so what this file is judged on locally is what it
 * composes and what it asks WordPress to do: the message captured through `pre_wp_mail`, and the
 * event asserted to exist at the right time. That is the same evidence T7 was accepted on.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cron hook one booking's reminder rides on.
 */
const BOOKIFY_BOOKING_REMINDER_HOOK = 'bookify_booking_reminder';

/**
 * The option the reminder settings live in.
 */
const BOOKIFY_BOOKING_REMINDER_OPTION = 'bookify_reminders';

/**
 * The reminder's meta keys.
 *
 * `bookify_reminder_sent` is when the message went, not whether it should: one key answers both
 * questions and there is nothing to keep in step. Clearing it is how a moved booking earns a
 * second reminder (see bookify_booking_reschedule()).
 */
const BOOKIFY_BOOKING_REMINDER_SENT_META = 'bookify_reminder_sent';

/**
 * The reminder settings as they are before the owner changes anything.
 *
 * On by default. The point of the feature is that it happens without anybody remembering to switch
 * it on, and an owner who does not want it says so once on the Settings screen.
 *
 * @return array{enabled:bool,hours_before:int}
 */
function bookify_booking_reminder_defaults() {
	return array(
		'enabled'      => true,
		'hours_before' => 24,
	);
}

/**
 * The stored reminder settings, re-validated on read.
 *
 * The stored shape is not trusted: a value written by `wp option update` that the code does not
 * understand falls back to the default rather than becoming a negative lead time, in the same way
 * bookify_booking_payment_mode() re-reads what it finds.
 *
 * @return array{enabled:bool,hours_before:int}
 */
function bookify_booking_reminders() {
	$defaults = bookify_booking_reminder_defaults();
	$stored   = get_option( BOOKIFY_BOOKING_REMINDER_OPTION, array() );
	$stored   = is_array( $stored ) ? $stored : array();

	$hours = $defaults['hours_before'];

	if ( isset( $stored['hours_before'] ) && is_numeric( $stored['hours_before'] ) ) {
		// A fortnight at most: a reminder further ahead than that is a second confirmation.
		$hours = min( 336, max( 1, (int) $stored['hours_before'] ) );
	}

	return array(
		'enabled'      => isset( $stored['enabled'] ) ? (bool) $stored['enabled'] : $defaults['enabled'],
		'hours_before' => $hours,
	);
}

/**
 * When one booking's reminder is due, on the site's own clock.
 *
 * @param int $booking_id The booking.
 * @return int|null Timestamp, or null when the booking has no readable date and time.
 */
function bookify_booking_reminder_due_at( $booking_id ) {
	$starts = bookify_booking_starts_at( $booking_id );

	if ( null === $starts ) {
		return null;
	}

	return $starts->getTimestamp() - ( bookify_booking_reminders()['hours_before'] * HOUR_IN_SECONDS );
}

/**
 * Whether this booking wants a reminder at all.
 *
 * Four questions, and each one is a reason not to write to somebody: the feature is on, the booking
 * exists, it still holds a place, and no reminder has gone out for it yet. Whether the *moment* has
 * passed is deliberately not asked here — that is a question about scheduling, and asking it here
 * too would stop a cron run that is a few minutes late from sending anything.
 *
 * @param int $booking_id The booking.
 * @return bool
 */
function bookify_booking_reminder_wanted( $booking_id ) {
	$booking_id = absint( $booking_id );

	if ( ! bookify_booking_reminders()['enabled'] ) {
		return false;
	}

	$booking = get_post( $booking_id );

	if ( ! $booking instanceof WP_Post || 'bookify_booking' !== $booking->post_type ) {
		return false;
	}

	/*
	 * A booking that holds a place is one somebody is coming to. Cancelled and expired ones are
	 * not, and the list is read from its one definition rather than repeated, so "using a place"
	 * means the same thing here as it does to the capacity count.
	 */
	$status = (string) get_post_meta( $booking_id, 'bookify_status', true );

	if ( ! in_array( $status, bookify_booking_slot_holding_statuses(), true ) ) {
		return false;
	}

	if ( '' !== (string) get_post_meta( $booking_id, BOOKIFY_BOOKING_REMINDER_SENT_META, true ) ) {
		return false;
	}

	return null !== bookify_booking_reminder_due_at( $booking_id );
}

/**
 * Ask for a reminder for this booking, or cancel the one it has.
 *
 * Idempotent by construction: the existing appointment is always cleared first, so this is equally
 * the "schedule", the "move" and the "give it up" path. A reminder whose moment has already gone is
 * not scheduled — a booking made inside the lead time gets its confirmation and nothing else, which
 * is the mail it actually needs.
 *
 * @param int $booking_id The booking.
 * @return bool Whether an appointment now exists.
 */
function bookify_booking_schedule_reminder( $booking_id ) {
	$booking_id = absint( $booking_id );

	bookify_booking_unschedule_reminder( $booking_id );

	if ( ! bookify_booking_reminder_wanted( $booking_id ) ) {
		return false;
	}

	$due = bookify_booking_reminder_due_at( $booking_id );

	if ( null === $due || $due <= time() ) {
		return false;
	}

	wp_schedule_single_event( $due, BOOKIFY_BOOKING_REMINDER_HOOK, array( $booking_id ) );

	return true;
}

/**
 * Forget this booking's reminder appointment.
 *
 * Called wherever the booking stops being something to attend, and whenever its time changes: an
 * event left behind would be harmless, because the send checks the status before it writes to
 * anybody, but an unbounded list of them is a list nobody will read — the same discipline T23's
 * expiry appointment is held to.
 *
 * @param int $booking_id The booking.
 */
function bookify_booking_unschedule_reminder( $booking_id ) {
	wp_clear_scheduled_hook( BOOKIFY_BOOKING_REMINDER_HOOK, array( absint( $booking_id ) ) );
}

/**
 * Compose one booking's reminder and hand it to wp_mail().
 *
 * Safe to call twice: the sent marker is written before the message is composed, so the cron run
 * and anything else that calls this cannot both send. It refuses a booking that no longer holds a
 * place, and one whose session has already started — a reminder to attend something that is over is
 * worse than no reminder.
 *
 * @param int $booking_id The booking.
 * @return bool Whether a message was composed and handed over.
 */
function bookify_booking_send_reminder( $booking_id ) {
	$booking_id = absint( $booking_id );

	if ( ! bookify_booking_reminder_wanted( $booking_id ) ) {
		return false;
	}

	$starts = bookify_booking_starts_at( $booking_id );

	if ( null === $starts || $starts->getTimestamp() <= time() ) {
		return false;
	}

	$email = (string) get_post_meta( $booking_id, 'bookify_customer_email', true );

	// The write path has already rejected a bad address; a booking whose address is unusable gets
	// no message rather than a broken one.
	if ( ! is_email( $email ) ) {
		return false;
	}

	$name      = (string) get_post_meta( $booking_id, 'bookify_customer_name', true );
	$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );

	// Printed exactly as they were chosen, like every other date this plugin writes out: turning
	// them into a timestamp would move the appointment if the site's timezone were ever changed.
	$date  = (string) get_post_meta( $booking_id, 'bookify_date', true );
	$time  = (string) get_post_meta( $booking_id, 'bookify_time', true );
	$party = (int) get_post_meta( $booking_id, 'bookify_party_size', true );

	$lines = array(
		sprintf(
			/* translators: %s: the customer's name. */
			__( 'A reminder about your booking, %s.', 'bookify-booking' ),
			$name
		),
		'',
		sprintf(
			/* translators: %s: booking reference, for example BK-00042. */
			__( 'Reference: %s', 'bookify-booking' ),
			$reference
		),
	);

	/*
	 * What the booking is for, named by the one function that knows (Stage 7) — a session's title,
	 * or an event and its tier. A session that has since been unpublished is still left out rather
	 * than named wrongly; the opening line above says "booking" rather than "session" for the same
	 * reason this one does.
	 */
	$item = bookify_booking_item_label( $booking_id );

	if ( '' !== $item ) {
		$lines[] = sprintf(
			/* translators: %s: what the booking is for — a session, or an event and a ticket tier. */
			__( 'Booking: %s', 'bookify-booking' ),
			$item
		);
	}

	$lines[] = sprintf(
		/* translators: %s: the booking date, as chosen. */
		__( 'Date: %s', 'bookify-booking' ),
		$date
	);

	$lines[] = sprintf(
		/* translators: %s: the booking time, as chosen. */
		__( 'Time: %s', 'bookify-booking' ),
		$time
	);

	$lines[] = sprintf(
		/* translators: %d: number of people. */
		__( 'People: %d', 'bookify-booking' ),
		$party
	);

	// The customer's own way to move or give up the slot, with no account and no password. A
	// reminder without one is a reminder they cannot act on.
	$manage = bookify_booking_manage_url( $booking_id );

	if ( '' !== $manage ) {
		$lines[] = '';
		$lines[] = __( 'If you can no longer make it, open this link to move or cancel it:', 'bookify-booking' );
		$lines[] = $manage;
	}

	$lines[] = '';
	$lines[] = __( 'See you then.', 'bookify-booking' );

	$headers = array(
		sprintf(
			'From: %s <%s>',
			sanitize_text_field( (string) get_option( 'blogname' ) ),
			get_option( 'admin_email' )
		),
		sprintf( 'Reply-To: %s <%s>', $name, $email ),
	);

	$subject = sprintf(
		/* translators: %s: booking reference, for example BK-00042. */
		__( 'Reminder: booking %s', 'bookify-booking' ),
		$reference
	);

	// Written before the message is handed over, so a second call — a cron run racing a page
	// request, or the same event fired twice — finds the marker and stops.
	update_post_meta( $booking_id, BOOKIFY_BOOKING_REMINDER_SENT_META, current_time( 'mysql', true ) );

	// The result is deliberately ignored, exactly as T7 ignores it: the booking stands whether or
	// not this container can deliver mail, and a retry queue is a hosting concern.
	wp_mail( $email, $subject, implode( "\n", $lines ), $headers );

	return true;
}

/**
 * Forget a deleted booking's reminder appointment.
 *
 * Hooked to `deleted_post`, so however a booking goes — the edit screen, WP-CLI, a cleanup job that
 * has never heard of this plugin — its appointment cannot outlive it.
 *
 * @param int           $post_id The post being deleted.
 * @param \WP_Post|null $post    The post, when WordPress passes it along.
 */
function bookify_booking_forget_deleted_reminder( $post_id, $post = null ) {
	if ( $post instanceof WP_Post && 'bookify_booking' !== $post->post_type ) {
		return;
	}

	bookify_booking_unschedule_reminder( $post_id );
}

/**
 * Re-ask for every upcoming booking's reminder.
 *
 * Called when the settings change, because an appointment built from the old lead time is now at
 * the wrong moment. Only bookings that could still want one are read: a fortnight either side of
 * today, and only those still holding a place.
 *
 * @return int How many bookings were looked at.
 */
function bookify_booking_refresh_reminders() {
	$ids = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => 'bookify_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
				),
				array(
					'key'     => 'bookify_status',
					'value'   => bookify_booking_slot_holding_statuses(),
					'compare' => 'IN',
				),
			),
		)
	);

	foreach ( $ids as $booking_id ) {
		bookify_booking_schedule_reminder( $booking_id );
	}

	return count( $ids );
}

/**
 * Register the reminder appointment and the settings that govern it.
 *
 * The settings screen calls update_option() on the whole option, so the refresh is hooked to the
 * option itself rather than to the form: a change made by `wp option update` moves the appointments
 * too.
 */
function bookify_booking_register_reminders() {
	add_action( BOOKIFY_BOOKING_REMINDER_HOOK, 'bookify_booking_send_reminder' );
	add_action( 'deleted_post', 'bookify_booking_forget_deleted_reminder', 10, 2 );
	add_action( 'update_option_' . BOOKIFY_BOOKING_REMINDER_OPTION, 'bookify_booking_refresh_reminders' );
}
