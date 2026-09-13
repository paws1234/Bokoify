<?php
/**
 * Stripe tells us it was paid (T25).
 *
 * The only thing that can make a booking paid is a signed event from Stripe. A browser arriving at
 * the success URL proves nothing — anybody can type a URL — so the success URL only ever *shows* the
 * booking and its real status, which is what T24's redirect does.
 *
 * The checks, in the order they run, and why each one is there:
 *
 *   1. **A signing secret must be configured.** Without one nothing can be verified, so nothing is
 *      trusted: the route answers 503 rather than pretending.
 *   2. **The signature is over the raw bytes.** `Stripe-Signature: t=…,v1=…` is verified as
 *      HMAC-SHA256 of `"{timestamp}.{body}"`, with the body read through `$request->get_body()` and
 *      never from a parsed array — WordPress having decoded and re-encoded the JSON would change the
 *      bytes and the signature could never match. The comparison uses `hash_equals()`, and an event
 *      whose timestamp is more than five minutes away from now is refused, so a captured request
 *      cannot be replayed indefinitely.
 *   3. **The same event twice does nothing the second time.** The event id is remembered in a
 *      transient, so Stripe's retries — which it will make, at the first hint of trouble — cannot
 *      charge, confirm or expire anything twice. Every handler below is also written to be
 *      idempotent in its own right, so a lost transient degrades the check rather than breaking it.
 *   4. **Only four event types are acted on, and everything else is logged and left alone.**
 *      `checkout.session.completed` confirms; `checkout.session.expired` gives the time back;
 *      `payment_intent.payment_failed` records the failure and keeps waiting;
 *      `charge.refunded` records the refund and leaves the appointment to a person.
 *
 * What is deliberately *not* here: replay prevention beyond the timestamp window and the event id,
 * partial refunds, and disputes. The plan puts those out of scope for this stage, and the public
 * webhook URL itself is a hosting step.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * How far a signature's timestamp may be from now, in seconds.
 *
 * Stripe's own documented default, and the reason a captured request cannot be replayed: outside
 * this window the signature is refused however correct it is.
 */
const BOOKIFY_STRIPE_SIGNATURE_TOLERANCE = 300;

/**
 * Verify a `Stripe-Signature` header against the raw request body.
 *
 * Written to Stripe's documented scheme: the header holds a signed timestamp (`t=…`) and one or more
 * signatures (`v1=…`), and the signed message is the timestamp, a dot, and the body exactly as it
 * arrived. `$now` is a parameter so a test can prove the staleness rule without waiting five
 * minutes.
 *
 * @param string   $payload   The raw request body.
 * @param string   $header    The Stripe-Signature header.
 * @param string   $secret    The endpoint's signing secret.
 * @param int|null $now       The time to measure staleness against. Null means now.
 * @return bool
 */
function bookify_booking_stripe_signature_is_valid( $payload, $header, $secret, $now = null ) {
	if ( '' === (string) $secret || ! is_string( $header ) || '' === $header ) {
		return false;
	}

	$timestamp  = null;
	$signatures = array();

	foreach ( explode( ',', $header ) as $part ) {
		$pair = explode( '=', trim( $part ), 2 );

		if ( count( $pair ) !== 2 ) {
			continue;
		}

		if ( 't' === $pair[0] ) {
			$timestamp = $pair[1];
		} elseif ( 'v1' === $pair[0] ) {
			$signatures[] = $pair[1];
		}
	}

	// No timestamp, no signature, or a timestamp that is not a Unix time: nothing to verify against.
	if ( null === $timestamp || ! ctype_digit( $timestamp ) || ! $signatures ) {
		return false;
	}

	$now = null === $now ? time() : (int) $now;

	if ( abs( $now - (int) $timestamp ) > BOOKIFY_STRIPE_SIGNATURE_TOLERANCE ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

	// hash_equals() and not ===: a comparison that returns early leaks how much of a guess was right.
	foreach ( $signatures as $signature ) {
		if ( hash_equals( $expected, $signature ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The booking a Stripe object is about.
 *
 * The reference travels in `metadata`, put there by T24, and `client_reference_id` is the fallback
 * for a Checkout Session — the same value, carried in the field Stripe shows in its dashboard. A
 * PaymentIntent or a Charge carries only the metadata, which is why T24 repeats it onto the intent.
 *
 * @param array $object The event's `data.object`, as decoded.
 * @return \WP_Post|null
 */
function bookify_booking_stripe_object_booking( array $object ) {
	$reference = '';

	if ( isset( $object['metadata'] ) && is_array( $object['metadata'] ) && isset( $object['metadata']['bookify_reference'] ) ) {
		$reference = (string) $object['metadata']['bookify_reference'];
	}

	if ( '' === $reference && isset( $object['client_reference_id'] ) ) {
		$reference = (string) $object['client_reference_id'];
	}

	if ( '' === $reference ) {
		return null;
	}

	$booking = bookify_booking_find_by_reference( $reference );

	return $booking instanceof WP_Post ? $booking : null;
}

/**
 * The first non-empty string among an object's keys.
 *
 * A tiny helper rather than four isset() chains: which identifier an event carries depends on its
 * type (a session has an id and a payment_intent, an intent has only an id), and the only thing that
 * matters is that one of them is recorded for an operator to look up.
 *
 * @param array            $object The decoded object.
 * @param array<int,string> $keys  Candidate keys, in preference order.
 * @return string
 */
function bookify_booking_stripe_object_id( array $object, array $keys ) {
	foreach ( $keys as $key ) {
		if ( isset( $object[ $key ] ) && is_string( $object[ $key ] ) && '' !== $object[ $key ] ) {
			return $object[ $key ];
		}
	}

	return '';
}

/**
 * Act on `checkout.session.completed`: the money arrived.
 *
 * Everything else about the booking is already correct — it was `awaiting_payment`, holding its
 * time — so this confirms it and records what paid it. The one case worth naming is money arriving
 * for a booking that had already stopped holding its time (it expired, or somebody cancelled it, in
 * the minutes between starting the payment and paying). That is a refund or a rebooking, and only a
 * person can decide which: the payment is recorded and a note is left for the operator.
 *
 * @param array $object The Checkout Session.
 * @return bool Whether anything was changed.
 */
function bookify_booking_stripe_mark_paid( array $object ) {
	$booking = bookify_booking_stripe_object_booking( $object );

	if ( ! $booking ) {
		bookify_booking_stripe_log( 'A completed checkout named no booking this site knows.' );

		return false;
	}

	$paid_with = bookify_booking_stripe_object_id( $object, array( 'payment_intent', 'id' ) );

	update_post_meta( $booking->ID, 'bookify_payment_status', 'paid' );

	if ( '' !== $paid_with ) {
		update_post_meta( $booking->ID, 'bookify_payment_reference', $paid_with );
	}

	if ( in_array( (string) get_post_meta( $booking->ID, 'bookify_status', true ), bookify_booking_slot_holding_statuses(), true ) ) {
		update_post_meta( $booking->ID, 'bookify_status', 'confirmed' );

		// Paid: there is no longer a deadline to sweep, so the appointment for it is released (T23).
		bookify_booking_unschedule_expiry( $booking->ID );

		return true;
	}

	$reference = (string) get_post_meta( $booking->ID, 'bookify_reference', true );

	update_post_meta(
		$booking->ID,
		'bookify_payment_note',
		__( 'Paid after this booking had already stopped holding its time. It has been left as it was — refund it or rebook it by hand.', 'bookify-booking' )
	);

	bookify_booking_stripe_log( 'Payment for ' . $reference . ' arrived after the booking stopped holding its time.' );

	return true;
}

/**
 * Act on `checkout.session.expired`: the customer never paid within Stripe's own window.
 *
 * The same effect as T23's own expiry, and it is deliberately allowed to be a no-op: whichever of
 * the two gets there first frees the time, and the second finds nothing to do.
 *
 * @param array $object The Checkout Session.
 * @return bool Whether anything was changed.
 */
function bookify_booking_stripe_release( array $object ) {
	$booking = bookify_booking_stripe_object_booking( $object );

	if ( ! $booking ) {
		bookify_booking_stripe_log( 'An expired checkout named no booking this site knows.' );

		return false;
	}

	return bookify_booking_expire_unpaid( $booking->ID );
}

/**
 * Act on `payment_intent.payment_failed`: the card was refused.
 *
 * The booking stays `awaiting_payment` and keeps its time, because the customer can try again while
 * the window lasts — which is exactly what T24's button is for. What changes is the record, so an
 * operator looking at a booking that never completes can see that a card was tried and refused.
 *
 * @param array $object The PaymentIntent.
 * @return bool Whether anything was changed.
 */
function bookify_booking_stripe_mark_failed( array $object ) {
	$booking = bookify_booking_stripe_object_booking( $object );

	if ( ! $booking ) {
		bookify_booking_stripe_log( 'A failed payment named no booking this site knows.' );

		return false;
	}

	// Only a booking that is still waiting for money. A failure that arrives after the booking was
	// confirmed, cancelled or expired is a late noise and must not rewrite its state.
	if ( ! bookify_booking_payment_is_due( $booking->ID ) ) {
		return false;
	}

	update_post_meta( $booking->ID, 'bookify_payment_status', 'failed' );

	return true;
}

/**
 * Act on `charge.refunded`: record it, and let a person decide about the appointment.
 *
 * Deliberately **not** a cancellation. A refund is a fact about money; whether the appointment is
 * still happening is a different question, and only the business can answer it — so the booking's
 * status is left alone and the note says so in as many words.
 *
 * @param array $object The Charge.
 * @return bool Whether anything was changed.
 */
function bookify_booking_stripe_mark_refunded( array $object ) {
	$booking = bookify_booking_stripe_object_booking( $object );

	if ( ! $booking ) {
		bookify_booking_stripe_log( 'A refund named no booking this site knows.' );

		return false;
	}

	update_post_meta( $booking->ID, 'bookify_payment_status', 'refunded' );

	// The charge's own currency rather than the site's, so a refund made in a currency the settings
	// no longer name is still printed correctly. Both are minor units, so both are divided by 100.
	$amount   = isset( $object['amount_refunded'] ) && is_numeric( $object['amount_refunded'] ) ? (int) $object['amount_refunded'] / 100 : 0;
	$currency = isset( $object['currency'] ) && is_string( $object['currency'] ) && '' !== $object['currency']
		? strtoupper( sanitize_text_field( $object['currency'] ) )
		: bookify_booking_currency();

	update_post_meta(
		$booking->ID,
		'bookify_payment_note',
		sprintf(
			/* translators: 1: the amount refunded, 2: the date the refund was recorded. */
			__( 'Stripe reported a refund of %1$s on %2$s. The appointment itself is untouched — cancel it if it is not going ahead.', 'bookify-booking' ),
			trim( $currency . ' ' . number_format_i18n( $amount, 2 ) ),
			wp_date( (string) get_option( 'date_format' ), null, wp_timezone() )
		)
	);

	return true;
}

/**
 * Act on one verified event.
 *
 * @param array $event The decoded event.
 * @return bool Whether the event changed anything.
 */
function bookify_booking_stripe_handle_event( array $event ) {
	$type   = isset( $event['type'] ) ? (string) $event['type'] : '';
	$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

	switch ( $type ) {
		case 'checkout.session.completed':
			return bookify_booking_stripe_mark_paid( $object );

		case 'checkout.session.expired':
			return bookify_booking_stripe_release( $object );

		case 'payment_intent.payment_failed':
			return bookify_booking_stripe_mark_failed( $object );

		case 'charge.refunded':
			return bookify_booking_stripe_mark_refunded( $object );
	}

	bookify_booking_stripe_log( 'Ignored an event of type "' . $type . '".' );

	return false;
}

/**
 * The webhook itself.
 *
 * Answers 503 when the site has no signing secret (Stripe will try again once one is saved), 400 to
 * anything whose signature or payload does not check out, and 200 to everything it has read — so a
 * retry only ever happens for a refusal the site could not make, never for an event it has dealt
 * with.
 *
 * @param WP_REST_Request $request The request, unread and untrusted.
 * @return WP_REST_Response
 */
function bookify_booking_stripe_webhook( WP_REST_Request $request ) {
	$secret = bookify_booking_payments()['webhook_secret'];

	if ( '' === $secret ) {
		bookify_booking_stripe_log( 'Refused a webhook: no signing secret is configured.' );

		return new WP_REST_Response( array( 'ok' => false, 'reason' => 'not-configured' ), 503 );
	}

	// The raw body, never a parsed array: the signature is over these bytes.
	$payload = (string) $request->get_body();
	$header  = $request->get_header( 'stripe-signature' );

	if ( ! bookify_booking_stripe_signature_is_valid( $payload, is_string( $header ) ? $header : '', $secret ) ) {
		bookify_booking_stripe_log( 'Refused a webhook whose signature did not check out.' );

		return new WP_REST_Response( array( 'ok' => false, 'reason' => 'bad-signature' ), 400 );
	}

	$event = json_decode( $payload, true );

	if ( ! is_array( $event ) || empty( $event['id'] ) || ! is_string( $event['id'] ) || empty( $event['type'] ) ) {
		bookify_booking_stripe_log( 'Refused a signed webhook whose body was not an event.' );

		return new WP_REST_Response( array( 'ok' => false, 'reason' => 'bad-payload' ), 400 );
	}

	// Idempotency, before anything is read or written: Stripe retries, and the second delivery of an
	// event that has already been dealt with must be answered rather than acted on again.
	$seen = 'bookify_stripe_event_' . md5( $event['id'] );

	if ( get_transient( $seen ) ) {
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'duplicate' => true,
			),
			200
		);
	}

	$changed = bookify_booking_stripe_handle_event( $event );

	// A week: long enough to cover every retry Stripe makes (three days at most), short enough that
	// the transient list cannot grow without bound.
	set_transient( $seen, 1, WEEK_IN_SECONDS );

	return new WP_REST_Response(
		array(
			'ok'      => true,
			'handled' => (bool) $changed,
		),
		200
	);
}

/**
 * Register the webhook route.
 *
 * On `rest_api_init`, because that is the only moment WordPress accepts a route: registered any
 * earlier the route still works, but WordPress says so in the log on every single request (T18's
 * trap, measured at 117 lines before it was found).
 *
 * The permission callback is open on purpose: the caller is Stripe, which has no account here. The
 * signature is the guard, and it is checked before any of this reads or writes anything.
 */
function bookify_booking_register_stripe_webhook() {
	register_rest_route(
		BOOKIFY_BOOKING_REST_NAMESPACE,
		'/stripe-webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'bookify_booking_stripe_webhook',
		)
	);
}

// Hooked here rather than from bookify_booking_init(), for the reason spelled out above: it is the
// registered *action* that matters, not who functions call.
add_action( 'rest_api_init', 'bookify_booking_register_stripe_webhook' );
