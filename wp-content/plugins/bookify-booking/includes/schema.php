<?php
/**
 * Structured data: what the business is, and what a session is.
 *
 * Every value is read from where it is already stored — `bookify_business` for the name, address,
 * phone and email, `bookify_availability` for the opening hours, the session's own meta for its
 * price and length. Nothing here is typed a second time, which is the whole point: T27's acceptance
 * is that changing the phone number on the Settings screen changes the published data with no other
 * edit.
 *
 * **DIVERGENCE (plan D20).** T27's do-step says to install Rank Math and point its LocalBusiness
 * module at these options. This is a ~140-line emitter instead, for three reasons. The container
 * keeps WordPress in a named volume, so a plugin installed through wp-admin would work and survive
 * a restart — but it would exist only inside that volume: invisible to git, absent from a fresh
 * clone, and impossible for `wpdev smoke` or a reviewer to reproduce. The values have to be read
 * from our options either way, so the integration is the work and the plugin is only packaging. And
 * an offer, an address and seven opening-hours rows do not need a site-wide SEO suite behind them.
 * If the owner later wants Rank Math, it can be added on top and this file deleted.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The days of the week as schema.org spells them.
 *
 * Deliberately not bookify_booking_weekdays(): that one returns translated names for people to
 * read, and schema.org's DayOfWeek is an English enumeration. A translated "Montag" in `dayOfWeek`
 * is not a value any consumer understands.
 *
 * @return array<int,string>
 */
function bookify_booking_schema_weekdays() {
	return array(
		1 => 'Monday',
		2 => 'Tuesday',
		3 => 'Wednesday',
		4 => 'Thursday',
		5 => 'Friday',
		6 => 'Saturday',
		0 => 'Sunday',
	);
}

/**
 * The opening hours, one specification per open day.
 *
 * Built from the stored weekdays rather than parsed back out of the grouped text the footer shows:
 * that text exists to be short, and "Monday to Friday 09:00–17:00" is not something to take apart
 * again when the days are already in the option.
 *
 * @return array<int,array<string,string>>
 */
function bookify_booking_schema_opening_hours() {
	$settings = bookify_availability();
	$specs    = array();

	foreach ( bookify_booking_schema_weekdays() as $day => $name ) {
		$row = isset( $settings['weekdays'][ $day ] ) && is_array( $settings['weekdays'][ $day ] ) ? $settings['weekdays'][ $day ] : array();

		if ( empty( $row['open'] ) ) {
			continue;
		}

		// A day whose close is not after its open is a day with no hours, which is how the
		// sanitiser stores an unreadable range (T15). Publishing it would be publishing nonsense.
		if ( bookify_booking_minutes( $row['to'] ) <= bookify_booking_minutes( $row['from'] ) ) {
			continue;
		}

		$specs[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $name,
			'opens'     => (string) $row['from'],
			'closes'    => (string) $row['to'],
		);
	}

	return $specs;
}

/**
 * The business itself, as a LocalBusiness node.
 *
 * Empty when nothing is stored, so a site whose owner has never opened the Settings screen
 * publishes no business at all rather than a node full of empty strings. The `@id` is what the
 * session nodes point back at, so the two read as one description of one business.
 *
 * @return array<string,mixed>
 */
function bookify_booking_schema_business() {
	$business = bookify_booking_business();

	$node = array(
		'@type' => 'LocalBusiness',
		'@id'   => home_url( '/#business' ),
		'url'   => home_url( '/' ),
	);

	if ( '' !== $business['name'] ) {
		$node['name'] = $business['name'];
	}

	if ( '' !== $business['phone'] ) {
		$node['telephone'] = $business['phone'];
	}

	if ( '' !== $business['email'] ) {
		$node['email'] = $business['email'];
	}

	$address = array_filter(
		array(
			'streetAddress'   => $business['street'],
			'addressLocality' => $business['locality'],
			'postalCode'      => $business['postcode'],
			'addressCountry'  => $business['country'],
		),
		'strlen'
	);

	if ( $address ) {
		$node['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
	}

	$hours = bookify_booking_schema_opening_hours();

	if ( $hours ) {
		$node['openingHoursSpecification'] = $hours;
	}

	// Nothing but the identifiers: the option is empty, so there is no business to describe.
	if ( count( $node ) <= 3 ) {
		return array();
	}

	return $node;
}

/**
 * One session, as a Service node with its price and length.
 *
 * The price is read from the meta and formatted without a thousands separator, because
 * `bookify_booking_service_price_and_length()` formats for an eye — "1,250.00" is not a price a
 * consumer can read. The currency comes from the payments option and is left out when it is not
 * configured, rather than being guessed.
 *
 * @param \WP_Post $session The session.
 * @return array<string,mixed>
 */
function bookify_booking_schema_session( $session ) {
	if ( ! $session instanceof WP_Post ) {
		return array();
	}

	$url = (string) get_permalink( $session );

	$node = array(
		'@type'    => 'Service',
		'name'     => $session->post_title,
		'provider' => array( '@id' => home_url( '/#business' ) ),
	);

	if ( '' !== $url ) {
		$node['url'] = $url;
	}

	$description = trim( wp_strip_all_tags( (string) $session->post_excerpt ) );

	if ( '' !== $description ) {
		$node['description'] = $description;
	}

	$price = get_post_meta( $session->ID, 'bookify_price', true );

	if ( '' !== $price && is_numeric( $price ) ) {
		$offer = array(
			'@type'        => 'Offer',
			'price'        => number_format( (float) $price, 2, '.', '' ),
			'availability' => 'https://schema.org/InStock',
		);

		if ( '' !== $url ) {
			$offer['url'] = $url;
		}

		$currency = (string) bookify_booking_payments()['currency'];

		if ( '' !== $currency ) {
			$offer['priceCurrency'] = strtoupper( $currency );
		}

		$node['offers'] = $offer;
	}

	$minutes = (int) get_post_meta( $session->ID, 'bookify_duration', true );

	if ( $minutes > 0 ) {
		// ISO 8601 duration, which is how schema.org states a length of time.
		$node['duration'] = 'PT' . $minutes . 'M';
	}

	return $node;
}

/**
 * Print the graph for the request being answered.
 *
 * The business goes on every front-end page, so any one page describes it without needing another
 * page's graph to have been read first; the session is added only on a session's own page.
 *
 * Encoded with JSON_HEX_TAG and JSON_HEX_AMP because this is JSON inside HTML: an owner who types
 * `</script>` into the business name would otherwise end the script element and have the rest of
 * their text parsed as markup. Both escapes are valid JSON, so a consumer decodes the value exactly
 * as it was stored.
 */
function bookify_booking_print_schema() {
	if ( is_admin() || is_feed() || is_404() || is_robots() || is_trackback() ) {
		return;
	}

	$graph = array();

	$business = bookify_booking_schema_business();

	if ( $business ) {
		$graph[] = $business;
	}

	if ( is_singular( 'bookify_service' ) ) {
		$session = bookify_booking_schema_session( get_post() );

		if ( $session ) {
			$graph[] = $session;
		}
	}

	if ( ! $graph ) {
		return;
	}

	$json = wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		),
		JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
	);

	if ( ! is_string( $json ) ) {
		return;
	}

	echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, encoded above with the tag escapes that make it safe inside a script element.
}

/**
 * Register the schema output.
 */
function bookify_booking_register_schema() {
	add_action( 'wp_head', 'bookify_booking_print_schema', 20 );
}
