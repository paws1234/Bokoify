<?php
/**
 * Proves that the image files survive the journey.
 *
 * `_probes/supabase-sync.php` already checks that every row the transforms produce matches
 * `schema.sql`, and it picks up `bookify_media` for free because it walks the table map. It cannot
 * check what this file checks, which is the thing the table exists for: that the bytes come back
 * **identical**. A row can have the right columns, the right types and a plausible base64 string and
 * still be a broken JPEG.
 *
 * What is proved here:
 *
 *  1. A real image's row carries *every* file WordPress generated for it — the uploaded file and every
 *     size in `_wp_attachment_metadata` — and each one decodes to the file on disk, byte for byte.
 *  2. `total_bytes` is the sum of the decoded lengths, and the `file` column is what WordPress itself
 *     stored in `_wp_attached_file`.
 *  3. The restore puts a wiped directory back. A copy of a real photograph is uploaded, mirrored, has
 *     its files deleted from disk, and is then restored and compared hash by hash. This is the claim.
 *  4. The refusals work. A non-image produces no row. An image over the ceiling produces no row. And a
 *     path that would escape `wp-content/uploads` is refused rather than repaired — that one is the
 *     only thing standing between a row in a third-party database and this filesystem.
 *  5. `thumbnail_id` on a session is the featured image WordPress has, and names a file that exists.
 *
 * Deliberately no base64 on stdout, and no value in a failure message. This runs in a terminal that
 * may become a transcript, and a megabyte of JPEG is not a useful thing to put in one.
 *
 * Run it with:
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/supabase-media.php
 *
 * @package bookify-booking
 */

/**
 * How many checks ran, and how many failed.
 *
 * In `$GLOBALS` and not in a plain local, because `wp eval-file` evaluates this file inside a method
 * — so a variable declared here is local to that method and the functions below cannot see it.
 */
$GLOBALS['bookify_media_probe'] = array(
	'checks'   => 0,
	'failures' => 0,
);

/**
 * The ceiling this probe installs to check the refusal, as a named function.
 *
 * Named rather than a closure because it has to be removable, and an anonymous function cannot be
 * named again in the `remove_filter()` call.
 *
 * @return string
 */
function bookify_media_probe_ceiling() {
	return '1';
}

/**
 * Report one check, and count it.
 *
 * Prefixed rather than sharing `probe_check()` with `supabase-sync.php`: both can be loaded into the
 * same PHP process, and a second declaration of the same function would be a fatal error.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it passed.
 * @param string $detail Extra detail, usually why it failed.
 * @return void
 */
function bookify_media_probe_check( $label, $ok, $detail = '' ) {
	global $bookify_media_probe;

	++$bookify_media_probe['checks'];

	if ( ! $ok ) {
		++$bookify_media_probe['failures'];
	}

	fwrite( STDERR, sprintf( "[%s] %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, '' === $detail ? '' : ' — ' . $detail ) );
}

/**
 * Delete a file, without complaining when it is already gone.
 *
 * `wp_delete_attachment()` — which is what `wp_delete_post()` calls for an attachment — removes the
 * uploaded file and its sizes itself, so the explicit tidy-up at the end is usually a second attempt
 * at a file that has already gone.
 *
 * @param string $path Absolute path.
 * @return void
 */
function bookify_media_probe_unlink( $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}

$uploads = wp_get_upload_dir();
$root    = trailingslashit( (string) $uploads['basedir'] );

// ---- 1: a real photograph, all the way down ----------------------------------------------------

$images = bookify_booking_supabase_local_ids( 'bookify_media' );

bookify_media_probe_check(
	sprintf( 'the media library offers %d image(s) to carry', count( $images ) ),
	array() !== $images
);

// The largest one, so the loop below has the most to go wrong in it.
$sample  = 0;
$largest = -1;

foreach ( $images as $candidate ) {
	$meta = wp_get_attachment_metadata( $candidate );
	$size = is_array( $meta ) && isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;

	if ( $size > $largest ) {
		$largest = $size;
		$sample  = (int) $candidate;
	}
}

$row = bookify_booking_supabase_media_row( $sample );

bookify_media_probe_check( sprintf( 'attachment %d produces a row', $sample ), is_array( $row ) );

if ( ! is_array( $row ) ) {
	bookify_media_probe_check( 'nothing further can be checked without a row', false );

	echo "--- BEGIN MEDIA PROBE ---\n";
	echo wp_json_encode( $GLOBALS['bookify_media_probe'] ) . "\n";
	echo "--- END MEDIA PROBE ---\n";

	return;
}

$files = bookify_booking_supabase_media_row_files( $row );

bookify_media_probe_check( 'the row carries at least one file', array() !== $files );

bookify_media_probe_check(
	'the file column is what WordPress stored',
	ltrim( (string) get_post_meta( $sample, '_wp_attached_file', true ), '/' ) === $row['file'],
	'file=' . $row['file']
);

bookify_media_probe_check( 'and it is one of the files carried', isset( $files[ $row['file'] ] ) );

$metadata = wp_get_attachment_metadata( $sample );
$sizes    = is_array( $metadata ) && isset( $metadata['sizes'] ) ? (array) $metadata['sizes'] : array();

bookify_media_probe_check(
	'width and height are what WordPress recorded',
	is_array( $metadata )
		&& (int) $row['width'] === (int) $metadata['width']
		&& (int) $row['height'] === (int) $metadata['height'],
	sprintf( 'row=%dx%d metadata=%dx%d', (int) $row['width'], (int) $row['height'], (int) $metadata['width'], (int) $metadata['height'] )
);

// Every size WordPress generated has to be in there. A copy holding only the uploaded file would
// restore a media library in which every intermediate size is a 404, and `srcset` names four at once.
$expected = array( $row['file'] );

foreach ( $sizes as $size ) {
	if ( isset( $size['file'] ) ) {
		$expected[] = dirname( $row['file'] ) . '/' . $size['file'];
	}
}

$expected = array_values( array_unique( $expected ) );
$absent   = array_diff( $expected, array_keys( $files ) );

bookify_media_probe_check(
	sprintf( 'every generated size is carried (%d file(s) expected)', count( $expected ) ),
	array() === $absent,
	'missing: ' . implode( ', ', $absent )
);

// ---- 2: the bytes, which is the only check that actually matters --------------------------------

$decoded_total = 0;
$mismatched    = array();
$outside       = array();

foreach ( $files as $relative => $encoded ) {
	// The containment test is on the joined path, because that is what a traversal attempts to fool.
	if ( ! str_starts_with( $root . $relative, $root ) || str_contains( $relative, '..' ) ) {
		$outside[] = $relative;

		continue;
	}

	$bytes = base64_decode( $encoded, true );

	if ( false === $bytes ) {
		$mismatched[] = $relative . ' (not base64)';

		continue;
	}

	$decoded_total += strlen( $bytes );

	// The claim, in one comparison. md5 rather than `===` so that a failure is a short line rather than
	// half a megabyte of binary.
	if ( md5_file( $root . $relative ) !== md5( $bytes ) ) {
		$mismatched[] = $relative;
	}
}

bookify_media_probe_check( 'every carried file is inside wp-content/uploads', array() === $outside, implode( ', ', $outside ) );

bookify_media_probe_check(
	sprintf( '%d file(s) decode to exactly what is on disk', count( $files ) ),
	array() === $mismatched,
	implode( ', ', $mismatched )
);

bookify_media_probe_check(
	'total_bytes is the sum of the decoded sizes',
	(int) $row['total_bytes'] === $decoded_total,
	sprintf( 'total_bytes=%d decoded=%d', (int) $row['total_bytes'], $decoded_total )
);

// ---- 3: the row as it arrives back -------------------------------------------------------------
//
// pdo_pgsql hands a jsonb column back as the text Postgres sent; PostgREST hands back an array. The
// string form is the one that has to be decoded by hand, so it is checked here rather than assumed.

$as_text          = $row;
$as_text['files'] = (string) wp_json_encode( $row['files'] );

bookify_media_probe_check(
	'a jsonb column arriving as a string is understood',
	bookify_booking_supabase_media_row_files( $as_text ) === $files
);

bookify_media_probe_check(
	'and one arriving as an array is understood too',
	bookify_booking_supabase_media_row_files( $row ) === $files
);

$traversal          = $row;
$traversal['files'] = array(
	'../../wp-config.php' => base64_encode( 'probe' ),
	'/etc/passwd'         => base64_encode( 'probe' ),
	'a/../../b.jpg'       => base64_encode( 'probe' ),
	$row['file']          => $files[ $row['file'] ],
);

bookify_media_probe_check(
	'paths that leave the uploads directory are dropped from a row',
	array( $row['file'] ) === array_keys( bookify_booking_supabase_media_row_files( $traversal ) )
);

bookify_media_probe_check(
	'a row whose files are not JSON at all yields nothing to write',
	array() === bookify_booking_supabase_media_row_files( array( 'files' => 'not json' ) )
);

// ---- 4: the refusals --------------------------------------------------------------------------

$refused_paths = array(
	'../../wp-config.php',
	'/etc/passwd',
	'a/../../b.jpg',
	'a\\b.jpg',
	'',
	'.hidden',
	"x\0.jpg",
);

foreach ( $refused_paths as $bad ) {
	bookify_media_probe_check(
		sprintf( 'refuses the path %s', '' === $bad ? '(empty)' : var_export( $bad, true ) ),
		! bookify_booking_supabase_media_is_path( $bad )
	);
}

bookify_media_probe_check( 'and accepts an ordinary one', bookify_booking_supabase_media_is_path( '2026/09/photo-300x200.jpg' ) );

// `$root . '../../wp-config.php'` resolves to the site's own configuration file, which is the point:
// if the refusal ever stopped working, that is what it would be writing over. Hashed before and after
// rather than tested for existence, because the file is supposed to be there either way.
$escape        = $root . '../../wp-config.php';
$before_escape = is_file( $escape ) ? md5_file( $escape ) : 'none';

bookify_media_probe_check(
	'writing through a refused path is refused',
	! bookify_booking_supabase_media_write( '../../wp-config.php', 'probe' )
);

bookify_media_probe_check(
	'and the file it named is untouched',
	( is_file( $escape ) ? md5_file( $escape ) : 'none' ) === $before_escape
);

foreach ( array( '../../wp-config.php', '/etc/passwd' ) as $bad ) {
	$attempt = bookify_booking_supabase_media_restore_files( array( 'files' => array( $bad => base64_encode( 'probe' ) ) ) );

	// Nothing written *and* nothing failed: the path is dropped by `media_row_files()` before the loop
	// that would have tried to write it, so there is no attempt to fail. That is the stronger of the two
	// outcomes, and asserting a failure here would have been asserting the wrong behaviour.
	bookify_media_probe_check(
		sprintf( 'restoring a row naming %s writes nothing and attempts nothing', $bad ),
		0 === $attempt['written'] && 0 === $attempt['failed'],
		sprintf( 'written=%d failed=%d', $attempt['written'], $attempt['failed'] )
	);
}

// A non-image is not a row of this table. A text file is the cheapest way to say so.
$text_path = $root . '2026/09/probe-media.txt';
file_put_contents( $text_path, 'not an image' );

$text_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'text/plain',
		'post_title'     => 'Supabase media probe (not an image)',
		'post_status'    => 'inherit',
	),
	$text_path
);

bookify_media_probe_check(
	'a non-image attachment produces no row',
	null === bookify_booking_supabase_media_row( $text_id ),
	'attachment ' . (int) $text_id
);

bookify_media_probe_check(
	'and it is not in the list of ids to carry',
	! in_array( (int) $text_id, $images, true )
);

wp_delete_post( $text_id, true );
bookify_media_probe_unlink( $text_path );

// The ceiling. Set to one byte, the largest photograph cannot fit — the same refusal an accidentally
// enormous upload would meet, checked with a filter rather than by making one.
add_filter( 'bookify_booking_supabase_media_max_bytes', 'bookify_media_probe_ceiling' );

$refused = bookify_booking_supabase_media_row( $sample );

remove_filter( 'bookify_booking_supabase_media_max_bytes', 'bookify_media_probe_ceiling' );

bookify_media_probe_check( 'an image over the ceiling produces no row', null === $refused );

bookify_media_probe_check(
	'and produces one again once the ceiling is back',
	is_array( bookify_booking_supabase_media_row( $sample ) )
);

// ---- 5: the featured images point at something that is carried --------------------------------

$services = bookify_booking_supabase_rows( 'bookify_services' );
$linked   = 0;
$dangling = array();

foreach ( $services as $service ) {
	$thumbnail = isset( $service['thumbnail_id'] ) ? $service['thumbnail_id'] : null;

	bookify_media_probe_check(
		sprintf( 'session %d publishes the featured image WordPress has', $service['id'] ),
		null === $thumbnail || (int) get_post_thumbnail_id( (int) $service['id'] ) === (int) $thumbnail
	);

	if ( null === $thumbnail ) {
		continue;
	}

	++$linked;

	if ( ! in_array( (int) $thumbnail, $images, true ) ) {
		$dangling[] = (int) $thumbnail;
	}
}

bookify_media_probe_check(
	sprintf( '%d session(s) name a featured image, and every one of them is carried', $linked ),
	array() === $dangling,
	'not carried: ' . implode( ', ', $dangling )
);

// ---- 5b: a URL has to resolve to its attachment, sizes included -------------------------------
//
// The on-demand handler is handed a URL and has to work out which row to fetch. Most of the URLs a
// page asks for are generated sizes, which are not in `_wp_attached_file`, so this is the difference
// between restoring a media library and restoring only its originals.

$uploads_baseurl = trailingslashit( (string) wp_get_upload_dir()['baseurl'] );
$first_size      = '';

foreach ( $sizes as $size ) {
	if ( isset( $size['file'] ) ) {
		$first_size = dirname( $row['file'] ) . '/' . $size['file'];

		break;
	}
}

bookify_media_probe_check(
	sprintf( 'the uploaded file resolves to attachment %d', $sample ),
	$sample === bookify_booking_supabase_media_attachment( $row['file'] ),
	'resolved to ' . bookify_booking_supabase_media_attachment( $row['file'] )
);

if ( '' !== $first_size ) {
	bookify_media_probe_check(
		sprintf( 'and the generated size %s resolves to the same attachment', basename( $first_size ) ),
		$sample === bookify_booking_supabase_media_attachment( $first_size ),
		'resolved to ' . bookify_booking_supabase_media_attachment( $first_size )
	);

	// A canary, not a complaint. `attachment_url_to_postid()` matches `_wp_attached_file` and stops,
	// which is why the helper above exists — so if this ever starts failing, core has learned to strip
	// the size suffix and the helper can be deleted.
	$core = attachment_url_to_postid( $uploads_baseurl . $first_size );

	bookify_media_probe_check(
		'core alone still cannot resolve a size, so the helper is still needed',
		0 === $core,
		'attachment_url_to_postid() now returns ' . $core . ' — if that is right, bookify_booking_supabase_media_attachment() can go'
	);
}

bookify_media_probe_check(
	'a path with no attachment behind it resolves to nothing',
	0 === bookify_booking_supabase_media_attachment( '2026/09/not-a-real-photo.jpg' )
);

bookify_media_probe_check(
	'and a path that tries to escape is refused before any query runs',
	0 === bookify_booking_supabase_media_attachment( '../../wp-config.php' )
);

// ---- 6: the restore, which is what all of this is for -----------------------------------------
//
// A photograph is copied, uploaded as its own attachment and mirrored — and then its files are deleted
// from disk, which is exactly what replacing a container without a persistent disk does. If this
// passes, the copy is genuinely enough to bring the images back; if it does not, everything above is
// describing a backup that cannot be restored.

$fixture_relative = dirname( $row['file'] ) . '/probe-media-' . wp_generate_password( 10, false, false ) . '.jpg';
$fixture_path     = $root . $fixture_relative;

copy( get_attached_file( $sample ), $fixture_path );

$fixture_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'Supabase media probe (restore)',
		'post_status'    => 'inherit',
	),
	$fixture_path
);

wp_update_attachment_metadata( $fixture_id, wp_generate_attachment_metadata( $fixture_id, $fixture_path ) );
update_post_meta( $fixture_id, '_wp_attachment_image_alt', 'Probe fixture' );

$fixture_row = bookify_booking_supabase_media_row( $fixture_id );

bookify_media_probe_check( sprintf( 'the fixture attachment %d produces a row', $fixture_id ), is_array( $fixture_row ) );

if ( is_array( $fixture_row ) ) {
	bookify_media_probe_check(
		'and its alt text is carried',
		'Probe fixture' === $fixture_row['alt'],
		'alt=' . var_export( $fixture_row['alt'], true )
	);

	$fixture_files = bookify_booking_supabase_media_row_files( $fixture_row );
	$count         = count( $fixture_files );

	// What is on disk before anything is touched. The restore is judged against this and not against
	// the row, so a row that had quietly captured the wrong bytes cannot pass by agreeing with itself.
	$before = array();

	foreach ( array_keys( $fixture_files ) as $relative ) {
		$before[ $relative ] = md5_file( $root . $relative );
	}

	// Wipe it, the way a container replacement would.
	foreach ( array_keys( $fixture_files ) as $relative ) {
		unlink( $root . $relative );
	}

	$gone = 0;

	foreach ( array_keys( $fixture_files ) as $relative ) {
		if ( ! is_file( $root . $relative ) ) {
			++$gone;
		}
	}

	bookify_media_probe_check( sprintf( '%d file(s) deleted from disk', $gone ), $gone === $count );

	bookify_media_probe_check(
		sprintf( 'all %d of them are reported as missing', $count ),
		count( bookify_booking_supabase_media_missing( $fixture_row ) ) === $count
	);

	$restored = bookify_booking_supabase_media_restore_files( $fixture_row );

	bookify_media_probe_check(
		sprintf( 'the restore writes all %d of them back', $count ),
		$restored['written'] === $count && 0 === $restored['failed'],
		sprintf( 'written=%d kept=%d failed=%d', $restored['written'], $restored['kept'], $restored['failed'] )
	);

	$changed = array();

	foreach ( $before as $relative => $hash ) {
		if ( md5_file( $root . $relative ) !== $hash ) {
			$changed[] = $relative;
		}
	}

	bookify_media_probe_check( 'and every one is byte for byte what it was', array() === $changed, implode( ', ', $changed ) );

	// Idempotent, which is what makes this safe to run on every start rather than only once.
	$again = bookify_booking_supabase_media_restore_files( $fixture_row );

	bookify_media_probe_check(
		'a second restore writes nothing and keeps everything',
		0 === $again['written'] && 0 === $again['failed'] && $again['kept'] === $count,
		sprintf( 'written=%d kept=%d failed=%d', $again['written'], $again['kept'], $again['failed'] )
	);

	// And `--force`, which is the repair path for a file that is present but wrong.
	file_put_contents( $root . $fixture_row['file'], 'corrupted' );

	$forced = bookify_booking_supabase_media_restore_files( $fixture_row, true );

	bookify_media_probe_check(
		'--force rewrites a file that is present but wrong',
		$forced['written'] === $count && md5_file( $root . $fixture_row['file'] ) === $before[ $fixture_row['file'] ],
		sprintf( 'written=%d', $forced['written'] )
	);

	// A row truncated in transit must not be written as a shorter file and then reported as restored.
	$truncated          = $fixture_row;
	$truncated['files'] = array( $fixture_row['file'] => substr( $fixture_files[ $fixture_row['file'] ], 0, 20 ) . '!!' );

	$broken = bookify_booking_supabase_media_restore_files( $truncated, true );

	bookify_media_probe_check(
		'undecodable base64 is counted as a failure, not written',
		0 === $broken['written'] && 1 === $broken['failed'],
		sprintf( 'written=%d failed=%d', $broken['written'], $broken['failed'] )
	);

	// Nothing may be left beside the files it was writing.
	$partials = glob( $root . dirname( $fixture_relative ) . '/*.bookify-part' );

	bookify_media_probe_check( 'no partial file is left behind', array() === $partials, implode( ', ', (array) $partials ) );

	// Cleanup. The attachment first, so the mirror's `deleted_post` watcher sees it; WordPress removes
	// the uploaded file and its sizes as part of that, so these are second attempts.
	wp_delete_post( $fixture_id, true );

	foreach ( array_keys( $fixture_files ) as $relative ) {
		bookify_media_probe_unlink( $root . $relative );
		bookify_media_probe_unlink( $root . $relative . '.bookify-part' );
	}
}

bookify_media_probe_check(
	sprintf( '%d check(s), %d failure(s)', $GLOBALS['bookify_media_probe']['checks'], $GLOBALS['bookify_media_probe']['failures'] ),
	0 === $GLOBALS['bookify_media_probe']['failures']
);

// stdout stays pipeable, and deliberately carries no base64: sizes and counts are what is worth
// reading, and a megabyte of image is not.
echo "--- BEGIN MEDIA PROBE ---\n";
echo wp_json_encode(
	array_merge(
		$GLOBALS['bookify_media_probe'],
		array(
			'images'       => count( $images ),
			'sample'       => $sample,
			'sample_file'  => $row['file'],
			'sample_files' => count( $files ),
			'sample_bytes' => $decoded_total,
		)
	)
) . "\n";
echo "--- END MEDIA PROBE ---\n";
