<?php
/**
 * Proof that the services stage2-more-services.php adds can actually be booked.
 *
 * A listing is not a booking. Everything between a new service appearing on the page and money
 * changing hands runs through `bookify_create_booking()`: the service has to resolve, its length
 * has to produce a slot, its own capacity has to be what the slot count is measured against, and
 * the price has to be stored on the booking as the amount. This drives that path once per service
 * and then removes everything it made, so the diary is left exactly as it was found.
 *
 * It also expects one refusal: a second booking in the same slot of a one-person service, which is
 * the check that capacity came from the service rather than from something global.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage2-more-services-bookable.php
 */

$titles = array(
	'Counselling session',
	'Physical therapy session',
	'Sports massage session',
	'Haircut at the studio',
	'Haircut at your home',
);

$day     = bookify_open_days()[0];
$made    = array();
$booked  = array();
$passed  = 0;

printf( "first open day: %s\n\n", $day );

/**
 * Book one slot of one service, the way the form's handler does.
 *
 * @param int    $service_id The service.
 * @param string $date       Date in YYYY-MM-DD form.
 * @param string $time       Time in HH:MM form.
 * @param string $name       Customer name.
 * @return int|\WP_Error The booking id, or the refusal.
 */
function bookify_probe_book( $service_id, $date, $time, $name ) {
	return bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => $name,
			'email'      => 'probe@example.com',
			'phone'      => '555 0100',
			'date'       => $date,
			'time'       => $time,
			'party_size' => 1,
		)
	);
}

/**
 * What a booking costs and what state it starts in, on one line.
 *
 * The amount is `bookify_payment_amount`, not `bookify_amount` — a booking with no price to charge
 * leaves that key unset and starts as `pending`, which is what these services do: they were added
 * with a price and no payment mode, exactly like the three that were already here.
 *
 * @param int $booking_id The booking.
 * @return string
 */
function bookify_probe_terms( $booking_id ) {
	$mode   = (string) get_post_meta( $booking_id, 'bookify_payment_mode', true );
	$amount = (string) get_post_meta( $booking_id, 'bookify_payment_amount', true );

	return sprintf(
		'mode %s, amount %s, status %s',
		'' === $mode ? 'none' : $mode,
		'' === $amount ? 'none' : $amount,
		(string) get_post_meta( $booking_id, 'bookify_status', true )
	);
}

foreach ( $titles as $title ) {
	$found = get_posts(
		array(
			'post_type'      => 'bookify_service',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'title'          => $title,
		)
	);

	if ( ! $found ) {
		printf( "MISSING  %s\n", $title );
		continue;
	}

	$service_id = (int) $found[0]->ID;
	$slots      = bookify_available_slots( $service_id, $day );

	if ( ! $slots ) {
		printf( "FAIL     %-26s no slot offered on %s\n", $title, $day );
		continue;
	}

	$result = bookify_probe_book( $service_id, $day, $slots[0], 'Probe Guest' );

	if ( is_wp_error( $result ) ) {
		printf( "FAIL     %-26s %s  %s\n", $title, $slots[0], $result->get_error_code() );
		continue;
	}

	$terms = bookify_probe_terms( $result );

	$made[]   = $result;
	$booked[] = array( $service_id, $title, $slots[0], $terms );
	$passed++;

	printf(
		"ok       %-26s %s %s  %s  %s\n",
		$title,
		$day,
		$slots[0],
		get_post_meta( $result, 'bookify_reference', true ),
		$terms
	);
}

/*
 * The control: a service that was on the site before any of this. A booking of it is the shape the
 * new ones have to match — same mode, same amount, same starting status. Five new rows that booked
 * in some other state would be the defect this probe exists to catch, and one capture of the old
 * behaviour is the only way to see it.
 */
$control = get_posts(
	array(
		'post_type'      => 'bookify_service',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'title'          => 'One-to-one session',
	)
);

if ( $control && $booked ) {
	$control_id     = (int) $control[0]->ID;
	$control_slots  = bookify_available_slots( $control_id, $day );
	$control_result = $control_slots ? bookify_probe_book( $control_id, $day, $control_slots[0], 'Probe Control' ) : new WP_Error( 'no_slot' );

	if ( ! is_wp_error( $control_result ) ) {
		$made[] = $control_result;
	}

	$control_terms = is_wp_error( $control_result ) ? 'refused ' . $control_result->get_error_code() : bookify_probe_terms( $control_result );
	$same          = ! is_wp_error( $control_result ) && $control_terms === $booked[0][3];

	printf( "\ncontrol  %-26s %s  %s\n", 'One-to-one session', $control_slots ? $control_slots[0] : '-', $control_terms );
	printf( "%-8s a new service books exactly as the control does (%s)\n", $same ? 'ok' : 'FAIL', $booked[0][3] );
}

// One service, one place: the second booking in the same slot has to be refused, or the capacity a
// service carries is not the capacity the diary enforces.
if ( $booked ) {
	list( $service_id, $title, $time ) = $booked[0];

	$second = bookify_probe_book( $service_id, $day, $time, 'Probe Guest Two' );

	printf(
		"\n%-8s a second booking in the same one-person slot (%s %s): %s\n",
		is_wp_error( $second ) ? 'ok' : 'FAIL',
		$title,
		$time,
		is_wp_error( $second ) ? $second->get_error_code() : 'accepted id=' . $second
	);

	if ( ! is_wp_error( $second ) ) {
		$made[] = $second;
	}
}

// Put the diary back: a probe that leaves bookings behind would block real customers on the day it
// ran, which is exactly the kind of damage an evidence script must not do.
$left = 0;

foreach ( $made as $booking_id ) {
	if ( ! wp_delete_post( (int) $booking_id, true ) ) {
		$left++;
	}
}

printf(
	"\n%d of %d new services booked, plus the control; %d booking(s) removed, %d left behind\n",
	$passed,
	count( $titles ),
	count( $made ) - $left,
	$left
);
