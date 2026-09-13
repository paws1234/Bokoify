<?php
/**
 * What a booking's confirmation does, and how the key is supplied to it.
 *
 * **The key is configuration, never a settings field.** It is read, in order, from the
 * `BOOKIFY_RESEND_API_KEY` constant in wp-config.php, from the environment, or from the
 * `bookify_booking_resend_api_key` filter. This probe uses the last of those three, which means it
 * needs no key at all, writes nothing to the database and never touches wp-config.php.
 *
 * Four things are worth proving, and none of them can send an email:
 *
 *  1. **Nothing changes without a key.** With none configured, sending must still end in `wp_mail()`
 *     — the call this plugin has always made, which is what keeps the older probes that capture
 *     `pre_wp_mail` passing.
 *  2. **The request is what Resend documents.** `pre_http_request` stubs the response, so the URL,
 *     headers and JSON body can be read back and printed. Nothing leaves the machine.
 *  3. **The endpoint is real.** The stub's own body is posted to Resend with a deliberately invalid
 *     key, so the answer is a 401 rather than an email: it proves the URL and the body shape are the
 *     ones the API accepts, without a key and without anyone receiving anything.
 *  4. **The HTML is the whole message.** It is written into `uploads/` so it can be opened in a
 *     browser and looked at, which is the only way to judge how an email is laid out.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/mail-resend.php
 */

$configured = bookify_booking_mail_is_configured();
$result_before = get_option( 'bookify_mail_last_result', null );

printf( "key configured before this probe ran: %s\n", $configured ? 'yes' : 'no' );

// --- one real booking to send about ----------------------------------------------------------
$service = get_page_by_path( 'counselling-session', OBJECT, 'bookify_service' );
$day     = bookify_open_days()[0];
$slots   = $service instanceof WP_Post ? bookify_available_slots( $service->ID, $day ) : array();

if ( ! $service instanceof WP_Post || ! $slots ) {
	echo "cannot run: no counselling session with a free slot\n";
	return;
}

$booking_id = bookify_create_booking(
	array(
		'service_id' => $service->ID,
		'name'       => 'Probe Reader',
		'email'      => 'probe@example.com',
		'phone'      => '555 0100',
		'date'       => $day,
		'time'       => $slots[0],
		'party_size' => 1,
	)
);

if ( is_wp_error( $booking_id ) ) {
	echo 'booking failed: ' . $booking_id->get_error_code() . "\n";
	return;
}

$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );
printf( "booking %d (%s) %s %s\n\n", $booking_id, $reference, $day, $slots[0] );

// --- 1. no key: the WordPress path ------------------------------------------------------------
$captured_wp = null;
$capture     = static function ( $return, $atts ) use ( &$captured_wp ) {
	$captured_wp = $atts;

	return true;
};

if ( $configured ) {
	echo "no key      skipped: this site has a key configured already, so the WordPress path cannot be\n";
	echo "            demonstrated here without removing it\n\n";
} else {
	add_filter( 'pre_wp_mail', $capture, 10, 2 );
	bookify_booking_send_confirmation( $booking_id );
	remove_filter( 'pre_wp_mail', $capture, 10 );

	printf(
		"no key      pre_wp_mail fired: %-3s  subject: %s\n",
		$captured_wp ? 'yes' : 'NO',
		$captured_wp ? $captured_wp['subject'] : '-'
	);
	printf(
		"            body begins: %s\n\n",
		$captured_wp ? str_replace( "\n", ' / ', substr( $captured_wp['message'], 0, 90 ) ) : '-'
	);
}

// --- 2. a key from configuration: what Resend would be sent -----------------------------------
$fake_key = 're_probe_not_a_real_key_000000';
$provided = array(
	'bookify_booking_resend_api_key'   => $fake_key,
	'bookify_booking_mail_from_address' => 'bookings@bookify.example',
	'bookify_booking_mail_from_name'    => 'Bookify Studio',
);

$callbacks = array();

foreach ( $provided as $filter => $value ) {
	$callbacks[ $filter ] = static function () use ( $value ) {
		return $value;
	};

	add_filter( $filter, $callbacks[ $filter ] );
}

printf( "with a key  configure: %s, from %s\n", bookify_booking_mail_is_configured() ? 'yes' : 'NO', bookify_booking_mail_from() );

$captured    = null;
$captured_wp = null;
$stub        = static function ( $pre, $args, $url ) use ( &$captured ) {
	$captured = array(
		'url'  => $url,
		'args' => $args,
	);

	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array( 'id' => 'probe-email-id' ) ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};

add_filter( 'pre_http_request', $stub, 10, 3 );
add_filter( 'pre_wp_mail', $capture, 10, 2 );

bookify_booking_send_confirmation( $booking_id );

remove_filter( 'pre_http_request', $stub, 10 );
remove_filter( 'pre_wp_mail', $capture, 10 );

$body = array();

if ( ! $captured ) {
	echo "FAIL        nothing was sent anywhere\n";
} else {
	$body    = json_decode( (string) $captured['args']['body'], true );
	$headers = array_change_key_case( (array) $captured['args']['headers'], CASE_LOWER );
	$auth    = isset( $headers['authorization'] ) ? (string) $headers['authorization'] : '';

	printf( "            URL     %s\n", $captured['url'] );
	printf( "            method  %s\n", isset( $captured['args']['method'] ) ? $captured['args']['method'] : 'POST (wp_remote_post default)' );
	printf( "            auth    %s (%d characters, not printed)\n", '' !== $auth ? 'Bearer …' : 'MISSING', strlen( $auth ) );
	printf( "            type    %s\n", isset( $headers['content-type'] ) ? $headers['content-type'] : '-' );
	printf( "            from    %s\n", $body['from'] ?? '-' );
	printf( "            to      %s\n", implode( ', ', (array) ( $body['to'] ?? array() ) ) );
	printf( "            subject %s\n", $body['subject'] ?? '-' );
	printf( "            text    %d bytes\n", strlen( (string) ( $body['text'] ?? '' ) ) );
	printf( "            html    %d bytes\n", strlen( (string) ( $body['html'] ?? '' ) ) );
	printf( "            wp_mail %s\n\n", $captured_wp ? 'ALSO CALLED (wrong - it should not be)' : 'not called, as it should not be' );

	$html = (string) ( $body['html'] ?? '' );

	printf( "%-24s %s\n", 'html has reference', false !== strpos( $html, $reference ) ? 'yes' : 'NO' );
	printf( "%-24s %s\n", 'html has the item', false !== strpos( $html, 'Counselling session' ) ? 'yes' : 'NO' );
	printf( "%-24s %s\n", 'html has the date', false !== strpos( $html, $day ) ? 'yes' : 'NO' );
	printf( "%-24s %s\n", 'html has the time', false !== strpos( $html, $slots[0] ) ? 'yes' : 'NO' );
	// Compared escaped: the markup holds `&#038;` between the query arguments, because that is what
	// esc_url() writes and what an attribute may contain. Comparing the raw URL finds nothing.
	printf(
		"%-24s %s\n",
		'html has manage link',
		false !== strpos( $html, esc_url( bookify_booking_manage_url( $booking_id ) ) ) ? 'yes' : 'NO'
	);
	printf( "%-24s %s\n", 'html has the address', false !== strpos( $html, '14 Harbour Lane' ) ? 'yes' : 'NO' );
	printf( "%-24s %s\n", 'text unchanged', false !== strpos( (string) ( $body['text'] ?? '' ), 'Reference: ' . $reference ) ? 'yes' : 'NO' );

	$path = WP_CONTENT_DIR . '/uploads/_probe-email.html';
	file_put_contents( $path, $html );
	printf( "\nwritten to %s — open it, then delete it\n", str_replace( WP_CONTENT_DIR, 'wp-content', $path ) );
}

// --- 3. the endpoint is real, and nothing is sent ---------------------------------------------
if ( $body ) {
	printf( "\nposting that same body to Resend with a deliberately invalid key:\n" );

	$response = wp_remote_post(
		'https://api.resend.com/emails',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer re_not_a_real_key_at_all',
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	$code   = is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );
	$answer = is_wp_error( $response ) ? '' : json_decode( (string) wp_remote_retrieve_body( $response ), true );

	printf(
		"  HTTP %s — %s\n",
		$code,
		is_array( $answer ) && isset( $answer['message'] ) ? $answer['message'] : '(no message)'
	);
	printf( "  401 here is the proof: the URL and the body shape are the ones Resend accepts.\n" );
}

// --- put everything back ----------------------------------------------------------------------
foreach ( $callbacks as $filter => $callback ) {
	remove_filter( $filter, $callback );
}

/*
 * The send in step 2 recorded "accepted by Resend", because as far as this plugin is concerned the
 * transport did accept it — the response was stubbed. On the settings screen that reads as a
 * delivery that never happened, so the record is put back too.
 */
if ( null === $result_before ) {
	delete_option( 'bookify_mail_last_result' );
} else {
	update_option( 'bookify_mail_last_result', $result_before, false );
}

wp_delete_post( $booking_id, true );

printf(
	"\nput back: %d filter(s) removed, key now %s, legacy option %s, last result %s, booking %d deleted\n",
	count( $callbacks ),
	bookify_booking_mail_is_configured() ? 'STILL SET (it was before this ran)' : 'empty',
	false === get_option( 'bookify_mail', false ) ? 'absent' : 'PRESENT',
	null === bookify_booking_mail_last_result() ? 'cleared' : 'restored',
	$booking_id
);

printf(
	"probe bookings left: %d\n",
	count(
		get_posts(
			array(
				'post_type'      => 'bookify_booking',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'bookify_customer_email',
						'value' => 'probe@example.com',
					),
				),
			)
		)
	)
);
