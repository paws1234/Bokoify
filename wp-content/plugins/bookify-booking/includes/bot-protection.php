<?php
/**
 * Making the one public write endpoint expensive to abuse (T19, plan D13).
 *
 * Two checks, in the order the handler runs them: a small budget per address and per email, and
 * Cloudflare Turnstile — off unless keys are configured. Neither is allowed to be the reason a
 * real booking fails, and neither ever says which check stopped a submission: every refusal here
 * comes back through the same ?bookify=rejected answer the honeypot uses, so a bot learns nothing
 * about the form by probing it.
 *
 * The rule this file is built on: **an outage never blocks a booking**. A verifier that cannot be
 * reached, answers with an HTTP error, or reports its own `internal-error` is logged and the
 * submission is let through to the checks that do not depend on anyone else's uptime. Only an
 * explicit "no" from a verifier that answered is treated as a refusal.
 *
 * This file reads the connection ($_SERVER) but never the submitted form: every value it needs is
 * handed to it by bookify_booking_handle_submission(), which stays the one place a request body
 * is read.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The option the Turnstile keys are stored in.
 */
const BOOKIFY_BOTS_OPTION = 'bookify_bots';

/**
 * The siteverify endpoint, from Cloudflare's own documentation.
 */
const BOOKIFY_BOTS_SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

/**
 * The keys the site has, if any.
 *
 * Nothing is stored until an owner enters keys, which is what "off by default" means in practice:
 * an option that was never written is the same as one holding two empty strings.
 *
 * @return array{site_key:string,secret_key:string}
 */
function bookify_bots_defaults() {
	return array(
		'site_key'   => '',
		'secret_key' => '',
	);
}

/**
 * The stored keys, checked on the way out.
 *
 * A value can be in the option without ever having gone through the sanitiser — `wp option update`
 * and an older version of this plugin are both ways in — so anything that is not a non-empty
 * string is read as "not configured" rather than being handed to a request.
 *
 * @return array{site_key:string,secret_key:string}
 */
function bookify_bots() {
	$stored = get_option( BOOKIFY_BOTS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	$keys   = bookify_bots_defaults();

	foreach ( $keys as $key => $empty ) {
		$keys[ $key ] = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? trim( $stored[ $key ] ) : $empty;
	}

	return $keys;
}

/**
 * Whether Turnstile is configured.
 *
 * Both halves are needed: a widget with no secret cannot be verified, and a secret with no widget
 * would refuse every visitor for a token that was never issued.
 *
 * @return bool
 */
function bookify_bots_turnstile_enabled() {
	$keys = bookify_bots();

	return '' !== $keys['site_key'] && '' !== $keys['secret_key'];
}

/**
 * The budgets, in one place.
 *
 * Both windows are ten minutes and both are deliberately small for what they measure.
 *
 * The **address** budget counts every submission that gets past the nonce and the honeypot,
 * including one that is then refused for a typo. That is what makes a customer's own retries safe:
 * ten attempts from one address in ten minutes is far more than correcting a field needs, and far
 * less than a flood, so the limiter can be checked on the way in without ever being the thing that
 * stops someone who is simply fixing their email address. A booking form is not a login page, and
 * a customer must never be locked out by their own mistakes.
 *
 * The **email** budget counts bookings that were actually created. Nothing else can be counted
 * there without risking the same lockout, and five real bookings in ten minutes is not a pattern a
 * customer has — it is a pattern a script has.
 *
 * @return array{window:int,ip:int,email:int}
 */
function bookify_bots_limits() {
	return array(
		'window' => 10 * MINUTE_IN_SECONDS,
		'ip'     => 10,
		'email'  => 5,
	);
}

/**
 * The address the request came from.
 *
 * REMOTE_ADDR first, and that order is the whole design: it is the connection itself and cannot
 * be claimed by whoever is making the request, so on a site serving visitors directly the budget
 * cannot be escaped by lying. A proxy header is only as trustworthy as the proxy in front of the
 * site — a client can send X-Forwarded-For itself — so those headers are read only when the
 * server reported no address at all rather than being preferred because they look more specific.
 *
 * That leaves one case worth naming: behind a proxy that does not overwrite the header, every
 * visitor shares the proxy's address and so shares one budget, which is a lockout an attacker
 * could bring on deliberately. `bookify_bots_client_ip` is filterable for exactly that: a site
 * behind a proxy it controls can say so, once, in one place, and the limiter becomes per-visitor
 * again. The default stays the unspoofable one.
 *
 * @return string An IP address, or '' when the server did not say.
 */
function bookify_bots_client_ip() {
	$candidates = array();

	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	// Cloudflare sets this when the site is behind it; the first hop of X-Forwarded-For is the
	// client a proxy saw. Both are only read if nothing above answered.
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
	}

	if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$forwarded    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$candidates[] = trim( explode( ',', $forwarded )[0] );
	}

	$address = '';

	foreach ( $candidates as $candidate ) {
		if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			$address = $candidate;

			break;
		}
	}

	/**
	 * Filters the address a submission is counted against.
	 *
	 * @param string $address The address the server reported, or '' when it reported none.
	 */
	return (string) apply_filters( 'bookify_bots_client_ip', $address );
}

/**
 * The transient name for one counter.
 *
 * Hashed, so a customer's address or email is not spelled out in the options table — the counter
 * itself is all this needs, and data the site does not have to keep is data it does not keep.
 *
 * @param string $scope What is being counted: 'ip' or 'email'.
 * @param string $value The address or email.
 * @return string
 */
function bookify_bots_counter_key( $scope, $value ) {
	return 'bookify_bots_' . $scope . '_' . md5( $scope . '|' . strtolower( trim( (string) $value ) ) );
}

/**
 * How much of one budget has been used.
 *
 * @param string $key The counter's transient name.
 * @return int
 */
function bookify_bots_count( $key ) {
	return absint( get_transient( $key ) );
}

/**
 * Count one more use of a budget.
 *
 * The window slides: each use restarts the ten minutes, so a steady trickle stays refused rather
 * than being admitted again once a fixed window rolls over. Read-then-write, not atomic — an
 * approximate count is the right cost for a speed bump, and the thing that must be atomic is the
 * slot itself, which bookify_create_booking() already locks (D16).
 *
 * @param string $key    The counter's transient name.
 * @param int    $window The window in seconds.
 */
function bookify_bots_count_up( $key, $window ) {
	set_transient( $key, bookify_bots_count( $key ) + 1, $window );
}

/**
 * A refusal, named for the log and never shown to the visitor.
 *
 * The handler answers any error from here with ?bookify=rejected, which is the same answer a
 * filled honeypot gets: the visitor is told the submission was not accepted, and a bot cannot tell
 * which check turned it away.
 *
 * @param string $code Which check refused.
 * @return \WP_Error
 */
function bookify_bots_refusal( $code ) {
	return new WP_Error( $code, __( 'That submission could not be accepted.', 'bookify-booking' ) );
}

/**
 * Write one line to the server's log.
 *
 * error_log() rather than a WP_DEBUG-only helper on purpose: this is the diagnostic for a refusal
 * or an outage that production needs to be able to see. Where the line lands is the server's
 * business rather than this plugin's — with WP_DEBUG_LOG on (as this development kit has it)
 * WordPress points error_log at wp-content/debug.log; otherwise it is the PHP or web server's own
 * error log. Measured 2026-09-12: with WP_DEBUG_LOG on, refusals appear in
 * wp-content/debug.log as "Bookify: the rate limiter refused a submission: …" and nothing appears
 * in the Apache log, which is worth knowing before going looking in the wrong file.
 *
 * Nothing personal is written — no address, no email, no token — so a log with this in it is not a
 * second copy of the customer table.
 *
 * @param string $message What happened.
 */
function bookify_bots_log( $message ) {
	error_log( 'Bookify: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Ask Turnstile about one token.
 *
 * wp_remote_post() is itself filterable — `pre_http_request` short-circuits it — which is how the
 * acceptance for this task proves both answers and the outage case with no account: the composed
 * request is real, the answer is the vendor's own fixed test keys, and a refused request can be
 * stubbed.
 *
 * @param string $token The token the widget put in the form.
 * @return true|\WP_Error True when the submission may continue.
 */
function bookify_bots_verify_turnstile( $token ) {
	if ( ! bookify_bots_turnstile_enabled() ) {
		return true;
	}

	$token = is_string( $token ) ? trim( $token ) : '';

	// A configured widget with no token means the widget never ran — JavaScript off, or blocked.
	// Turnstile cannot be completed without JavaScript, so there is no honest way to admit this.
	// The length is checked with it: the vendor's own documentation caps a token at 2048
	// characters, so anything longer is not one, and forwarding it would be doing a stranger's
	// work for them.
	if ( '' === $token || strlen( $token ) > 2048 ) {
		bookify_bots_log( 'Turnstile is configured but the submission carried no usable token.' );

		return bookify_bots_refusal( 'bookify_bots_turnstile_missing' );
	}

	$keys = bookify_bots();
	$body = array(
		'secret'   => $keys['secret_key'],
		'response' => $token,
	);

	$ip = bookify_bots_client_ip();

	if ( '' !== $ip ) {
		$body['remoteip'] = $ip;
	}

	$response = wp_remote_post(
		BOOKIFY_BOTS_SITEVERIFY,
		array(
			'timeout' => 8,
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		bookify_bots_log( 'Turnstile could not be reached, so the submission was let through: ' . $response->get_error_message() );

		return true;
	}

	$answer = json_decode( wp_remote_retrieve_body( $response ), true );
	$status = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $status || ! is_array( $answer ) || ! isset( $answer['success'] ) ) {
		bookify_bots_log( sprintf( 'Turnstile answered HTTP %d with no answer to read, so the submission was let through.', $status ) );

		return true;
	}

	if ( ! empty( $answer['success'] ) ) {
		return true;
	}

	$codes = isset( $answer['error-codes'] ) && is_array( $answer['error-codes'] ) ? $answer['error-codes'] : array();
	$codes = array_map( 'sanitize_key', $codes );

	// The vendor's own trouble is not the visitor's: an internal error is an outage on their side,
	// so it is treated exactly like not being able to reach them at all.
	if ( in_array( 'internal-error', $codes, true ) ) {
		bookify_bots_log( 'Turnstile reported an internal error, so the submission was let through.' );

		return true;
	}

	bookify_bots_log( 'Turnstile refused a submission: ' . ( $codes ? implode( ', ', $codes ) : 'no error code' ) );

	return bookify_bots_refusal( 'bookify_bots_turnstile_failed' );
}

/**
 * Count one more booking against an email address.
 *
 * Called after a booking exists, never before: the budget is spent by bookings, so a submission
 * that was refused for any reason has spent nothing.
 *
 * @param string $email The customer's email address.
 */
function bookify_bots_record_booking( $email ) {
	$email = sanitize_email( (string) $email );

	if ( '' === $email ) {
		return;
	}

	$limits = bookify_bots_limits();

	bookify_bots_count_up( bookify_bots_counter_key( 'email', $email ), $limits['window'] );
}

/**
 * Run the two checks that stand between a submitted form and the diary.
 *
 * The order is the order of the costs: the counters are local and free, the verification is a
 * network call, so the budget is spent before anyone else is asked anything.
 *
 * @param string $email           The email the form carried, for the per-email budget.
 * @param string $turnstile_token The token the widget put in the form, if any.
 * @return true|\WP_Error True when the submission may go on to the write path.
 */
function bookify_bots_guard( $email, $turnstile_token ) {
	$limits = bookify_bots_limits();

	$ip = bookify_bots_client_ip();

	if ( '' !== $ip ) {
		$key = bookify_bots_counter_key( 'ip', $ip );

		if ( bookify_bots_count( $key ) >= $limits['ip'] ) {
			bookify_bots_log( 'the rate limiter refused a submission: that address has used its budget.' );

			return bookify_bots_refusal( 'bookify_bots_rate_ip' );
		}
	}

	$email = sanitize_email( (string) $email );

	if ( '' !== $email ) {
		$key = bookify_bots_counter_key( 'email', $email );

		if ( bookify_bots_count( $key ) >= $limits['email'] ) {
			bookify_bots_log( 'the rate limiter refused a submission: that email address has booked its budget.' );

			return bookify_bots_refusal( 'bookify_bots_rate_email' );
		}
	}

	$verified = bookify_bots_verify_turnstile( $turnstile_token );

	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	// Admitted, so the address pays: this is the submission that will reach the write path. A
	// submission refused above has spent nothing, which is what keeps an outage from also being a
	// lockout.
	if ( '' !== $ip ) {
		bookify_bots_count_up( bookify_bots_counter_key( 'ip', $ip ), $limits['window'] );
	}

	return true;
}

/**
 * The Turnstile widget, when it is configured.
 *
 * Printed inside the booking form — the one that writes — and not the picker above it, which only
 * asks the diary a question. Nothing is rendered and nothing is enqueued unless both keys are
 * present, so a site that has never heard of Turnstile loads exactly the page it loaded before.
 *
 * @return string
 */
function bookify_bots_turnstile_widget() {
	$keys = bookify_bots();

	if ( ! bookify_bots_turnstile_enabled() ) {
		return '';
	}

	wp_enqueue_script(
		'bookify-turnstile',
		'https://challenges.cloudflare.com/turnstile/v0/api.js',
		array(),
		BOOKIFY_BOOKING_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	return sprintf(
		'<div class="cf-turnstile bookify-booking__turnstile" data-sitekey="%s"></div>',
		esc_attr( $keys['site_key'] )
	);
}
