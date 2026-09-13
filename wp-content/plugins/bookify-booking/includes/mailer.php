<?php
/**
 * How a message actually leaves the site.
 *
 * Two reasons this file exists rather than a plain `wp_mail()` call.
 *
 * The container this project runs in has **no mailer at all** — `sh: /usr/sbin/sendmail: not found`
 * appears once per booking in the existing probes' output — so nothing sent the WordPress way can be
 * delivered here, and "the confirmation is on its way" was not true on any local run. On a real host
 * the usual problem is the opposite and just as bad: PHP's own mail from a shared box gets filed as
 * spam exactly when it matters.
 *
 * So a message goes to **Resend's HTTP API** when a key and a from-address are configured, and to
 * `wp_mail()` when they are not. That fallback is deliberate and load-bearing:
 *
 * - a site that has not set up Resend behaves exactly as it did before this file existed;
 * - every probe that captures `pre_wp_mail` keeps working, because `wp_mail()` is still the call
 *   being made when there is no key;
 * - if Resend answers with an error, the message still goes the old way rather than disappearing.
 *
 * The key is configuration, not a settings field: a secret that is typed into a browser is a secret
 * that ends up in a screenshot, a support ticket and an export of the options table. On this project
 * it is read from the environment, which `.env` fills through `docker-compose.yml` — and `.env` is
 * gitignored, so it stays on the machine. A host with no `.env` reads the `BOOKIFY_RESEND_API_KEY`
 * constant, which `wp config set` writes, or the `bookify_booking_resend_api_key` filter, which is
 * how the probe tests this file with no key existing anywhere. Nothing on the settings screen
 * accepts it.
 *
 * The last send's outcome is recorded in an option so a failure is visible on that screen instead of
 * being swallowed — which is what `wp_mail()`'s ignored result used to do. That is a record, not
 * input: only this plugin writes it.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Resend endpoint and the shape of what it is sent.
 *
 * @return array{endpoint:string,timeout:int}
 */
function bookify_booking_resend_defaults() {
	return array(
		'endpoint' => 'https://api.resend.com/emails',
		'timeout'  => 15,
	);
}

/**
 * The first value that is actually set, of a constant, an environment variable, and a filter.
 *
 * @param string   $constant Name of the constant to look for.
 * @param string[] $env      Environment variable names, in order.
 * @param string   $filter   Filter name, applied to '' as its initial value.
 * @return string
 */
function bookify_booking_config_value( $constant, array $env, $filter ) {
	if ( defined( $constant ) ) {
		$value = constant( $constant );

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}

	foreach ( $env as $name ) {
		$value = getenv( $name );

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}

	/**
	 * Supply the value from code, for a host that has neither a constant nor an environment.
	 *
	 * @param string $value The value found so far, which is '' by the time this runs.
	 */
	return (string) apply_filters( $filter, '' );
}

/**
 * The mail configuration, read from configuration every time rather than cached.
 *
 * A constant cannot change while a request is running, but a filter can, and the probe relies on
 * that: it sets one, sends, and takes it away again.
 *
 * @return array{api_key:string,from_email:string,from_name:string}
 */
function bookify_booking_mail() {
	$from = bookify_booking_config_value(
		'BOOKIFY_MAIL_FROM',
		array( 'BOOKIFY_MAIL_FROM', 'RESEND_FROM' ),
		'bookify_booking_mail_from_address'
	);

	return array(
		'api_key'    => bookify_booking_config_value(
			'BOOKIFY_RESEND_API_KEY',
			array( 'BOOKIFY_RESEND_API_KEY', 'RESEND_API_KEY' ),
			'bookify_booking_resend_api_key'
		),
		// An address that will not validate is treated as absent: it would look configured and then
		// fail every send, which is the state this is meant to get an owner out of.
		'from_email' => is_email( $from ) ? $from : '',
		'from_name'  => bookify_booking_config_value(
			'BOOKIFY_MAIL_FROM_NAME',
			array( 'BOOKIFY_MAIL_FROM_NAME' ),
			'bookify_booking_mail_from_name'
		),
	);
}

/**
 * Whether the site is set up to send through Resend.
 *
 * Both halves are required. A key on its own is not enough: Resend refuses a from-address on a domain
 * the owner has not verified, and a "configured" site that fails every send would be worse than one
 * that plainly still uses WordPress. Requiring the address makes the settings screen ask for the
 * thing that decides whether any of this works.
 *
 * @return bool
 */
function bookify_booking_mail_is_configured() {
	$mail = bookify_booking_mail();

	return '' !== $mail['api_key'] && '' !== $mail['from_email'];
}

/**
 * An address that stands in for the customer's while the site is being tested.
 *
 * Resend refuses every message to anyone but the account owner until a domain is verified, so on a
 * site with no domain the owner sees none of the bookings the form takes — each one is composed,
 * addressed to the customer and then rejected. Pointing this at an inbox they can read puts every
 * message in one place and makes the whole path observable, including the ones addressed to somebody
 * else entirely.
 *
 * Empty means off, which is what a live site wants: the message goes to the customer, as it always
 * did. The two are not silently interchangeable, so the subject is prefixed with the address the
 * booking came from — `[from sam@example.com] Booking BK-00042 received` — because the body greets
 * the customer by name and an inbox holding every booking's mail is otherwise impossible to
 * attribute to one. What the message leaves out, though, is the address it was addressed to, so the
 * composers put the client's own address inside the body when this is on.
 *
 * @return string An address, or '' when nothing is being diverted.
 */
function bookify_booking_mail_redirect_to() {
	$address = bookify_booking_config_value(
		'BOOKIFY_MAIL_REDIRECT_TO',
		array( 'BOOKIFY_MAIL_REDIRECT_TO' ),
		'bookify_booking_mail_redirect_to'
	);

	// The same rule as the from-address: one that will not validate is treated as absent, rather
	// than looking configured and then failing every send.
	return is_email( $address ) ? $address : '';
}

/**
 * The sender line, as an email header or as Resend wants it: `Name <address>`.
 *
 * One place decides it for both transports: the configured address when there is one, and
 * WordPress's own default — the site title and the admin address — when there is not. The settings
 * screen prints this same line, so what it shows is what a customer will receive.
 *
 * @return string
 */
function bookify_booking_mail_from() {
	$mail = bookify_booking_mail();
	$name = '' !== $mail['from_name'] ? $mail['from_name'] : sanitize_text_field( (string) get_option( 'blogname' ) );

	$address = '' !== $mail['from_email'] ? $mail['from_email'] : (string) get_option( 'admin_email' );

	return sprintf( '%s <%s>', $name, $address );
}

/**
 * Split the header lines WordPress was given into the pairs Resend takes.
 *
 * `wp_mail()` accepts headers as an array or as a newline-separated string, and this plugin passes
 * lines like `From: X <y@z>`. Only the two Resend can express are read; anything else — a
 * `Content-Type`, a `Cc` — is left for the WordPress path rather than guessed at.
 *
 * @param array|string $headers Headers as given to wp_mail().
 * @return array{from:string,reply_to:string}
 */
function bookify_booking_mail_headers( $headers ) {
	$lines = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
	$parts = array(
		'from'     => '',
		'reply_to' => '',
	);

	foreach ( (array) $lines as $line ) {
		if ( ! is_string( $line ) || false === strpos( $line, ':' ) ) {
			continue;
		}

		list( $name, $value ) = explode( ':', $line, 2 );

		$key = strtolower( trim( $name ) );

		if ( isset( $parts[ $key ] ) ) {
			$parts[ $key ] = trim( $value );
		}
	}

	// The configured address wins over the header, because the configured one is the address Resend
	// will accept: WordPress's default From is the site's admin address, which is usually on a domain
	// nobody has verified.
	$parts['from'] = bookify_booking_mail_from();

	return $parts;
}

/**
 * Record how the last send went, so a failure is visible rather than silent.
 *
 * Not autoloaded: nothing on the front end reads it, and an option that lives in memory on every
 * request to say "the last email worked" is a cost with no reader.
 *
 * @param bool   $ok      Whether it was accepted.
 * @param string $message What to show the owner.
 */
function bookify_booking_mail_record( $ok, $message ) {
	update_option(
		'bookify_mail_last_result',
		array(
			'time'    => time(),
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		),
		false
	);
}

/**
 * The last send's outcome, if any.
 *
 * @return array{time:int,ok:bool,message:string}|null
 */
function bookify_booking_mail_last_result() {
	$stored = get_option( 'bookify_mail_last_result', null );

	if ( ! is_array( $stored ) || ! isset( $stored['time'] ) ) {
		return null;
	}

	return array(
		'time'    => (int) $stored['time'],
		'ok'      => ! empty( $stored['ok'] ),
		'message' => isset( $stored['message'] ) ? (string) $stored['message'] : '',
	);
}

/**
 * Hand one message to Resend.
 *
 * The response body is read even when the status is good-looking, because Resend answers a rejected
 * recipient with JSON carrying the reason and that reason is the only thing that tells an owner what
 * to fix.
 *
 * @param array{to:string,subject:string,text:string,html:string,headers:array|string} $mail The message.
 * @return true|\WP_Error
 */
function bookify_booking_resend_send( array $mail ) {
	$settings = bookify_booking_resend_defaults();
	$headers  = bookify_booking_mail_headers( isset( $mail['headers'] ) ? $mail['headers'] : array() );

	$body = array(
		'from'    => $headers['from'],
		'to'      => array( (string) $mail['to'] ),
		'subject' => (string) $mail['subject'],
	);

	if ( '' !== $headers['reply_to'] ) {
		$body['reply_to'] = $headers['reply_to'];
	}

	// Both parts, always. A message with an HTML body and no text part is the shape spam filters
	// distrust most, and some clients are still set to show text.
	$body['text'] = (string) $mail['text'];

	if ( '' !== (string) $mail['html'] ) {
		$body['html'] = (string) $mail['html'];
	}

	$response = wp_remote_post(
		$settings['endpoint'],
		array(
			'timeout' => $settings['timeout'],
			'headers' => array(
				'Authorization' => 'Bearer ' . bookify_booking_mail()['api_key'],
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$raw  = (string) wp_remote_retrieve_body( $response );
	$json = json_decode( $raw, true );

	if ( $code >= 200 && $code < 300 ) {
		return true;
	}

	$reason = is_array( $json ) && isset( $json['message'] ) ? (string) $json['message'] : trim( wp_strip_all_tags( $raw ) );

	return new WP_Error(
		'bookify_resend_' . $code,
		sprintf(
			/* translators: 1: the HTTP status Resend answered with, 2: Resend's own explanation. */
			__( 'Resend refused the message (HTTP %1$d): %2$s', 'bookify-booking' ),
			$code,
			'' !== $reason ? $reason : __( 'no explanation given', 'bookify-booking' )
		)
	);
}

/**
 * Send one message: through Resend when it is set up, through WordPress when it is not.
 *
 * @param array{to:string,subject:string,text:string,html?:string,headers?:array|string} $mail The message.
 * @return bool Whether some transport accepted it.
 */
function bookify_booking_send_mail( array $mail ) {
	$mail = wp_parse_args(
		$mail,
		array(
			'to'      => '',
			'subject' => '',
			'text'    => '',
			'html'    => '',
			'headers' => array(),
		)
	);

	if ( ! is_email( $mail['to'] ) ) {
		return false;
	}

	// Testing only, and off unless configured: see bookify_booking_mail_redirect_to(). Done here
	// rather than in each composer because this function is the one place every message passes
	// through — confirmations, reminders, event summaries and the manage link alike.
	$redirect = bookify_booking_mail_redirect_to();

	if ( '' !== $redirect && strtolower( $redirect ) !== strtolower( (string) $mail['to'] ) ) {
		$mail['subject'] = sprintf( '[from %s] %s', $mail['to'], $mail['subject'] );
		$mail['to']      = $redirect;
	}

	if ( bookify_booking_mail_is_configured() ) {
		$sent = bookify_booking_resend_send( $mail );

		if ( ! is_wp_error( $sent ) ) {
			bookify_booking_mail_record( true, __( 'Accepted by Resend.', 'bookify-booking' ) );

			return true;
		}

		bookify_booking_mail_record( false, $sent->get_error_message() );

		// Fall through rather than return: a message that cannot go the good way should still be
		// attempted the old way, and the reason it failed is now on the settings screen.
	}

	return (bool) wp_mail( $mail['to'], $mail['subject'], $mail['text'], $mail['headers'] );
}
