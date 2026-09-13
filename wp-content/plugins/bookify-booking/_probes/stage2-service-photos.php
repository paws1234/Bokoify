<?php
/**
 * One photograph per service, imported into the media library.
 *
 * The photographs are CC0 (public domain dedication) stock from StockSnap.io, so they carry no
 * attribution requirement and no restriction on commercial use — which is what makes them safe to
 * publish on a studio's own site. The source and its photo id are kept in the attachment's
 * description anyway, so a photograph can always be traced back to where it came from.
 *
 * Two deliberate choices about *what* each picture shows. Nothing here is a photograph of this
 * studio, its therapists or its clients, and none of it pretends to be: the alt text describes what
 * is actually in the frame ("potted plants on a sunlit windowsill"), never the service. And where
 * a literal photograph did not exist in a collection that was free to use, the picture is the room
 * or the equipment rather than a staged scene of people pretending — a calm interior says more
 * about a counselling room than a posed handshake would.
 *
 * The files are read from `wp-content/uploads/_staging/`, which is the one place inside the
 * container that a host-side download can be put: the container mounts uploads, and nothing else
 * outside the theme and this plugin. Delete that folder afterwards — this probe copies, it does not
 * consume, so leaving it behind would only leave eight duplicates in the uploads tree. To put them
 * back after a database reset, fetch each photo id below from
 * `https://cdn.stocksnap.io/img-thumbs/960w/<id>.jpg` with a browser user-agent and a
 * `https://stocksnap.io/` referer, because that CDN refuses the request without both.
 *
 * Re-runnable: a service that already has a featured image is left alone, so an owner who chooses a
 * different photograph does not lose it to a re-run.
 *
 *   wpdev wp eval-file wp-content/plugins/bookify-booking/_probes/stage2-service-photos.php
 */

$photos = array(
	'counselling-session'      => array(
		'file'  => 'counselling-session.jpg',
		'title' => 'Houseplants on a sunlit windowsill',
		'alt'   => 'Three potted plants on a windowsill in daylight',
		'source' => 'DXLNXCCDGP',
	),
	'physical-therapy-session' => array(
		'file'  => 'physical-therapy-session.jpg',
		'title' => 'A side stretch on a mat beside a tall window',
		'alt'   => 'A person holding a side stretch on a mat beside a tall window',
		'source' => 'MVCPFTBOTT',
	),
	'sports-massage-session'   => array(
		'file'  => 'sports-massage-session.jpg',
		'title' => 'Hands working across a shoulder on a treatment table',
		'alt'   => 'Two hands working across a shoulder during a massage on a treatment table',
		'source' => 'VH22RVC5UT',
	),
	'haircut-at-the-studio'    => array(
		'file'  => 'haircut-at-the-studio.jpg',
		'title' => 'Scissors cutting a section of long hair',
		'alt'   => 'Scissors cutting a section of long hair, seen from behind',
		'source' => 'S7UEWWIRTD',
	),
	'haircut-at-your-home'     => array(
		'file'  => 'haircut-at-your-home.jpg',
		'title' => 'A close-up of long wavy hair',
		'alt'   => 'A close-up of long, wavy, light brown hair',
		'source' => 'HDZE2J2VNL',
	),
	'one-to-one-session'       => array(
		'file'  => 'one-to-one-session.jpg',
		'title' => 'A trainer guiding one client through a stretch',
		'alt'   => 'A trainer guiding one client through an arm stretch in a bright studio',
		'source' => 'Q9DNPMZNQT',
	),
	'partner-session'          => array(
		'file'  => 'partner-session.jpg',
		'title' => 'Two people training side by side',
		'alt'   => 'Two people lifting dumbbells side by side in a studio',
		'source' => 'DHFRHWFYAH',
	),
	'small-group-class'        => array(
		'file'  => 'small-group-class.jpg',
		'title' => 'A small class seated on mats',
		'alt'   => 'Three people sitting cross-legged on mats in a studio with tall windows',
		'source' => 'NK0FIGBQJM',
	),
);

/**
 * Copy one staged file into the media library and describe it.
 *
 * `wp_upload_bits()` rather than `media_handle_sideload()`: that one moves its source, and a source
 * inside the uploads tree is the only kind this host can offer — a re-run then has nothing left to
 * read. This copies instead.
 *
 * @param string $source   Absolute path to the staged file.
 * @param string $filename Name to store it under, which becomes its URL.
 * @param string $title    Attachment title.
 * @param string $alt      Alt text, describing the photograph rather than the service.
 * @param string $credit   Attachment description, recording where it came from.
 * @return int|\WP_Error Attachment id, or why it could not be imported.
 */
function bookify_probe_import_photo( $source, $filename, $title, $alt, $credit ) {
	$contents = @file_get_contents( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a probe reports its own failure below.

	if ( false === $contents ) {
		return new WP_Error( 'missing', 'no such file: ' . $source );
	}

	$bits = wp_upload_bits( $filename, null, $contents );

	if ( ! empty( $bits['error'] ) ) {
		return new WP_Error( 'upload', (string) $bits['error'] );
	}

	$type = wp_check_filetype( $bits['file'] );

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $type['type'],
			'post_title'     => $title,
			'post_content'   => $credit,
			'post_status'    => 'inherit',
		),
		$bits['file'],
		0,
		true
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$metadata = wp_generate_attachment_metadata( $attachment_id, $bits['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	// The alt text is the one thing WordPress never generates, and the one thing a screen reader
	// reads in place of the picture.
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

	return $attachment_id;
}

$imported = 0;

foreach ( $photos as $slug => $photo ) {
	$service = get_page_by_path( $slug, OBJECT, 'bookify_service' );

	if ( ! $service instanceof WP_Post ) {
		printf( "MISSING  service %s\n", $slug );
		continue;
	}

	if ( get_post_thumbnail_id( $service->ID ) ) {
		printf( "kept     %-26s already has image %d\n", $slug, (int) get_post_thumbnail_id( $service->ID ) );
		continue;
	}

	$attachment_id = bookify_probe_import_photo(
		WP_CONTENT_DIR . '/uploads/_staging/' . $photo['file'],
		$photo['file'],
		$photo['title'],
		$photo['alt'],
		sprintf( 'Photograph: StockSnap.io, CC0 1.0 (public domain dedication). Photo id %s.', $photo['source'] )
	);

	if ( is_wp_error( $attachment_id ) ) {
		printf( "FAILED   %-26s %s\n", $slug, $attachment_id->get_error_message() );
		continue;
	}

	set_post_thumbnail( $service->ID, $attachment_id );

	$metadata = wp_get_attachment_metadata( $attachment_id );
	$sizes    = isset( $metadata['sizes'] ) ? count( $metadata['sizes'] ) : 0;

	printf(
		"ok       %-26s image %d  %s  %dx%d  %d size(s) generated\n",
		$slug,
		$attachment_id,
		basename( (string) get_attached_file( $attachment_id ) ),
		(int) ( $metadata['width'] ?? 0 ),
		(int) ( $metadata['height'] ?? 0 ),
		$sizes
	);

	$imported++;
}

// The listing is what proves it end to end: it draws a card per published service and each card
// now leads with this image, so a service missing one would show as a gap in a row.
$with_images = 0;

foreach ( array_keys( $photos ) as $slug ) {
	$service = get_page_by_path( $slug, OBJECT, 'bookify_service' );

	if ( $service instanceof WP_Post && get_post_thumbnail_id( $service->ID ) ) {
		$with_images++;
	}
}

printf(
	"\n%d image(s) imported this run; %d of %d published services have one\n",
	$imported,
	$with_images,
	count( $photos )
);
