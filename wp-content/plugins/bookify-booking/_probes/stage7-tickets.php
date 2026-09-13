<?php
/**
 * Throwaway probe for T35: the ticket form renders the tiers, and a ticket is priced and charged.
 *
 * One `eval-file` run is one request, so everything happens in sequence here. Proof sought:
 *
 *   1. the form offers every tier with its price and what is left, and the quantity ceiling is what
 *      the chosen tier has left;
 *   2. one tier on sale becomes a hidden field rather than a dropdown with one answer;
 *   3. an event with nothing left and an event that has started each say so instead of offering a
 *      control that cannot work;
 *   4. a ticket costs the tier's price times the number of tickets;
 *   5. the request Stripe is asked for carries that amount, the number of tickets as the line's
 *      quantity, and the event's name.
 *
 * Everything it creates is deleted at the end, so it is safe to re-run:
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage7-tickets.php
 *
 * Kept as T35's re-runnable evidence.
 */

$p7_created = array(
	'events' => array(),
	'tiers'  => array(),
);

function p7_note( $label, $ok, $detail = '' ) {
	printf( "%-52s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', '' === $detail ? '' : "  {$detail}" );
}

function p7_probe_event( $title, $args = array() ) {
	$args = $args + array(
		'date'     => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +40 days' ) ),
		'start'    => '19:00',
		'end'      => '22:00',
		'capacity' => 0,
	);

	$id = wp_insert_post(
		array(
			'post_type'   => 'bookify_event',
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, 'bookify_event_date', $args['date'] );
	update_post_meta( $id, 'bookify_event_start', $args['start'] );
	update_post_meta( $id, 'bookify_event_end', $args['end'] );

	if ( $args['capacity'] > 0 ) {
		update_post_meta( $id, 'bookify_event_capacity', $args['capacity'] );
	}

	return (int) $id;
}

function p7_probe_tier( $event_id, $title, $price, $capacity ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'bookify_tier',
			'post_status' => 'publish',
			'post_title'  => $title,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, 'bookify_event_id', (int) $event_id );
	update_post_meta( $id, 'bookify_tier_price', $price );
	update_post_meta( $id, 'bookify_tier_capacity', $capacity );

	return (int) $id;
}

$p7_site_event = get_posts(
	array(
		'post_type'      => 'bookify_event',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'title'          => 'Autumn reset workshop',
	)
);

$p7_real = $p7_site_event ? (int) $p7_site_event[0] : 0;

if ( ! $p7_real ) {
	echo "The site's own event is missing; run _probes/stage7-content.php first.\n";
	return;
}

// ---------------------------------------------------------------- 1: the form on the real event.

// The queue is registered on wp_enqueue_scripts, which does not run under wp-cli, so it is fired here
// for the same reason a page view would: to have something for the render to enqueue.
do_action( 'wp_enqueue_scripts' );

// Nothing may leave the container (D6): the confirmation a booking sends is captured here rather
// than attempted, which also keeps debug.log free of transport errors.
add_filter(
	'pre_wp_mail',
	function () {
		return true;
	}
);

$p7_html = bookify_event_ticket_form_html( array( 'event' => $p7_real ) );

p7_note( 'the form renders', '' !== $p7_html && false === strpos( $p7_html, 'bookify-booking__empty' ) );
p7_note( 'every tier is offered', 2 === substr_count( $p7_html, '<option value="' ) );
p7_note(
	'with its price and what is left',
	false !== strpos( $p7_html, 'General admission — GBP 45.00 — 18 left' )
		&& false !== strpos( $p7_html, 'VIP — GBP 75.00 — 6 left' )
);
p7_note( 'the event is named in a hidden field', false !== strpos( $p7_html, 'name="bookify_event_id" value="' . $p7_real . '"' ) );
p7_note( 'the nonce is printed', false !== strpos( $p7_html, 'name="bookify_nonce"' ) );
p7_note( 'the honeypot is printed', false !== strpos( $p7_html, 'name="bookify_website"' ) );
p7_note( 'the script and the stylesheet are asked for', wp_style_is( 'bookify-booking-form', 'enqueued' ) && wp_script_is( 'bookify-event-tickets', 'enqueued' ) );
preg_match( '/name="bookify_party_size"[^>]*max="([0-9]+)"/', $p7_html, $p7_max );
p7_note( 'the quantity ceiling is the chosen tier\'s places left', isset( $p7_max[1] ) && '18' === $p7_max[1], isset( $p7_max[1] ) ? 'max=' . $p7_max[1] : 'no max found' );

preg_match( '/data-bookify-tier-available="([^"]+)"/', $p7_html, $p7_available );
$p7_map = isset( $p7_available[1] ) ? json_decode( html_entity_decode( $p7_available[1] ), true ) : array();
p7_note(
	'the script is told every tier\'s places left',
	is_array( $p7_map ) && 2 === count( $p7_map ) && in_array( 18, array_map( 'intval', $p7_map ), true ) && in_array( 6, array_map( 'intval', $p7_map ), true ),
	isset( $p7_available[1] ) ? $p7_available[1] : 'nothing'
);

// ---------------------------------------------------------------- 2: one tier is not a choice.

$p7_single     = p7_probe_event( 'T35 probe single tier' );
$p7_single_tier = p7_probe_tier( $p7_single, 'Only ticket', 12, 3 );
$p7_created['events'][] = $p7_single;
$p7_created['tiers'][]  = $p7_single_tier;

$p7_single_html = bookify_event_ticket_form_html( array( 'event' => $p7_single ) );

p7_note(
	'one tier on sale is a hidden field, not a dropdown',
	false !== strpos( $p7_single_html, '<input type="hidden" name="bookify_tier_id" value="' . $p7_single_tier . '"' )
		&& false === strpos( $p7_single_html, '<select' )
);

// ---------------------------------------------------------------- 3: nothing on sale.

$p7_empty     = p7_probe_event( 'T35 probe nothing left' );
$p7_empty_tier = p7_probe_tier( $p7_empty, 'Gone', 10, 1 );
$p7_created['events'][] = $p7_empty;
$p7_created['tiers'][]  = $p7_empty_tier;

bookify_create_booking(
	array(
		'event_id'   => $p7_empty,
		'tier_id'    => $p7_empty_tier,
		'party_size' => 1,
		'name'       => 'T35 probe',
		'email'      => 't35@example.com',
	)
);

$p7_empty_booking = get_posts(
	array(
		'post_type'      => 'bookify_booking',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => 'bookify_event_id',
				'value' => $p7_empty,
			),
		),
	)
);

$p7_sold_out_html = bookify_event_ticket_form_html( array( 'event' => $p7_empty ) );

p7_note(
	'an event with nothing left says so',
	false !== strpos( $p7_sold_out_html, 'Every ticket for this event has gone.' )
		&& false === strpos( $p7_sold_out_html, 'name="bookify_tier_id"' ),
	'places left ' . bookify_event_places_left( $p7_empty )
);

$p7_past      = p7_probe_event( 'T35 probe over', array( 'date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 days' ) ) ) );
$p7_past_tier = p7_probe_tier( $p7_past, 'Past ticket', 10, 5 );
$p7_created['events'][] = $p7_past;
$p7_created['tiers'][]  = $p7_past_tier;

$p7_over_html = bookify_event_ticket_form_html( array( 'event' => $p7_past ) );

p7_note(
	'an event that has started says so',
	false !== strpos( $p7_over_html, 'This event has finished, so its tickets are no longer for sale.' )
);

// ---------------------------------------------------------------- 4 and 5: the price and Stripe.

$p7_tier = bookify_event_tiers( $p7_real );
$p7_ga   = null;

foreach ( $p7_tier as $p7_candidate ) {
	if ( 'General admission' === $p7_candidate->post_title ) {
		$p7_ga = $p7_candidate;
	}
}

p7_note( 'the event has a General admission tier', null !== $p7_ga );

if ( null === $p7_ga ) {
	return;
}

p7_note(
	'three tickets cost three times the price',
	135.0 === bookify_booking_ticket_amount( $p7_ga->ID, 3 ),
	'price ' . bookify_tier_price_label( $p7_ga->ID ) . ' × 3 = ' . bookify_booking_ticket_amount( $p7_ga->ID, 3 )
);

$p7_booking = bookify_create_booking(
	array(
		'event_id'   => $p7_real,
		'tier_id'    => $p7_ga->ID,
		'party_size' => 3,
		'name'       => 'T35 probe',
		'email'      => 't35@example.com',
	)
);

if ( ! is_wp_error( $p7_booking ) ) {
	p7_note(
		'the booking stores the amount the tier charges for three',
		135.0 === (float) get_post_meta( $p7_booking, 'bookify_payment_amount', true ),
		'amount ' . get_post_meta( $p7_booking, 'bookify_payment_amount', true )
	);

	// Stripe, asked for with a key set and the answer stubbed: no request leaves the machine.
	$p7_payments = get_option( 'bookify_payments', array() );
	update_option(
		'bookify_payments',
		array(
			'currency'       => 'gbp',
			'secret_key'     => 'sk_test_probe',
			'webhook_secret' => '',
			'expiry_minutes' => 30,
		)
	);

	$p7_caught  = null;

	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( &$p7_caught ) {
			$p7_caught = array(
				'url'  => $url,
				'body' => isset( $args['body'] ) ? $args['body'] : array(),
			);

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'id'  => 'cs_test_probe',
						'url' => 'https://checkout.stripe.com/c/pay/cs_test_probe',
					)
				),
			);
		},
		10,
		3
	);

	$p7_session = bookify_booking_stripe_create_session( $p7_booking );

	remove_all_filters( 'pre_http_request' );

	$p7_body = ( $p7_caught && is_array( $p7_caught['body'] ) ) ? $p7_caught['body'] : array();

	p7_note( 'Stripe is asked for a session', ! is_wp_error( $p7_session ), is_wp_error( $p7_session ) ? $p7_session->get_error_message() : 'cs_test_probe' );
	p7_note(
		'the line is three units of the tier price',
		isset( $p7_body['line_items'][0]['quantity'], $p7_body['line_items'][0]['price_data']['unit_amount'] )
			&& 3 === (int) $p7_body['line_items'][0]['quantity']
			&& 4500 === (int) $p7_body['line_items'][0]['price_data']['unit_amount'],
		isset( $p7_body['line_items'][0] ) ? wp_json_encode( $p7_body['line_items'][0] ) : 'nothing caught'
	);
	p7_note(
		'the description names the event and the ticket',
		isset( $p7_body['line_items'][0]['price_data']['product_data']['name'] )
			&& false !== strpos( $p7_body['line_items'][0]['price_data']['product_data']['name'], 'Autumn reset workshop' )
			&& false !== strpos( $p7_body['line_items'][0]['price_data']['product_data']['name'], 'General admission' ),
		isset( $p7_body['line_items'][0]['price_data']['product_data']['name'] ) ? $p7_body['line_items'][0]['price_data']['product_data']['name'] : 'nothing'
	);
	p7_note(
		'the request carries the reference and nothing about the customer',
		isset( $p7_body['client_reference_id'] ) && 1 === count( $p7_body['metadata'] )
			&& ! isset( $p7_body['customer_email'] ),
		isset( $p7_body['client_reference_id'] ) ? 'ref ' . $p7_body['client_reference_id'] : 'nothing'
	);

	update_option( 'bookify_payments', $p7_payments );

	wp_delete_post( $p7_booking, true );
}

// ---------------------------------------------------------------- the widget and the shortcode.

$p7_widgets = \Elementor\Plugin::$instance->widgets_manager->get_widget_types();

p7_note(
	'the widget is registered under bookify',
	isset( $p7_widgets['bookify_event_tickets'] )
		&& 'Ticket form' === $p7_widgets['bookify_event_tickets']->get_title()
		&& in_array( 'bookify', $p7_widgets['bookify_event_tickets']->get_categories(), true ),
	isset( $p7_widgets['bookify_event_tickets'] ) ? $p7_widgets['bookify_event_tickets']->get_title() : 'missing'
);

// Rendered the way Elementor renders it, with the panel's own settings array.
$p7_element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
	array(
		'id'         => 'stage7probe',
		'elType'     => 'widget',
		'widgetType' => 'bookify_event_tickets',
		'settings'   => array(
			'event'          => $p7_real,
			'title'          => 'Control-driven ticket title',
			'submit_label'   => 'Control-driven button',
			'label_quantity' => 'How many of these',
		),
		'elements'   => array(),
	)
);

ob_start();
$p7_element->render_content();
$p7_widget_html = ob_get_clean();

p7_note(
	'the widget prints its copy from the controls',
	false !== strpos( $p7_widget_html, 'Control-driven ticket title' )
		&& false !== strpos( $p7_widget_html, 'Control-driven button' )
		&& false !== strpos( $p7_widget_html, 'How many of these' )
);
p7_note(
	'and offers the same tiers, from the same event',
	false !== strpos( $p7_widget_html, 'General admission — GBP 45.00 — 18 left' )
		&& false !== strpos( $p7_widget_html, 'name="bookify_event_id" value="' . $p7_real . '"' )
);

$p7_shortcode_html = do_shortcode( '[bookify_event_tickets event="' . $p7_real . '" title="Shortcode ticket title"]' );

p7_note(
	'the shortcode renders the same form',
	false !== strpos( $p7_shortcode_html, 'Shortcode ticket title' )
		&& false !== strpos( $p7_shortcode_html, 'VIP — GBP 75.00 — 6 left' )
);

// ---------------------------------------------------------------- cleanup.

if ( $p7_empty_booking ) {
	foreach ( $p7_empty_booking as $p7_id ) {
		wp_delete_post( $p7_id, true );
	}
}

foreach ( $p7_created['tiers'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

foreach ( $p7_created['events'] as $p7_id ) {
	wp_delete_post( $p7_id, true );
}

p7_note( 'nothing left behind but the site\'s own event', true, 'event ' . $p7_real . ' still has ' . bookify_event_places_left( $p7_real ) . ' places left' );
