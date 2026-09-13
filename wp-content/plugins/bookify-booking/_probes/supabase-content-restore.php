<?php
/**
 * Proves the site can be rebuilt from Supabase.
 *
 * This is the claim the content mirror exists to make, so it is the one worth testing directly. The
 * probe builds an **empty database with the real schema** — the closest thing to a fresh WordPress that
 * can be made without installing one — points `wpdb` at it, runs the restore, and then compares what
 * came back against what Supabase holds, column by column, for every row.
 *
 * Four things are checked:
 *
 *  1. Every table that was published comes back with exactly the row count Supabase holds.
 *  2. Every value in every one of those rows is the value Supabase stored. This is the round trip:
 *     MySQL → text → json → jsonb → text → MySQL. Counts alone would pass with every row present and
 *     every value wrong.
 *  3. A row inserted afterwards gets an id above the restored maximum, so the restored database is
 *     *usable* and not merely populated — an explicit-id `REPLACE` that left the auto-increment counter
 *     at 1 would collide on the first new page.
 *  4. The columns that must not travel are absent from the copy, and the account they leave behind
 *     cannot be logged into — an empty hash, which is what WordPress's schema defaults the column to.
 *  5. The prune would remove **nothing**. That is the safety net under `content push --prune`: a prune
 *     that found orphans on a site that is already in sync would delete the copy, at the end of an
 *     ordinary push. Asserted as a dry run, so this probe still writes nothing to Supabase.
 *
 * It runs against the live Supabase project, so it needs the built image (the dev container has no
 * `pdo_pgsql`):
 *
 *   wp eval-file .../_probes/supabase-content-restore.php
 *
 * It creates and drops a scratch database named `<this one>_restore_probe`. It never touches the real
 * one, and it never writes to Supabase.
 *
 * @package bookify-booking
 */

/**
 * Report one check, and count it.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail Extra detail, usually why it failed.
 * @return void
 */
function crp_note( $label, $ok, $detail = '' ) {
	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail ) );
}

/**
 * Say that a check could not be run here — which is not the same as it passing, and not the same as it
 * failing, so it gets a word of its own.
 *
 * @param string $label What could not be checked.
 * @param string $why   Why not.
 * @return void
 */
function crp_skip( $label, $why ) {
	fwrite( STDERR, sprintf( "[skip] %s — %s\n", $label, $why ) );
}

/**
 * An administrative connection, for the one thing this probe cannot do as the application user.
 *
 * Holding the scratch database and granting the application user access to it are both above
 * `wordpress`'s privileges: it holds rights on `wordpress.*` and on nothing else, so it can neither
 * create a database nor select one it has never been granted.
 *
 * `mysqli` directly, and not a second `wpdb`, because `wpdb` answers a connection it cannot make with
 * `wp_die()` — which would end the probe with an error page rather than a line saying `root` was not
 * available. The credentials are the ones `docker-compose.yml` gives the `db` service; a host that has
 * changed them can say so with `BOOKIFY_DB_ADMIN_USER` and `BOOKIFY_DB_ADMIN_PASSWORD`.
 *
 * @return mysqli|false
 */
function crp_admin() {
	$user = getenv( 'BOOKIFY_DB_ADMIN_USER' );
	$pass = getenv( 'BOOKIFY_DB_ADMIN_PASSWORD' );

	$user = is_string( $user ) && '' !== trim( $user ) ? trim( $user ) : 'root';
	$pass = is_string( $pass ) && '' !== trim( $pass ) ? trim( $pass ) : 'root';

	$host = defined( 'DB_HOST' ) ? (string) DB_HOST : 'localhost';
	$port = 3306;

	if ( str_contains( $host, ':' ) ) {
		list( $host, $port ) = explode( ':', $host, 2 );
	}

	try {
		$dbh = mysqli_connect( $host, $user, $pass, '', (int) $port );
	} catch ( \Throwable $e ) {
		return false;
	}

	return $dbh instanceof mysqli ? $dbh : false;
}

/**
 * The row of a table identified by one of its keys.
 *
 * @param string               $table Table name.
 * @param string[]             $key   Key columns.
 * @param array<string,string> $row   A row, used for the key values.
 * @return array<string,string>|null
 */
function crp_by_key( $table, array $key, array $row ) {
	global $wpdb;

	$where  = array();
	$params = array();

	foreach ( $key as $column ) {
		$where[]  = "`$column` = %s";
		$params[] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
	}

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE " . implode( ' AND ', $where ), $params ), ARRAY_A );
}

// `wp eval-file` evaluates this inside a method, so nothing WordPress sets up is in scope here by
// default — `$wpdb` included. Everything below expects the real one.
global $wpdb;

$live = (string) $wpdb->dbname;
$test = $live . '_restore_probe';
// ---- what Supabase holds, which is what has to come back --------------------------------------

$stored = bookify_booking_supabase_content_fetch();

if ( is_wp_error( $stored ) ) {
	crp_note( 'the content table can be read', false, $stored->get_error_message() );

	return;
}

crp_note( 'the content table can be read', array() !== $stored, sprintf( '%d row(s) stored', count( $stored ) ) );

if ( ! $stored ) {
	return;
}

$expected = array();

foreach ( $stored as $row ) {
	$table = isset( $row['table_name'] ) ? (string) $row['table_name'] : '';

	if ( '' !== $table ) {
		$expected[ $table ] = isset( $expected[ $table ] ) ? $expected[ $table ] + 1 : 1;
	}
}

// ---- an empty database with the real schema ----------------------------------------------------

// The live row counts are taken first, so the comparison at the end is against the source and not
// against something already half-written.
$live_counts = array();

foreach ( array_keys( $expected ) as $table ) {
	$live_counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
}

$admin = crp_admin();

if ( ! $admin ) {
	crp_skip(
		sprintf( 'rebuilding the site from Supabase (%d row(s) stored)', count( $stored ) ),
		'no administrative database connection, so a scratch database cannot be made — set BOOKIFY_DB_ADMIN_USER / BOOKIFY_DB_ADMIN_PASSWORD'
	);

	return;
}

mysqli_query( $admin, "DROP DATABASE IF EXISTS `$test`" );

if ( ! mysqli_query( $admin, "CREATE DATABASE `$test`" ) ) {
	crp_note( 'a scratch database can be created', false, mysqli_error( $admin ) );
	mysqli_close( $admin );

	return;
}

$copied = 0;

foreach ( array_keys( $expected ) as $table ) {
	if ( ! mysqli_query( $admin, "CREATE TABLE `$test`.`$table` LIKE `$live`.`$table`" ) ) {
		crp_note( sprintf( 'the schema for %s is copied', $table ), false, mysqli_error( $admin ) );
		mysqli_close( $admin );

		return;
	}

	++$copied;
}

// The application user has to be allowed into it. Without this, `$wpdb->select()` fails with
// "Access denied" and the probe would look broken for a reason that has nothing to do with the code.
if ( ! mysqli_query( $admin, sprintf( 'GRANT ALL ON `%s`.* TO %s', $test, "'" . mysqli_real_escape_string( $admin, DB_USER ) . "'@'%'" ) ) ) {
	crp_note( 'the application user is granted access to it', false, mysqli_error( $admin ) );
	mysqli_close( $admin );

	return;
}

crp_note(
	sprintf( 'an empty database with the real schema was made (%d table(s) copied)', $copied ),
	true,
	$test
);

// And point WordPress at it. This is the switch that makes the rest of the probe a genuine rebuild
// rather than a restatement of what is already there.
//
// Two things about `wpdb::select()` are worth knowing. It returns **nothing** — it is a
// `mysqli_select_db()` that calls `wp_die()` on failure — so `! $wpdb->select( $test )` is true however
// well it went. And it never updates `$wpdb->dbname`, which goes on saying `wordpress` while the
// connection is somewhere else entirely. So the only honest check is to ask the server where the
// connection is, which is what `DATABASE()` answers.
$wpdb->select( $test );

crp_note(
	'WordPress is now pointed at the empty database',
	$test === (string) $wpdb->get_var( 'SELECT DATABASE()' ),
	sprintf( 'DATABASE() says %s', (string) $wpdb->get_var( 'SELECT DATABASE()' ) )
);

$empty = 0;

foreach ( array_keys( $expected ) as $table ) {
	$empty += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
}

crp_note( 'and it holds nothing at all', 0 === $empty, sprintf( '%d row(s)', $empty ) );

// ---- the restore ------------------------------------------------------------------------------

$restored = bookify_booking_supabase_content_restore();

if ( is_wp_error( $restored ) ) {
	crp_note( 'the restore ran', false, $restored->get_error_message() );

	$wpdb->select( $live );
	mysqli_query( $admin, "DROP DATABASE IF EXISTS `$test`" );
	mysqli_close( $admin );

	return;
}

crp_note(
	'the restore ran',
	0 === $restored['count'],
	$restored['count'] ? sprintf( '%d row(s) failed: %s', $restored['count'], implode( ' | ', $restored['failures'] ) ) : ''
);

// ---- 1: the counts ----------------------------------------------------------------------------

$wrong_counts = array();

foreach ( $expected as $table => $count ) {
	$back = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );

	if ( $back !== $count ) {
		$wrong_counts[] = sprintf( '%s: %d stored, %d restored', $table, $count, $back );
	}
}

crp_note(
	sprintf( 'all %d row(s) across %d table(s) came back', array_sum( $expected ), count( $expected ) ),
	array() === $wrong_counts,
	implode( '; ', $wrong_counts )
);

// ---- 2: the values, which is the part counts cannot see ---------------------------------------

$compared   = 0;
$mismatched = array();

foreach ( $stored as $entry ) {
	$table = isset( $entry['table_name'] ) ? (string) $entry['table_name'] : '';
	$data  = bookify_booking_supabase_content_data( (array) $entry );

	if ( '' === $table || ! $data ) {
		continue;
	}

	$key = bookify_booking_supabase_content_key( $table );
	$row = crp_by_key( $table, $key, $data );

	if ( ! $row ) {
		$mismatched[] = $table . ':' . ( isset( $entry['row_id'] ) ? $entry['row_id'] : '?' ) . ' (no row came back)';

		continue;
	}

	foreach ( $data as $column => $expected_value ) {
		++$compared;

		$actual = isset( $row[ $column ] ) ? $row[ $column ] : null;

		// Both sides are strings, but a NULL and an empty string are different facts and MySQL is happy
		// to report either. Compared strictly, and only the lengths are printed when they differ.
		if ( (string) $actual !== (string) $expected_value
			|| ( null === $actual ) !== ( null === $expected_value ) ) {
			$mismatched[] = sprintf(
				'%s:%s.%s stored %d bytes, restored %d',
				$table,
				isset( $entry['row_id'] ) ? $entry['row_id'] : '?',
				$column,
				strlen( (string) $expected_value ),
				strlen( (string) $actual )
			);
		}
	}
}

crp_note(
	sprintf( '%d value(s) restored exactly as stored', $compared ),
	array() === $mismatched,
	implode( '; ', array_slice( $mismatched, 0, 5 ) )
);

// Grouped out of the rows already in hand rather than asked for again: every read of `bookify_content`
// is a round trip to Sydney.
$elementor = 0;
$layouts   = 0;
$types     = array();
$pages     = 0;

foreach ( $stored as $entry ) {
	$table = isset( $entry['table_name'] ) ? (string) $entry['table_name'] : '';
	$data  = bookify_booking_supabase_content_data( (array) $entry );

	if ( 'wp_postmeta' === $table && isset( $data['meta_key'] ) && '_elementor_data' === $data['meta_key'] ) {
		$elementor += isset( $data['meta_value'] ) ? strlen( $data['meta_value'] ) : 0;
		++$layouts;
	}

	if ( 'wp_posts' === $table && ! empty( $data['post_type'] ) ) {
		$types[ $data['post_type'] ] = isset( $types[ $data['post_type'] ] ) ? $types[ $data['post_type'] ] + 1 : 1;

		if ( 'page' === $data['post_type'] ) {
			++$pages;
		}
	}
}

// The layouts specifically: the biggest values in the copy, and the ones whose truncation would show as
// a half-built page rather than as an error.
crp_note(
	sprintf( '%d Elementor layout(s) arrived intact (%s of JSON)', $layouts, size_format( $elementor ) ),
	$layouts > 0 && $elementor > 0
);

$order = array();

foreach ( $types as $type => $count ) {
	$order[] = $type . '=' . $count;
}

sort( $order );

crp_note(
	sprintf( 'and %d page(s) of %d post(s) in total', $pages, array_sum( $types ) ),
	$pages > 0,
	implode( ', ', $order )
);

// Read straight out of the restored database rather than through `get_option()`. WordPress loads the
// autoloaded options into the object cache at `init`, which has already run by now — so `get_option()`
// here would answer with the *live* value and the check would prove nothing.
$front = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', 'page_on_front' ) );

crp_note(
	'a restored option is readable in the restored database',
	is_string( $front ),
	'page_on_front=' . var_export( $front, true )
);

// ---- 3: is the restored database usable, or only populated? -----------------------------------

$highest = (int) $wpdb->get_var( "SELECT MAX(ID) FROM wp_posts" );
$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO wp_posts (post_title, post_status, post_type) VALUES (%s, 'draft', 'post')", 'restore probe' ) );
$new_id  = (int) $wpdb->insert_id;

crp_note(
	sprintf( 'a new row gets id %d, above the restored maximum of %d', $new_id, $highest ),
	1 === $inserted && $new_id > $highest
);

// ---- 4: what must not have travelled ----------------------------------------------------------

$login       = (string) $wpdb->get_var( 'SELECT user_login FROM wp_users LIMIT 1' );
$copy_pass   = (string) $wpdb->get_var( 'SELECT user_pass FROM wp_users LIMIT 1' );
$source_pass = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM `$live`.`wp_users` LIMIT 1" ) );

crp_note( 'the account came back', '' !== $login, 'user_login=' . $login );

// The copy carries no password hashes, on purpose. WordPress's schema gives `user_pass` a default of the
// empty string, so MySQL fills it in and the row inserts cleanly — and the account ends up unusable,
// which is the intent and is checked below rather than assumed.
crp_note(
	'and it has no password hash — the real one did not travel',
	'' === $copy_pass && '' !== $source_pass,
	sprintf( 'the source hash is %d characters, the restored one is %d', strlen( $source_pass ), strlen( $copy_pass ) )
);

// Measured, not reasoned about: `wp_check_password()` is the function that decides whether a hash is a
// way in, and an empty one is not — not with an empty password and not with any other.
crp_note(
	'so an empty hash cannot be logged into',
	false === wp_check_password( '', $copy_pass ) && false === wp_check_password( 'password', $copy_pass ),
	sprintf( "empty='%s' wrong='%s'", var_export( wp_check_password( '', $copy_pass ), true ), var_export( wp_check_password( 'password', $copy_pass ), true ) )
);

$tokens = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key = 'session_tokens'" );

crp_note( 'no login session was restored', 0 === $tokens );

$cron = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_options WHERE option_name = 'cron'" );

crp_note( 'and no cron schedule either', 0 === $cron );

// ---- leave the live database as it was found --------------------------------------------------

$wpdb->select( $live );

mysqli_query( $admin, "DROP DATABASE IF EXISTS `$test`" );
mysqli_close( $admin );

$still_there = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_posts" );

crp_note(
	sprintf( 'the scratch database is gone and the real one still holds %d post(s)', $still_there ),
	$still_there === $live_counts['wp_posts'],
	sprintf( 'was %d', $live_counts['wp_posts'] )
);

// ---- 5: the prune, which is the one thing here that can destroy the copy -----------------------
//
// A **dry run**, and deliberately: it reads the copy and works out what it would remove, and writes
// nothing — which is what makes it safe to run against a live project, and why this probe has been able
// to promise it never writes to Supabase.
//
// The property worth asserting is the negative one. A prune that reported orphans on a site that is in
// sync would delete the copy, and it would do it at the end of an ordinary `content push --prune`. So:
// nothing planned for removal, and every table's stored count equal to its local count.

$planned  = bookify_booking_supabase_content_prune( array(), true );
$removed  = 0;
$orphans  = 0;
$skipped  = array();
$drifted  = array();

foreach ( (array) $planned as $table => $result ) {
	$removed += (int) $result['removed'];
	$orphans += (int) $result['orphans'];

	if ( '' !== $result['note'] ) {
		$skipped[] = $table . ': ' . $result['note'];
	}

	if ( (int) $result['stored'] !== (int) $result['local'] ) {
		$drifted[] = sprintf( '%s has %d stored and %d here', $table, $result['stored'], $result['local'] );
	}
}

crp_note(
	sprintf( 'the prune can plan without writing anything (%d table(s) checked, dry run)', count( (array) $planned ) ),
	is_array( $planned ) && $planned
);

crp_note( 'and a dry run removes nothing', 0 === $removed );

crp_note(
	'and finds no orphan on a site that is in sync, so it cannot delete the copy by accident',
	0 === $orphans && array() === $drifted,
	implode( '; ', array_merge( $drifted, $skipped ) )
);

crp_note(
	'and every table it looked at had its rows read without a refusal',
	array() === $skipped,
	implode( '; ', $skipped )
);
