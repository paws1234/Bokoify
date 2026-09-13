<?php
/**
 * The mirror's WP-CLI command.
 *
 * Loaded only on a WP-CLI run (`sync.php` requires it behind a `class_exists( 'WP_CLI' )`), so a web
 * request never parses it.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Publish this site's sessions, events, tiers and bookings to Supabase.
 */
class Bookify_Supabase_Command {

	/**
	 * Report whether the mirror is configured, what it would send, and how the last push went.
	 *
	 * Building the rows is the point of this command as much as the configuration is: the transform
	 * runs end to end and a mapping that cannot produce a row shows up here, on a machine that has no
	 * Supabase project to point at.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bookify-supabase status
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags. Unused.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$config = bookify_booking_supabase_config();

		WP_CLI::log( 'configured: ' . ( bookify_booking_supabase_is_configured() ? 'yes' : 'no' ) );
		WP_CLI::log( 'url: ' . ( '' !== $config['url'] ? $config['url'] : '(none)' ) );

		// The key's length, and nothing else. Proving it is present without ever printing it is the
		// same rule the Resend probe follows, and it is why a `service_role` credential can be checked
		// in a session that may end up in a transcript.
		WP_CLI::log( 'key: ' . ( '' !== $config['key'] ? strlen( $config['key'] ) . ' characters' : '(none)' ) );

		$db = bookify_booking_supabase_db();

		WP_CLI::log(
			'transport: ' . ( $db['configured']
				// The host, never the password. Which of the two paths a site is on is the first thing worth
				// knowing when a write fails, and it is not obvious from the outside: both end with rows in
				// the same tables.
				? 'direct Postgres connection to ' . $db['host'] . ' (no HTTP)'
				: 'Supabase REST API over HTTPS' )
		);

		$rows = array();

		foreach ( array_keys( bookify_booking_supabase_tables() ) as $table ) {
			$rows[] = array(
				'table'   => $table,
				'sources' => count( bookify_booking_supabase_local_ids( $table ) ),
				'rows'    => count( bookify_booking_supabase_rows( $table ) ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'sources', 'rows' ) );

		$next = wp_next_scheduled( BOOKIFY_SUPABASE_RECONCILE_HOOK );

		WP_CLI::log( 'reconciliation: ' . ( $next ? 'next ' . wp_date( 'Y-m-d H:i:s', $next ) : 'not scheduled' ) );

		// A schedule nobody runs is worse than no schedule, because it is trusted. WP-Cron only fires on
		// a page view, so a host that disables it — or a site quiet enough to have no visitors — silently
		// stops reconciling. It stops the reminders and the payment expiry too, which are scheduled the
		// same way and have been since stage 8; this warning is about all of them.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			WP_CLI::warning( 'DISABLE_WP_CRON is set, so nothing runs this schedule on its own. Point a real cron job at wp-cron.php.' );
		}

		$last = bookify_booking_supabase_last_result();

		if ( ! $last['time'] ) {
			WP_CLI::log( 'last push: never' );

			return;
		}

		WP_CLI::log(
			sprintf(
				'last push: %s — %s, %d written, %d removed',
				wp_date( 'Y-m-d H:i:s', $last['time'] ),
				$last['ok'] ? 'ok' : 'FAILED',
				$last['upserted'],
				$last['deleted']
			)
		);

		if ( '' !== $last['message'] ) {
			WP_CLI::log( '  ' . $last['message'] );
		}
	}

	/**
	 * Send the whole catalogue and every booking.
	 *
	 * Idempotent: every write is an upsert keyed on the WordPress post id, so running it twice changes
	 * nothing the second time.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Build the rows and report what would be sent, without sending anything. Does not need a
	 * project URL or key.
	 *
	 * [--prune]
	 * : Also remove rows Supabase holds for posts that no longer exist here. Trashed posts are not
	 * orphans — they are mirrored with `status = 'trash'`.
	 *
	 * [--table=<name>]
	 * : Limit the run to some tables. Repeatable. One of: bookify_services, bookify_events,
	 * bookify_tiers, bookify_bookings, bookify_media. Include `bookify_media` only when the images are
	 * wanted: it is the one table measured in megabytes rather than rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bookify-supabase sync
	 *     wp bookify-supabase sync --dry-run
	 *     wp bookify-supabase sync --prune --table=bookify_bookings
	 *
	 * @param array $args       Positional arguments. Unused.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function sync( $args, $assoc_args ) {
		$only    = isset( $assoc_args['table'] ) ? (array) $assoc_args['table'] : array();
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$prune   = ! empty( $assoc_args['prune'] );

		$known = array_keys( bookify_booking_supabase_tables() );

		foreach ( $only as $table ) {
			if ( ! in_array( $table, $known, true ) ) {
				WP_CLI::error( sprintf( 'Unknown table "%s". Known tables: %s', $table, implode( ', ', $known ) ) );
			}
		}

		if ( ! $dry_run && ! bookify_booking_supabase_is_configured() ) {
			WP_CLI::error( 'Supabase is not configured. Set BOOKIFY_SUPABASE_URL and BOOKIFY_SUPABASE_KEY.' );
		}

		$rows     = array();
		$failures = array();

		foreach ( bookify_booking_supabase_backfill( $only, $dry_run ) as $table => $report ) {
			if ( '' !== $report['error'] ) {
				$failures[] = $table;
			}

			$rows[] = array(
				'table'   => $table,
				'sources' => $report['sources'],
				'rows'    => $report['rows'],
				'pushed'  => $dry_run ? '—' : ( $report['pushed'] ? 'yes' : 'NO' ),
				// The reason, not the shortfall. A table that failed and a table whose rows could not be
				// built are different problems, and printing only the second one is how a run in which
				// nothing at all was written came to report "Success".
				'note'    => '' !== $report['error'] ? $report['error'] : $report['skipped'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'sources', 'rows', 'pushed', 'note' ) );

		// Stopped before the prune, deliberately: deleting rows is the one operation here that can
		// destroy data, and doing it while every write is failing is exactly when it must not run.
		if ( $failures ) {
			WP_CLI::error( sprintf( 'Nothing was mirrored for: %s', implode( ', ', $failures ) ) );
		}

		if ( $prune ) {
			$pruned = array();

			foreach ( bookify_booking_supabase_prune( $only, $dry_run ) as $table => $report ) {
				if ( ! empty( $report['error'] ) ) {
					WP_CLI::warning( $table . ': ' . $report['error'] );

					continue;
				}

				$pruned[] = array(
					'table'   => $table,
					'remote'  => $report['remote'],
					'local'   => $report['local'],
					'removed' => $dry_run ? '—' : $report['removed'],
				);
			}

			if ( $pruned ) {
				WP_CLI\Utils\format_items( 'table', $pruned, array( 'table', 'remote', 'local', 'removed' ) );
			}
		}

		WP_CLI::success( $dry_run ? 'Dry run: nothing was sent.' : 'Mirrored.' );
	}

	/**
	 * The site's own tables: the pages, the Elementor layouts, the menus, the options.
	 *
	 * The half of the copy that is not about bookings. Everything WordPress holds lives in its own
	 * tables, and on a host with no Shell there is no dump to import — so a fresh deploy is an empty
	 * site. This publishes those rows to Supabase and puts them back.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : `status` reports what would be published and what is already there. `push` sends it now.
	 * `restore` writes it back into this database, which is the point of the whole thing.
	 * ---
	 * default: status
	 * options:
	 *   - status
	 *   - push
	 *   - restore
	 * ---
	 *
	 * [--table=<name>]
	 * : Limit the run to some WordPress tables, e.g. `wp_posts`. Repeatable.
	 *
	 * [--dry-run]
	 * : Work out what a restore would do, write nothing, and report it.
	 *
	 * [--prune]
	 * : With `push`, also remove stored rows for WordPress rows that no longer exist. Needed after
	 * deleting anything on the site: without it the copy keeps the row, and the next restore puts it
	 * back. Never runs on its own — deleting is the one operation here that can destroy the copy.
	 *
	 * [--if-empty]
	 * : Restore only when this site has no pages of its own yet. That is the state of a fresh deploy, and
	 * it is what makes the restore safe to put on the container's start-up: on a redeploy whose content is
	 * already there, this reads a marker and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bookify-supabase content status
	 *     wp bookify-supabase content push
	 *     wp bookify-supabase content push --prune
	 *     wp bookify-supabase content restore --dry-run
	 *     wp bookify-supabase content restore
	 *     wp bookify-supabase content restore --if-empty
	 *     wp bookify-supabase content push --table=wp_posts --table=wp_postmeta
	 *
	 * @param array $args       Positional arguments: the action.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function content( $args, $assoc_args ) {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		$only   = isset( $assoc_args['table'] ) ? array_map( 'strval', (array) $assoc_args['table'] ) : array();

		if ( 'status' === $action ) {
			$this->content_status( $only );

			return;
		}

		if ( 'push' === $action ) {
			$this->content_push( $only, ! empty( $assoc_args['prune'] ) );

			return;
		}

		if ( 'restore' === $action ) {
			$this->content_restore( $only, ! empty( $assoc_args['dry-run'] ), ! empty( $assoc_args['if-empty'] ) );

			return;
		}

		WP_CLI::error( sprintf( 'Unknown action "%s". Use status, push or restore.', $action ) );
	}

	/**
	 * What the site's tables hold, and whether Supabase has it.
	 *
	 * The stored count is read from Supabase rather than assumed, so a table that has never been pushed
	 * shows as `0 stored` rather than as an absence nobody counts. It needs the connection; without one
	 * the local half is still reported, which is what makes this usable before Supabase is set up.
	 *
	 * @param string[] $only Optional. Tables to report.
	 * @return void
	 */
	private function content_status( array $only ) {
		$stored = array();

		if ( bookify_booking_supabase_is_configured() ) {
			$rows = bookify_booking_supabase_content_fetch();

			if ( is_wp_error( $rows ) ) {
				WP_CLI::warning( 'Could not read the content table: ' . $rows->get_error_message() );
			} else {
				foreach ( $rows as $row ) {
					$table = isset( $row['table_name'] ) ? (string) $row['table_name'] : '';

					$stored[ $table ] = isset( $stored[ $table ] ) ? $stored[ $table ] + 1 : 1;
				}
			}
		}

		$report  = array();
		$local   = 0;
		$remote  = 0;
		$refused = 0;

		foreach ( bookify_booking_supabase_content_tables() as $table ) {
			if ( $only && ! in_array( $table, $only, true ) ) {
				continue;
			}

			$built = bookify_booking_supabase_content_rows( $table );
			$here  = count( $built['rows'] );

			$local   += $here;
			$remote  += isset( $stored[ $table ] ) ? $stored[ $table ] : 0;
			$refused += count( $built['refused'] );

			$report[] = array(
				'table'  => $table,
				'local'  => $here,
				'stored' => isset( $stored[ $table ] ) ? $stored[ $table ] : 0,
				'state'  => bookify_booking_supabase_is_configured()
					? ( ( isset( $stored[ $table ] ) ? $stored[ $table ] : 0 ) === $here ? 'same' : 'DIFFERENT' )
					: '—',
			);
		}

		WP_CLI\Utils\format_items( 'table', $report, array( 'table', 'local', 'stored', 'state' ) );

		WP_CLI::log( sprintf( '%d row(s) here, %d row(s) stored in Supabase', $local, $remote ) );

		if ( $refused ) {
			WP_CLI::warning( sprintf( '%d row(s) could not be encoded and will not be published.', $refused ) );
		}

		if ( ! bookify_booking_supabase_is_configured() ) {
			WP_CLI::log( 'nothing was compared with Supabase: it is not configured' );
		}
	}

	/**
	 * Send the site's tables now.
	 *
	 * @param string[] $only  Optional. Tables to publish.
	 * @param bool     $prune Optional. Also remove stored rows for WordPress rows that no longer exist.
	 * @return void
	 */
	private function content_push( array $only, $prune = false ) {
		if ( ! bookify_booking_supabase_is_configured() ) {
			WP_CLI::error( 'Supabase is not configured. Set BOOKIFY_SUPABASE_DB_URL, or BOOKIFY_SUPABASE_URL and BOOKIFY_SUPABASE_KEY.' );
		}

		$report   = array();
		$rows     = 0;
		$failures = array();
		$refused  = array();

		foreach ( bookify_booking_supabase_content_push( $only ) as $table => $result ) {
			$rows += (int) $result['rows'];

			if ( '' !== $result['error'] ) {
				$failures[] = $table;
			}

			foreach ( $result['reasons'] as $reason ) {
				$refused[] = $reason;
			}

			$report[] = array(
				'table'   => $table,
				'rows'    => $result['rows'],
				'pushed'  => $result['pushed'] ? 'yes' : 'NO',
				'refused' => $result['refused'],
				'note'    => $result['error'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $report, array( 'table', 'rows', 'pushed', 'refused', 'note' ) );

		// Named, not counted. A refused row is a row that will not be in the copy, and an operator who
		// only sees a number has no way to tell whether it mattered.
		foreach ( $refused as $reason ) {
			WP_CLI::warning( 'not carried: ' . $reason );
		}

		if ( $failures ) {
			WP_CLI::error( sprintf( 'Nothing was published for: %s', implode( ', ', $failures ) ) );
		}

		// After the writes, and only once every one of them succeeded — the rule `sync --prune` follows, and
		// for the same reason: deleting is the one operation here that can destroy data, and doing it while
		// writes are failing is exactly when it must not run.
		if ( $prune ) {
			$pruned = bookify_booking_supabase_content_prune( $only );

			if ( is_wp_error( $pruned ) ) {
				WP_CLI::error( 'The prune failed, so nothing was removed: ' . $pruned->get_error_message() );
			}

			$prune_rows = array();

			foreach ( $pruned as $table => $result ) {
				// Only the tables with something to say, so a clean run is a short one.
				if ( ! $result['orphans'] && '' === $result['note'] ) {
					continue;
				}

				$prune_rows[] = array(
					'table'   => $table,
					'stored'  => $result['stored'],
					'local'   => $result['local'],
					'orphans' => $result['orphans'],
					'removed' => $result['removed'],
					'note'    => $result['note'],
				);
			}

			if ( $prune_rows ) {
				WP_CLI\Utils\format_items( 'table', $prune_rows, array( 'table', 'stored', 'local', 'orphans', 'removed', 'note' ) );
			} else {
				WP_CLI::log( 'Nothing in the copy is missing from this site, so there was nothing to prune.' );
			}
		}

		WP_CLI::success( sprintf( 'Published %d row(s).', $rows ) );
	}

	/**
	 * Put the site's tables back.
	 *
	 * The one command in this plugin that writes to WordPress's own tables, and it is the reason the
	 * rest exists: on a host with no Shell, a fresh container plus this is how the site comes back.
	 *
	 * @param string[] $only     Optional. Tables to restore.
	 * @param bool     $dry_run  Optional. Report without writing.
	 * @param bool     $if_empty Optional. Do nothing when the site already has pages.
	 * @return void
	 */
	private function content_restore( array $only, $dry_run, $if_empty = false ) {
		// Needed for the users table below, and easy to leave out: a WP-CLI command class is a plain
		// object, so nothing is in scope but what the method asks for. Found by the warning this threw
		// when a fresh deploy ran it — the check it guards was silently never firing.
		global $wpdb;

		if ( $if_empty && bookify_booking_supabase_content_present() ) {
			WP_CLI::success( 'This site already has pages of its own, so nothing was restored.' );

			return;
		}

		$restored = bookify_booking_supabase_content_restore( $only, $dry_run );

		if ( is_wp_error( $restored ) ) {
			WP_CLI::error( $restored->get_error_message() );
		}

		$report   = array();
		$written  = 0;
		$repaired = 0;
		$failed   = 0;

		foreach ( $restored['tables'] as $table => $result ) {
			$written  += (int) $result['written'];
			$repaired += (int) $result['repaired'];
			$failed   += (int) $result['failed'];

			$report[] = array(
				'table'    => $table,
				'stored'   => $result['stored'],
				'written'  => $dry_run ? '—' : $result['written'],
				'repaired' => $result['repaired'],
				'failed'   => $result['failed'],
				'note'     => $result['error'],
			);
		}

		if ( $report ) {
			WP_CLI\Utils\format_items( 'table', $report, array( 'table', 'stored', 'written', 'repaired', 'failed', 'note' ) );
		}

		// Said out loud, because "the site is back but the login does not work" is otherwise the next hour.
		// The copy carries no password hashes, on purpose, and WordPress's schema leaves the restored
		// column empty — which cannot be logged into, measured, but is not an account anyone can use.
		if ( isset( $restored['tables'][ $wpdb->users ] ) && $restored['tables'][ $wpdb->users ]['written'] > 0 ) {
			WP_CLI::warning( 'The copy does not carry password hashes, so the restored account(s) cannot be logged into. Set one with `wp user update <id> --user_pass=<password>` before the site is reachable.' );
		}

		if ( $repaired ) {
			WP_CLI::warning( sprintf( '%d value(s) had no default and were written empty — see `media status` if a picture or a setting looks wrong.', $repaired ) );
		}

		if ( $restored['count'] ) {
			foreach ( $restored['failures'] as $failure ) {
				WP_CLI::log( '  ' . $failure );
			}

			WP_CLI::error( sprintf( '%d row(s) failed to write.', $restored['count'] ) );
		}

		WP_CLI::success( $dry_run ? 'Dry run: nothing was written.' : sprintf( 'Restored %d row(s).', $written ) );
	}

	/**
	 * The images: how much they are, whether they have gone, and putting them back.
	 *
	 * The half of the mirror that is not rows. A row in `bookify_media` is an image file, so this is
	 * the command that answers "are the photographs actually over there?" and the one that puts them
	 * back on a machine whose uploads directory has been replaced — which is the whole reason the table
	 * exists.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : `status` reports what is carried and how large it is. `push` sends the image rows now.
	 * `restore` reads the rows back and writes any file that is missing. `verify` reads them back and
	 * checks the bytes against the files here, without writing anything.
	 * ---
	 * default: status
	 * options:
	 *   - status
	 *   - push
	 *   - restore
	 *   - verify
	 * ---
	 *
	 * [--id=<id>]
	 * : Limit the run to one attachment id. Repeatable.
	 *
	 * [--force]
	 * : With `restore`, rewrite files that are already on disk. Without it only what is missing is
	 * written, and the run is then cheap enough to make part of starting a container.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bookify-supabase media status
	 *     wp bookify-supabase media push
	 *     wp bookify-supabase media verify
	 *     wp bookify-supabase media restore
	 *     wp bookify-supabase media restore --force --id=575
	 *
	 * @param array $args       Positional arguments: the action.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function media( $args, $assoc_args ) {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		$ids    = isset( $assoc_args['id'] ) ? array_map( 'absint', (array) $assoc_args['id'] ) : array();
		$force  = ! empty( $assoc_args['force'] );

		if ( 'status' === $action ) {
			$this->media_status();

			return;
		}

		if ( 'push' === $action ) {
			$this->media_push( $ids );

			return;
		}

		if ( 'restore' === $action ) {
			$this->media_restore( $ids, $force );

			return;
		}

		if ( 'verify' === $action ) {
			$this->media_verify( $ids );

			return;
		}

		WP_CLI::error( sprintf( 'Unknown action "%s". Use status, push, restore or verify.', $action ) );
	}

	/**
	 * What the images weigh, and whether Supabase has them.
	 *
	 * The list is of what *would* be sent — every image attachment, as the row builder sees it — set
	 * against the ids Supabase actually holds, so a photo that has never made it across is visible as
	 * `NO` rather than as an absence nobody counts.
	 *
	 * @return void
	 */
	private function media_status() {
		$images = bookify_booking_supabase_local_ids( 'bookify_media' );
		$remote = array();

		if ( bookify_booking_supabase_is_configured() ) {
			$remote = bookify_booking_supabase_ids( 'bookify_media' );

			if ( is_wp_error( $remote ) ) {
				WP_CLI::warning( 'Could not read the media table: ' . $remote->get_error_message() );
				$remote = array();
			}
		}

		$rows    = array();
		$files   = 0;
		$bytes   = 0;
		$refused = array();

		foreach ( $images as $id ) {
			$row = bookify_booking_supabase_media_row( $id );

			// A null here is a real answer rather than an error: not an image, not on disk, or larger
			// than the ceiling. Collected by id so the reason is one command away.
			if ( ! is_array( $row ) ) {
				$refused[] = $id;

				continue;
			}

			$files += count( $row['files'] );
			$bytes += (int) $row['total_bytes'];

			$rows[] = array(
				'id'     => $id,
				'file'   => $row['file'],
				'files'  => count( $row['files'] ),
				'bytes'  => size_format( $row['total_bytes'] ),
				'pushed' => in_array( $id, $remote, true ) ? 'yes' : 'NO',
			);
		}

		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'file', 'files', 'bytes', 'pushed' ) );
		}

		WP_CLI::log(
			sprintf(
				'%d image(s), %d file(s), %s of image — about %s once base64 encoded, against a ceiling of %s',
				count( $rows ),
				$files,
				size_format( $bytes ),
				size_format( (int) ceil( $bytes * 4 / 3 ) ),
				size_format( bookify_booking_supabase_media_ceiling() )
			)
		);

		if ( ! bookify_booking_supabase_is_configured() ) {
			WP_CLI::log( 'nothing has been checked against Supabase: it is not configured' );
		}

		if ( $refused ) {
			WP_CLI::warning(
				sprintf(
					'%d image attachment(s) produce no row and are not carried: %s',
					count( $refused ),
					implode( ', ', $refused )
				)
			);
		}
	}

	/**
	 * Send the image rows now.
	 *
	 * The same write the shutdown hook performs, for when it has failed or has never run — the rows are
	 * large enough that sending them on every request would be rude, which is why the table is also the
	 * one thing a reconciliation of a quiet site cannot be trusted to have delivered promptly.
	 *
	 * @param int[] $ids Optional. Attachment ids to send. Default: all of them.
	 * @return void
	 */
	private function media_push( array $ids ) {
		if ( ! bookify_booking_supabase_is_configured() ) {
			WP_CLI::error( 'Supabase is not configured. Set BOOKIFY_SUPABASE_DB_URL, or BOOKIFY_SUPABASE_URL and BOOKIFY_SUPABASE_KEY.' );
		}

		$rows = bookify_booking_supabase_rows( 'bookify_media', $ids );

		if ( ! $rows ) {
			WP_CLI::warning( 'No image produced a row, so nothing was sent.' );

			return;
		}

		$result = bookify_booking_supabase_upsert( 'bookify_media', $rows );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Sent %d image row(s).', count( $rows ) ) );
	}

	/**
	 * Read the images back and check the bytes.
	 *
	 * The difference between "the row is there" and "it is the same photograph". Every file is decoded
	 * and hashed against the file on disk, and **nothing is written** — so this can be run against a
	 * live site, which is the one place the comparison means anything.
	 *
	 * A file that is not on this machine is counted apart from one that differs, because the two say
	 * different things: a mismatch says the copy is wrong, and an absence usually just says this machine
	 * never had the file. Only a mismatch is a failure.
	 *
	 * @param int[] $ids Optional. Attachment ids to check. Default: all of them.
	 * @return void
	 */
	private function media_verify( array $ids ) {
		$rows = bookify_booking_supabase_media_fetch( $ids );

		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}

		if ( ! $rows ) {
			WP_CLI::error( 'The media table is empty, so there is nothing to check.' );
		}

		$root = trailingslashit( (string) wp_get_upload_dir()['basedir'] );

		$same    = 0;
		$differs = 0;
		$absent  = 0;
		$bytes   = 0;
		$report  = array();

		foreach ( $rows as $row ) {
			$files   = bookify_booking_supabase_media_row_files( (array) $row );
			$decoded = 0;

			foreach ( $files as $relative => $encoded ) {
				$data = base64_decode( $encoded, true );

				// A value that will not decode is a corrupted copy, which is a mismatch and not an absence.
				if ( false === $data ) {
					++$differs;

					continue;
				}

				$decoded += strlen( $data );
				$bytes   += strlen( $data );

				if ( ! is_file( $root . $relative ) ) {
					++$absent;

					continue;
				}

				if ( md5_file( $root . $relative ) === md5( $data ) ) {
					++$same;
				} else {
					++$differs;
				}
			}

			// The row's own view of its size, checked against what the base64 actually decodes to. This is
			// the one number that would catch a column silently truncated somewhere on the way out.
			$stored = isset( $row['total_bytes'] ) ? (int) $row['total_bytes'] : 0;

			$report[] = array(
				'id'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'files' => count( $files ),
				'bytes' => size_format( $decoded ),
				'total' => $stored === $decoded ? 'agrees' : sprintf( 'DIFFERS (%d)', $stored ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $report, array( 'id', 'files', 'bytes', 'total' ) );

		WP_CLI::log(
			sprintf(
				'%d file(s) identical, %d differing, %d not on this machine — %s decoded',
				$same,
				$differs,
				$absent,
				size_format( $bytes )
			)
		);

		if ( $differs ) {
			WP_CLI::error( sprintf( '%d file(s) do not match the copy in Supabase.', $differs ) );
		}

		WP_CLI::success(
			0 === $absent
				? 'Every image in Supabase is byte for byte the image on this machine.'
				: sprintf( 'Every image present here matches. %d file(s) are not on this machine and could not be compared.', $absent )
		);
	}

	/**
	 * Read the image rows back and write what is missing.
	 *
	 * Idempotent, and deliberately so: every file already on disk is left alone, so this can run on
	 * every start of a container whose uploads directory may or may not have survived, and cost nothing
	 * on the runs where it did.
	 *
	 * @param int[] $ids   Optional. Attachment ids to restore. Default: all of them.
	 * @param bool  $force Optional. Rewrite files that are already present.
	 * @return void
	 */
	private function media_restore( array $ids, $force ) {
		$rows = bookify_booking_supabase_media_fetch( $ids );

		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}

		if ( ! $rows ) {
			WP_CLI::warning( 'The media table is empty, so there was nothing to put back.' );

			return;
		}

		$report  = array();
		$written = 0;
		$kept    = 0;
		$failed  = 0;

		foreach ( $rows as $row ) {
			$result = bookify_booking_supabase_media_restore_files( (array) $row, $force );

			$written += $result['written'];
			$kept    += $result['kept'];
			$failed  += $result['failed'];

			// Only the rows that did something, so a media library that is already whole reports one
			// line instead of one per image.
			if ( $result['written'] || $result['failed'] ) {
				$report[] = array(
					'id'      => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'file'    => isset( $row['file'] ) ? (string) $row['file'] : '',
					'written' => $result['written'],
					'failed'  => $result['failed'],
				);
			}
		}

		if ( $report ) {
			WP_CLI\Utils\format_items( 'table', $report, array( 'id', 'file', 'written', 'failed' ) );
		}

		if ( $failed ) {
			// A file that could not be written is worth failing on rather than mentioning: the whole point
			// of the command is that after it the images are there, and a message that reads as success
			// while a picture is still missing is what this is avoiding.
			WP_CLI::error( sprintf( '%d file(s) written, %d could not be, %d already present.', $written, $failed, $kept ) );
		}

		WP_CLI::success( sprintf( '%d file(s) written, %d already present.', $written, $kept ) );
	}
}

WP_CLI::add_command( 'bookify-supabase', 'Bookify_Supabase_Command' );
