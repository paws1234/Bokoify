<?php
/**
 * Throwaway probe for T24's customer-visible half: the pay block on the manage page and the answer
 * the pay button gets when the site has no Stripe key.
 *
 * Phases, because the checks are made with curl from the host (the container cannot reach its own
 * site):
 *
 *   wp option update bookify_probe_pay setup   && wp eval-file …/stage5-http-pay.php
 *   (curl the manage page, and POST the pay form)
 *   wp option update bookify_probe_pay cleanup && wp eval-file …/stage5-http-pay.php
 *
 * Deleted after use.
 */

$phase = (string) get_option( 'bookify_probe_pay', '' );

$services   = bookify_booking_published_services();
$service    = $services[0];
$service_id = $service->ID;
$days       = bookify_available_days( $service_id );
$day        = $days ? $days[0] : '';

if ( 'setup' === $phase ) {
	update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
	update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
	update_post_meta( $service_id, 'bookify_deposit_value', '25.00' );

	$slots = bookify_available_slots( $service_id, $day );
	$time  = $slots ? $slots[0] : '';

	$booking_id = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => 'Stage 5 pay probe',
			'email'      => 'stage5-pay@example.com',
			'date'       => $day,
			'time'       => $time,
			'party_size' => 1,
		)
	);

	if ( is_wp_error( $booking_id ) ) {
		echo 'REFUSED ' . $booking_id->get_error_code() . "\n";
		exit;
	}

	update_option( 'bookify_probe_pay_booking', $booking_id );

	echo 'BOOKING=' . $booking_id . "\n";
	echo 'REFERENCE=' . get_post_meta( $booking_id, 'bookify_reference', true ) . "\n";
	echo 'MANAGE_URL=' . bookify_booking_manage_url( $booking_id ) . "\n";
	// Minted as nobody, which is what an anonymous HTTP request is: WordPress salts a nonce with the
	// user id and the session token, and both are empty on both sides.
	echo 'NONCE=' . wp_create_nonce( 'bookify_pay' ) . "\n";
	echo 'ENABLED=' . ( bookify_booking_stripe_enabled() ? 'yes' : 'no' ) . "\n";
	exit;
}

if ( 'cleanup' === $phase ) {
	$booking_id = absint( get_option( 'bookify_probe_pay_booking', 0 ) );

	if ( $booking_id ) {
		wp_delete_post( $booking_id, true );
	}

	delete_option( 'bookify_probe_pay' );
	delete_option( 'bookify_probe_pay_booking' );

	foreach ( array( 'bookify_payment_mode', 'bookify_deposit_type', 'bookify_deposit_value' ) as $key ) {
		delete_post_meta( $service_id, $key );
	}

	echo "cleaned up\n";
	exit;
}

echo "unknown phase '{$phase}'\n";
