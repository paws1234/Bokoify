<?php
/**
 * Taking the money (T24, plan D11).
 *
 * Stripe Checkout, called directly with wp_remote_post(): no SDK, no plugin, no Composer — this
 * image has no Composer on purpose — and no card data anywhere near this server. Checkout is a
 * redirect, so the customer types their card into Stripe's page and this site only ever learns
 * that a payment happened, from Stripe, in T25.
 *
 * Four rules, each of which is a decision rather than an accident:
 *
 *   1. **Off unless configured.** No secret key, no currency, no call, and no mention of Stripe in
 *      the flow at all. The site is exactly what it was after T23.
 *   2. **The request is the evidence.** The body carries the amount decided when the booking was
 *      written, the site's currency, the booking's reference in `metadata` — and nothing else about
 *      the customer. No name, no address, no email, no phone: those belong in this site's own
 *      record, and the plan says the metadata carries the reference and nothing else.
 *   3. **A failure is a message, never a blank page.** A transport error, a non-2xx, an unreadable
 *      body or a response with no URL all leave the booking exactly as it was — `awaiting_payment`,
 *      still holding its slot, still payable — and send the customer back to their booking with a
 *      sentence and a working button.
 *   4. **Filterable, so the acceptance is local.** wp_remote_post() dispatches `pre_http_request`,
 *      so `wpdev wp eval` can stub the call, read the exact bytes that would have gone to Stripe and
 *      answer with a canned session. No account and no tunnel are needed to prove this works, which
 *      is the constraint every third-party call in this plan is held to. A key beginning `sk_test_`
 *      is a test key and needs nothing else — there is no separate test switch to get wrong.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe's Checkout Session endpoint, from its API reference.
 */
const BOOKIFY_STRIPE_API = 'https://api.stripe.com/v1/checkout/sessions';

/**
 * Whether this site can take a payment at all.
 *
 * Both halves are needed and each is a real answer: a key with no currency cannot build a line
 * item, and a currency with no key cannot build a request. An empty currency also switches the
 * whole feature off, which is the honest reading of "we do not know what money this business takes".
 *
 * @return bool
 */
function bookify_booking_stripe_enabled() {
	$payments = bookify_booking_payments();

	return '' !== $payments['secret_key'] && '' !== $payments['currency'];
}

/**
 * Note something about a payment in the site's log.
 *
 * Never a key, never a card and never a whole request body: what is worth writing down is what
 * happened — refused, unreachable, ignored — and the identifiers an operator can look up.
 *
 * @param string $message What to record.
 */
function bookify_booking_stripe_log( $message ) {
	error_log( 'Bookify Stripe: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the kit's debug.log is where this plugin logs.
}

/**
 * Whether a URL is one Stripe sent us.
 *
 * The redirect below is deliberately not wp_safe_redirect(): the whole point is to leave this site
 * for stripe.com, which that function exists to refuse. The host is checked first, so a tampered or
 * unexpected response cannot send a customer somewhere else while carrying the appearance of a
 * payment page.
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function bookify_booking_stripe_is_url( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );

	if ( ! is_string( $host ) || '' === $host ) {
		return false;
	}

	$host = strtolower( $host );

	return 'stripe.com' === $host || str_ends_with( $host, '.stripe.com' );
}

/**
 * Ask Stripe for a Checkout Session for one booking.
 *
 * The amount is read from the booking, not from the service: it was decided when the booking was
 * written, it is the same number the confirmation email carried, and a price the owner changes
 * afterwards cannot move it.
 *
 * `unit_amount` is in the currency's smallest unit. Every currency this site is likely to use has
 * two decimal places, so the amount is multiplied by 100 and rounded; a zero-decimal currency would
 * need its own handling and is not pretended to work here.
 *
 * @param int $booking_id The booking to be paid for.
 * @return array{id:string,url:string}|\WP_Error The session, or what was wrong with asking for it.
 */
function bookify_booking_stripe_create_session( $booking_id ) {
	$payments = bookify_booking_payments();

	if ( '' === $payments['secret_key'] || '' === $payments['currency'] ) {
		return new WP_Error(
			'bookify_stripe_not_configured',
			__( 'Card payments are not set up on this site.', 'bookify-booking' )
		);
	}

	$booking = get_post( absint( $booking_id ) );

	if ( ! $booking instanceof WP_Post || 'bookify_booking' !== $booking->post_type ) {
		return new WP_Error(
			'bookify_stripe_unknown_booking',
			__( 'That booking could not be found.', 'bookify-booking' )
		);
	}

	$reference = (string) get_post_meta( $booking->ID, 'bookify_reference', true );
	$amount    = (float) get_post_meta( $booking->ID, 'bookify_payment_amount', true );
	$return    = bookify_booking_manage_url( $booking->ID );

	if ( '' === $reference || '' === $return ) {
		return new WP_Error(
			'bookify_stripe_unusable_booking',
			__( 'That booking cannot be paid online.', 'bookify-booking' )
		);
	}

	if ( $amount <= 0 ) {
		return new WP_Error(
			'bookify_stripe_nothing_due',
			__( 'There is nothing to pay on that booking.', 'bookify-booking' )
		);
	}

	$service_id = absint( get_post_meta( $booking->ID, 'bookify_service_id', true ) );
	$service    = bookify_booking_bookable_service( $service_id );
	$deposit    = 'deposit' === bookify_booking_service_payment_mode( $service_id );

	/*
	 * Stage 7: a ticket names the event and the kind of ticket, and Stripe is asked for the tickets
	 * themselves rather than for a multiple of one line — three General Admission tickets at GBP 15
	 * are three units of GBP 15 on Stripe's own page, which is what the customer is buying. What the
	 * session path does is unchanged: one line, one unit, the whole amount.
	 */
	$ticket = bookify_booking_event( $booking->ID );

	// What the customer is buying, in one line. The reference is in it because the customer sees
	// this on Stripe's own page and in Stripe's receipts, where "Aromatherapy massage" alone would
	// not say which of their bookings it was.
	if ( $ticket ) {
		/* translators: 1: what was booked — an event and its ticket tier, 2: booking reference, for example BK-00042. */
		$description = sprintf(
			__( '%1$s — booking %2$s', 'bookify-booking' ),
			bookify_booking_item_label( $booking->ID ),
			$reference
		);
	} elseif ( $deposit ) {
		/* translators: 1: the service's title, 2: booking reference, for example BK-00042. */
		$description = sprintf(
			__( '%1$s — deposit for booking %2$s', 'bookify-booking' ),
			$service ? $service->post_title : __( 'Appointment', 'bookify-booking' ),
			$reference
		);
	} else {
		/* translators: 1: the service's title, 2: booking reference, for example BK-00042. */
		$description = sprintf(
			__( '%1$s — booking %2$s', 'bookify-booking' ),
			$service ? $service->post_title : __( 'Appointment', 'bookify-booking' ),
			$reference
		);
	}

	// One line, and the number of units the price is multiplied by: one for a session (whose price
	// is what the slot costs), the tickets bought for a tier.
	$units = $ticket ? max( 1, (int) get_post_meta( $booking->ID, 'bookify_party_size', true ) ) : 1;

	$body = array(
		'mode'                 => 'payment',
		// Both URLs come back to the customer's own booking page, which shows the booking's real
		// status and never assumes the payment succeeded (T25, do 4).
		'success_url'          => $return,
		'cancel_url'           => $return,
		// The reference and nothing else. No name, no email, no phone: Stripe does not need them to
		// take a payment, and the plan's constraint is that this site hands over as little as it can.
		'client_reference_id'  => $reference,
		'metadata'             => array( 'bookify_reference' => $reference ),
		// Repeated onto the PaymentIntent, which is the only way T25's `payment_intent.payment_failed`
		// and `charge.refunded` can name the booking they are about: those events carry the intent or
		// the charge, not the session.
		'payment_intent_data'  => array( 'metadata' => array( 'bookify_reference' => $reference ) ),
		'line_items'           => array(
			array(
				'quantity'   => $units,
				'price_data' => array(
					'currency'     => $payments['currency'],
					'unit_amount'  => (int) round( ( $amount / $units ) * 100 ),
					'product_data' => array( 'name' => $description ),
				),
			),
		),
	);

	$response = wp_remote_post(
		BOOKIFY_STRIPE_API,
		array(
			// Longer than the 5-second default: this call is made while a customer waits, and a
			// slow-but-successful session is better than a redirect into a failure.
			'timeout' => 20,
			'headers' => array(
				// The secret key, from the settings screen, and never written to the log or to a
				// page: it exists in this array and nowhere else.
				'Authorization' => 'Bearer ' . $payments['secret_key'],
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			// wp_remote_post() encodes a nested array as Stripe's own bracketed form fields
			// (line_items[0][price_data][currency]=…), which is the request format its API documents.
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		// The transport's own message can hold a host name but never a credential.
		bookify_booking_stripe_log( 'Could not reach the API for ' . $reference . ': ' . $response->get_error_message() );

		return new WP_Error(
			'bookify_stripe_unreachable',
			__( 'We could not reach the payment provider.', 'bookify-booking' )
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code > 299 ) {
		// Stripe's error body carries a type and a message and no credential of ours.
		$reason = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
			? $data['error']['message']
			: 'no message';

		bookify_booking_stripe_log( sprintf( 'Refused %s with HTTP %d: %s', $reference, $code, $reason ) );

		return new WP_Error(
			'bookify_stripe_refused',
			__( 'The payment provider would not start a payment for this booking.', 'bookify-booking' )
		);
	}

	$session_id = is_array( $data ) && isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
	$url        = is_array( $data ) && isset( $data['url'] ) && is_string( $data['url'] ) ? $data['url'] : '';

	if ( '' === $url || ! bookify_booking_stripe_is_url( $url ) ) {
		bookify_booking_stripe_log( 'A session for ' . $reference . ' came back without a usable URL.' );

		return new WP_Error(
			'bookify_stripe_no_url',
			__( 'The payment provider did not return a payment page.', 'bookify-booking' )
		);
	}

	return array(
		'id'  => $session_id,
		'url' => $url,
	);
}

/**
 * Handle the customer's "Pay now" button.
 *
 * The same guard the manage page's other buttons use, and for the same reason: the page that
 * offered the button proves nothing, so the nonce and the booking's own token are both checked
 * again here. Everything this does not like ends in one of three answers on the customer's own
 * page — never a redirect to Stripe for nothing, and never a blank screen.
 *
 * Nothing is written to the booking here. Whether a booking is paid is decided in T25, by Stripe,
 * so this handler cannot mark anything paid even if it wanted to.
 */
function bookify_booking_handle_stripe_start() {
	$args   = bookify_booking_manage_request_args( 'post' );
	$lookup = bookify_booking_manage_lookup( $args );
	$target = bookify_booking_manage_page_url();
	$nonce  = isset( $_POST['bookify_pay_nonce'] ) ? sanitize_key( wp_unslash( $_POST['bookify_pay_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'bookify_pay' ) || 'act' !== $lookup['state'] ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'error', $target ) );
		exit;
	}

	// A booking that is not waiting for money — already paid, cancelled, expired, or free from the
	// start — has nothing to start. Say so rather than opening a checkout for the wrong amount.
	if ( ! bookify_booking_payment_is_due( $lookup['booking']->ID ) ) {
		wp_safe_redirect( add_query_arg( 'bookify', 'pay_none', $target ) );
		exit;
	}

	$session = bookify_booking_stripe_create_session( $lookup['booking']->ID );

	if ( is_wp_error( $session ) ) {
		// A site with no key is not a failure, it is an answer: "we do not take cards here yet".
		$state = bookify_booking_stripe_enabled() ? 'pay_failed' : 'pay_off';

		wp_safe_redirect( add_query_arg( 'bookify', $state, $target ) );
		exit;
	}

	// Stripe's own URL, which wp_safe_redirect() would refuse, and which the check above has
	// already confirmed is Stripe's.
	wp_redirect( $session['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- an off-site redirect is the point of a hosted checkout.
	exit;
}

/**
 * Register the payment start handler.
 */
function bookify_booking_register_stripe() {
	add_action( 'admin_post_bookify_pay', 'bookify_booking_handle_stripe_start' );
	add_action( 'admin_post_nopriv_bookify_pay', 'bookify_booking_handle_stripe_start' );
}
