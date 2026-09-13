<?php
/**
 * Throwaway: fill every slot of one day, for the "every time that day is taken" check. Deleted after use.
 */

$service_id = 19;
$day        = '2026-09-16';

$created = array();

foreach ( bookify_available_slots( $service_id, $day ) as $slot ) {
	$result = bookify_create_booking(
		array(
			'service_id' => $service_id,
			'name'       => 'Probe Fill',
			'email'      => 'probe@example.com',
			'phone'      => '555 0100',
			'date'       => $day,
			'time'       => $slot,
			'party_size' => 1,
		)
	);

	if ( is_wp_error( $result ) ) {
		echo "FAILED $slot " . $result->get_error_code() . "\n";
		continue;
	}

	$created[] = $result;
}

echo 'booked ' . count( $created ) . " slots on $day\n";
echo 'slots left: ' . count( bookify_available_slots( $service_id, $day ) ) . "\n";

// The same render the page does, so the message can be read without a browser. The argument is the
// one the listing's "Book this" link uses - see includes/booking-form.php for why it is not
// `bookify_service`.
$_GET['bookify_service_id'] = (string) $service_id;
$_GET['bookify_date']       = $day;

$html = bookify_booking_form_html();

if ( preg_match( '/bookify-booking__message--info">\s*([^<]+)/', $html, $match ) ) {
	echo 'form says: ' . trim( $match[1] ) . "\n";
} else {
	echo "form says: (no message — that is the bug)\n";
}

echo 'time picker rendered: ' . ( false === strpos( $html, 'id="bookify_time"' ) ? 'no' : 'YES' ) . "\n";
