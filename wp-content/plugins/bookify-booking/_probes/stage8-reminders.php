<?php
/**
 * Throwaway probe for T30: reminders are scheduled, sent once, moved and given up.
 *
 * One `eval-file` run is one request, so everything happens in sequence here. Proof sought:
 *
 *   1. a booking far enough ahead carries a scheduled reminder at exactly start minus the lead;
 *   2. running the appointment composes one message, to the right address, with the session and
 *      the manage link in it;
 *   3. running it twice sends one message, not two;
 *   4. cancelling the booking drops the appointment;
 *   5. moving the booking moves the appointment, and clears the sent marker so Friday is reminded
 *      even though Tuesday was;
 *   6. a booking whose reminder moment has already gone is not scheduled at all;
 *   7. with reminders switched off nothing is scheduled;
 *   8. deleting a booking drops its appointment.
 *
 * Everything it creates is deleted at the end, so it is safe to re-run:
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage8-reminders.php
 *
 * Kept as T30's re-runnable evidence.
 */

$probe_ids = array();

function probe_note( $label, $ok, $detail = '' ) {
	printf( "%-46s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', '' === $detail ? '' : "  {$detail}" );
}

function probe_service_for_capacity( $wanted ) {
	foreach ( bookify_booking_published_services() as $service ) {
		if ( bookify_slot_capacity( $service->ID ) >= $wanted ) {
			return $service;
		}
	}

	return null;
}

function probe_day_at_least( $service_id, $days ) {
	$target = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +{$days} days" ) );

	foreach ( bookify_available_days( $service_id ) as $day ) {
		if ( $day >= $target ) {
			return $day;
		}
	}

	return '';
}

function probe_book( $service_id, $day, $time, $email ) {
	$result = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => 'T30 reminder probe',
			'email'      => $email,
			'date'       => $day,
			'time'       => $time,
			'party_size' => 1,
		)
	);

	return is_wp_error( $result ) ? $result : (int) $result;
}

$service = probe_service_for_capacity( 4 );

if ( ! $service ) {
	echo "no session with room for this probe\n";
	return;
}

$service_id = $service->ID;
$day        = probe_day_at_least( $service_id, 3 );
$later_day  = probe_day_at_least( $service_id, 10 );

echo "session={$service_id} \"{$service->post_title}\" capacity=" . bookify_slot_capacity( $service_id ) . " day={$day} later={$later_day}\n";
echo 'settings: ' . wp_json_encode( bookify_booking_reminders() ) . "\n\n";

$settings = bookify_booking_reminders();

// ---- 1 and 2: scheduled at the right moment, and sent once -------------------------------

$booking = probe_book( $service_id, $day, bookify_available_slots( $service_id, $day )[0], 't30-one@example.com' );

if ( is_wp_error( $booking ) ) {
	echo 'booking failed: ' . $booking->get_error_code() . "\n";
	return;
}

$probe_ids[] = $booking;

$expected = bookify_booking_starts_at( $booking )->getTimestamp() - ( $settings['hours_before'] * HOUR_IN_SECONDS );
$scheduled = wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $booking ) );

probe_note( '1. reminder scheduled exactly at start minus lead', $scheduled === $expected, "scheduled={$scheduled} expected={$expected}" );

// The test redirect, which sends every message to the owner's inbox, is pinned off here: this probe
// asserts the reminder's own recipient, so inheriting BOOKIFY_MAIL_REDIRECT_TO from .env would make
// it fail for a reason that has nothing to do with reminders.
putenv( 'BOOKIFY_MAIL_REDIRECT_TO=' );

$captured = array();
$capture  = static function ( $pre, $atts ) use ( &$captured ) {
	$captured[] = $atts;

	return true;
};

add_filter( 'pre_wp_mail', $capture, 10, 2 );
do_action( BOOKIFY_BOOKING_REMINDER_HOOK, $booking );
remove_filter( 'pre_wp_mail', $capture );

$message = $captured ? $captured[0] : array();
$body    = isset( $message['message'] ) ? (string) $message['message'] : '';
$reference = (string) get_post_meta( $booking, 'bookify_reference', true );

probe_note( '2a. one message, to the booking\'s own address', 1 === count( $captured ) && 't30-one@example.com' === ( $message['to'] ?? '' ) );
probe_note(
	'2b. it names the booking, the date and the manage link',
	false !== strpos( $body, 'Booking: ' . $service->post_title )
		&& false !== strpos( $body, 'Date: ' . $day )
		&& false !== strpos( $body, bookify_booking_manage_url( $booking ) )
);
probe_note( '2c. the subject names the booking', false !== strpos( (string) ( $message['subject'] ?? '' ), $reference ), (string) ( $message['subject'] ?? '' ) );
probe_note( '2d. it says the time and how many people', false !== strpos( $body, 'Time: ' ) && false !== strpos( $body, 'People: 1' ) );

$captured = array();
add_filter( 'pre_wp_mail', $capture, 10, 2 );
do_action( BOOKIFY_BOOKING_REMINDER_HOOK, $booking );
remove_filter( 'pre_wp_mail', $capture );

probe_note( '3. a second run sends nothing', 0 === count( $captured ) );
probe_note( '3b. the sent marker is recorded', '' !== (string) get_post_meta( $booking, BOOKIFY_BOOKING_REMINDER_SENT_META, true ) );

// ---- 4: cancelling drops the appointment -------------------------------------------------

bookify_booking_cancel( $booking );

probe_note( '4. cancelling drops the appointment', false === wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $booking ) ) );

$captured = array();
add_filter( 'pre_wp_mail', $capture, 10, 2 );
do_action( BOOKIFY_BOOKING_REMINDER_HOOK, $booking );
remove_filter( 'pre_wp_mail', $capture );

probe_note( '4b. a cancelled booking is never written to', 0 === count( $captured ) );

// ---- 5: moving moves it, and earns a second reminder -------------------------------------

$moved = probe_book( $service_id, $day, bookify_available_slots( $service_id, $day )[1], 't30-move@example.com' );

if ( is_wp_error( $moved ) ) {
	echo 'move probe booking failed: ' . $moved->get_error_code() . "\n";
} else {
	$probe_ids[] = $moved;

	// Sent once, then moved: the second reminder is the point of clearing the marker.
	$captured = array();
	add_filter( 'pre_wp_mail', $capture, 10, 2 );
	do_action( BOOKIFY_BOOKING_REMINDER_HOOK, $moved );
	remove_filter( 'pre_wp_mail', $capture );

	$slot     = bookify_available_slots( $service_id, $later_day );
	$new_time = $slot ? $slot[0] : '';
	$result   = '' === $new_time ? new WP_Error( 'probe_no_slot' ) : bookify_booking_reschedule( $moved, $later_day, $new_time );

	if ( is_wp_error( $result ) ) {
		echo 'reschedule failed: ' . $result->get_error_code() . "\n";
	} else {
		$expected = bookify_booking_starts_at( $moved )->getTimestamp() - ( $settings['hours_before'] * HOUR_IN_SECONDS );
		$scheduled = wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $moved ) );

		probe_note( '5a. the appointment moved with the booking', $scheduled === $expected, "scheduled={$scheduled} expected={$expected}" );
		probe_note( '5b. the sent marker was cleared by the move', '' === (string) get_post_meta( $moved, BOOKIFY_BOOKING_REMINDER_SENT_META, true ) );

		$captured = array();
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		do_action( BOOKIFY_BOOKING_REMINDER_HOOK, $moved );
		remove_filter( 'pre_wp_mail', $capture );

		probe_note( '5c. the moved booking is reminded about its new slot', 1 === count( $captured ) && false !== strpos( (string) ( $captured[0]['message'] ?? '' ), 'Date: ' . $later_day ) );
	}
}

// ---- 6: a reminder moment that has gone is not scheduled ---------------------------------

update_option( BOOKIFY_BOOKING_REMINDER_OPTION, array( 'enabled' => true, 'hours_before' => 336 ) );

$late = probe_book( $service_id, $day, bookify_available_slots( $service_id, $day )[2], 't30-late@example.com' );

if ( is_wp_error( $late ) ) {
	echo 'late probe booking failed: ' . $late->get_error_code() . "\n";
} else {
	$probe_ids[] = $late;
	probe_note( '6. a passed reminder moment is not scheduled', false === wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $late ) ) );
}

// ---- 7: switched off means nothing is scheduled ------------------------------------------

update_option( BOOKIFY_BOOKING_REMINDER_OPTION, array( 'enabled' => false, 'hours_before' => 24 ) );

$off = probe_book( $service_id, $day, bookify_available_slots( $service_id, $day )[3], 't30-off@example.com' );

if ( is_wp_error( $off ) ) {
	echo 'off probe booking failed: ' . $off->get_error_code() . "\n";
} else {
	$probe_ids[] = $off;
	probe_note( '7. with reminders off nothing is scheduled', false === wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $off ) ) );
}

// ---- 8: deleting a booking drops its appointment -----------------------------------------

// Back on, and back to the default lead: case 7 left the feature switched off.
update_option( BOOKIFY_BOOKING_REMINDER_OPTION, array( 'enabled' => true, 'hours_before' => 24 ) );

$deleted = probe_book( $service_id, $day, bookify_available_slots( $service_id, $day )[4], 't30-delete@example.com' );

if ( is_wp_error( $deleted ) ) {
	echo 'delete probe booking failed: ' . $deleted->get_error_code() . "\n";
} else {
	probe_note( '8a. scheduled before the delete', false !== wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $deleted ) ) );

	wp_delete_post( $deleted, true );

	probe_note( '8b. deleting the booking dropped it', false === wp_next_scheduled( BOOKIFY_BOOKING_REMINDER_HOOK, array( $deleted ) ) );
}

// ---- settings round-trip, and cleanup -----------------------------------------------------

update_option( BOOKIFY_BOOKING_REMINDER_OPTION, array( 'enabled' => 1, 'hours_before' => 48 ) );
$stored = bookify_booking_reminders();
probe_note( '9a. the settings round-trip', true === $stored['enabled'] && 48 === $stored['hours_before'], wp_json_encode( $stored ) );

update_option( BOOKIFY_BOOKING_REMINDER_OPTION, array( 'enabled' => true, 'hours_before' => 99999 ) );
probe_note( '9b. an absurd lead time is clamped, not stored', 336 === bookify_booking_reminders()['hours_before'] );

update_option( BOOKIFY_BOOKING_REMINDER_OPTION, array( 'enabled' => true, 'hours_before' => 24 ) );

foreach ( $probe_ids as $probe_id ) {
	bookify_booking_unschedule_reminder( $probe_id );
	wp_delete_post( $probe_id, true );
}

echo "\ncleaned up " . count( $probe_ids ) . " probe bookings\n";
