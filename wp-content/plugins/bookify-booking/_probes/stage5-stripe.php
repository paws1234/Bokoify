<?php
/**
 * Throwaway probe for T24: the request the site composes for Stripe, and every way it can fail.
 *
 * wp_remote_post() dispatches `pre_http_request`, so the whole API is stubbed here: the probe reads
 * the exact bytes that would have gone to Stripe and answers with a canned response. Nothing here
 * needs an account, a key or a tunnel — which is the constraint the plan holds every third-party
 * call to. Deleted after use.
 */

$GLOBALS['probe_requests']  = array();
$GLOBALS['probe_stub_mode'] = 'session';

function probe_stub( $preempt, $args, $url ) {
	$GLOBALS['probe_requests'][] = array(
		'url'    => $url,
		'method' => isset( $args['method'] ) ? $args['method'] : '',
		'headers' => isset( $args['headers'] ) ? $args['headers'] : array(),
		'body'   => isset( $args['body'] ) ? $args['body'] : array(),
	);

	switch ( $GLOBALS['probe_stub_mode'] ) {
		case 'transport':
			return new WP_Error( 'probe_transport', 'Stubbed: the connection timed out.' );

		case 'error402':
			$code = 402;
			$body = wp_json_encode( array( 'error' => array( 'type' => 'card_error', 'message' => 'Your card was declined.' ) ) );
			break;

		case 'error500':
			$code = 500;
			$body = wp_json_encode( array( 'error' => array( 'message' => 'Internal server error.' ) ) );
			break;

		case 'badurl':
			$code = 200;
			$body = wp_json_encode( array( 'id' => 'cs_probe_bad', 'url' => 'https://evil.example.com/pay' ) );
			break;

		case 'nourl':
			$code = 200;
			$body = wp_json_encode( array( 'id' => 'cs_probe_nourl' ) );
			break;

		default:
			$code = 200;
			$body = wp_json_encode( array( 'id' => 'cs_probe_0001', 'url' => 'https://checkout.stripe.com/c/pay/cs_probe_0001' ) );
	}

	return array(
		'headers'  => array(),
		'body'     => $body,
		'response' => array(
			'code'    => $code,
			'message' => 'Stubbed',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter( 'pre_http_request', 'probe_stub', 10, 3 );

function probe_requests() {
	return $GLOBALS['probe_requests'];
}

function probe_calls( callable $callback ) {
	$GLOBALS['probe_requests'] = array();
	$result                    = $callback();

	printf(
		"  requests in this call: %d   result: %s\n",
		count( probe_requests() ),
		is_wp_error( $result ) ? 'WP_Error ' . $result->get_error_code() : 'url=' . $result['url'] . ' id=' . $result['id']
	);

	foreach ( probe_requests() as $request ) {
		echo '    ' . $request['method'] . ' ' . $request['url'] . "\n";

		foreach ( $request['headers'] as $name => $value ) {
			// The key is shown cut short on purpose: this probe is read by a person, and a full
			// credential in a terminal scrollback is exactly what D11 says must not happen.
			echo '      ' . $name . ': ' . ( 'Authorization' === $name ? substr( (string) $value, 0, 11 ) . '…' : $value ) . "\n";
		}

		foreach ( $request['body'] as $key => $value ) {
			echo '      ' . $key . ' = ' . ( is_array( $value ) ? wp_json_encode( $value ) : $value ) . "\n";
		}
	}
}

/* ---- set up a booking that needs paying ---- */

$services    = bookify_booking_published_services();
$service     = $services[0];
$service_id  = $service->ID;
$original    = get_option( BOOKIFY_PAYMENTS_OPTION, null );

delete_post_meta( $service_id, 'bookify_payment_mode' );
update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
update_post_meta( $service_id, 'bookify_deposit_value', '25.00' );

$days = bookify_available_days( $service_id );
$day  = $days ? $days[0] : '';
$slots = bookify_available_slots( $service_id, $day );
$time  = $slots ? $slots[0] : '';

$booking_id = bookify_create_booking(
	array(
		'service_id' => $service_id,
		'name'       => 'Stage 5 stripe probe',
		'email'      => 'stage5-stripe@example.com',
		'date'       => $day,
		'time'       => $time,
		'party_size' => 1,
	)
);

if ( is_wp_error( $booking_id ) ) {
	echo 'could not set up: ' . $booking_id->get_error_code() . "\n";
	exit;
}

$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );

echo "booking {$booking_id} {$reference} at {$day} {$time}\n";

/* ---- 1. no key: no request at all (acceptance 3) ---- */

echo "\n== no key configured ==\n";

delete_option( BOOKIFY_PAYMENTS_OPTION );

echo 'enabled: ' . ( bookify_booking_stripe_enabled() ? 'yes (wrong)' : 'no' ) . "\n";

probe_calls( static function () use ( $booking_id ) {
	return bookify_booking_stripe_create_session( $booking_id );
} );

echo 'booking untouched: status=' . get_post_meta( $booking_id, 'bookify_status', true )
	. ' payment=' . bookify_booking_payment_status( $booking_id ) . "\n";

/* ---- 2. a key: exactly one request, carrying the right things (acceptance 1) ---- */

echo "\n== a key is configured ==\n";

update_option(
	BOOKIFY_PAYMENTS_OPTION,
	array(
		'currency'       => 'gbp',
		'secret_key'     => 'sk_test_probe_0000000000000000',
		'webhook_secret' => 'whsec_probe_0000000000000000',
		'expiry_minutes' => 30,
	)
);

echo 'enabled: ' . ( bookify_booking_stripe_enabled() ? 'yes' : 'no' )
	. '  amount_due_on_the_booking: ' . bookify_booking_amount_label( (float) get_post_meta( $booking_id, 'bookify_payment_amount', true ) ) . "\n";

probe_calls( static function () use ( $booking_id ) {
	return bookify_booking_stripe_create_session( $booking_id );
} );


foreach ( array( 'error402', 'error500', 'transport', 'badurl', 'nourl' ) as $mode ) {
	$GLOBALS['probe_stub_mode'] = $mode;

	echo $mode . ":\n";

	probe_calls( static function () use ( $booking_id ) {
		return bookify_booking_stripe_create_session( $booking_id );
	} );

	printf(
		"  afterwards: status=%s payment=%s sweep_scheduled=%s\n",
		(string) get_post_meta( $booking_id, 'bookify_status', true ),
		bookify_booking_payment_status( $booking_id ),
		wp_next_scheduled( 'bookify_booking_expire', array( $booking_id ) ) ? 'yes' : 'no'
	);
}

/* ---- 4. a booking with nothing to pay is never sent anywhere ---- */

echo "\n== a service that is free ==\n";

delete_post_meta( $service_id, 'bookify_payment_mode' );

$free_slots = bookify_available_slots( $service_id, $day );
$free_id    = bookify_create_booking(
	array(
		'service_id' => $service_id,
		'name'       => 'Stage 5 free probe',
		'email'      => 'stage5-free@example.com',
		'date'       => $day,
		'time'       => $free_slots ? $free_slots[0] : '',
		'party_size' => 1,
	)
);

if ( is_wp_error( $free_id ) ) {
	echo 'the free booking was REFUSED ' . $free_id->get_error_code() . "\n";
} else {
	echo 'the free booking is ' . (string) get_post_meta( $free_id, 'bookify_status', true ) . "\n";

	probe_calls( static function () use ( $free_id ) {
		return bookify_booking_stripe_create_session( $free_id );
	} );
}

/* ---- 5. nothing card-shaped is stored ---- */

echo "\n== stored meta on the booking ==\n";

$meta = get_post_meta( $booking_id );

foreach ( $meta as $key => $values ) {
	echo '  ' . $key . ' = ' . implode( '|', array_map( 'strval', $values ) ) . "\n";
}

/* ---- cleanup ---- */

if ( null === $original ) {
	delete_option( BOOKIFY_PAYMENTS_OPTION );
} else {
	update_option( BOOKIFY_PAYMENTS_OPTION, $original );
}

delete_post_meta( $service_id, 'bookify_payment_mode' );
delete_post_meta( $service_id, 'bookify_deposit_type' );
delete_post_meta( $service_id, 'bookify_deposit_value' );

wp_delete_post( $booking_id, true );

if ( ! is_wp_error( $free_id ) ) {
	wp_delete_post( $free_id, true );
}

echo "\ncleaned up\n";
