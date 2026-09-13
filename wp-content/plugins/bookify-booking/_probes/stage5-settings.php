<?php
/**
 * Throwaway probe for T23/T24's settings side: the payment sanitiser, and the screen not printing a
 * stored key back. Deleted after use.
 */

$backup = get_option( BOOKIFY_PAYMENTS_OPTION, null );

function probe_clean( $value ) {
	return bookify_booking_sanitize_payments( $value );
}

function probe_show( $label, $value ) {
	if ( is_array( $value ) ) {
		$value = implode(
			' ',
			array_map(
				static function ( $key, $one ) {
					return $key . '=' . ( '' === $one ? '(empty)' : $one );
				},
				array_keys( $value ),
				$value
			)
		);
	}

	printf( "%-46s %s\n", $label, $value );
}

echo "-- what a submission does --\n";

probe_show( 'a good submission', probe_clean( array( 'currency' => 'gbp', 'expiry_minutes' => '45', 'secret_key' => 'sk_new', 'webhook_secret' => 'whsec_new' ) ) );
probe_show( 'a currency of two letters', probe_clean( array( 'currency' => 'US', 'expiry_minutes' => 30 ) ) );
probe_show( 'a currency of three letters, upper case', probe_clean( array( 'currency' => ' EUR ' ) ) );
probe_show( 'expiry below the floor (1)', probe_clean( array( 'currency' => 'gbp', 'expiry_minutes' => 1 ) ) );
probe_show( 'expiry above the ceiling (9999)', probe_clean( array( 'currency' => 'gbp', 'expiry_minutes' => 9999 ) ) );
probe_show( 'an expiry that is a word', probe_clean( array( 'currency' => 'gbp', 'expiry_minutes' => 'soon' ) ) );
probe_show( 'nothing at all (null)', probe_clean( null ) );
probe_show( 'a key submitted as an array', probe_clean( array( 'currency' => 'gbp', 'secret_key' => array( 'nope' ) ) ) );
probe_show( 'the remove checkbox', probe_clean( array( 'clear' => '1', 'currency' => 'gbp', 'secret_key' => 'sk_new' ) ) );

echo "\n-- an empty box keeps a stored key, and does not keep an empty currency --\n";

update_option( BOOKIFY_PAYMENTS_OPTION, array( 'currency' => 'eur', 'secret_key' => 'sk_stored', 'webhook_secret' => 'whsec_stored', 'expiry_minutes' => 20 ) );

probe_show( 'stored', bookify_booking_payments() );
probe_show( 'after saving two empty password boxes', probe_clean( array( 'currency' => 'eur', 'expiry_minutes' => 20, 'secret_key' => '', 'webhook_secret' => '' ) ) );
probe_show( 'after typing one new key', probe_clean( array( 'currency' => 'eur', 'expiry_minutes' => 20, 'secret_key' => 'sk_replaced', 'webhook_secret' => '' ) ) );

echo "\n-- the option round-trips through its own sanitiser --\n";

$shape = array( 'currency' => 'usd', 'secret_key' => 'sk_round', 'webhook_secret' => 'whsec_round', 'expiry_minutes' => 45 );

update_option( BOOKIFY_PAYMENTS_OPTION, $shape );

$stored = bookify_booking_payments();

probe_show( 'stored', $stored );
probe_show( 'identical after update_option()', $stored === $shape ? 'yes' : 'NO — ' . wp_json_encode( $stored ) );

echo "\n-- the screen never prints a stored key back --\n";

wp_set_current_user( 1 );

ob_start();
bookify_booking_render_settings_page();
$html = (string) ob_get_clean();

foreach ( array( 'sk_round' => 'the secret key', 'whsec_round' => 'the webhook secret' ) as $needle => $what ) {
	printf( "  %-18s appears in the screen: %s\n", $what, false === strpos( $html, $needle ) ? 'no' : 'YES (wrong)' );
}

printf( "  %-18s shown back: %s\n", 'the currency', false !== strpos( $html, 'usd' ) ? 'yes' : 'NO (wrong)' );
printf( "  %-18s shown back: %s\n", 'the payment window', false !== strpos( $html, 'value="45"' ) ? 'yes' : 'NO (wrong)' );
printf( "  %-18s the password inputs hold a value: %s\n", 'the key fields', preg_match( '/name="bookify_payments\[secret_key\]"\s+value=""/', $html ) && preg_match( '/name="bookify_payments\[webhook_secret\]"\s+value=""/', $html ) ? 'no' : 'YES (wrong)' );
printf( "  %-18s reports a key is stored: %s\n", 'the description', false !== strpos( $html, 'never shown again' ) ? 'yes' : 'NO (wrong)' );

// Cleanup.
if ( null === $backup ) {
	delete_option( BOOKIFY_PAYMENTS_OPTION );
} else {
	update_option( BOOKIFY_PAYMENTS_OPTION, $backup );
}

echo "\nrestored; bookify_payments is now " . var_export( get_option( BOOKIFY_PAYMENTS_OPTION, 'unset' ), true ) . "\n";
