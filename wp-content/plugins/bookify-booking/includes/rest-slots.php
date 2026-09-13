<?php
/**
 * The one route the calendar's script reads.
 *
 * Public on purpose: it answers "which times of day exist for this session on this date", which
 * is exactly what the page already prints, so it gives away nothing a visitor could not read by
 * loading the form. It returns the times and nothing else — no customer data, no booking ids, no
 * counts of what is booked — and it reads the same function the form renders and the write path
 * enforces, so the calendar cannot offer a time the server would refuse (T18, plan D12).
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The namespace every Bookify route lives in.
 */
const BOOKIFY_BOOKING_REST_NAMESPACE = 'bookify/v1';

/**
 * Register the read-only routes.
 *
 * Hooked to rest_api_init, which is the only moment WordPress will accept a route: calling
 * register_rest_route() earlier does register it, but WordPress says so on every single request —
 * "REST API routes must be registered on the rest_api_init action" — and a notice per request is
 * a bug whatever the route then does. Measured before the fix: 2 notices per page in
 * wp-content/debug.log; after it, none.
 */
function bookify_booking_register_rest_routes() {
	register_rest_route(
		BOOKIFY_BOOKING_REST_NAMESPACE,
		'/slots',
		array(
			'methods'             => WP_REST_Server::READABLE,
			// Public by design, and stated rather than implied: this route only reads, and only
			// ever answers with the times the form on the same page already shows. There is
			// nothing here a stranger could not get by loading the booking page.
			'permission_callback' => '__return_true',
			'callback'            => 'bookify_booking_rest_slots',
			'args'                => array(
				'service' => array(
					'required'          => true,
					'type'              => 'integer',
					'description'       => __( 'The session whose length the times are computed for.', 'bookify-booking' ),
					'validate_callback' => 'bookify_booking_rest_validate_service',
					'sanitize_callback' => 'absint',
				),
				'date'    => array(
					'required'          => true,
					'type'              => 'string',
					'description'       => __( 'The day to ask about, as YYYY-MM-DD.', 'bookify-booking' ),
					'validate_callback' => 'bookify_booking_rest_validate_date',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

/**
 * Whether the service argument is a positive whole number.
 *
 * The shape only. Whether it names a service that can be booked is not this route's business:
 * bookify_available_slots() answers an unknown service with an empty list, which tells a prober
 * nothing about which ids exist, where a "no such service" error would.
 *
 * @param mixed $value The submitted value.
 * @return true|\WP_Error
 */
function bookify_booking_rest_validate_service( $value ) {
	if ( ! is_numeric( $value ) || absint( $value ) < 1 ) {
		return new WP_Error(
			'bookify_rest_invalid_service',
			__( 'The session must be a positive whole number.', 'bookify-booking' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Whether the date argument is a real calendar date in YYYY-MM-DD form.
 *
 * @param mixed $value The submitted value.
 * @return true|\WP_Error
 */
function bookify_booking_rest_validate_date( $value ) {
	if ( ! is_string( $value ) || ! bookify_booking_is_date( $value ) ) {
		return new WP_Error(
			'bookify_rest_invalid_date',
			__( 'The date must be a real date in YYYY-MM-DD form.', 'bookify-booking' ),
			array( 'status' => 400 )
		);
	}

	return true;
}

/**
 * Answer with one day's times.
 *
 * array_values() rather than the raw list so the response is a JSON array (`["09:00",…]`) and
 * not an object keyed by whatever the slot generator happened to leave behind.
 *
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response
 */
function bookify_booking_rest_slots( WP_REST_Request $request ) {
	$service_id = (int) $request->get_param( 'service' );
	$date       = (string) $request->get_param( 'date' );

	return rest_ensure_response( array_values( bookify_available_slots( $service_id, $date ) ) );
}

add_action( 'rest_api_init', 'bookify_booking_register_rest_routes' );
