<?php
/**
 * Post types and registered meta for sessions and bookings.
 *
 * Storage shape only: no request handling, no rendering and no mail lives here.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the session and booking post types.
 *
 * The post type key stays `bookify_service` (plan D17): the key is what the abilities, the REST
 * layer and every stored `bookify_service_id` refer to, and only the words a person reads change.
 *
 * Bookings are deliberately not public. A booking carries the customer's name, email and
 * phone, and a public post type makes every entry individually addressable; the trade is
 * that the wp-agent-bridge list-post-types ability only enumerates public types.
 */
function bookify_booking_register_post_types() {
	register_post_type(
		'bookify_service',
		array(
			'labels'       => array(
				'name'          => __( 'Sessions', 'bookify-booking' ),
				'singular_name' => __( 'Session', 'bookify-booking' ),
				'add_new_item'  => __( 'Add session', 'bookify-booking' ),
				'edit_item'     => __( 'Edit session', 'bookify-booking' ),
			),
			'description'  => __( 'A session a visitor can book.', 'bookify-booking' ),
			'public'       => true,
			/*
			 * T26 reverses D9, exactly as D15 said it would: the theme now holds the one template
			 * that was missing, so a session gets a real page of its own instead of Hello
			 * Elementor's bare fallback. Both flags have to come back together, and T10 measured
			 * why: `publicly_queryable` alone leaves the rewrite rule in place, so the URL matches
			 * a rule whose query variable is then ignored and the blog index answers 200.
			 *
			 * The slug is `sessions`, the same word as the listing page's own slug. They do not
			 * collide — `/sessions/` is the page and `/sessions/<slug>/` is one session, which is
			 * the shape `/services/<slug>/` would have had under D9.
			 */
			'publicly_queryable' => true,
			'rewrite'      => array(
				'slug'       => 'sessions',
				'with_front' => false,
			),
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-clock',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			'has_archive'  => false,
		)
	);

	register_post_type(
		'bookify_booking',
		array(
			'labels'        => array(
				'name'          => __( 'Bookings', 'bookify-booking' ),
				'singular_name' => __( 'Booking', 'bookify-booking' ),
				'edit_item'     => __( 'Booking', 'bookify-booking' ),
				'search_items'  => __( 'Search bookings', 'bookify-booking' ),
			),
			'description'   => __( 'A booking a visitor made.', 'bookify-booking' ),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-calendar-alt',
			'supports'      => array( 'title' ),
		)
	);
}

/**
 * Read the plugin's meta.
 *
 * Both types are read on the front end without a session, so reading stays open; writing
 * this meta through the REST or admin surfaces needs the capability below.
 *
 * @return bool
 */
function bookify_booking_meta_auth() {
	return current_user_can( 'edit_posts' );
}

/**
 * Sanitise a price.
 *
 * Deliberately not `floatval`. WordPress calls a sanitize callback with four arguments and
 * an internal function rejects the extra ones, which is a fatal error rather than a warning.
 *
 * @param mixed $value Raw value.
 * @return float
 */
function bookify_booking_sanitize_price( $value ) {
	return (float) $value;
}

/**
 * Register every meta key the booking code reads and writes.
 */
function bookify_booking_register_meta() {
	$services = array(
		'bookify_duration' => array(
			'type'              => 'integer',
			'description'       => __( 'Session length in minutes.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		'bookify_price'    => array(
			'type'              => 'number',
			'description'       => __( 'Price in the site currency.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_price',
		),
		'bookify_capacity' => array(
			'type'              => 'integer',
			'description'       => __( 'People that fit in one slot.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		// T23: what this service asks to be paid before a booking of it is confirmed, and how a
		// deposit is expressed. The three are read through includes/payments/amounts.php, which
		// re-validates each one, so a value written by `wp post meta` cannot change what is charged
		// in a way the code does not understand.
		'bookify_payment_mode'   => array(
			'type'              => 'string',
			'description'       => __( 'none, deposit or full.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_payment_mode',
		),
		'bookify_deposit_type'   => array(
			'type'              => 'string',
			'description'       => __( 'Whether a deposit is an amount or a percentage of the price.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_deposit_type',
		),
		'bookify_deposit_value'  => array(
			'type'              => 'number',
			'description'       => __( 'What a deposit is worth: an amount, or a percentage.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_price',
		),
	);

	$bookings = array(
		'bookify_service_id'     => array(
			'type'              => 'integer',
			'description'       => __( 'The booked session.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		// Stage 7: what a ticket is for. A session booking stores bookify_service_id instead, and the
		// two never both appear — a booking is one product or the other, and the write path decides
		// which from the request rather than letting a form claim both.
		'bookify_event_id'       => array(
			'type'              => 'integer',
			'description'       => __( 'The event this booking is a ticket for, if any.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		'bookify_tier_id'        => array(
			'type'              => 'integer',
			'description'       => __( 'The ticket tier this booking was sold on, if any.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		'bookify_customer_name'  => array(
			'type'              => 'string',
			'description'       => __( 'Name the booking is for.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		'bookify_customer_email' => array(
			'type'              => 'string',
			'description'       => __( 'Address the confirmation goes to.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_email',
		),
		'bookify_customer_phone' => array(
			'type'              => 'string',
			'description'       => __( 'Contact number, optional.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		'bookify_date'           => array(
			'type'              => 'string',
			'description'       => __( 'Requested date, YYYY-MM-DD.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		'bookify_time'           => array(
			'type'              => 'string',
			'description'       => __( 'Requested time, HH:MM.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		'bookify_party_size'     => array(
			'type'              => 'integer',
			'description'       => __( 'Number of guests.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		'bookify_status'         => array(
			'type'              => 'string',
			'description'       => __( 'awaiting_payment, pending, confirmed, expired or cancelled.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_key',
		),
		// T23/T25: what was asked for, whether it arrived, and when the window closes. The amount
		// is stored rather than re-read from the service, so a price change cannot rewrite what the
		// customer was already told (see includes/payments/amounts.php).
		'bookify_payment_status'    => array(
			'type'              => 'string',
			'description'       => __( 'unpaid, paid, failed or refunded.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_key',
		),
		'bookify_payment_amount'    => array(
			'type'              => 'number',
			'description'       => __( 'Amount to pay, in the site currency, decided when the booking was made.', 'bookify-booking' ),
			'sanitize_callback' => 'bookify_booking_sanitize_price',
		),
		'bookify_payment_due'       => array(
			'type'              => 'integer',
			'description'       => __( 'When the unpaid booking stops holding its place, as a Unix timestamp.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		// The Stripe object id (a Checkout Session or a PaymentIntent) this booking was paid with,
		// kept so an operator can find the payment in Stripe. It is an *identifier*, not a reusable
		// instrument: no card number, no CVC and no payment method token is stored anywhere by this
		// plugin, and nothing here can be used to charge anything.
		'bookify_payment_reference' => array(
			'type'              => 'string',
			'description'       => __( 'The Stripe object that paid this booking, for an operator to look up.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
		),
		'bookify_payment_note'      => array(
			'type'              => 'string',
			'description'       => __( 'What a refund or a late payment needs a person to know.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		'bookify_reference'      => array(
			'type'              => 'string',
			'description'       => __( 'Short code the customer quotes.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		'bookify_cancel_token'   => array(
			'type'              => 'string',
			'description'       => __( 'Secret that lets the customer cancel from their own email.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
			// Deliberately not exposed through REST: it is a bearer credential, and nothing reads
			// it outside this plugin.
			'show_in_rest'      => false,
		),
		// T20's link, which carries the reference, the token above and an expiry. "Signed" in the
		// sense the plan means it: the token is a bearer secret compared with hash_equals(), and
		// the reference is only a lookup key — a query argument never names a post id.
		'bookify_manage_token'      => array(
			'type'              => 'string',
			'description'       => __( 'Secret that proves a manage link belongs to the booking it names.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
		),

		'bookify_manage_expires'    => array(
			'type'              => 'integer',
			'description'       => __( 'When the manage link stops working, as a Unix timestamp.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
		// T21: one key, so an operator can answer "why did this move?". Written as the booking
		// that was given up, printed exactly as the date and time were stored.
		'bookify_previous_slot'     => array(
			'type'              => 'string',
			'description'       => __( 'The date and time this booking was moved away from.', 'bookify-booking' ),
			'sanitize_callback' => 'sanitize_text_field',
		),
		// T22: the account this booking belongs to, when the customer was signed in. Never set by
		// matching an email address to an existing user — see includes/customer-accounts.php.
		'bookify_customer_user'     => array(
			'type'              => 'integer',
			'description'       => __( 'The signed-in customer this booking belongs to, if any.', 'bookify-booking' ),
			'sanitize_callback' => 'absint',
		),
	);

	$types = array(
		'bookify_service' => $services,
		'bookify_booking' => $bookings,
	);

	foreach ( $types as $post_type => $fields ) {
		foreach ( $fields as $meta_key => $args ) {
			register_post_meta(
				$post_type,
				$meta_key,
				$args + array(
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => 'bookify_booking_meta_auth',
				)
			);
		}
	}
}
