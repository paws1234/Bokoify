<?php
/**
 * Throwaway: create a booking and capture the confirmation wp_mail would send, so the cancel
 * link in it can be tested. Deleted after use.
 */

add_filter(
	'pre_wp_mail',
	static function ( $return, $atts ) {
		$GLOBALS['probe_mail'] = $atts;

		// Claim the message went out: nothing can leave this container, and what is under test is
		// what was composed (T7's rule).
		return true;
	},
	10,
	2
);

$day   = '2026-09-17';
$slots = bookify_available_slots( 19, $day );
$slot  = $slots[6];

echo 'slots before: ' . implode( ' ', $slots ) . "\n";
echo "booking $day $slot for probe@example.com\n";

$booking_id = bookify_create_booking(
	array(
		'service_id' => 19,
		'name'       => 'Probe Cancel',
		'email'      => 'probe@example.com',
		'phone'      => '555 0100',
		'date'       => $day,
		'time'       => $slot,
		'party_size' => 1,
	)
);

if ( is_wp_error( $booking_id ) ) {
	echo 'FAILED ' . $booking_id->get_error_code() . "\n";
	return;
}

$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );
$token     = (string) get_post_meta( $booking_id, 'bookify_cancel_token', true );

echo 'booking id=' . $booking_id . ' reference=' . $reference . "\n";
echo 'status=' . get_post_meta( $booking_id, 'bookify_status', true ) . "\n";
echo 'token length=' . strlen( $token ) . ' token=' . substr( $token, 0, 6 ) . "…\n";
echo 'meta registered: ' . ( isset( get_registered_meta_keys( 'post', 'bookify_booking' )['bookify_cancel_token'] ) ? 'yes' : 'NO' ) . "\n";
echo 'slots after: ' . implode( ' ', bookify_available_slots( 19, $day ) ) . "\n";

$mail = isset( $GLOBALS['probe_mail'] ) ? $GLOBALS['probe_mail'] : array();

echo "\n--- the confirmation wp_mail was handed ---\n";
echo 'to: ' . implode( ',', (array) ( isset( $mail['to'] ) ? $mail['to'] : array() ) ) . "\n";
echo 'subject: ' . ( isset( $mail['subject'] ) ? $mail['subject'] : '' ) . "\n";
echo "message:\n" . ( isset( $mail['message'] ) ? $mail['message'] : '' ) . "\n";

if ( preg_match( '#https?://\S+bookify_cancel=\S+#', (string) ( isset( $mail['message'] ) ? $mail['message'] : '' ), $match ) ) {
	echo "\nCANCEL_URL=" . rtrim( $match[0], '.' ) . "\n";
} else {
	echo "\nCANCEL_URL=none\n";
}

echo 'BOOKING_ID=' . $booking_id . "\n";
echo 'REFERENCE=' . $reference . "\n";
echo 'OTHER_REFERENCE=' . get_post_meta( 20, 'bookify_reference', true ) . "\n";
