<?php
/**
 * Throwaway probe for T23: what is paid, and when. Deleted after use.
 *
 * Creates its own bookings and deletes exactly those at the end — never by a query: a cleanup list
 * written as a meta query once matched every booking on this site (T22's lesson).
 *
 * The window itself is proved in stage5-expiry.php, across separate requests, because
 * bookify_booking_sweep_expired() sweeps once per request on purpose and one `eval-file` run is one
 * request.
 */

$GLOBALS['probe_bookings'] = array();
$GLOBALS['probe_mail']     = array();

/**
 * Capture the composed confirmation instead of sending it.
 */
function probe_capture_mail( $return, $atts ) {
	$GLOBALS['probe_mail'][] = $atts;

	return true;
}

add_filter( 'pre_wp_mail', 'probe_capture_mail', 10, 2 );

function probe_book( $service_id, $date, $time ) {
	$result = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => 'Stage 5 probe',
			'email'      => 'stage5-probe@example.com',
			'phone'      => '',
			'date'       => $date,
			'time'       => $time,
			'party_size' => 1,
		)
	);

	if ( ! is_wp_error( $result ) ) {
		$GLOBALS['probe_bookings'][] = $result;
	}

	return $result;
}

function probe_last_mail() {
	$mails = $GLOBALS['probe_mail'];

	return $mails ? $mails[ count( $mails ) - 1 ] : array();
}

function probe_mail_payment_lines() {
	$mail  = probe_last_mail();
	$lines = isset( $mail['message'] ) ? explode( "\n", (string) $mail['message'] ) : array();

	return array_values(
		array_filter(
			$lines,
			static function ( $line ) {
				return false !== strpos( $line, 'pay' ) || false !== strpos( $line, 'due' );
			}
		)
	);
}

/**
 * The first free time on the day.
 *
 * Each iteration of the mode tests deletes its own booking before the next one is made, so the same
 * time is free again every time — but the probe still asks rather than assuming, because a slot a
 * previous probe left behind would otherwise make the booking fail for the wrong reason.
 */
function probe_free_slot( $service_id, $day ) {
	$slots = bookify_available_slots( $service_id, $day );

	return $slots ? $slots[0] : '';
}

function probe_cleanup() {
	$deleted = 0;

	foreach ( $GLOBALS['probe_bookings'] as $booking_id ) {
		if ( wp_delete_post( $booking_id, true ) ) {
			$deleted++;
		}
	}

	echo 'cleaned up ' . $deleted . " booking(s)\n";
}

/**
 * Delete one of this probe's bookings and stop counting it as live, so the next case can use the
 * time it was holding.
 */
function probe_forget( $booking_id ) {
	wp_delete_post( $booking_id, true );
	$GLOBALS['probe_bookings'] = array_values( array_diff( $GLOBALS['probe_bookings'], array( $booking_id ) ) );
}

$services   = bookify_booking_published_services();
$service    = $services[0];
$service_id = $service->ID;

echo "service {$service_id} {$service->post_title} price=" . get_post_meta( $service_id, 'bookify_price', true )
	. ' capacity=' . get_post_meta( $service_id, 'bookify_capacity', true ) . "\n";

$days = bookify_available_days( $service_id );

/*
 * The day with the most room, not merely the first one open. The first day is often today, and today
 * is a Saturday with three slots in it and a lead time that moves as the probe runs — which is how an
 * earlier version of this probe ran out of slots half way through.
 */
$day  = $days ? $days[0] : '';
$room = 0;

foreach ( array_slice( $days, 0, 10 ) as $candidate ) {
	$available = count( bookify_available_slots( $service_id, $candidate ) );

	if ( $available > $room ) {
		$room = $available;
		$day  = $candidate;
	}
}

echo "day={$day} free_times_here={$room}\n\n";

/* ---- 1. each mode: status, amount, and the message ---- */

$modes = array(
	'none'    => array(),
	'deposit' => array( 'bookify_payment_mode' => 'deposit', 'bookify_deposit_type' => 'amount', 'bookify_deposit_value' => '25.00' ),
	'percent' => array( 'bookify_payment_mode' => 'deposit', 'bookify_deposit_type' => 'percent', 'bookify_deposit_value' => '50' ),
	'full'    => array( 'bookify_payment_mode' => 'full' ),
);

foreach ( $modes as $label => $meta ) {
	delete_post_meta( $service_id, 'bookify_payment_mode' );
	delete_post_meta( $service_id, 'bookify_deposit_type' );
	delete_post_meta( $service_id, 'bookify_deposit_value' );

	foreach ( $meta as $key => $value ) {
		update_post_meta( $service_id, $key, $value );
	}

	$before_mail = count( $GLOBALS['probe_mail'] );
	$time        = probe_free_slot( $service_id, $day );
	$booking_id  = probe_book( $service_id, $day, $time );

	if ( is_wp_error( $booking_id ) ) {
		echo sprintf( "%-8s REFUSED %s (time=%s)\n", $label, $booking_id->get_error_code(), $time );
		continue;
	}

	$amount_due = bookify_booking_amount_due( $service_id );
	$due_at     = bookify_booking_payment_due_at( $booking_id );

	printf(
		"%-8s at %s mode=%-8s amount_due=%-7s stored=%-7s status=%-16s closes_in=%s min\n",
		$label,
		$time,
		bookify_booking_service_payment_mode( $service_id ),
		number_format( $amount_due, 2 ),
		number_format( (float) get_post_meta( $booking_id, 'bookify_payment_amount', true ), 2 ),
		(string) get_post_meta( $booking_id, 'bookify_status', true ),
		$due_at ? round( ( $due_at - time() ) / 60 ) : 0
	);

	echo '  subject: ' . probe_last_mail()['subject'] . "\n";

	foreach ( probe_mail_payment_lines() as $line ) {
		echo '  mail: ' . $line . "\n";
	}

	echo '  sweep scheduled: ' . ( wp_next_scheduled( 'bookify_booking_expire', array( $booking_id ) ) ? 'yes' : 'no' ) . "\n";
	echo '  payment row: "' . bookify_booking_payment_label( $booking_id ) . "\"\n";
	echo '  admin amount cell: ' . bookify_booking_amount_label( (float) get_post_meta( $booking_id, 'bookify_payment_amount', true ) ) . "\n";

	wp_delete_post( $booking_id, true );
	$GLOBALS['probe_bookings'] = array_values( array_diff( $GLOBALS['probe_bookings'], array( $booking_id ) ) );
	$GLOBALS['probe_mail']     = array_slice( $GLOBALS['probe_mail'], 0, $before_mail );
}

delete_post_meta( $service_id, 'bookify_payment_mode' );
delete_post_meta( $service_id, 'bookify_deposit_type' );
delete_post_meta( $service_id, 'bookify_deposit_value' );

/* ---- 2. deposits that cannot be taken ---- */

echo "\n-- deposits that cannot be taken --\n";

$price = (float) get_post_meta( $service_id, 'bookify_price', true );

update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
update_post_meta( $service_id, 'bookify_deposit_value', '500.00' );

printf( "a 500.00 deposit on a %s price -> %s\n", number_format( $price, 2 ), number_format( bookify_booking_amount_due( $service_id ), 2 ) );

update_post_meta( $service_id, 'bookify_deposit_type', 'percent' );
update_post_meta( $service_id, 'bookify_deposit_value', '250' );

printf( "a 250%% deposit -> %s\n", number_format( bookify_booking_amount_due( $service_id ), 2 ) );

update_post_meta( $service_id, 'bookify_payment_mode', 'nonsense' );

printf( "an unreadable mode -> %s (reads as '%s')\n", number_format( bookify_booking_amount_due( $service_id ), 2 ), bookify_booking_service_payment_mode( $service_id ) );

$original_price = get_post_meta( $service_id, 'bookify_price', true );
delete_post_meta( $service_id, 'bookify_price' );
update_post_meta( $service_id, 'bookify_payment_mode', 'full' );

printf( "the full price of an unpriced service -> %s\n", number_format( bookify_booking_amount_due( $service_id ), 2 ) );

update_post_meta( $service_id, 'bookify_price', $original_price );
delete_post_meta( $service_id, 'bookify_payment_mode' );
delete_post_meta( $service_id, 'bookify_deposit_type' );
delete_post_meta( $service_id, 'bookify_deposit_value' );

/* ---- 3. with every service free, nothing changed since T16 ---- */

echo "\n-- free service (T16 behaviour) --\n";

$time = probe_free_slot( $service_id, $day );
$free = probe_book( $service_id, $day, $time );

if ( is_wp_error( $free ) ) {
	echo 'REFUSED ' . $free->get_error_code() . "\n";
} else {
	printf(
		"status=%s payment_status=%s amount=%s sweep_scheduled=%s payment_row=\"%s\"\n",
		(string) get_post_meta( $free, 'bookify_status', true ),
		'' === bookify_booking_payment_status( $free ) ? '(none)' : bookify_booking_payment_status( $free ),
		'' === (string) get_post_meta( $free, 'bookify_payment_amount', true ) ? '(none)' : (string) get_post_meta( $free, 'bookify_payment_amount', true ),
		wp_next_scheduled( 'bookify_booking_expire', array( $free ) ) ? 'yes (wrong)' : 'no',
		bookify_booking_payment_label( $free )
	);

	echo 'mail mentions payment: ' . ( probe_mail_payment_lines() ? 'YES (wrong)' : 'no' ) . "\n";

	probe_forget( $free );
}

/* ---- 4. the expiry appointment is released by every path that ends the wait ---- */

echo "\n-- the expiry appointment --\n";

function probe_scheduled( $booking_id ) {
	return wp_next_scheduled( 'bookify_booking_expire', array( $booking_id ) ) ? 'yes' : 'no';
}

update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
update_post_meta( $service_id, 'bookify_deposit_value', '25.00' );

$when = probe_free_slot( $service_id, $day );

/**
 * Book something wherever there is genuinely room, and stop rather than guess.
 *
 * Each case below leaves its booking holding the time it took in a different way — cancelled, paid,
 * expired — so every case asks for its own free slot.
 */
function probe_booking_anywhere( $service_id, $day ) {
	$time = probe_free_slot( $service_id, $day );
	$id   = probe_book( $service_id, $day, $time );

	if ( is_wp_error( $id ) ) {
		echo 'REFUSED ' . $id->get_error_code() . " (time={$time})\n";
		probe_cleanup();
		exit;
	}

	return $id;
}

$one = probe_booking_anywhere( $service_id, $day );
echo 'when it is booked:  ' . probe_scheduled( $one ) . ' at ' . get_post_meta( $one, 'bookify_time', true ) . "\n";

bookify_booking_cancel( $one );
echo 'after cancelling:   ' . probe_scheduled( $one ) . ' (status ' . get_post_meta( $one, 'bookify_status', true ) . ")\n";

probe_forget( $one );

$two = probe_booking_anywhere( $service_id, $day );

bookify_booking_stripe_mark_paid( array( 'metadata' => array( 'bookify_reference' => get_post_meta( $two, 'bookify_reference', true ) ) ) );
echo 'after paying:       ' . probe_scheduled( $two ) . ' (status ' . get_post_meta( $two, 'bookify_status', true ) . ")\n";

probe_forget( $two );

$three = probe_booking_anywhere( $service_id, $day );

update_post_meta( $three, 'bookify_payment_due', time() - 60 );
bookify_booking_expire_unpaid( $three );
echo 'after expiring:     ' . probe_scheduled( $three ) . ' (status ' . get_post_meta( $three, 'bookify_status', true ) . ")\n";

probe_forget( $three );

$four = probe_booking_anywhere( $service_id, $day );

probe_forget( $four );
echo 'after deleting:     ' . probe_scheduled( $four ) . "\n";

foreach ( array( 'bookify_payment_mode', 'bookify_deposit_type', 'bookify_deposit_value' ) as $key ) {
	delete_post_meta( $service_id, $key );
}

echo "\n";
probe_cleanup();
