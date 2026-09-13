<?php
/**
 * Throwaway probe for T25: the webhook's signature, its four events, and its idempotency.
 *
 * Phases, because the HTTP half has to be driven from the host (the container cannot reach its own
 * site, T3's lesson) and each `eval-file` run is one request:
 *
 *   wp option update bookify_probe_webhook rules        && wp eval-file …/stage5-webhook.php
 *   wp option update bookify_probe_webhook http-setup   && wp eval-file …/stage5-webhook.php
 *   (curl from the host, with the two files this wrote)
 *   wp option update bookify_probe_webhook http-check   && wp eval-file …/stage5-webhook.php
 *   wp option update bookify_probe_webhook cleanup      && wp eval-file …/stage5-webhook.php
 *
 * Deleted after use.
 */

$phase  = (string) get_option( 'bookify_probe_webhook', '' );
$secret = 'whsec_probe_0000000000000000';

function probe_service() {
	$services = bookify_booking_published_services();

	return $services[0];
}

function probe_book( $service_id, $day, $time, $name ) {
	$result = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => $name,
			'email'      => 'stage5-webhook@example.com',
			'date'       => $day,
			'time'       => $time,
			'party_size' => 1,
		)
	);

	return is_wp_error( $result ) ? $result : $result;
}

function probe_free_slot( $service_id, $day ) {
	$slots = bookify_available_slots( $service_id, $day );

	return $slots ? $slots[0] : '';
}

function probe_event( $id, $type, $reference, array $extra = array() ) {
	return wp_json_encode(
		array(
			'id'   => $id,
			'type' => $type,
			'data' => array(
				'object' => array_merge( array( 'metadata' => array( 'bookify_reference' => $reference ) ), $extra ),
			),
		)
	);
}

function probe_sign( $payload, $secret, $timestamp = null ) {
	$timestamp = null === $timestamp ? time() : $timestamp;

	return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
}

/**
 * Deliver a signed body through the real route, the way Stripe would.
 *
 * rest_do_request() and not the callback: whether the route is registered on rest_api_init is
 * exactly one of the things this is meant to catch.
 */
function probe_deliver( $payload, $signature ) {
	$request = new WP_REST_Request( 'POST', '/bookify/v1/stripe-webhook' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( $payload );

	if ( null !== $signature ) {
		$request->set_header( 'stripe-signature', $signature );
	}

	$response = rest_do_request( $request );

	return array(
		$response->get_status(),
		$response->get_data(),
	);
}

function probe_book_state( $booking_id ) {
	return sprintf(
		'status=%-16s payment=%-9s reference=%-12s note="%s"',
		(string) get_post_meta( $booking_id, 'bookify_status', true ),
		'' === bookify_booking_payment_status( $booking_id ) ? '(none)' : bookify_booking_payment_status( $booking_id ),
		(string) get_post_meta( $booking_id, 'bookify_payment_reference', true ),
		(string) get_post_meta( $booking_id, 'bookify_payment_note', true )
	);
}

function probe_payments_backup() {
	return get_option( BOOKIFY_PAYMENTS_OPTION, null );
}

function probe_payments_restore( $backup ) {
	if ( null === $backup ) {
		delete_option( BOOKIFY_PAYMENTS_OPTION );
	} else {
		update_option( BOOKIFY_PAYMENTS_OPTION, $backup );
	}
}

$service    = probe_service();
$service_id = $service->ID;
$days       = bookify_available_days( $service_id );
$day        = $days ? $days[0] : '';

echo "phase={$phase} service={$service_id} day={$day}\n";

/* ------------------------------------------------ 1. the rules, in process */

if ( 'rules' === $phase ) {
	$backup = probe_payments_backup();

	/*
	 * The idempotency transients outlive a run — a week is the point of them — so a second run of
	 * this probe would deliver events it has already delivered and be told `duplicate` for every one
	 * of them. Cleared here rather than in the cleanup phase, so a run that dies half way through
	 * still starts clean next time.
	 */
	foreach ( array( 'evt_nosecret', 'evt_signature', 'evt_paid_1', 'evt_expired_1', 'evt_expired_2', 'evt_failed_1', 'evt_refund_1', 'evt_ignored_1', 'evt_stranger_1' ) as $event_id ) {
		delete_transient( 'bookify_stripe_event_' . md5( $event_id ) );
	}

	delete_post_meta( $service_id, 'bookify_payment_mode' );
	update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
	update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
	update_post_meta( $service_id, 'bookify_deposit_value', '25.00' );

	// No signing secret yet: nothing can be verified, so nothing may be trusted.
	update_option( BOOKIFY_PAYMENTS_OPTION, array( 'currency' => 'gbp', 'secret_key' => 'sk_test_probe', 'webhook_secret' => '', 'expiry_minutes' => 30 ) );

	$time       = probe_free_slot( $service_id, $day );
	$booking_id = probe_book( $service_id, $day, $time, 'Stage 5 webhook probe' );

	if ( is_wp_error( $booking_id ) ) {
		echo 'could not set up: ' . $booking_id->get_error_code() . "\n";
		exit;
	}

	$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );

	echo "\n-- no signing secret configured --\n";
	list( $status, $data ) = probe_deliver( probe_event( 'evt_nosecret', 'checkout.session.completed', $reference ), probe_sign( 'x', $secret ) );
	printf( "  HTTP %d %s\n", $status, wp_json_encode( $data ) );

	update_option( BOOKIFY_PAYMENTS_OPTION, array( 'currency' => 'gbp', 'secret_key' => 'sk_test_probe', 'webhook_secret' => $secret, 'expiry_minutes' => 30 ) );

	echo "\n-- the signature --\n";

	$payload = probe_event( 'evt_signature', 'checkout.session.completed', $reference );

	printf( "  a correct signature:            %s\n", bookify_booking_stripe_signature_is_valid( $payload, probe_sign( $payload, $secret ), $secret ) ? 'accepted' : 'REFUSED (wrong)' );

	$stale = probe_sign( $payload, $secret, time() - 400 );

	printf( "  one 400 seconds old:            %s\n", bookify_booking_stripe_signature_is_valid( $payload, $stale, $secret ) ? 'ACCEPTED (wrong)' : 'refused' );
	printf( "  ...measured against a clock 400s earlier: %s\n", bookify_booking_stripe_signature_is_valid( $payload, $stale, $secret, time() - 400 ) ? 'accepted' : 'REFUSED (wrong)' );

	// One character flipped, never "two characters replaced by the same two": a signature ending in
	// ff would survive that, and the probe would report the plugin as broken.
	$signature = probe_sign( $payload, $secret );
	$tampered  = substr( $signature, 0, -1 ) . ( 'f' === substr( $signature, -1 ) ? 'a' : 'f' );

	printf( "  a signature with two characters changed: %s\n", bookify_booking_stripe_signature_is_valid( $payload, $tampered, $secret ) ? 'ACCEPTED (wrong)' : 'refused' );
	printf( "  the right signature, wrong secret:       %s\n", bookify_booking_stripe_signature_is_valid( $payload, probe_sign( $payload, 'whsec_something_else' ), $secret ) ? 'ACCEPTED (wrong)' : 'refused' );
	printf( "  no header at all:                        %s\n", bookify_booking_stripe_signature_is_valid( $payload, '', $secret ) ? 'ACCEPTED (wrong)' : 'refused' );

	echo "\n-- a stale signature is refused by the route, too --\n";

	list( $status, $data ) = probe_deliver( $payload, $stale );
	printf( "  HTTP %d %s\n", $status, wp_json_encode( $data ) );

	echo "\n-- a wrong signature changes nothing --\n";

	list( $status, $data ) = probe_deliver( $payload, $tampered );
	printf( "  HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '  ' . probe_book_state( $booking_id ) . "\n";

	echo "\n-- the signature this site cannot compute --\n";

	list( $status, $data ) = probe_deliver( $payload, null );
	printf( "  HTTP %d %s\n", $status, wp_json_encode( $data ) );


	echo "\n-- checkout.session.completed --\n";

	$completed = probe_event( 'evt_paid_1', 'checkout.session.completed', $reference, array( 'payment_intent' => 'pi_probe_0001', 'id' => 'cs_probe_0001' ) );

	list( $status, $data ) = probe_deliver( $completed, probe_sign( $completed, $secret ) );
	printf( "  HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '  ' . probe_book_state( $booking_id ) . "\n";

	echo "\n-- the same event id, twice --\n";

	/*
	 * Back to waiting for money, with the window closed, so the expiry event has something to do if
	 * the idempotency check is not the thing that stops the second delivery.
	 */
	update_post_meta( $booking_id, 'bookify_status', 'awaiting_payment' );
	update_post_meta( $booking_id, 'bookify_payment_due', time() - 60 );

	$expired_event = probe_event( 'evt_expired_1', 'checkout.session.expired', $reference );

	list( $status, $data ) = probe_deliver( $expired_event, probe_sign( $expired_event, $secret ) );
	printf( "  first delivery:  HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '  ' . probe_book_state( $booking_id ) . "\n";
	printf( "  slot offered again: %s\n", in_array( $time, bookify_available_slots( $service_id, $day ), true ) ? 'yes' : 'NO (wrong)' );

	update_post_meta( $booking_id, 'bookify_status', 'awaiting_payment' );

	list( $status, $data ) = probe_deliver( $expired_event, probe_sign( $expired_event, $secret ) );
	printf( "  second delivery: HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '  ' . probe_book_state( $booking_id ) . "  <- must still be awaiting_payment\n";

	echo "\n-- a second, different event id (same content) is not a duplicate --\n";

	$other = probe_event( 'evt_expired_2', 'checkout.session.expired', $reference );

	list( $status, $data ) = probe_deliver( $other, probe_sign( $other, $secret ) );
	printf( "  HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '  ' . probe_book_state( $booking_id ) . "  <- a different id, so it acts\n";

	echo "\n-- other event types --\n";

	update_post_meta( $booking_id, 'bookify_status', 'awaiting_payment' );
	update_post_meta( $booking_id, 'bookify_payment_status', 'unpaid' );
	update_post_meta( $booking_id, 'bookify_payment_due', time() - 60 );
	delete_post_meta( $booking_id, 'bookify_payment_note' );

	$failed = probe_event( 'evt_failed_1', 'payment_intent.payment_failed', $reference, array( 'id' => 'pi_probe_0001' ) );

	list( $status, $data ) = probe_deliver( $failed, probe_sign( $failed, $secret ) );
	printf( "  payment_failed:       HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '    ' . probe_book_state( $booking_id ) . "\n";

	$refunded = probe_event( 'evt_refund_1', 'charge.refunded', $reference, array( 'amount_refunded' => 2500, 'currency' => 'gbp' ) );

	list( $status, $data ) = probe_deliver( $refunded, probe_sign( $refunded, $secret ) );
	printf( "  charge.refunded:      HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '    ' . probe_book_state( $booking_id ) . "\n";

	$ignored = probe_event( 'evt_ignored_1', 'invoice.paid', $reference );

	list( $status, $data ) = probe_deliver( $ignored, probe_sign( $ignored, $secret ) );
	printf( "  invoice.paid (ignored): HTTP %d %s\n", $status, wp_json_encode( $data ) );
	echo '    ' . probe_book_state( $booking_id ) . "\n";

	$nobooking = probe_event( 'evt_stranger_1', 'checkout.session.completed', 'BK-99999' );

	list( $status, $data ) = probe_deliver( $nobooking, probe_sign( $nobooking, $secret ) );
	printf( "  a reference nobody knows: HTTP %d %s\n", $status, wp_json_encode( $data ) );

	// A body that is not an event at all.
	list( $status, $data ) = probe_deliver( '{"id":"evt_junk","type":"checkout.session.completed","data":{"object":[]}}', null );
	printf( "  no signature at all:      HTTP %d\n", $status );

	// Valid JSON, correctly signed, and not an event: this one reaches the callback.
	$junk = '{"hello":"world"}';
	list( $status, $data ) = probe_deliver( $junk, probe_sign( $junk, $secret ) );
	printf( "  signed, valid JSON, not an event: HTTP %d %s\n", $status, wp_json_encode( $data ) );

	// Not JSON at all: WordPress's own REST parsing answers before the callback does.
	$notjson = 'this is not json';
	list( $status, $data ) = probe_deliver( $notjson, probe_sign( $notjson, $secret ) );
	printf( "  signed, not JSON at all:  HTTP %d %s\n", $status, wp_json_encode( $data ) );

	probe_payments_restore( $backup );
	delete_post_meta( $service_id, 'bookify_payment_mode' );
	delete_post_meta( $service_id, 'bookify_deposit_type' );
	delete_post_meta( $service_id, 'bookify_deposit_value' );
	wp_delete_post( $booking_id, true );

	echo "\ncleaned up\n";
	exit;
}

/* ------------------------------------------------ 2. the HTTP half */

if ( 'http-setup' === $phase ) {
	$backup = probe_payments_backup();

	update_option( 'bookify_probe_webhook_backup', null === $backup ? 'unset' : $backup );

	update_post_meta( $service_id, 'bookify_payment_mode', 'deposit' );
	update_post_meta( $service_id, 'bookify_deposit_type', 'amount' );
	update_post_meta( $service_id, 'bookify_deposit_value', '25.00' );
	update_option( BOOKIFY_PAYMENTS_OPTION, array( 'currency' => 'gbp', 'secret_key' => 'sk_test_probe', 'webhook_secret' => $secret, 'expiry_minutes' => 30 ) );

	$time       = probe_free_slot( $service_id, $day );
	$booking_id = probe_book( $service_id, $day, $time, 'Stage 5 HTTP webhook probe' );

	if ( is_wp_error( $booking_id ) ) {
		echo 'could not set up: ' . $booking_id->get_error_code() . "\n";
		exit;
	}

	$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );
	$payload   = probe_event( 'evt_http_1', 'checkout.session.completed', $reference, array( 'payment_intent' => 'pi_http_0001' ) );
	$signature = probe_sign( $payload, $secret );

	update_option( 'bookify_probe_webhook_booking', $booking_id );
	update_option( 'bookify_probe_webhook_slot', $day . ' ' . $time );

	file_put_contents( __DIR__ . '/wh-payload.json', $payload );
	file_put_contents( __DIR__ . '/wh-signature.txt', $signature );

	// A second file with a signature that is right except for one character.
	file_put_contents( __DIR__ . '/wh-signature-bad.txt', substr( $signature, 0, -1 ) . ( 'f' === substr( $signature, -1 ) ? 'a' : 'f' ) );

	echo 'BOOKING=' . $booking_id . ' REFERENCE=' . $reference . ' AT=' . $day . ' ' . $time . "\n";
	echo 'PAYLOAD=' . $payload . "\n";
	echo 'SIGNATURE=' . $signature . "\n";
	echo "the files wh-payload.json, wh-signature.txt and wh-signature-bad.txt are written beside this probe\n";
	exit;
}

if ( 'http-check' === $phase ) {
	$booking_id = absint( get_option( 'bookify_probe_webhook_booking', 0 ) );
	list( $day, $time ) = array_pad( explode( ' ', (string) get_option( 'bookify_probe_webhook_slot', '' ) ), 2, '' );

	echo 'after the real POST: ' . probe_book_state( $booking_id ) . "\n";
	printf( "slot still held: %s\n", in_array( $time, bookify_available_slots( $service_id, $day ), true ) ? 'no (right: a paid booking holds it)' : 'yes (wrong)' );
	printf( "transient for the event id: %s\n", get_transient( 'bookify_stripe_event_' . md5( 'evt_http_1' ) ) ? 'set' : 'NOT SET (wrong)' );
	exit;
}

if ( 'cleanup' === $phase ) {
	$backup = get_option( 'bookify_probe_webhook_backup', 'unset' );

	if ( 'unset' === $backup ) {
		delete_option( BOOKIFY_PAYMENTS_OPTION );
	} elseif ( is_array( $backup ) ) {
		update_option( BOOKIFY_PAYMENTS_OPTION, $backup );
	}

	$booking_id = absint( get_option( 'bookify_probe_webhook_booking', 0 ) );

	if ( $booking_id ) {
		wp_delete_post( $booking_id, true );
	}

	delete_option( 'bookify_probe_webhook' );
	delete_option( 'bookify_probe_webhook_booking' );
	delete_option( 'bookify_probe_webhook_slot' );
	delete_option( 'bookify_probe_webhook_backup' );
	delete_transient( 'bookify_stripe_event_' . md5( 'evt_http_1' ) );

	delete_post_meta( $service_id, 'bookify_payment_mode' );
	delete_post_meta( $service_id, 'bookify_deposit_type' );
	delete_post_meta( $service_id, 'bookify_deposit_value' );

	foreach ( array( 'wh-payload.json', 'wh-signature.txt', 'wh-signature-bad.txt' ) as $file ) {
		if ( file_exists( __DIR__ . '/' . $file ) ) {
			unlink( __DIR__ . '/' . $file );
		}
	}

	echo "cleaned up\n";
	exit;
}

echo "unknown phase '{$phase}'\n";
