<?php
/**
 * What is paid, and when (T23).
 *
 * A service is free, asks for a deposit, or asks for the whole price. This file is everything
 * that follows from that before any payment provider is involved: the mode, the amount, the
 * status a booking starts in, the deadline, and the sweep that gives a slot back when the
 * deadline passes.
 *
 * Two rules shape it:
 *
 *   1. **The amount is decided once.** bookify_booking_amount_due() is read as the booking is
 *      written and the result is stored *on the booking*, so the message the customer is sent
 *      and the request T24 composes for Stripe cannot disagree, and a price the owner changes
 *      afterwards does not rewrite what somebody was already told.
 *   2. **A booking that is waiting for money still holds its place.** It counts towards the slot
 *      capacity until it is paid, cancelled or expired; otherwise a second customer could take
 *      the slot the first one is paying for. That is the one change T23 makes to T16's counting.
 *
 * The expiry has two mechanisms on purpose, and neither is trusted alone:
 * `wp_schedule_single_event()` asks for the sweep with an appointment of its own, and
 * bookify_booking_sweep_expired() runs on the next availability read. A cron run that never
 * happens — this container is a good example of one — therefore cannot strand a slot forever.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The option the Stripe keys, the currency and the payment window live in.
 *
 * One option rather than three: it is written by one screen and read by one function, and the
 * payment window is the same kind of business rule as the currency it is charged in.
 */
const BOOKIFY_PAYMENTS_OPTION = 'bookify_payments';

/**
 * The option as it is before an owner has saved anything.
 *
 * **The currency is a deliberate choice, and it is the only invented value in Stage 5.** Stripe
 * needs an ISO 4217 code, and the site stores no currency anywhere — the form's `currency`
 * argument is a display symbol its own caller supplies. `gbp` is the default because the
 * business details the owner fills in ask for a *postcode* and a *town or city*, which is the
 * spelling of a UK address, and the screen lets it be changed to any code. It is a default, not
 * an assumption: checkout is off until a secret key is saved too.
 *
 * expiry_minutes is T23's window: how long an unpaid booking holds its slot.
 *
 * @return array{currency:string,secret_key:string,webhook_secret:string,expiry_minutes:int}
 */
function bookify_booking_payments_defaults() {
	return array(
		'currency'       => 'gbp',
		'secret_key'     => '',
		'webhook_secret' => '',
		'expiry_minutes' => 30,
	);
}

/**
 * The stored payment settings, checked on the way out.
 *
 * A value can be in the option without ever having gone through the sanitiser — `wp option
 * update` and an older version of this plugin are both ways in — so every value is re-validated
 * here rather than being handed to Stripe or to the email.
 *
 * @return array{currency:string,secret_key:string,webhook_secret:string,expiry_minutes:int}
 */
function bookify_booking_payments() {
	$stored = get_option( BOOKIFY_PAYMENTS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$clean  = bookify_booking_payments_defaults();

	$clean['currency'] = isset( $stored['currency'] ) ? bookify_booking_sanitize_currency( $stored['currency'] ) : $clean['currency'];

	foreach ( array( 'secret_key', 'webhook_secret' ) as $key ) {
		$clean[ $key ] = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? trim( $stored[ $key ] ) : '';
	}

	if ( isset( $stored['expiry_minutes'] ) && is_numeric( $stored['expiry_minutes'] ) ) {
		$clean['expiry_minutes'] = min( 1440, max( 5, (int) $stored['expiry_minutes'] ) );
	}

	return $clean;
}

/**
 * A currency as Stripe wants it: a lowercase ISO 4217 code, or nothing.
 *
 * A userland function, so it can be a sanitize callback — WordPress hands one four arguments and
 * an internal PHP function refuses the extras (T2's trap). Anything that is not three letters is
 * dropped rather than guessed at, and an empty currency switches checkout off (see
 * bookify_booking_stripe_enabled()).
 *
 * @param mixed $value Raw value.
 * @return string
 */
function bookify_booking_sanitize_currency( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$code = strtolower( trim( $value ) );

	return preg_match( '/^[a-z]{3}$/', $code ) ? $code : '';
}

/**
 * The ways a service can be paid for, as value => label.
 *
 * One list: the service meta box renders it, the saver accepts only what is in it, and the
 * amounts below read it. A key that is not in here is read as 'none'.
 *
 * @return array<string,string>
 */
function bookify_booking_payment_modes() {
	return array(
		'none'    => __( 'Nothing — the booking is confirmed as it is', 'bookify-booking' ),
		'deposit' => __( 'A deposit', 'bookify-booking' ),
		'full'    => __( 'The full price', 'bookify-booking' ),
	);
}

/**
 * How a deposit is expressed, as value => label.
 *
 * @return array<string,string>
 */
function bookify_booking_deposit_types() {
	return array(
		'amount'  => __( 'An amount', 'bookify-booking' ),
		'percent' => __( 'A percentage of the price', 'bookify-booking' ),
	);
}

/**
 * Sanitise a payment mode.
 *
 * @param mixed $value Raw value.
 * @return string One of bookify_booking_payment_modes(), or 'none'.
 */
function bookify_booking_sanitize_payment_mode( $value ) {
	$mode = is_string( $value ) ? sanitize_key( $value ) : '';

	return isset( bookify_booking_payment_modes()[ $mode ] ) ? $mode : 'none';
}

/**
 * Sanitise a deposit type.
 *
 * @param mixed $value Raw value.
 * @return string One of bookify_booking_deposit_types(), or 'amount'.
 */
function bookify_booking_sanitize_deposit_type( $value ) {
	$type = is_string( $value ) ? sanitize_key( $value ) : '';

	return isset( bookify_booking_deposit_types()[ $type ] ) ? $type : 'amount';
}

/**
 * The payment mode of a service.
 *
 * @param int $service_id The service.
 * @return string 'none', 'deposit' or 'full'.
 */
function bookify_booking_service_payment_mode( $service_id ) {
	return bookify_booking_sanitize_payment_mode( get_post_meta( absint( $service_id ), 'bookify_payment_mode', true ) );
}

/**
 * How a service's deposit is expressed.
 *
 * @param int $service_id The service.
 * @return string 'amount' or 'percent'.
 */
function bookify_booking_service_deposit_type( $service_id ) {
	return bookify_booking_sanitize_deposit_type( get_post_meta( absint( $service_id ), 'bookify_deposit_type', true ) );
}

/**
 * What a service's deposit is worth, as stored: an amount or a percentage.
 *
 * @param int $service_id The service.
 * @return float
 */
function bookify_booking_service_deposit_value( $service_id ) {
	return max( 0.0, (float) get_post_meta( absint( $service_id ), 'bookify_deposit_value', true ) );
}

/**
 * The amount a booking of this service has to pay, in the site currency.
 *
 * Read once, when the booking is written, and stored with the booking. The price is the
 * service's, not the price per guest: a service's price is what one slot costs, which is what
 * capacity and the listing have meant since T9 and T10 (the couples massage is priced for two).
 *
 * A deposit can never come out above the price, and a mode of 'deposit' on a service with no
 * price written yet is a free booking rather than a request to pay nothing through Stripe.
 *
 * @param int $service_id The service.
 * @return float Zero when this booking needs no payment at all.
 */
function bookify_booking_amount_due( $service_id ) {
	$mode = bookify_booking_service_payment_mode( $service_id );

	if ( 'none' === $mode ) {
		return 0.0;
	}

	$price = max( 0.0, (float) get_post_meta( absint( $service_id ), 'bookify_price', true ) );

	if ( 'full' === $mode ) {
		return round( $price, 2 );
	}

	$value = bookify_booking_service_deposit_value( $service_id );

	$amount = 'percent' === bookify_booking_service_deposit_type( $service_id )
		? $price * min( 100.0, $value ) / 100
		: $value;

	return round( min( $amount, $price ), 2 );
}

/**
 * The amount a booking of tickets has to pay, in the site currency (Stage 7, plan D24).
 *
 * A tier's price is per ticket, and that is the one way a ticket prices differently from a session:
 * a session's price is what one slot costs whatever the party size (T23, which is why the partners'
 * session is priced for two), so a party of three there pays what a party of one pays. Three
 * General Admission tickets at GBP 15 owe GBP 45.
 *
 * Rounded once, here, and then stored on the booking: the confirmation's sentence, the operator's
 * screen and the request T24 composes for Stripe are one number that cannot drift, and a price the
 * owner changes tomorrow does not rewrite what the customer was already told.
 *
 * A ticket is paid in full rather than by deposit: a deposit exists so a business can hold an
 * appointment somebody might not turn up to, and a ticket is bought rather than held (D24).
 *
 * @param int $tier_id  The tier.
 * @param int $quantity How many tickets.
 * @return float
 */
function bookify_booking_ticket_amount( $tier_id, $quantity ) {
	return round( bookify_tier_price( $tier_id ) * max( 1, absint( $quantity ) ), 2 );
}

/**
 * The site's currency as an ISO code, upper case for printing, or '' when none is set.
 *
 * @return string
 */
function bookify_booking_currency() {
	$currency = bookify_booking_payments()['currency'];

	return '' === $currency ? '' : strtoupper( $currency );
}

/**
 * An amount as a visitor reads it: the code, then the number.
 *
 * The code rather than a symbol: the site stores an ISO code and no symbol table, and inventing
 * one would be guessing. This is the same string the confirmation email carries, so an emailed
 * amount and a screen amount are one string.
 *
 * @param float $amount Amount in the site currency.
 * @return string
 */
function bookify_booking_amount_label( $amount ) {
	$formatted = number_format_i18n( (float) $amount, 2 );
	$currency  = bookify_booking_currency();

	return '' === $currency ? $formatted : $currency . ' ' . $formatted;
}

/**
 * How long an unpaid booking holds its place, in minutes.
 *
 * @return int
 */
function bookify_booking_payment_window_minutes() {
	return (int) bookify_booking_payments()['expiry_minutes'];
}

/**
 * The statuses that occupy a place in a slot.
 *
 * The one definition of "this booking is using a place", read by the capacity count in
 * includes/availability.php and by the manage page. `awaiting_payment` is in it because the
 * customer is in the middle of paying for that slot; `expired` and `cancelled` are out, which is
 * what gives the place back.
 *
 * @return array<int,string>
 */
function bookify_booking_slot_holding_statuses() {
	return array( 'awaiting_payment', 'pending', 'confirmed' );
}

/**
 * The payment states a booking can be in, as value => label.
 *
 * Deliberately not the same list as the booking's own statuses: a refunded payment on an
 * appointment that is still going ahead is a refunded payment, not a cancelled booking, so the
 * two facts are stored separately and the operator's screen shows both.
 *
 * @return array<string,string>
 */
function bookify_booking_payment_statuses() {
	return array(
		'unpaid'   => __( 'Unpaid', 'bookify-booking' ),
		'paid'     => __( 'Paid', 'bookify-booking' ),
		'failed'   => __( 'The last payment attempt failed', 'bookify-booking' ),
		'refunded' => __( 'Refunded', 'bookify-booking' ),
	);
}

/**
 * A booking's payment state.
 *
 * @param int $booking_id The booking.
 * @return string One of bookify_booking_payment_statuses(), or '' when no payment is involved.
 */
function bookify_booking_payment_status( $booking_id ) {
	$status = sanitize_key( (string) get_post_meta( absint( $booking_id ), 'bookify_payment_status', true ) );

	return isset( bookify_booking_payment_statuses()[ $status ] ) ? $status : '';
}

/**
 * Whether this booking is waiting for money.
 *
 * @param int $booking_id The booking.
 * @return bool
 */
function bookify_booking_payment_is_due( $booking_id ) {
	return 'awaiting_payment' === (string) get_post_meta( absint( $booking_id ), 'bookify_status', true );
}

/**
 * When this booking's payment window closes, as a Unix timestamp.
 *
 * A timestamp, like T20's link expiry and for the same reason: it is compared with time() and
 * has no timezone to get wrong. Printed where a person reads it through wp_date().
 *
 * @param int $booking_id The booking.
 * @return int Zero when this booking has no deadline.
 */
function bookify_booking_payment_due_at( $booking_id ) {
	return absint( get_post_meta( absint( $booking_id ), 'bookify_payment_due', true ) );
}

/**
 * The payment line an operator or a customer reads, or '' when there is nothing to say.
 *
 * @param int $booking_id The booking.
 * @return string
 */
function bookify_booking_payment_label( $booking_id ) {
	$status = bookify_booking_payment_status( $booking_id );

	if ( '' === $status ) {
		return '';
	}

	$label = bookify_booking_payment_statuses()[ $status ];

	if ( 'awaiting_payment' === (string) get_post_meta( absint( $booking_id ), 'bookify_status', true ) ) {
		$label = sprintf(
			/* translators: %s: a payment state, for example "Unpaid". */
			__( '%s — waiting for payment', 'bookify-booking' ),
			$label
		);
	}

	$amount = (float) get_post_meta( absint( $booking_id ), 'bookify_payment_amount', true );

	if ( $amount > 0 && in_array( $status, array( 'unpaid', 'paid', 'failed' ), true ) ) {
		$label .= ' — ' . bookify_booking_amount_label( $amount );
	}

	return $label;
}

/**
 * Ask for this booking to be swept when its window closes.
 *
 * An appointment of its own, not a poll: one event, at the moment the deadline passes. If cron
 * never runs, bookify_booking_sweep_expired() is the belt to this pair of braces.
 *
 * @param int $booking_id The booking.
 */
function bookify_booking_schedule_expiry( $booking_id ) {
	$booking_id = absint( $booking_id );
	$due        = bookify_booking_payment_due_at( $booking_id );

	if ( ! $due ) {
		return;
	}

	$args = array( $booking_id );

	if ( ! wp_next_scheduled( 'bookify_booking_expire', $args ) ) {
		// One second after the deadline, so the sweep that runs on time finds it due.
		wp_schedule_single_event( $due + 1, 'bookify_booking_expire', $args );
	}
}

/**
 * Forget this booking's expiry appointment.
 *
 * The pair to bookify_booking_schedule_expiry(), called from every path that takes a booking out of
 * "waiting for money" — expired, cancelled, or paid. An event left behind would be harmless, because
 * the sweep checks the status before it touches anything, but an unbounded list of them is a list
 * nobody will ever read, and releasing on every exit path is the same discipline T16's advisory lock
 * is held to.
 *
 * @param int $booking_id The booking.
 */
function bookify_booking_unschedule_expiry( $booking_id ) {
	wp_clear_scheduled_hook( 'bookify_booking_expire', array( absint( $booking_id ) ) );
}

/**
 * Forget a deleted booking's expiry appointment.
 *
 * Hooked to `deleted_post`, so however a booking goes — the edit screen, WP-CLI, a cleanup job that
 * does not know about this plugin — its appointment cannot outlive it. The `$post` argument is only
 * there so the hook costs every other post type nothing.
 *
 * @param int           $post_id The post being deleted.
 * @param \WP_Post|null $post    The post, when WordPress passes it along.
 */
function bookify_booking_forget_deleted_expiry( $post_id, $post = null ) {
	if ( $post instanceof WP_Post && 'bookify_booking' !== $post->post_type ) {
		return;
	}

	bookify_booking_unschedule_expiry( $post_id );
}

/**
 * Expire one unpaid booking, giving its place back.
 *
 * Only a booking that is still waiting for money and whose window has closed is touched, so this
 * is safe to call twice — which is what makes the cron event and the sweep able to race without
 * doing anything different from either one alone.
 *
 * @param int $booking_id The booking.
 * @return bool Whether this call was the one that expired it.
 */
function bookify_booking_expire_unpaid( $booking_id ) {
	$booking_id = absint( $booking_id );

	if ( ! bookify_booking_payment_is_due( $booking_id ) ) {
		return false;
	}

	$due = bookify_booking_payment_due_at( $booking_id );

	// No deadline recorded: this is not a booking the site put a window on, so it is left alone
	// rather than expired by surprise (an operator can set the status to awaiting_payment by hand).
	if ( ! $due || $due > time() ) {
		return false;
	}

	// The place is given back by the status alone: bookify_booking_slot_holding_statuses() no
	// longer names this booking, so the next capacity read does not count it.
	update_post_meta( $booking_id, 'bookify_status', 'expired' );

	bookify_booking_unschedule_expiry( $booking_id );

	// Nobody is coming to an unpaid booking, so its reminder goes with its deadline (T30).
	bookify_booking_unschedule_reminder( $booking_id );

	return true;
}

/**
 * Expire every unpaid booking whose window has closed.
 *
 * Runs on the next availability read, which is the second half of T23's belt and braces: an
 * availability question is exactly the moment a stale booking starts to matter, so answering it
 * is the right time to clean up. Bounded to fifty bookings per call — a sweep is a tidy-up, not a
 * migration — and it runs once per request however many slots are asked about.
 *
 * @return int How many bookings this call expired.
 */
function bookify_booking_sweep_expired() {
	static $swept = false;

	if ( $swept ) {
		return 0;
	}

	$swept = true;

	$ids = get_posts(
		array(
			'post_type'      => 'bookify_booking',
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => 'bookify_status',
					'value' => 'awaiting_payment',
				),
				array(
					'key'     => 'bookify_payment_due',
					'value'   => time(),
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);

	$expired = 0;

	foreach ( $ids as $booking_id ) {
		if ( bookify_booking_expire_unpaid( $booking_id ) ) {
			$expired++;
		}
	}

	return $expired;
}

/**
 * Register the expiry appointment.
 *
 * The sweep needs no hook: it is called from the capacity count, which is the read it exists to
 * keep honest.
 */
function bookify_booking_register_payments() {
	add_action( 'bookify_booking_expire', 'bookify_booking_expire_unpaid' );
	add_action( 'deleted_post', 'bookify_booking_forget_deleted_expiry', 10, 2 );
}
