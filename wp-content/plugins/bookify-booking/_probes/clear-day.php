<?php
/**
 * Throwaway: delete the probe bookings made for the full-day check. Deleted after use.
 */

$deleted = 0;

foreach ( get_posts(
	array(
		'post_type'      => 'bookify_booking',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => 'bookify_customer_email',
				'value' => 'probe@example.com',
			),
		),
	)
) as $booking_id ) {
	wp_delete_post( $booking_id, true );
	$deleted++;
}

echo "deleted $deleted probe bookings\n";
echo 'slots back on 2026-09-16: ' . count( bookify_available_slots( 19, '2026-09-16' ) ) . "\n";
