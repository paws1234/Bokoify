<?php
/**
 * Talking to Supabase's Postgres directly, with no HTTP layer in between.
 *
 * This is the other half of `client.php`: that file speaks PostgREST over HTTPS, this one opens a
 * socket to the database. Both write the same rows through the same calls — `sync.php` neither knows
 * nor cares which is in use, because the choice is made inside `bookify_booking_supabase_upsert()` and
 * friends. Set a database URL and this takes over; clear it and PostgREST resumes.
 *
 * **Why both exist.** PostgREST needs no PHP extension, no database credential in the application, and
 * cannot exhaust a connection pool — on a small site it is the safer default, and it is what runs if
 * nothing else is configured. A direct connection buys one thing PostgREST cannot offer: a *transaction*.
 * The six tables go out as six statements, and each statement is atomic on its own, so a partial write
 * is impossible within a table; the differentiator is that a failure rolls back rather than leaving a
 * half-applied batch, and that there is no per-table HTTPS round trip.
 *
 * **The host in the URL matters more than it looks.** `db.<project-ref>.supabase.co` — the "Direct
 * connection" string in the Supabase dashboard — is **IPv6-only** on current projects; measured, it has
 * an AAAA record and no A record at all. PHP will hang or fail on a host with no IPv6 route, and many
 * hosts (including containers on the machine this was built on) have none. The working target is the
 * **Supavisor pooler**, `aws-0-<region>.pooler.supabase.com`, which answers on IPv4 — measured:
 * `aws-0-us-east-1`, `aws-0-us-west-1`, `aws-0-eu-central-1` and `aws-0-ap-southeast-1` all resolve to
 * IPv4 addresses. Use the "Session pooler" or "Transaction pooler" string from the dashboard's Connect
 * panel, not the "Direct connection" one.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * How many rows go into one statement.
 *
 * The batch is a single `INSERT ... ON CONFLICT` so it is atomic, which is the point of connecting
 * directly. That also means the statement grows with the batch, and a site with a decade of bookings
 * would eventually build one too large to be sensible. Chunking keeps each statement bounded; a chunk
 * that fails still rolls back with the rest, because the whole run is inside one transaction.
 */
const BOOKIFY_SUPABASE_PG_CHUNK = 200;

/**
 * The database connection details, from configuration and nothing else.
 *
 * Two variables, not one, because they have different lives: the URL is not a secret worth hiding (it
 * names a host) and can sit in a Blueprint, while the password is one and should not. So the password
 * is read separately when it is given, and the URL's own password is used only as a fallback. That is
 * also what makes the dashboard's own string pasteable as-is: a URL still holding the literal
 * `[YOUR-PASSWORD]` placeholder is read as *not configured* rather than as a password.
 *
 * @return array{configured:bool,dsn:string,user:string,password:string,host:string,database:string,sslmode:string}
 */
function bookify_booking_supabase_db() {
	$url = bookify_booking_config_value(
		'BOOKIFY_SUPABASE_DB_URL',
		array( 'BOOKIFY_SUPABASE_DB_URL', 'SUPABASE_DB_URL' ),
		'bookify_booking_supabase_db_url'
	);

	$password = bookify_booking_config_value(
		'BOOKIFY_SUPABASE_DB_PASSWORD',
		array( 'BOOKIFY_SUPABASE_DB_PASSWORD', 'SUPABASE_DB_PASSWORD' ),
		'bookify_booking_supabase_db_password'
	);

	$empty = array(
		'configured' => false,
		'dsn'        => '',
		'user'       => '',
		'password'   => '',
		'host'       => '',
		'database'   => '',
		'sslmode'    => '',
	);

	if ( '' === $url ) {
		return $empty;
	}

	$parts = wp_parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] )
		|| ! isset( $parts['scheme'] )
		|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'postgres', 'postgresql' ), true ) ) {
		return $empty;
	}

	$user = isset( $parts['user'] ) ? rawurldecode( (string) $parts['user'] ) : '';

	// The password that came with the URL, if it is a real one. `[YOUR-PASSWORD]` is what the dashboard
	// prints before you have revealed it, and treating it as a credential would produce a connection
	// failure that looks like a wrong password rather than a missing one.
	$inline = isset( $parts['pass'] ) ? rawurldecode( (string) $parts['pass'] ) : '';

	if ( in_array( $inline, array( '[YOUR-PASSWORD]', 'YOUR-PASSWORD', '' ), true ) ) {
		$inline = '';
	}

	if ( '' === $inline && '' === $password ) {
		return $empty;
	}

	$host     = (string) $parts['host'];
	$port     = isset( $parts['port'] ) ? (int) $parts['port'] : 5432;
	$database = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';

	if ( '' === $database ) {
		$database = 'postgres';
	}

	/*
	 * Everything that goes into the DSN string is checked first, and a value that is not shaped like a
	 * host, a database name or a user makes the whole thing count as *not configured* rather than being
	 * pasted in.
	 *
	 * The DSN is a semicolon-separated string, so a value containing a semicolon does not merely look
	 * odd — it adds a parameter. `host=evil;sslmode=disable` would silently turn off transport
	 * encryption on a connection carrying a database password. The values come from an operator's own
	 * environment rather than from a visitor, so this is hygiene rather than a defence, but it is the
	 * kind of hygiene that stops a typo becoming a downgrade.
	 */
	$pattern = '/^[A-Za-z0-9._:\[\]-]+$/';

	if ( ! preg_match( $pattern, $host )
		|| ! preg_match( '/^[A-Za-z0-9_.-]+$/', $database )
		|| ! preg_match( '/^[A-Za-z0-9._@-]+$/', $user ) ) {
		return $empty;
	}

	$sslmode = bookify_booking_config_value(
		'BOOKIFY_SUPABASE_DB_SSLMODE',
		array( 'BOOKIFY_SUPABASE_DB_SSLMODE' ),
		'bookify_booking_supabase_db_sslmode'
	);

	// Whitelisted rather than passed through, for the same reason: this value is written straight into
	// the DSN. `require` is the default because Supabase mandates TLS — it encrypts the connection
	// without verifying the certificate against a CA, which is the only option that works out of the
	// box, since Supabase's own CA is not in the usual trust stores and `verify-full` would need their
	// certificate shipped alongside this file. `disable` exists for a local or self-hosted Postgres and
	// must never be used against Supabase.
	if ( ! in_array( $sslmode, array( 'require', 'verify-ca', 'verify-full', 'prefer', 'disable' ), true ) ) {
		$sslmode = 'require';
	}

	return array(
		'configured' => true,
		'dsn'        => sprintf(
			'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
			$host,
			$port,
			$database,
			$sslmode
		),
		'user'       => '' !== $user ? $user : 'postgres',
		'password'   => '' !== $password ? $password : $inline,
		'host'       => $host,
		'database'   => $database,
		'sslmode'    => $sslmode,
	);
}

/**
 * Whether a direct database connection is configured.
 *
 * @return bool
 */
function bookify_booking_supabase_db_is_configured() {
	$db = bookify_booking_supabase_db();

	return (bool) $db['configured'];
}

/**
 * A PDO handle, opened once per request.
 *
 * Reused rather than reopened: the six tables are written one after another, and Supabase's pooler
 * counts connections. `ERRMODE_EXCEPTION` is what lets the caller wrap the whole run in one transaction
 * and roll it back on anything that goes wrong.
 *
 * @return \PDO|\WP_Error
 */
function bookify_booking_supabase_pg_connect() {
	static $pdo = null;

	if ( $pdo instanceof PDO ) {
		return $pdo;
	}

	$db = bookify_booking_supabase_db();

	if ( ! $db['configured'] ) {
		return new WP_Error(
			'bookify_supabase_db_not_configured',
			__( 'No Supabase database URL is configured, so nothing was written directly.', 'bookify-booking' )
		);
	}

	if ( ! extension_loaded( 'pdo_pgsql' ) ) {
		// A perfectly ordinary failure with a perfectly unhelpful PHP message, so it is named here. The
		// Dockerfile installs the extension; a host that does not have it cannot use this path at all.
		return new WP_Error(
			'bookify_supabase_no_pdo_pgsql',
			__( 'This PHP has no pdo_pgsql extension, so it cannot open a Postgres connection. Install it, or leave the database URL unset and the mirror will use Supabase\'s HTTPS API instead.', 'bookify-booking' )
		);
	}

	try {
		$pdo = new PDO(
			$db['dsn'],
			$db['user'],
			$db['password'],
			array(
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				// Real prepared statements: the values are sent separately from the SQL, which is what
				// makes building a hundred-row statement out of a placeholder string safe.
				PDO::ATTR_EMULATE_PREPARES   => false,
				PDO::ATTR_TIMEOUT            => 15,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			)
		);
	} catch ( \Throwable $e ) {
		$pdo = null;

		return new WP_Error(
			'bookify_supabase_db_connect',
			sprintf(
				/* translators: 1: the database host, 2: the driver's own explanation. */
				__( 'Could not connect to %1$s: %2$s', 'bookify-booking' ),
				$db['host'],
				$e->getMessage()
			)
		);
	}

	return $pdo;
}

/**
 * A table or column name, quoted, and only if it looks like one.
 *
 * Every name reaching this file was written in this plugin — the table map in `sync.php` and the row
 * keys in `transform.php` — so this is not a defence against a user. It is a defence against a typo in
 * a future row builder: a name that is not a plain identifier is refused loudly rather than
 * interpolated into a statement.
 *
 * @param string $name Table or column name.
 * @return string|\WP_Error
 */
function bookify_booking_supabase_pg_name( $name ) {
	$name = (string) $name;

	if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/', $name ) ) {
		return new WP_Error(
			'bookify_supabase_bad_name',
			sprintf(
				/* translators: %s: the offending table or column name. */
				__( '"%s" is not a plain table or column name, so it was not sent.', 'bookify-booking' ),
				$name
			)
		);
	}

	return '"' . $name . '"';
}

/**
 * One value as a SQL placeholder, appending its parameter if it needs one.
 *
 * Types are handled by hand because they have to be. A PHP array is a `jsonb` value and needs encoding
 * plus a cast, or PDO tries to send it as a string and Postgres rejects it. A PHP bool sent as a bare
 * parameter is at the mercy of the driver's idea of how to spell a boolean, so `t`/`f` with an explicit
 * cast removes the question. Null becomes the SQL keyword rather than a parameter, which keeps Postgres
 * from having to infer a type for nothing.
 *
 * @param mixed   $value  The value.
 * @param mixed[] $params Parameter list, by reference.
 * @return string
 */
function bookify_booking_supabase_pg_bind( $value, array &$params ) {
	if ( null === $value ) {
		return 'null';
	}

	if ( is_array( $value ) ) {
		$params[] = (string) wp_json_encode( $value );

		return '?::jsonb';
	}

	if ( is_bool( $value ) ) {
		$params[] = $value ? 't' : 'f';

		return '?::boolean';
	}

	$params[] = $value;

	return '?';
}

/**
 * Insert or update rows, keyed on `id`, in one statement.
 *
 * `ON CONFLICT (id) DO UPDATE` is the Postgres spelling of the upsert PostgREST was asked for with
 * `Prefer: resolution=merge-duplicates`. Writing the same row twice is a no-op, which is what lets the
 * reconciliation re-send everything daily and not care what changed.
 *
 * The whole run is one transaction, so a failure anywhere leaves the database as it was rather than
 * half-updated — the thing a direct connection is actually for.
 *
 * @param string                 $table Table name.
 * @param array<int,array<mixed>> $rows  Rows to write. All must share the same keys.
 * @param string[]               $key   Optional. The columns the conflict is resolved on. Default
 *                                      `id`, which is what every entity table uses; `bookify_content`
 *                                      passes its two-column key instead.
 * @return true|\WP_Error
 */
function bookify_booking_supabase_pg_upsert( $table, array $rows, array $key = array( 'id' ) ) {
	if ( ! $rows ) {
		return true;
	}

	$pdo = bookify_booking_supabase_pg_connect();

	if ( is_wp_error( $pdo ) ) {
		return $pdo;
	}

	$table_name = bookify_booking_supabase_pg_name( $table );

	if ( is_wp_error( $table_name ) ) {
		return $table_name;
	}

	$columns = array_keys( $rows[0] );

	$quoted = array();

	foreach ( $columns as $column ) {
		$name = bookify_booking_supabase_pg_name( $column );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$quoted[ $column ] = $name;
	}

	// A conflict target naming a column the rows do not carry would be a syntax error at best and
	// `on conflict (null)` at worst, which never matches and so turns the upsert into a plain insert
	// that fails on the second run. Named here rather than left to be discovered as a duplicate key.
	$target = array();

	foreach ( $key as $column ) {
		if ( ! isset( $quoted[ $column ] ) ) {
			return new WP_Error(
				'bookify_supabase_missing_key',
				sprintf(
					/* translators: 1: the table name, 2: the column the conflict is resolved on. */
					__( 'The rows for %1$s do not carry %2$s, which the upsert resolves conflicts on.', 'bookify-booking' ),
					$table,
					$column
				)
			);
		}

		$target[] = $quoted[ $column ];
	}

	// Everything but the key: an update that set `id = id` would be legal and pointless.
	$assignments = array();

	foreach ( $quoted as $column => $name ) {
		if ( ! in_array( $column, $key, true ) ) {
			$assignments[] = $name . ' = EXCLUDED.' . $name;
		}
	}

	try {
		$pdo->beginTransaction();

		foreach ( array_chunk( $rows, BOOKIFY_SUPABASE_PG_CHUNK ) as $chunk ) {
			$params = array();
			$tuples = array();

			foreach ( $chunk as $row ) {
				$values = array();

				foreach ( $columns as $column ) {
					// A row that is missing a column gets null rather than being skipped: every row of a
					// table must spell out the same columns, or the statement is malformed.
					$values[] = bookify_booking_supabase_pg_bind( array_key_exists( $column, $row ) ? $row[ $column ] : null, $params );
				}

				$tuples[] = '(' . implode( ', ', $values ) . ')';
			}

			$sql = sprintf(
				'insert into %s (%s) values %s on conflict (%s) do update set %s',
				$table_name,
				implode( ', ', $quoted ),
				implode( ', ', $tuples ),
				implode( ', ', $target ),
				implode( ', ', $assignments )
			);

			$statement = $pdo->prepare( $sql );
			$statement->execute( $params );
		}

		$pdo->commit();
	} catch ( \Throwable $e ) {
		if ( $pdo->inTransaction() ) {
			$pdo->rollBack();
		}

		return new WP_Error(
			'bookify_supabase_db_write',
			sprintf(
				/* translators: 1: the table name, 2: the driver's own explanation. */
				__( 'Supabase refused the write to %1$s: %2$s', 'bookify-booking' ),
				$table,
				$e->getMessage()
			)
		);
	}

	return true;
}

/**
 * Every id a table holds.
 *
 * @param string $table Table name.
 * @return int[]|\WP_Error
 */
function bookify_booking_supabase_pg_ids( $table ) {
	$pdo = bookify_booking_supabase_pg_connect();

	if ( is_wp_error( $pdo ) ) {
		return $pdo;
	}

	$name = bookify_booking_supabase_pg_name( $table );

	if ( is_wp_error( $name ) ) {
		return $name;
	}

	try {
		$statement = $pdo->query( 'select id from ' . $name );
		$ids       = array();

		foreach ( (array) $statement->fetchAll() as $row ) {
			$ids[] = (int) $row['id'];
		}
	} catch ( \Throwable $e ) {
		return new WP_Error( 'bookify_supabase_db_read', $e->getMessage() );
	}

	return $ids;
}

/**
 * Rows from a table, in full, for some ids or for all of them.
 *
 * The mirror is one-directional and this is the exception that proves the direction has not changed:
 * it exists so a copy can be read back *by this site*, to put a wiped `wp-content/uploads` back on
 * disk. Nothing in the booking path calls it, and nothing that answers a visitor does either.
 *
 * `select *` rather than a named column list because the caller is putting files back, which needs
 * whatever the table happens to hold, and because the column list is already the thing `transform.php`
 * owns — a second copy of it here would be a third place to update.
 *
 * @param string $table    Table name.
 * @param int[]  $ids      Optional. Ids to read. Default: every row.
 * @param string $order_by Optional. The column to sort by, or `''` for no particular order. Default
 *                         `id` — but `bookify_content` has no `id`, so it asks for none rather than
 *                         being unreadable.
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function bookify_booking_supabase_pg_select( $table, array $ids = array(), $order_by = 'id' ) {
	$pdo = bookify_booking_supabase_pg_connect();

	if ( is_wp_error( $pdo ) ) {
		return $pdo;
	}

	$name = bookify_booking_supabase_pg_name( $table );

	if ( is_wp_error( $name ) ) {
		return $name;
	}

	$order = '';

	if ( '' !== $order_by ) {
		$column = bookify_booking_supabase_pg_name( $order_by );

		if ( is_wp_error( $column ) ) {
			return $column;
		}

		$order = ' order by ' . $column . ' asc';
	}

	$params = array();
	$sql    = 'select * from ' . $name;

	if ( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		$sql    .= ' where id in (' . implode( ', ', array_fill( 0, count( $ids ), '?' ) ) . ')';
		$params = $ids;
	}

	try {
		$statement = $pdo->prepare( $sql . $order );
		$statement->execute( $params );

		return (array) $statement->fetchAll();
	} catch ( \Throwable $e ) {
		return new WP_Error( 'bookify_supabase_db_read', $e->getMessage() );
	}
}

/**
 * Remove rows of `bookify_content` by their two-column key.
 *
 * Separate from the function above because the key is a different kind of thing. Those rows are
 * identified by `id`, and the ids are integers, which is why `absint()` is safe there. These are
 * identified by a WordPress table name and a *text* row id — and one of the row ids is a pair of key
 * columns joined with a colon, so `absint()` would turn `wp_term_relationships`'s `5:3` into `5` and
 * delete a row nobody asked about.
 *
 * The table name is a bound value here, not an identifier, so it needs no quoting check: it is compared
 * against a column rather than interpolated into the statement.
 *
 * One statement per table, all inside one transaction, so a prune that fails part way leaves the copy
 * as it was rather than half-pruned.
 *
 * @param array<string,string[]> $by_table Table name to the row ids to remove.
 * @return true|\WP_Error
 */
function bookify_booking_supabase_pg_delete_content( array $by_table ) {
	if ( ! $by_table ) {
		return true;
	}

	$pdo = bookify_booking_supabase_pg_connect();

	if ( is_wp_error( $pdo ) ) {
		return $pdo;
	}

	try {
		$pdo->beginTransaction();

		foreach ( $by_table as $table => $ids ) {
			$ids = array_values( array_unique( array_map( 'strval', (array) $ids ) ) );

			if ( ! $ids ) {
				continue;
			}

			$statement = $pdo->prepare(
				'delete from bookify_content where table_name = ? and row_id in ('
					. implode( ', ', array_fill( 0, count( $ids ), '?' ) ) . ')'
			);

			$statement->execute( array_merge( array( (string) $table ), $ids ) );
		}

		$pdo->commit();
	} catch ( \Throwable $e ) {
		if ( $pdo->inTransaction() ) {
			$pdo->rollBack();
		}

		return new WP_Error( 'bookify_supabase_db_delete', $e->getMessage() );
	}

	return true;
}

/**
 * Remove rows by id.
 *
 * @param string $table Table name.
 * @param int[]  $ids   Ids to remove.
 * @return true|\WP_Error
 */
function bookify_booking_supabase_pg_delete( $table, array $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

	if ( ! $ids ) {
		return true;
	}

	$pdo = bookify_booking_supabase_pg_connect();

	if ( is_wp_error( $pdo ) ) {
		return $pdo;
	}

	$name = bookify_booking_supabase_pg_name( $table );

	if ( is_wp_error( $name ) ) {
		return $name;
	}

	try {
		// Placeholders rather than the ids themselves: they are integers by this point, but a statement
		// assembled from values is a habit worth not having.
		$statement = $pdo->prepare(
			'delete from ' . $name . ' where id in (' . implode( ', ', array_fill( 0, count( $ids ), '?' ) ) . ')'
		);

		$statement->execute( $ids );
	} catch ( \Throwable $e ) {
		return new WP_Error( 'bookify_supabase_db_delete', $e->getMessage() );
	}

	return true;
}
