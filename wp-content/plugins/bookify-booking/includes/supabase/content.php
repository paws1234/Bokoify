<?php
/**
 * WordPress's own tables, carried to Supabase a row at a time.
 *
 * `transform.php` publishes the booking domain: sessions, events, tiers, bookings, customers, the
 * settings row, and the images. All of that is *data about bookings*. It says nothing about what the
 * site **is** — the pages, the Elementor layouts those pages are built from, the menus, the options
 * holding the business details, the user accounts. Those live in WordPress's own tables, and that is
 * the part that cannot be recreated by hand and cannot be got in on a host with no Shell.
 *
 * This file is how it gets in. Every row of every prefixed table is copied to `bookify_content`, and
 * `wp bookify-supabase content restore` puts them back.
 *
 * **Why not read the database back live instead.** Because WordPress is MySQL dialect from top to
 * bottom and Supabase is Postgres, and the gap is not one of syntax. Measured against a real Postgres
 * 16: of the fourteen SQL shapes core actually uses, eleven fail outright —
 * `SQL_CALC_FOUND_ROWS` (WP_Query's pagination), `` `backticks` ``, `LIMIT 0, 10`,
 * `CAST(meta_value AS SIGNED)` (`WP_Meta_Query`), `DATE_FORMAT`, `GROUP_CONCAT`,
 * `INSERT ... ON DUPLICATE KEY UPDATE` (`add_option`, `wp_set_object_terms`), `REGEXP`
 * (`WP_User_Query`'s capability filter), `SHOW TABLES LIKE`, `RAND()`. Those are loud and could be
 * translated. The other three are the reason this file is not a live backend:
 *
 *   - `'abc' = 'ABC'` is **true** on this site (the tables are `utf8mb4_unicode_520_ci`) and **false**
 *     on Postgres. A login typed in the wrong case works on MySQL and would not on Postgres.
 *   - `post_title LIKE 'book%'` matches **12 rows** here and **0** on Postgres.
 *   - `meta_value = 45` against a `longtext` column returns **4 rows** on MySQL and is a hard
 *     `operator does not exist: text = integer` on Postgres.
 *
 * A translator can rewrite syntax. Re-implementing MySQL's collation means rewriting *every* comparison
 * on *every* text column, and a mistake there does not raise an error — it silently returns a different
 * answer. So the translation happens once, on the way out and on the way back, where it can be checked:
 * restore into a fresh database and compare row counts. That is why this is a copy and not a driver.
 *
 * **Why JSON and not a typed column per WordPress column.** These are not this plugin's tables. Core
 * gains columns between releases and any plugin may add a table of its own — `wp_e_events` is
 * Elementor's and nobody declared it. A typed mirror would need a migration on every WordPress upgrade
 * and would silently drop what it had not been told about. One `jsonb` column copies whatever is there.
 *
 * **Why every value is a string.** Because `wpdb` hands them over that way and MySQL takes them back:
 * measured, `SELECT ID, post_parent FROM wp_posts` returns `string` for both. The round trip is text →
 * json → text on both sides, so there is no type to guess in either direction and nothing to coerce.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many rows are read from WordPress at a time.
 *
 * A page of rows rather than all of them, because a table with a decade of revisions in it should not
 * have to fit in memory to be copied.
 */
const BOOKIFY_SUPABASE_CONTENT_BATCH = 500;

/**
 * The columns that never leave this site, in whatever table they appear.
 *
 * `user_pass` is a password hash and `user_activation_key` is a working password-reset token. Neither
 * belongs in a third party's database, and the project has held that line since the mirror was built
 * — `bookify_customers` is a named column list for the same reason.
 *
 * The consequence is deliberate and is reported rather than hidden: a restored `wp_users` row has no
 * usable password. WordPress's own schema gives `user_pass` a default of the empty string, so the row
 * inserts cleanly and the account is left with an empty hash — and **measured**, `wp_check_password()`
 * answers false for an empty hash against an empty password and against every other one. So the
 * account exists and cannot be logged into, and `wp user update --user_pass` is how it is made usable.
 * A restore that quietly carried a known password would be worse.
 *
 * @return string[]
 */
function bookify_booking_supabase_content_private_columns() {
	return array( 'user_pass', 'user_activation_key', 'session_tokens' );
}

/**
 * The options that are not content.
 *
 * `elementor_log` is a debug log of things that went wrong on a machine that no longer exists. There is
 * nothing in it to restore — and it is also the one row in this database that cannot be stored: the
 * value is a PHP-serialised object, and `\0*\0` private-member markers put a raw NUL byte inside it,
 * which `jsonb` cannot represent. Postgres has no character type that holds a NUL; this is not a
 * WordPress or Elementor problem, it is a Postgres one.
 *
 * Measured on this site: it is the **only** such row, in any table. That is why there is no escaping
 * scheme here — the general case does not occur, and inventing a transform to handle it would be a way
 * to corrupt content that nothing has been tested against. A row that does have a NUL is *refused by
 * name* instead; see the check in `bookify_booking_supabase_content_rows()`. If one ever turns up in
 * something that matters, the report says which row and which column, and an escape can be designed
 * against a real example.
 *
 * @return string[]
 */
function bookify_booking_supabase_content_non_content_options() {
	return array( 'elementor_log' );
}

/**
 * Whether one WordPress row is content, or is something that must not travel.
 *
 * Three kinds of row are refused, and none of them is content: the cron schedule (a list of pending
 * events, meaningless anywhere else), transients (a cache with an expiry — restoring them restores
 * stale answers), and stored login sessions.
 *
 * @param string               $table Table name.
 * @param array<string,string> $row   The row.
 * @return bool True when the row must not be published.
 */
function bookify_booking_supabase_content_skip( $table, array $row ) {
	global $wpdb;

	if ( $wpdb->options === $table ) {
		$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';

		if ( in_array( $name, array( 'cron', BOOKIFY_SUPABASE_LAST_RESULT_OPTION ), true ) ) {
			return true;
		}

		if ( in_array( $name, bookify_booking_supabase_content_non_content_options(), true ) ) {
			return true;
		}

		if ( str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) ) {
			return true;
		}
	}

	if ( $wpdb->usermeta === $table ) {
		$key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';

		if ( in_array( $key, bookify_booking_supabase_content_private_columns(), true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Every table in this database that belongs to this install.
 *
 * Discovered from `information_schema` rather than listed here, and filtered to the install's own
 * prefix. That is the whole point: a table a plugin added is copied without this file having to know
 * it exists, which is how `wp_e_events` would be covered.
 *
 * @return string[]
 */
function bookify_booking_supabase_content_tables() {
	global $wpdb;

	$names = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s
			 ORDER BY TABLE_NAME",
			$wpdb->esc_like( $wpdb->prefix ) . '%'
		)
	);

	return array_values( array_filter( array_map( 'strval', $names ) ) );
}

/**
 * A table's primary key columns, in order.
 *
 * From `information_schema` rather than assumed, because `wp_term_relationships` has a two-column key
 * and a plugin's table may have none at all.
 *
 * @param string $table Table name.
 * @return string[]
 */
function bookify_booking_supabase_content_key( $table ) {
	global $wpdb;

	$columns = (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'PRIMARY'
			 ORDER BY ORDINAL_POSITION",
			$table
		)
	);

	return array_values( array_filter( array_map( 'strval', $columns ) ) );
}

/**
 * A table's columns, in order.
 *
 * Used by the restore, which has to drop a stored column the local table does not have — restoring a
 * row written by a newer WordPress into an older schema would otherwise be a hard error.
 *
 * @param string $table Table name.
 * @return string[]
 */
function bookify_booking_supabase_content_columns( $table ) {
	global $wpdb;

	$columns = (array) $wpdb->get_col(
		$wpdb->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
			$table
		)
	);

	return array_values( array_filter( array_map( 'strval', $columns ) ) );
}

/**
 * The columns a restored row must supply, because MySQL will not invent them.
 *
 * `NOT NULL`, no default, and not auto-increment. `wp_users.user_pass` is the one that matters: it is
 * deliberately stripped before publishing, and putting the row back without it is a hard error.
 *
 * @param string $table Table name.
 * @return string[]
 */
function bookify_booking_supabase_content_required( $table ) {
	global $wpdb;

	$rows = (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT COLUMN_NAME FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
			   AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%%auto_increment%%'",
			$table
		),
		ARRAY_A
	);

	$columns = array();

	foreach ( $rows as $row ) {
		$columns[] = (string) $row['COLUMN_NAME'];
	}

	return $columns;
}

/**
 * One row's identity in the mirror.
 *
 * The primary key values joined with a colon. A table with no primary key at all — which a plugin may
 * have — falls back to a hash of the row, which is stable for a row that does not change and is the
 * best that can be done without an identity to use.
 *
 * @param string[]             $key   Primary key columns.
 * @param array<string,string> $row   The row.
 * @return string
 */
function bookify_booking_supabase_content_row_id( array $key, array $row ) {
	if ( ! $key ) {
		return 'hash:' . md5( (string) wp_json_encode( $row ) );
	}

	$parts = array();

	foreach ( $key as $column ) {
		$parts[] = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
	}

	return implode( ':', $parts );
}

/**
 * Every row of one WordPress table, shaped for `bookify_content`.
 *
 * Read in pages. Skips the rows and strips the columns that must not travel, and refuses to publish a
 * row that will not encode — a single malformed byte would otherwise make the whole batch fail to
 * insert, and the batch is the unit that either lands or does not.
 *
 * The connection is `utf8mb4`, so MySQL has already converted every value into valid UTF-8 and the
 * encode should never fail. It is checked rather than assumed because the failure would be a rejected
 * batch five tables later, blamed on whichever query failed.
 *
 * A NUL byte is refused outright, because `jsonb` cannot hold one and the alternative — storing
 * something that is not the value — is how content goes missing without anyone noticing. See
 * `bookify_booking_supabase_content_non_content_options()` for why there is no escaping scheme.
 *
 * @param string $table Table name.
 * @return array{rows:array<int,array<string,mixed>>,refused:string[]}
 */
function bookify_booking_supabase_content_rows( $table ) {
	global $wpdb;

	// The table name came out of `information_schema` a moment ago, and is checked anyway before it is
	// interpolated into a statement — the same rule the Postgres driver applies to its own names.
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
		return array(
			'rows'    => array(),
			'refused' => array( $table . ' (not a plain table name)' ),
		);
	}

	$key     = bookify_booking_supabase_content_key( $table );
	$stamp   = gmdate( 'c' );
	$rows    = array();
	$refused = array();
	$offset  = 0;

	while ( true ) {
		$page = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", BOOKIFY_SUPABASE_CONTENT_BATCH, $offset ),
			ARRAY_A
		);

		if ( ! $page ) {
			break;
		}

		foreach ( $page as $row ) {
			if ( bookify_booking_supabase_content_skip( $table, $row ) ) {
				continue;
			}

			foreach ( bookify_booking_supabase_content_private_columns() as $column ) {
				unset( $row[ $column ] );
			}

			$id = bookify_booking_supabase_content_row_id( $key, $row );

			$nul = '';

			foreach ( $row as $column => $value ) {
				if ( is_string( $value ) && str_contains( $value, "\0" ) ) {
					$nul = (string) $column;

					break;
				}
			}

			if ( '' !== $nul ) {
				$refused[] = $table . ':' . $id . ' (' . $nul . ' holds a NUL byte, which jsonb cannot store)';

				continue;
			}

			if ( false === wp_json_encode( $row ) ) {
				$refused[] = $table . ':' . $id . ' (a value is not valid UTF-8)';

				continue;
			}

			$rows[] = array(
				'table_name' => $table,
				'row_id'     => $id,
				'data'       => $row,
				'updated_at' => $stamp,
			);
		}

		if ( count( $page ) < BOOKIFY_SUPABASE_CONTENT_BATCH ) {
			break;
		}

		$offset += BOOKIFY_SUPABASE_CONTENT_BATCH;
	}

	return array(
		'rows'    => $rows,
		'refused' => $refused,
	);
}

/**
 * Publish every WordPress row to `bookify_content`.
 *
 * Not hooked to anything, and deliberately: core writes `wp_options` on almost every request — a
 * transient, a cache key — and mirroring those writes one at a time would be a request to Supabase per
 * page view to store something with an expiry on it. Content changes when somebody edits a page, so it
 * is published on demand and by the daily reconciliation, which is often enough to stay current and
 * rare enough not to matter.
 *
 * @param string[] $only Optional. Table names to publish. Default: all of them.
 * @return array<string,array{rows:int,refused:int,reasons:string[],pushed:bool,error:string}>
 */
function bookify_booking_supabase_content_push( array $only = array() ) {
	$report = array();

	foreach ( bookify_booking_supabase_content_tables() as $table ) {
		if ( $only && ! in_array( $table, $only, true ) ) {
			continue;
		}

		$built = bookify_booking_supabase_content_rows( $table );

		$report[ $table ] = array(
			'rows'    => count( $built['rows'] ),
			'refused' => count( $built['refused'] ),
			// The first few by name. A refusal is a row that will not be in the copy, and knowing which one
			// is the difference between "a row is missing" and "this specific row is missing because of a
			// NUL byte in that column".
			'reasons' => array_slice( $built['refused'], 0, 5 ),
			'pushed'  => false,
			'error'   => '',
		);

		if ( ! $built['rows'] ) {
			continue;
		}

		$result = bookify_booking_supabase_upsert( 'bookify_content', $built['rows'], array( 'table_name', 'row_id' ) );

		if ( is_wp_error( $result ) ) {
			$report[ $table ]['error'] = $result->get_error_message();

			continue;
		}

		$report[ $table ]['pushed'] = true;
	}

	return $report;
}

/**
 * The stored rows, as a flat list.
 *
 * @param string $table Optional. One WordPress table. Default: every row of every table.
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function bookify_booking_supabase_content_fetch( $table = '' ) {
	// No `order by`: this table is keyed on ( table_name, row_id ) and has no `id` to sort by, and the
	// order rows come back in does not matter to either caller — one counts them per table, the other
	// writes each one with `REPLACE`.
	$rows = bookify_booking_supabase_select( 'bookify_content', array(), '' );

	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	if ( '' === $table ) {
		return (array) $rows;
	}

	$wanted = array();

	foreach ( (array) $rows as $row ) {
		if ( isset( $row['table_name'] ) && $table === (string) $row['table_name'] ) {
			$wanted[] = $row;
		}
	}

	return $wanted;
}

/**
 * The `data` of a stored row, as an array.
 *
 * A `jsonb` column arrives as a **string** through `pdo_pgsql` and as an **array** through PostgREST,
 * the same way `bookify_media.files` does, so one of the two transports has to be decoded here.
 *
 * @param array<string,mixed> $stored A row of `bookify_content`.
 * @return array<string,string>
 */
function bookify_booking_supabase_content_data( array $stored ) {
	$data = isset( $stored['data'] ) ? $stored['data'] : array();

	if ( is_string( $data ) ) {
		$decoded = json_decode( $data, true );
		$data    = is_array( $decoded ) ? $decoded : array();
	}

	if ( ! is_array( $data ) ) {
		return array();
	}

	$clean = array();

	foreach ( $data as $column => $value ) {
		// A JSON object decodes to a string-keyed array; a JSON *array* would decode to a list, which is
		// not a row. Refusing anything with a numeric key keeps a malformed value from becoming a
		// statement about a column called "0".
		if ( is_string( $column ) ) {
			$clean[ $column ] = null === $value ? null : (string) $value;
		}
	}

	return $clean;
}

/**
 * Put the WordPress tables back.
 *
 * A `REPLACE INTO` per row, which is what makes this safe to re-run: the stored copy wins, and a row
 * that is already right is rewritten with the values it has. Run it against a database that is empty —
 * a fresh install — for a predictable result; against a populated one it will still converge, because
 * `REPLACE` deletes whatever conflicts on any unique key before inserting.
 *
 * WordPress core declares **no foreign keys**, so the tables can be restored in any order and
 * alphabetical is as good as any.
 *
 * @param string[] $only    Optional. WordPress tables to restore. Default: all of them.
 * @param bool     $dry_run Optional. Work out what would happen without writing.
 * @return array<string,mixed>|\WP_Error
 */
function bookify_booking_supabase_content_restore( array $only = array(), $dry_run = false ) {
	global $wpdb;

	$fetched = bookify_booking_supabase_content_fetch();

	if ( is_wp_error( $fetched ) ) {
		return $fetched;
	}

	$here     = bookify_booking_supabase_content_tables();
	$report   = array();
	$unknown  = array();
	$unusable = array();
	$failures = array();

	foreach ( $fetched as $stored ) {
		$table = isset( $stored['table_name'] ) ? (string) $stored['table_name'] : '';

		if ( '' === $table || ( $only && ! in_array( $table, $only, true ) ) ) {
			continue;
		}

		if ( ! isset( $report[ $table ] ) ) {
			$report[ $table ] = array(
				'stored'   => 0,
				'written'  => 0,
				'repaired' => 0,
				'failed'   => 0,
				'error'    => '',
			);
		}

		++$report[ $table ]['stored'];

		// A table this database does not have. Elementor's, on a site where Elementor is not installed
		// yet; or a plugin's that has been removed. Reported once and skipped, rather than failing per row.
		if ( ! in_array( $table, $here, true ) ) {
			$unknown[ $table ] = true;

			continue;
		}

		$data = bookify_booking_supabase_content_data( $stored );

		if ( ! $data ) {
			++$report[ $table ]['failed'];

			continue;
		}

		// Drop a column the local table does not have — a row written by a newer WordPress, being put
		// back into an older one.
		$local = bookify_booking_supabase_content_columns( $table );

		foreach ( array_keys( $data ) as $column ) {
			if ( ! in_array( $column, $local, true ) ) {
				unset( $data[ $column ] );
			}
		}

		// And supply the ones MySQL will not invent — a `NOT NULL` column with no default, which the row
		// cannot carry because it was stripped before publishing, and which MySQL will not fill in.
		//
		// `user_pass` deliberately does **not** arrive here, and the reason is worth knowing: WordPress's
		// schema declares it `NOT NULL DEFAULT ''`, so MySQL supplies the empty string and the row inserts
		// cleanly. That is not a way in — `wp_check_password()` was measured against an empty hash and
		// answers false for an empty password and for every other one — so the restored account simply
		// cannot be logged into. This guard is for the column that has no default at all, which would
		// otherwise be a rejected row.
		foreach ( bookify_booking_supabase_content_required( $table ) as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				continue;
			}

			$data[ $column ] = '';

			++$report[ $table ]['repaired'];
		}

		if ( $dry_run ) {
			continue;
		}

		// `REPLACE`, not `INSERT`: it is the one statement that resolves a conflict on *any* unique key,
		// which matters because the key a row is stored under is not always the key that will collide.
		$result = $wpdb->replace( $table, $data );

		if ( false === $result ) {
			++$report[ $table ]['failed'];
			$failures[] = $table . ': ' . $wpdb->last_error;

			continue;
		}

		++$report[ $table ]['written'];
	}

	foreach ( array_keys( $unknown ) as $table ) {
		$report[ $table ] = array(
			'stored'   => $report[ $table ]['stored'],
			'written'  => 0,
			'repaired' => 0,
			'failed'   => 0,
			'error'    => 'this database has no such table',
		);
	}

	// Reported rather than raised: a restore that half-worked is still worth having, and the operator
	// needs to know exactly which rows did not land.
	if ( $failures ) {
		$unusable = array_values( array_unique( array_slice( $failures, 0, 5 ) ) );
	}

	return array(
		'tables'   => $report,
		'failures' => $unusable,
		'count'    => count( $failures ),
		'dry_run'  => $dry_run,
	);
}

/**
 * The slugs of the content WordPress creates for itself on every install.
 *
 * Measured against a fresh `wp core install`, which creates exactly three things: `Hello world!`, a
 * published `Sample Page`, and a **draft** `Privacy Policy`. The third is the one that catches you out —
 * a test for "is there published content" looks right and still answers *yes, there is a site* on a
 * database that was empty ninety seconds earlier, which is precisely what happened when this was first
 * run.
 *
 * `fresh_site` was the obvious alternative and is not used. Core sets it to `1` at install and clears it
 * when a post is published through the admin — but it clears it through *hooks*, and a restore writes
 * with `REPLACE INTO`, which fires none. So it would say `1` forever after a restore, and every restart
 * would restore all over again. This is a local list of three names instead, with the advantage of never
 * mistaking a dump somebody imported by hand for a fresh install.
 *
 * @return string[]
 */
function bookify_booking_supabase_content_demo_slugs() {
	return array( 'hello-world', 'sample-page', 'privacy-policy' );
}

/**
 * Whether this WordPress is already the site, rather than a fresh install.
 *
 * The question a restore-on-start has to answer, and the reason it is not a row count: `wp core install`
 * always creates demo content, so counting pages answers "yes, there is content" on a database that has
 * never been used.
 *
 * Two signals, because either alone has a hole in it. A page or post WordPress did not name is the
 * clearest sign of a site somebody has used; and the plugin's own settings row is the sign that survives
 * somebody deleting every page. A fresh install has neither.
 *
 * The bias is deliberate and is the safe direction: anything that looks like content means **do not
 * restore**, so a database somebody filled by hand is never overwritten by an older copy. The cost of
 * being wrong is that a fresh install is mistaken for a site and the restore is skipped — which is
 * visible immediately, and fixable with `content restore` without the flag.
 *
 * @return bool
 */
function bookify_booking_supabase_content_present() {
	global $wpdb;

	$demo = bookify_booking_supabase_content_demo_slugs();

	$of_its_own = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ( 'page', 'post' )
			   AND post_status NOT IN ( 'auto-draft', 'trash' )
			   AND post_name NOT IN ( " . implode( ', ', array_fill( 0, count( $demo ), '%s' ) ) . ' )',
			$demo
		)
	);

	if ( $of_its_own > 0 ) {
		return true;
	}

	// The settings row is written when the owner first saves the plugin's settings, so it exists on this
	// site and on no fresh install of it.
	//
	// `null` and not `false`: `wpdb::get_var()` returns null when the query matched nothing — it falls off
	// the end of a function that returns the value `isset()`. Testing `false !== $wpdb->get_var(...)` is
	// therefore always true, which is how this reported "the site is already here" for a database that had
	// been empty ninety seconds earlier. Both are checked, because which one comes back is not documented
	// and does not matter.
	$marker = $wpdb->get_var(
		$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'bookify_business' )
	);

	return null !== $marker && '' !== $marker;
}

/**
 * Remove stored rows for WordPress rows that no longer exist.
 *
 * The counterpart of `sync --prune`, and it is needed for a reason that one does not have: without it a
 * row deleted on the site — an option, a page, a post — stays in Supabase, and the **next restore puts it
 * back**. Found by doing exactly that: options removed by an earlier test came back, because nothing had
 * ever taken them out. A copy that resurrects deletions is a copy nobody can trust to be the site.
 *
 * Three guards, because this is the one function in the plugin that destroys part of the copy.
 *
 *  1. **Only tables this database still has.** A plugin's table that is missing right now — the plugin is
 *     not activated on this machine yet — must not have its stored rows deleted on that basis. Those rows
 *     are left alone, and the report says so by leaving the table out.
 *  2. **Only when the local read is believable.** An empty set of local rows is trusted only if the table
 *     really is empty. If `SELECT COUNT(*)` disagrees with what could be read, the read failed and every
 *     stored row for that table would look like an orphan — which is the shape of the accident this is
 *     guarding against. That table is skipped and named.
 *  3. **Never on its own.** It runs when an operator asks, like `sync --prune`, and never from the daily
 *     reconciliation.
 *
 * @param string[] $only    Optional. WordPress tables to prune. Default: all of them.
 * @param bool     $dry_run Optional. Report the orphans without removing them.
 * @return array<string,array{stored:int,local:int,orphans:int,removed:int,note:string}>|\WP_Error
 */
function bookify_booking_supabase_content_prune( array $only = array(), $dry_run = false ) {
	global $wpdb;

	$fetched = bookify_booking_supabase_content_fetch();

	if ( is_wp_error( $fetched ) ) {
		return $fetched;
	}

	$here   = bookify_booking_supabase_content_tables();
	$stored = array();

	foreach ( $fetched as $row ) {
		$table = isset( $row['table_name'] ) ? (string) $row['table_name'] : '';

		if ( '' === $table || $only && ! in_array( $table, $only, true ) ) {
			continue;
		}

		// Guard 1. Also covers a table that was mirrored by an older version of this file and no longer
		// exists here: its rows cannot be proven orphans, so they are left where they are.
		if ( ! in_array( $table, $here, true ) ) {
			continue;
		}

		$stored[ $table ][] = isset( $row['row_id'] ) ? (string) $row['row_id'] : '';
	}

	$report  = array();
	$orphans = array();

	foreach ( $stored as $table => $ids ) {
		$built = bookify_booking_supabase_content_rows( $table );
		$live  = array();

		foreach ( $built['rows'] as $row ) {
			$live[ (string) $row['row_id'] ] = true;
		}

		$report[ $table ] = array(
			'stored'  => count( $ids ),
			'local'   => count( $live ),
			'orphans' => 0,
			'removed' => 0,
			'note'    => '',
		);

		// Guard 2.
		if ( ! $live ) {
			$actual = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

			if ( $actual > 0 ) {
				$report[ $table ]['note'] = sprintf(
					'skipped: %d row(s) in the table, but none could be read — refusing to call them orphans',
					$actual
				);

				continue;
			}
		}

		$gone = array();

		foreach ( $ids as $id ) {
			if ( ! isset( $live[ $id ] ) ) {
				$gone[] = $id;
			}
		}

		$report[ $table ]['orphans'] = count( $gone );

		if ( $gone && ! $dry_run ) {
			$orphans[ $table ] = $gone;
		}
	}

	foreach ( $orphans as $table => $ids ) {
		// In pages, so a table that has lost thousands of rows does not become one enormous statement.
		foreach ( array_chunk( $ids, BOOKIFY_SUPABASE_CONTENT_BATCH ) as $chunk ) {
			$result = bookify_booking_supabase_delete_content( array( $table => $chunk ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$report[ $table ]['removed'] += count( $chunk );
		}
	}

	return $report;
}

/**
 * The Supabase tables that are not part of the entity mirror.
 *
 * `bookify_content` belongs to no post type, no user and no option, so it cannot be an entry in
 * `sync.php`'s table map — it is written by this file instead. The probe that checks the map against
 * `schema.sql` needs to know that, or it reads a correct pair of files as a mismatch.
 *
 * @return string[]
 */
function bookify_booking_supabase_content_store_tables() {
	return array( 'bookify_content' );
}
