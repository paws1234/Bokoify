<?php
/**
 * Throwaway probe for Stage 2 (T15-T17). Deleted after use.
 */

echo 'today=' . current_time( 'Y-m-d' ) . ' weekday=' . current_time( 'w' ) . "\n";

$availability = bookify_availability();

echo sprintf(
	"interval=%d lead_hours=%d days_ahead=%d blocked=%s\n",
	$availability['interval'],
	$availability['lead_hours'],
	$availability['days_ahead'],
	implode( ',', $availability['blocked_dates'] )
);

foreach ( $availability['weekdays'] as $day => $row ) {
	echo sprintf( "  day %d open=%s %s-%s\n", $day, $row['open'] ? 'yes' : 'no', $row['from'], $row['to'] );
}

echo 'hours html: ' . bookify_booking_opening_hours_html() . "\n";

$open_days = bookify_open_days();
echo 'open days: ' . count( $open_days ) . ' first=' . $open_days[0] . ' last=' . end( $open_days ) . "\n";

$services = bookify_booking_published_services();
echo 'services: ' . count( $services ) . "\n";

foreach ( $services as $service ) {
	echo sprintf(
		"  %d %s duration=%s capacity=%s\n",
		$service->ID,
		$service->post_title,
		get_post_meta( $service->ID, 'bookify_duration', true ),
		get_post_meta( $service->ID, 'bookify_capacity', true )
	);
}

$service_id = $services[0]->ID;
$day        = $open_days[0];

echo "slots for $day: " . implode( ' ', bookify_available_slots( $service_id, $day ) ) . "\n";
echo 'available days for that service: ' . implode( ' ', array_slice( bookify_available_days( $service_id ), 0, 5 ) ) . " ...\n";

$bookings = get_posts( array( 'post_type' => 'bookify_booking', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
echo 'bookings: ' . count( $bookings ) . "\n";

foreach ( $bookings as $booking_id ) {
	echo sprintf(
		"  %d %s status=%s %s %s %d guests\n",
		$booking_id,
		get_post_meta( $booking_id, 'bookify_reference', true ),
		get_post_meta( $booking_id, 'bookify_status', true ),
		get_post_meta( $booking_id, 'bookify_date', true ),
		get_post_meta( $booking_id, 'bookify_time', true ),
		(int) get_post_meta( $booking_id, 'bookify_party_size', true )
	);
}

echo 'booking page: ' . bookify_booking_page_url() . "\n";
