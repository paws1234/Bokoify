<?php
/**
 * The connection string, parsed — every shape an operator is likely to paste.
 *
 * `includes/supabase/postgres.php` turns one environment variable into a PDO DSN, and the values in it
 * come from a dashboard's "Connect" panel, which prints five different strings for five different
 * purposes. Getting the wrong one produces a failure that looks like a wrong password, so the parsing
 * is worth testing on its own rather than only through a live connection — which is also the only way
 * to test it without a Supabase project.
 *
 * Nothing here prints a password. The DSN itself is safe to print: it names a host, a port, a database
 * and an sslmode, and carries no credential by construction.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/supabase-db.php
 *
 * @package bookify-booking
 */

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail Extra detail, usually why it failed.
 * @return void
 */
function sd_note( $label, $ok, $detail = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail ) );
}

/**
 * Parse one URL, with an optional separate password and sslmode.
 *
 * @param string $url      Database URL, or ''.
 * @param string $password Separate password, or ''.
 * @param string $sslmode  sslmode, or ''.
 * @return array
 */
function sd_parse( $url, $password = '', $sslmode = '' ) {
	$filters = array(
		'bookify_booking_supabase_db_url'      => $url,
		'bookify_booking_supabase_db_password' => $password,
		'bookify_booking_supabase_db_sslmode'  => $sslmode,
	);

	$callbacks = array();

	foreach ( $filters as $filter => $value ) {
		$callbacks[ $filter ] = static function () use ( $value ) {
			return $value;
		};

		add_filter( $filter, $callbacks[ $filter ] );
	}

	$db = bookify_booking_supabase_db();

	foreach ( $callbacks as $filter => $callback ) {
		remove_filter( $filter, $callback );
	}

	return $db;
}

$ref = 'ltpadcfsurvrgfvqipbh';

// ------------------------------------------------------ 1: the placeholder must not half-configure

// This is the exact string the Supabase dashboard shows before you reveal the password. Read as a
// password it would produce "password authentication failed"; read as absent, it says what is true.
$placeholder = sd_parse( "postgresql://postgres:[YOUR-PASSWORD]@db.{$ref}.supabase.co:5432/postgres" );

sd_note( 'the [YOUR-PASSWORD] placeholder is not a password', ! $placeholder['configured'] );
sd_note( 'and nothing at all is configured without one', ! sd_parse( '' )['configured'] );

// ------------------------------------------------------------------- 2: the working shapes

$with_password = sd_parse( "postgresql://postgres:sekret@db.{$ref}.supabase.co:5432/postgres" );

sd_note( 'a URL carrying its own password is configured', $with_password['configured'] );
sd_note( 'with the host, database and user read out of it', 'db.' . $ref . '.supabase.co' === $with_password['host'] && 'postgres' === $with_password['user'] && 'postgres' === $with_password['database'] );
sd_note( 'and TLS required, because Supabase mandates it', false !== strpos( $with_password['dsn'], 'sslmode=require' ), $with_password['dsn'] );
sd_note( 'the DSN carries no password of its own', false === strpos( $with_password['dsn'], 'sekret' ) );

// The point of two variables: the URL can live in a Blueprint, the password only in the environment.
$password_only = sd_parse( "postgresql://postgres@db.{$ref}.supabase.co:5432/postgres", 'from-the-environment' );

sd_note( 'a password supplied separately configures it', $password_only['configured'] && 'from-the-environment' === $password_only['password'] );

$overridden = sd_parse( "postgresql://postgres:stale@db.{$ref}.supabase.co:5432/postgres", 'from-the-environment' );

sd_note( 'and wins over the one in the URL', 'from-the-environment' === $overridden['password'], $overridden['password'] === 'stale' ? 'the URL won' : '' );

// The pooler, which is the shape that actually works — see the note in postgres.php about IPv6.
$pooler = sd_parse( "postgresql://postgres.{$ref}:sekret@aws-0-eu-central-1.pooler.supabase.com:6543/postgres" );

sd_note(
	'the Supavisor pooler string is accepted, username and all',
	$pooler['configured'] && "postgres.{$ref}" === $pooler['user'] && 'aws-0-eu-central-1.pooler.supabase.com' === $pooler['host'],
	$pooler['user']
);

$ipv6 = sd_parse( 'postgresql://postgres:sekret@[2406:da1c:16f1:f601::1]:5432/postgres' );

sd_note( 'and so is a bracketed IPv6 host', $ipv6['configured'] && false !== strpos( $ipv6['dsn'], '[' ) );

// A percent-encoded password is what a URL has to use for anything but alphanumerics.
$encoded = sd_parse( 'postgresql://postgres:p%40ss%3Aw0rd@db.' . $ref . '.supabase.co:5432/postgres' );

sd_note( 'a percent-encoded password is decoded', 'p@ss:w0rd' === $encoded['password'] );

// ------------------------------------------------------------------------ 3: the refusals

$mysql = sd_parse( 'mysql://postgres:sekret@db.example.com:3306/wordpress' );

sd_note( 'a MySQL URL is refused rather than attempted', ! $mysql['configured'] );

// The DSN is semicolon-separated, so a value with a semicolon in it adds a parameter. These are the
// values that would quietly turn TLS off on a connection carrying a database password.
$injected_host = sd_parse( "postgresql://postgres:sekret@evil;sslmode=disable:5432/postgres" );

sd_note( 'a host trying to inject a DSN parameter is refused', ! $injected_host['configured'] );

$injected_db = sd_parse( "postgresql://postgres:sekret@db.{$ref}.supabase.co:5432/postgres;sslmode=disable" );

sd_note( 'and so is a database name that does', ! $injected_db['configured'] );

// Whitelisted, so a typo falls back to the safe value rather than reaching the driver.
$bogus = sd_parse( "postgresql://postgres:sekret@db.{$ref}.supabase.co:5432/postgres", '', 'whatever' );

sd_note( 'an sslmode that is not one of the real ones falls back to require', $bogus['configured'] && 'require' === $bogus['sslmode'] );

$disabled = sd_parse( "postgresql://postgres:sekret@db.{$ref}.supabase.co:5432/postgres", '', 'disable' );

sd_note( 'while an explicitly requested disable is honoured, for a local server', 'disable' === $disabled['sslmode'] );
