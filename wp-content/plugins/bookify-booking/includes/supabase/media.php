<?php
/**
 * The photographs, carried as data.
 *
 * The five entity tables in `transform.php` are enough to describe a booking. They are not enough to
 * *show* one, because a page also needs the image files, and those live in `wp-content/uploads` — a
 * directory on whichever machine happens to be serving the request. On a host with a persistent disk
 * that is fine and this file does nothing. On a host without one the directory is empty every time
 * the container is replaced, the rows in MariaDB survive, and the site comes back with every photo
 * broken. This is the half of the copy that fixes that.
 *
 * **The bytes go in the database, as base64, on purpose.** Supabase Storage and any S3 bucket would
 * be the smaller and the faster answer; both were considered and both were set aside deliberately. A
 * bucket is a second system, a second credential, and a second thing to configure on the target
 * host — and this site's entire media library is eight JPEGs totalling a little over a megabyte, held
 * against a 500 MB allowance. What is bought with that space is that the copy is *complete*: one
 * connection, one credential, one thing to restore, and no question about whether the pictures came
 * with the data.
 *
 * **Three moving parts.**
 *
 *  1. {@see bookify_booking_supabase_media_row()} turns one attachment into one row, reading every
 *     file WordPress generated for it. It is the transform, and like the others it neither sends
 *     anything nor decides anything.
 *  2. {@see bookify_booking_supabase_media_restore_files()} writes a row's files back to disk. It is
 *     what the `media restore` command and the on-demand path below both use.
 *  3. {@see bookify_booking_supabase_media_serve()} is the part that makes the whole thing invisible:
 *     a request for an uploads file that is not on disk is answered from Supabase, written, and
 *     handed back to the browser as if it had been there all along.
 *
 * **Every generated size is carried, not just the uploaded file.** A page references
 * `photo-300x200.jpg` as often as `photo.jpg` and `srcset` names four at once, so a copy that held
 * only the original would restore a working media library with every intermediate size still
 * missing. The row's `files` object is path-to-base64 for all of them.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The largest image, all sizes together, the mirror will carry.
 *
 * A ceiling rather than no ceiling, because this is the one table whose rows are sized by something
 * other than a form field, and a single 40 MB photograph would spend eight per cent of a free
 * project's whole database. Five megabytes is roughly forty times the largest file this site has, so
 * it is a guard against an accident and not a policy — an image over it is simply left out, and
 * `wp bookify-supabase media status` names it rather than leaving it to be discovered as a broken
 * picture later.
 *
 * Set `BOOKIFY_SUPABASE_MEDIA_MAX_BYTES`, or the `bookify_booking_supabase_media_max_bytes` filter,
 * to change it. Zero carries nothing at all, which is how this half of the mirror is switched off.
 */
const BOOKIFY_SUPABASE_MEDIA_MAX_BYTES = 5242880;

/**
 * The image types the mirror carries.
 *
 * Images and nothing else. This table exists so that a page renders, and a page renders with images;
 * carrying a PDF, an import file or a zip would spend the database's own room on bytes that nothing
 * on the site displays. It is also a whitelist rather than a `image/` prefix test, so a type nobody
 * has checked cannot arrive by being named well.
 *
 * @return string[]
 */
function bookify_booking_supabase_media_types() {
	return array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'image/avif',
	);
}

/**
 * How many bytes one image may occupy before it is left out.
 *
 * Read from configuration every time, like the mail and Stripe settings are, so a probe can set the
 * filter, check the refusal, and take it away again.
 *
 * @return int
 */
function bookify_booking_supabase_media_ceiling() {
	$bytes = bookify_booking_config_value(
		'BOOKIFY_SUPABASE_MEDIA_MAX_BYTES',
		array( 'BOOKIFY_SUPABASE_MEDIA_MAX_BYTES' ),
		'bookify_booking_supabase_media_max_bytes'
	);

	return '' === $bytes ? BOOKIFY_SUPABASE_MEDIA_MAX_BYTES : max( 0, (int) $bytes );
}

/**
 * Whether a string is a relative path this plugin is willing to turn into a filename.
 *
 * **This is the only check between a row in somebody else's database and the filesystem.** The paths
 * in `bookify_media.files` are written by this plugin, but they come back over the network, and the
 * code that uses them writes files under `wp-content/uploads`. A row is therefore not trusted: the
 * four ways to escape a directory are refused outright rather than sanitised away, because a path
 * that needs repairing is a path that should not have been sent.
 *
 * The shape allowed is what WordPress's own `sanitize_file_name()` produces — letters, digits, dot,
 * underscore, hyphen and slashes — with the extra rule that the first character is alphanumeric, so
 * nothing can address a dotfile.
 *
 * @param mixed $relative Candidate path, relative to the uploads directory.
 * @return bool
 */
function bookify_booking_supabase_media_is_path( $relative ) {
	if ( ! is_string( $relative ) || '' === $relative || strlen( $relative ) > 255 ) {
		return false;
	}

	if ( str_contains( $relative, '..' )
		|| str_contains( $relative, "\0" )
		|| str_contains( $relative, '\\' )
		|| str_starts_with( $relative, '/' ) ) {
		return false;
	}

	return (bool) preg_match( '{^[A-Za-z0-9][A-Za-z0-9._/-]*$}', $relative );
}

/**
 * Every file WordPress generated for one attachment, relative to the uploads directory.
 *
 * The uploaded file comes from `_wp_attached_file`, which is the path WordPress itself uses. The
 * generated sizes come from `_wp_attachment_metadata`, and each of those stores a bare filename
 * beside the uploaded file rather than a path — so the directory is taken from the uploaded file once
 * and prefixed. An image uploaded straight into the uploads root rather than into a year/month folder
 * has no directory to take, which is why that part is conditional.
 *
 * `original_image` is the pre-rotation copy WordPress keeps when an image was edited rather than
 * uploaded as it stands; it is a real file on disk and is carried for the same reason as the rest.
 *
 * @param int $attachment_id The attachment.
 * @return string[]
 */
function bookify_booking_supabase_media_paths( $attachment_id ) {
	$main = ltrim( (string) get_post_meta( absint( $attachment_id ), '_wp_attached_file', true ), '/' );

	if ( '' === $main ) {
		return array();
	}

	$slash     = strrpos( $main, '/' );
	$directory = false === $slash ? '' : substr( $main, 0, $slash + 1 );

	$paths = array( $main );
	$meta  = wp_get_attachment_metadata( $attachment_id );

	if ( is_array( $meta ) ) {
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$paths[] = $directory . $meta['original_image'];
		}

		$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();

		foreach ( $sizes as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$paths[] = $directory . $size['file'];
			}
		}
	}

	return array_values( array_unique( array_filter( $paths, 'bookify_booking_supabase_media_is_path' ) ) );
}

/**
 * The row for one image attachment.
 *
 * Null, and never an error, whenever this attachment is not something the table holds: a post that is
 * not an attachment, a mime type that is not an image, a file that is not on disk, or an image larger
 * than the ceiling. `_rows()` treats a null as "not a row of this table" and skips it, which is the
 * same arrangement a tier with no event already uses.
 *
 * `total_bytes` is the sum of the *decoded* sizes, and is counted before anything is read — so an
 * oversized image is refused without first being pulled into memory to measure it.
 *
 * @param int $attachment_id The attachment.
 * @return array|null Null when this attachment is not carried.
 */
function bookify_booking_supabase_media_row( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	$attachment    = get_post( $attachment_id );

	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
		return null;
	}

	$mime = (string) $attachment->post_mime_type;

	if ( ! in_array( $mime, bookify_booking_supabase_media_types(), true ) ) {
		return null;
	}

	$ceiling = bookify_booking_supabase_media_ceiling();

	if ( $ceiling <= 0 ) {
		return null;
	}

	$root  = trailingslashit( (string) wp_get_upload_dir()['basedir'] );
	$found = array();
	$total = 0;

	foreach ( bookify_booking_supabase_media_paths( $attachment_id ) as $relative ) {
		$path = $root . $relative;

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			continue;
		}

		$size = (int) filesize( $path );

		if ( $size <= 0 ) {
			continue;
		}

		$total += $size;

		if ( $total > $ceiling ) {
			return null;
		}

		$found[ $relative ] = $path;
	}

	if ( ! $found ) {
		return null;
	}

	$files = array();

	foreach ( $found as $relative => $path ) {
		$bytes = file_get_contents( $path );

		// Read as one string and encoded, and never passed through a filesystem abstraction: base64 is
		// what makes the bytes safe to carry through JSON, and it is the one property this needs.
		if ( false === $bytes ) {
			return null;
		}

		$files[ $relative ] = base64_encode( $bytes );
	}

	$metadata = wp_get_attachment_metadata( $attachment_id );

	return array(
		'id'          => $attachment_id,
		'file'        => ltrim( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), '/' ),
		'title'       => (string) $attachment->post_title,
		'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'mime_type'   => $mime,
		'width'       => bookify_booking_supabase_integer( is_array( $metadata ) && isset( $metadata['width'] ) ? $metadata['width'] : '' ),
		'height'      => bookify_booking_supabase_integer( is_array( $metadata ) && isset( $metadata['height'] ) ? $metadata['height'] : '' ),
		'total_bytes' => $total,
		'files'       => $files,
		'created_at'  => bookify_booking_supabase_timestamp( $attachment->post_date_gmt ),
		'updated_at'  => bookify_booking_supabase_timestamp( $attachment->post_modified_gmt ),
	);
}

/**
 * The `files` of a row that came back from Supabase, as a usable map of path to base64.
 *
 * Two things happen here and both are needed. A `jsonb` column arrives as a **string** through
 * `pdo_pgsql` and as an **array** through PostgREST, so one of the two transports has to be decoded
 * and only the caller knows which it got. And the paths are re-checked on the way in: a row that has
 * been edited by hand, or written by an older version of this file, does not get to name a file
 * outside the uploads directory.
 *
 * @param array<string,mixed> $row A row as read back from Supabase.
 * @return array<string,string> Relative path to base64 content.
 */
function bookify_booking_supabase_media_row_files( array $row ) {
	$files = isset( $row['files'] ) ? $row['files'] : array();

	if ( is_string( $files ) ) {
		$decoded = json_decode( $files, true );
		$files   = is_array( $decoded ) ? $decoded : array();
	}

	if ( ! is_array( $files ) ) {
		return array();
	}

	$clean = array();

	foreach ( $files as $relative => $encoded ) {
		if ( bookify_booking_supabase_media_is_path( $relative ) && is_string( $encoded ) && '' !== $encoded ) {
			$clean[ (string) $relative ] = $encoded;
		}
	}

	return $clean;
}

/**
 * Which of a row's files are not on this machine.
 *
 * The question `media status` and `media restore` are both built on.
 *
 * @param array<string,mixed> $row A row as read back from Supabase.
 * @return string[] Relative paths that are missing.
 */
function bookify_booking_supabase_media_missing( array $row ) {
	$root    = trailingslashit( (string) wp_get_upload_dir()['basedir'] );
	$missing = array();

	foreach ( array_keys( bookify_booking_supabase_media_row_files( $row ) ) as $relative ) {
		if ( ! is_file( $root . $relative ) ) {
			$missing[] = $relative;
		}
	}

	return $missing;
}

/**
 * Write one file into the uploads directory.
 *
 * Written beside the target and moved into place. A directory that is being repopulated is read by
 * whatever else is serving the site at the same time, and a half-written JPEG is a worse outcome than
 * no file at all: the next attempt would find it, decide the work was done, and leave it there.
 *
 * @param string $relative Path relative to the uploads directory.
 * @param string $bytes    The file's contents.
 * @return bool
 */
function bookify_booking_supabase_media_write( $relative, $bytes ) {
	if ( ! bookify_booking_supabase_media_is_path( $relative ) ) {
		return false;
	}

	$path      = trailingslashit( (string) wp_get_upload_dir()['basedir'] ) . $relative;
	$directory = dirname( $path );

	if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
		return false;
	}

	$partial = $path . '.bookify-part';

	if ( false === file_put_contents( $partial, $bytes ) ) {
		return false;
	}

	if ( ! rename( $partial, $path ) ) {
		unlink( $partial );

		return false;
	}

	return true;
}

/**
 * Put one row's files back on disk.
 *
 * Skipped by default when the file is already there, which is what makes a restore cheap to run on
 * every start: the second and every later run reads the rows and writes nothing.
 *
 * The base64 is decoded strictly. A row that has been truncated by a limit somewhere on the way back
 * decodes to a shorter file rather than to an error if the strict flag is left off, and a truncated
 * image is a broken image that reports itself as restored.
 *
 * @param array<string,mixed> $row   A row as read back from Supabase.
 * @param bool                $force Optional. Rewrite files that are already present.
 * @return array{written:int,kept:int,failed:int}
 */
function bookify_booking_supabase_media_restore_files( array $row, $force = false ) {
	$root   = trailingslashit( (string) wp_get_upload_dir()['basedir'] );
	$result = array(
		'written' => 0,
		'kept'    => 0,
		'failed'  => 0,
	);

	foreach ( bookify_booking_supabase_media_row_files( $row ) as $relative => $encoded ) {
		if ( ! $force && is_file( $root . $relative ) ) {
			++$result['kept'];

			continue;
		}

		$bytes = base64_decode( $encoded, true );

		if ( false === $bytes || '' === $bytes || ! bookify_booking_supabase_media_write( $relative, $bytes ) ) {
			++$result['failed'];

			continue;
		}

		++$result['written'];
	}

	return $result;
}

/**
 * Rows from the media table, or a `WP_Error` saying why not.
 *
 * Errors are returned rather than swallowed, because the two callers want different things: the
 * command prints them, and the request handler below treats one as "carry on and be a 404".
 *
 * @param int[] $ids Optional. Ids to read. Default: every row.
 * @return array<int,array<string,mixed>>|\WP_Error
 */
function bookify_booking_supabase_media_fetch( array $ids = array() ) {
	if ( ! bookify_booking_supabase_is_configured() ) {
		return new WP_Error(
			'bookify_supabase_not_configured',
			__( 'Supabase is not configured, so no images could be read back.', 'bookify-booking' )
		);
	}

	$rows = bookify_booking_supabase_select( 'bookify_media', $ids );

	return is_wp_error( $rows ) ? $rows : (array) $rows;
}

/**
 * The attachment that one of its own files belongs to, by path relative to the uploads directory.
 *
 * **Deliberately not `attachment_url_to_postid()`**, which is the obvious candidate and is wrong here.
 * That function looks the path up in `_wp_attached_file` and stops there: it answers for the file that
 * was uploaded and has no answer for a generated size. Read the current source and it is plain — one
 * `SELECT ... WHERE meta_value = %s`, no attempt to strip a size suffix, and a return of 0 when
 * nothing matches. Measured: it resolves `counselling-session.jpg` to 575 and
 * `counselling-session-768x512.jpg` to 0, on a site where the second file is that attachment's own
 * 768-pixel size.
 *
 * That would matter even if only the full-size file were ever requested, because it is not. A page
 * references `photo-300x200.jpg` as often as `photo.jpg`, `srcset` names four sizes at once, and every
 * one of them is a URL that has to resolve to its attachment before the image behind it can be found.
 * A resolver that answered only for the original would restore the smallest share of the library and
 * leave the rest of the pages broken.
 *
 * So the path is looked up twice: as given, and with the `-WIDTHxHEIGHT` suffix WordPress appends
 * removed. Both are equality lookups against the same meta key, both prepared, and the second only
 * runs when the first finds nothing.
 *
 * @param string $relative Path relative to the uploads directory.
 * @return int The attachment id, or 0 when this site has no such file.
 */
function bookify_booking_supabase_media_attachment( $relative ) {
	global $wpdb;

	$relative = ltrim( (string) $relative, '/' );

	if ( '' === $relative || ! bookify_booking_supabase_media_is_path( $relative ) ) {
		return 0;
	}

	$candidates = array( $relative );

	// `photo-300x200.jpg` is stored as `photo.jpg`. WordPress's generated suffixes are always
	// `-<digits>x<digits>` immediately before the extension, and nothing else is stripped: a file the
	// owner named `photo-2.jpg` keeps its name, and one they named `photo-300x200.jpg` themselves is
	// found by the first pass above before this one runs at all.
	$stripped = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $relative );

	if ( is_string( $stripped ) && $stripped !== $relative ) {
		$candidates[] = $stripped;
	}

	foreach ( $candidates as $candidate ) {
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$candidate
			)
		);

		if ( $found ) {
			return (int) $found;
		}
	}

	return 0;
}

/**
 * Answer a request for an uploads file that is not on disk.
 *
 * This is the whole reason the copy is useful rather than merely complete. Nothing has to be
 * restored in advance, no start-up step has to succeed, and no page has to know that the file it is
 * about to reference is missing — the first request for each image brings it back, and every request
 * after that is served from disk by the web server.
 *
 * It runs on a 404, which is what a missing static file becomes: WordPress's own rewrite rules send
 * any request that is not a real file to `index.php`, so a broken image arrives here rather than
 * being answered by Apache. Three things stop it from being a way to make the site do work:
 * the path has to be under the uploads URL, it has to be a plain relative path, and it has to name a
 * file this site actually has — a genuine 404 names nothing, and stops before Supabase is asked.
 *
 * @return void
 */
function bookify_booking_supabase_media_serve() {
	if ( ! is_404() || ! bookify_booking_supabase_is_configured() ) {
		return;
	}

	$requested = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
	$path      = wp_parse_url( $requested, PHP_URL_PATH );
	$uploads   = wp_get_upload_dir();
	$baseurl   = wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path || ! is_string( $baseurl ) || '' === $baseurl ) {
		return;
	}

	$prefix = trailingslashit( $baseurl );

	if ( ! str_starts_with( $path, $prefix ) ) {
		return;
	}

	// A browser asks for a file by its encoded name and a filesystem wants it decoded. The result is
	// checked immediately, so a name that decodes into something that is not a plain relative path
	// stops here and never reaches the filesystem.
	$relative = rawurldecode( substr( $path, strlen( $prefix ) ) );

	if ( ! bookify_booking_supabase_media_is_path( $relative ) ) {
		return;
	}

	if ( is_file( trailingslashit( (string) $uploads['basedir'] ) . $relative ) ) {
		return;
	}

	// The attachment this file belongs to, whether it is the uploaded file or one of the generated
	// sizes — which are the majority of the URLs a page asks for, and the reason this is not simply
	// `attachment_url_to_postid()`.
	$attachment_id = bookify_booking_supabase_media_attachment( $relative );

	if ( ! $attachment_id ) {
		return;
	}

	$rows = bookify_booking_supabase_media_fetch( array( $attachment_id ) );

	if ( is_wp_error( $rows ) || ! $rows ) {
		return;
	}

	$files = bookify_booking_supabase_media_row_files( $rows[0] );
	$main  = isset( $rows[0]['file'] ) ? (string) $rows[0]['file'] : '';

	// The size that was asked for, or the uploaded file it was generated from. The fallback is
	// deliberate: a size that was never generated, or a row written before one existed, still has an
	// image in it, and an image at the wrong dimensions is a better answer than a broken one.
	$encoded = '';

	foreach ( array( $relative, $main ) as $candidate ) {
		if ( '' !== $candidate && isset( $files[ $candidate ] ) ) {
			$encoded = $files[ $candidate ];

			break;
		}
	}

	if ( '' === $encoded ) {
		return;
	}

	$bytes = base64_decode( $encoded, true );

	if ( false === $bytes || '' === $bytes || ! bookify_booking_supabase_media_write( $relative, $bytes ) ) {
		return;
	}

	// Now on disk, so the browser is sent back to the same address and the web server serves it — with
	// the right content type, byte ranges and its own caching, none of which this function would get
	// right by echoing a string. The `is_file` check above means a write that did not happen cannot
	// produce a loop, because the redirect only follows a successful one.
	wp_redirect( trailingslashit( (string) $uploads['baseurl'] ) . $relative, 302 );

	exit;
}

/**
 * Start answering for missing images.
 */
function bookify_booking_register_supabase_media() {
	// Priority 0: this is a request for a file, not a page. Nothing about rendering a template is needed
	// first, and the earlier this can be decided the less work is done to decide it is not our business.
	add_action( 'template_redirect', 'bookify_booking_supabase_media_serve', 0 );
}
