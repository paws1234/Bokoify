<?php
/**
 * Throwaway probe for T23's payment window: expired after the deadline, place given back.
 *
 * Run three times, with the phase in the `bookify_probe_phase` option, because each `eval-file` run
 * is one request and bookify_booking_sweep_expired() sweeps once per request by design:
 *
 *   wp option update bookify_probe_phase hold  && wp eval-file …/stage5-expiry.php
 *   wp option update bookify_probe_phase close && wp eval-file …/stage5-expiry.php
 *   wp option update bookify_probe_phase sweep && wp eval-file …/stage5-expiry.php
 *
 * Deleted after use.
 */

$phase = (string) get_option( 'bookify_probe_phase', '' );

function probe_service() {
	$services = bookify_booking_published_services();

	return $services[0];
}

function probe_free_slot( $service_id, $day ) {
	$slots = bookify_available_slots( $service_id, $day );

	return $slots ? $slots[0] : '';
}

function probe_report( $label, $booking_id, $service_id, $day, $time ) {
	printf(
		"%-22s status=%-16s remaining=%d offered_again=%s\n",
		$label,
		(string) get_post_meta( $booking_id, 'bookify_status', true ),
		bookify_slot_remaining( $service_id, $day, $time ),
		in_array( $time, bookify_available_slots( $service_id, $day ), true ) ? 'yes' : 'no'
	);
}

$service    = probe_service();
$service_id = $service->ID;
$days       = bookify_available_days( $service_id );
$day        = $days ? $days[0] : '';

echo "phase={$phase} service={$service_id} day={$day} window=" . bookify_booking_payment_window_minutes() . " min\n";

if ( 'hold' === $phase ) {
	delete_post_meta( $service_id, 'bookify_payment_mode' );
	update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
	update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
	update_post_meta( $service_id, 'bookify_deposit_value', '25.00' );

	$time       = probe_free_slot( $service_id, $day );
	$booking_id = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => 'Stage 5 expiry probe',
			'email'      => 'stage5-expiry@example.com',
			'date'       => $day,
			'time'       => $time,
			'party_size' => 1,
		)
	);

	if ( is_wp_error( $booking_id ) ) {
		echo 'REFUSED ' . $booking_id->get_error_code() . "\n";
		exit;
	}

	update_option( 'bookify_probe_booking', $booking_id );
	update_option( 'bookify_probe_slot', $day . ' ' . $time );

	printf( "booked %d at %s, due at %s\n", $booking_id, $time, wp_date( 'H:i', bookify_booking_payment_due_at( $booking_id ), wp_timezone() ) );

	// This is the availability read the sweep rides on, and it is *this* request's first one: the
	// booking is not due yet, so the sweep must leave it exactly as it is (T23, acceptance 1).
	probe_report( 'while the window is open', $booking_id, $service_id, $day, $time );
	exit;
}

if ( 'close' === $phase ) {
	$booking_id = absint( get_option( 'bookify_probe_booking', 0 ) );

	// Straight to the meta rather than waiting thirty minutes. No availability function is called in
	// this phase, so the sweep cannot run before the next phase asks for a slot.
	update_post_meta( $booking_id, 'bookify_payment_due', time() - 60 );

	echo 'window closed at ' . wp_date( 'H:i', bookify_booking_payment_due_at( $booking_id ), wp_timezone() )
		. '; status is still ' . (string) get_post_meta( $booking_id, 'bookify_status', true ) . "\n";
	exit;
}

if ( 'sweep' === $phase ) {
	$booking_id  = absint( get_option( 'bookify_probe_booking', 0 ) );
	list( $day, $time ) = array_pad( explode( ' ', (string) get_option( 'bookify_probe_slot', '' ) ), 2, '' );

	/*
	 * The first availability read of this request, and that is the whole test: the sweep rides on
	 * bookify_booking_guests_by_day(), so asking what is bookable is what expires the overdue booking
	 * and gives its place back — with no cron run anywhere in sight (T23, acceptance 2 and 3).
	 */
	probe_report( 'on the next read', $booking_id, $service_id, $day, $time );

	// ...and the cron path, which is the other half of the same pair. A second booking, its window
	// closed, swept by the function the scheduled event calls.
	$second_time = probe_free_slot( $service_id, $day );
	$second      = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => 'Stage 5 cron probe',
			'email'      => 'stage5-cron@example.com',
			'date'       => $day,
			'time'       => $second_time,
			'party_size' => 1,
		)
	);

	if ( is_wp_error( $second ) ) {
		echo 'the cron-path booking was REFUSED ' . $second->get_error_code() . "\n";
	} else {
		update_post_meta( $second, 'bookify_payment_due', time() - 60 );

		$changed = bookify_booking_expire_unpaid( $second );

		printf(
			"the cron function on %d: expired=%s status=%s offered_again=%s (a second call changes %s)\n",
			$second,
			$changed ? 'yes' : 'no',
			(string) get_post_meta( $second, 'bookify_status', true ),
			in_array( $second_time, bookify_available_slots( $service_id, $day ), true ) ? 'yes' : 'no',
			bookify_booking_expire_unpaid( $second ) ? 'something (wrong)' : 'nothing'
		);

		wp_delete_post( $second, true );
	}

	wp_delete_post( $booking_id, true );
	delete_option( 'bookify_probe_booking' );
	delete_option( 'bookify_probe_slot' );
	delete_option( 'bookify_probe_phase' );
	delete_post_meta( $service_id, 'bookify_payment_mode' );
	delete_post_meta( $service_id, 'bookify_deposit_type' );
	delete_post_meta( $service_id, 'bookify_deposit_value' );

	echo "cleaned up\n";
	exit;
}

echo "unknown phase '{$phase}'\n";
