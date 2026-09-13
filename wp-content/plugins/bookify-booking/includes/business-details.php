<?php
/**
 * The business's own details.
 *
 * Stored once in the bookify_business option and printed from one function, so the footer
 * and the Contact page cannot drift apart. Stage 6 reads the same option for LocalBusiness
 * schema (plan D7: one option, one screen).
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The fields the business details hold: key => label and input type.
 *
 * The one list both the settings screen and the sanitiser read, so a field cannot be
 * rendered and then silently dropped on save.
 *
 * @return array<string,array{label:string,type:string}>
 */
function bookify_booking_business_fields() {
	return array(
		'name'     => array(
			'label' => __( 'Business name', 'bookify-booking' ),
			'type'  => 'text',
		),
		'street'   => array(
			'label' => __( 'Street address', 'bookify-booking' ),
			'type'  => 'text',
		),
		'locality' => array(
			'label' => __( 'Town or city', 'bookify-booking' ),
			'type'  => 'text',
		),
		'postcode' => array(
			'label' => __( 'Postcode', 'bookify-booking' ),
			'type'  => 'text',
		),
		'country'  => array(
			'label' => __( 'Country', 'bookify-booking' ),
			'type'  => 'text',
		),
		'phone'    => array(
			'label' => __( 'Phone', 'bookify-booking' ),
			'type'  => 'text',
		),
		'email'    => array(
			'label' => __( 'Email', 'bookify-booking' ),
			'type'  => 'email',
		),
	);
}

/**
 * The stored business details, with every field present.
 *
 * @return array<string,string>
 */
function bookify_booking_business() {
	$stored = get_option( 'bookify_business', array() );
	$stored = is_array( $stored ) ? $stored : array();

	$business = array();

	foreach ( array_keys( bookify_booking_business_fields() ) as $key ) {
		$business[ $key ] = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? $stored[ $key ] : '';
	}

	return $business;
}

/**
 * The business details as markup, or '' when nothing is stored.
 *
 * The opening hours are appended from bookify_booking_opening_hours_html(), which reads the
 * availability option: the hours a visitor reads here are the hours the booking form generates
 * its slots from, so the two cannot drift (T15, acceptance 4).
 *
 * @return string
 */
function bookify_booking_business_details_html() {
	$business = bookify_booking_business();

	$address = array_filter(
		array( $business['street'], $business['locality'], $business['postcode'], $business['country'] ),
		'strlen'
	);

	$lines = array();

	if ( '' !== $business['name'] ) {
		$lines[] = '<strong class="bookify-business__name">' . esc_html( $business['name'] ) . '</strong>';
	}

	if ( $address ) {
		$lines[] = esc_html( implode( ', ', $address ) );
	}

	if ( '' !== $business['phone'] ) {
		// Dialable form for the link, printed form for the eye.
		$lines[] = sprintf(
			'<a class="bookify-business__phone" href="%s">%s</a>',
			esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $business['phone'] ) ),
			esc_html( $business['phone'] )
		);
	}

	if ( '' !== $business['email'] ) {
		$lines[] = sprintf(
			'<a class="bookify-business__email" href="%s">%s</a>',
			esc_url( 'mailto:' . $business['email'] ),
			esc_html( $business['email'] )
		);
	}

	$lines = array_map(
		static function ( $line ) {
			return '<span class="bookify-business__line">' . $line . '</span>';
		},
		$lines
	);

	// Both halves are escaped where they are built.
	$hours = bookify_booking_opening_hours_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	if ( ! $lines && '' === $hours ) {
		return '';
	}

	return '<address class="bookify-business">' . implode( '', $lines ) . $hours . '</address>';
}

/**
 * Print the details at the end of every page.
 *
 * On wp_footer rather than inside the theme's <footer>: that template has no hook, and
 * adding a template file to the theme is out of scope for this stage (plan D10).
 */
function bookify_booking_business_details_footer() {
	// Escaped in bookify_booking_business_details_html().
	echo bookify_booking_business_details_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render the details from a shortcode, so the Contact page can print them.
 *
 * @return string
 */
function bookify_booking_business_details_shortcode() {
	return bookify_booking_business_details_html();
}

/**
 * Register the details block: its shortcode, its place in the footer and its stylesheet.
 */
function bookify_booking_register_business_details() {
	add_shortcode( 'bookify_business_details', 'bookify_booking_business_details_shortcode' );
	add_action( 'wp_footer', 'bookify_booking_business_details_footer' );
	add_action(
		'wp_enqueue_scripts',
		static function () {
			wp_enqueue_style(
				'bookify-business-details',
				BOOKIFY_BOOKING_URL . 'assets/business-details.css',
				array(),
				BOOKIFY_BOOKING_VERSION
			);
		}
	);
}
