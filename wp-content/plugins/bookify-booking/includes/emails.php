<?php
/**
 * The booking confirmation.
 *
 * The plain-text message is the one the probes capture through `pre_wp_mail`, so its wording is
 * unchanged. What this file adds on top is an HTML version of the same facts, sent by the transport
 * in includes/mailer.php — see that file for why the site sends through Resend at all.
 *
 * Both versions say the same things, from the same values: the text is what a client set to plain
 * text shows, and what the fallback transport sends.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The link that cancels one booking.
 *
 * Built here rather than stored, so a site whose address changes does not keep handing out a
 * dead host, and so the token is read from the booking at the moment the message is composed.
 *
 * Since T20 this is no longer the link the confirmation prints — that is the manage link, which
 * can cancel too — but it still works, so a customer holding an older email is not left with a
 * dead URL.
 *
 * @param int $booking_id The booking.
 * @return string URL, or '' when the booking has no token to prove itself with.
 */
function bookify_booking_cancel_url( $booking_id ) {
	$reference = (string) get_post_meta( $booking_id, 'bookify_reference', true );
	$token     = (string) get_post_meta( $booking_id, 'bookify_cancel_token', true );

	if ( '' === $reference || '' === $token ) {
		return '';
	}

	return add_query_arg(
		array(
			'bookify_cancel' => $reference,
			'bookify_key'    => $token,
		),
		bookify_booking_page_url()
	);
}

/**
 * The message as HTML, for the clients that can show it.
 *
 * Written as tables with inline styles and no external anything, because that is what an email
 * client can be relied on to render: no stylesheet, no web font, no CSS grid, no `rem`. The colours
 * are the site's own tokens spelled out — an email cannot read a CSS custom property — and the
 * serif on the heading is Georgia rather than Lora, because a web font that silently fails to load
 * is worse than a system serif that always works.
 *
 * `color-scheme: light` is declared on purpose. Apple Mail and others will happily invent a dark
 * version of a light email by inverting it, and an inversion of this one — dark text on the band
 * that is already dark — is worse than no dark mode at all.
 *
 * @param array{title:string,greeting:string,rows:array<string,string>,notes:array<int,string>,highlight:array{title:string,body:string}|null,action:array{label:string,url:string,note:string}|null,footer:array<int,string>,preheader:string} $data What to say.
 * @return string
 */
function bookify_booking_email_html( array $data ) {
	$data = wp_parse_args(
		$data,
		array(
			'title'     => '',
			'greeting'  => '',
			'rows'      => array(),
			'notes'     => array(),
			'highlight' => null,
			'action'    => null,
			'footer'    => array(),
			'preheader' => '',
		)
	);

	$ink    = '#1f2a28';
	$body   = '#4a5654';
	$muted  = '#6e675c';
	$accent = '#14665b';
	$band   = '#14312c';
	$cream  = '#f6f3ee';
	$line   = '#e4ded4';
	$page   = '#f4f1ec';
	$card   = '#ffffff';
	$soft   = '#e3f0ed';
	$sans   = "-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
	$serif  = "Georgia, 'Times New Roman', serif";

	ob_start();
	?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width" />
<meta name="color-scheme" content="light" />
<meta name="supported-color-schemes" content="light" />
<title><?php echo esc_html( $data['title'] ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:<?php echo esc_attr( $page ); ?>;">
<span style="display:none;visibility:hidden;opacity:0;height:0;width:0;overflow:hidden;"><?php echo esc_html( $data['preheader'] ); ?></span>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:<?php echo esc_attr( $page ); ?>;">
	<tr>
		<td align="center" style="padding:24px 12px;">
			<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:<?php echo esc_attr( $card ); ?>;border:1px solid <?php echo esc_attr( $line ); ?>;border-radius:14px;">
				<tr>
					<td style="background-color:<?php echo esc_attr( $band ); ?>;padding:20px 28px;">
						<span style="font-family:<?php echo esc_attr( $serif ); ?>;font-size:18px;font-weight:600;color:<?php echo esc_attr( $cream ); ?>;"><?php echo esc_html( (string) get_option( 'blogname' ) ); ?></span>
					</td>
				</tr>
				<tr>
					<td style="padding:28px 28px 8px;font-family:<?php echo esc_attr( $sans ); ?>;font-size:16px;line-height:1.6;color:<?php echo esc_attr( $body ); ?>;">
						<h1 style="margin:0 0 12px;font-family:<?php echo esc_attr( $serif ); ?>;font-size:24px;line-height:1.25;font-weight:600;color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( $data['title'] ); ?></h1>

						<?php if ( '' !== $data['greeting'] ) : ?>
							<p style="margin:0 0 20px;"><?php echo esc_html( $data['greeting'] ); ?></p>
						<?php endif; ?>

						<?php if ( $data['rows'] ) : ?>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;border-top:1px solid <?php echo esc_attr( $line ); ?>;">
								<?php foreach ( $data['rows'] as $label => $value ) : ?>
									<tr>
										<td style="padding:10px 0;border-bottom:1px solid <?php echo esc_attr( $line ); ?>;font-size:14px;color:<?php echo esc_attr( $muted ); ?>;width:40%;"><?php echo esc_html( (string) $label ); ?></td>
										<td style="padding:10px 0;border-bottom:1px solid <?php echo esc_attr( $line ); ?>;font-size:15px;font-weight:600;color:<?php echo esc_attr( $ink ); ?>"><?php echo esc_html( (string) $value ); ?></td>
									</tr>
								<?php endforeach; ?>
							</table>
						<?php endif; ?>

						<?php foreach ( $data['notes'] as $note ) : ?>
							<p style="margin:0 0 16px;"><?php echo esc_html( (string) $note ); ?></p>
						<?php endforeach; ?>

						<?php if ( is_array( $data['highlight'] ) ) : ?>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
								<tr>
									<td style="background-color:<?php echo esc_attr( $soft ); ?>;border-left:4px solid <?php echo esc_attr( $accent ); ?>;border-radius:4px;padding:14px 16px;">
										<strong style="display:block;color:<?php echo esc_attr( $ink ); ?>;"><?php echo esc_html( (string) $data['highlight']['title'] ); ?></strong>
										<span style="display:block;margin-top:4px;font-size:14px;color:<?php echo esc_attr( $body ); ?>;"><?php echo esc_html( (string) $data['highlight']['body'] ); ?></span>
									</td>
								</tr>
							</table>
						<?php endif; ?>

						<?php if ( is_array( $data['action'] ) ) : ?>
							<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">
								<tr>
									<td style="background-color:<?php echo esc_attr( $accent ); ?>;border-radius:999px;">
										<a href="<?php echo esc_url( $data['action']['url'] ); ?>" style="display:inline-block;padding:13px 26px;font-family:<?php echo esc_attr( $sans ); ?>;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;"><?php echo esc_html( (string) $data['action']['label'] ); ?></a>
									</td>
								</tr>
							</table>
							<?php if ( '' !== (string) $data['action']['note'] ) : ?>
								<p style="margin:0 0 20px;font-size:14px;color:<?php echo esc_attr( $muted ); ?>;"><?php echo esc_html( (string) $data['action']['note'] ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:18px 28px 24px;border-top:1px solid <?php echo esc_attr( $line ); ?>;background-color:#faf7f2;font-family:<?php echo esc_attr( $sans ); ?>;font-size:13px;line-height:1.6;color:<?php echo esc_attr( $muted ); ?>;">
						<?php foreach ( $data['footer'] as $line_text ) : ?>
							<span style="display:block;"><?php echo esc_html( (string) $line_text ); ?></span>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
	<?php
	return (string) ob_get_clean();
}

/**
 * The business's own lines for the bottom of an email, from the one place that stores them.
 *
 * @return array<int,string>
 */
function bookify_booking_email_footer() {
	$business = bookify_booking_business();
	$lines    = array();

	if ( '' !== $business['name'] ) {
		$lines[] = $business['name'];
	}

	/*
	 * The address is four stored parts, not one, and they are joined here exactly as
	 * bookify_booking_business_details_html() joins them for the footer of every page — comma
	 * separated, empty parts dropped. Reading a key called `address` instead finds nothing and the
	 * email quietly loses the studio's address, which is what the first run of the mail probe caught.
	 */
	$address = array_filter(
		array( $business['street'], $business['locality'], $business['postcode'], $business['country'] ),
		'strlen'
	);

	if ( $address ) {
		$lines[] = implode( ', ', $address );
	}

	if ( '' !== $business['phone'] ) {
		$lines[] = $business['phone'];
	}

	if ( '' !== $business['email'] ) {
		$lines[] = $business['email'];
	}

	return $lines;
}

/**
 * Compose the confirmation for a booking and hand it to wp_mail().
 *
 * The subject and the body are built here rather than being stored, so there is one wording to
 * change and nothing to keep in sync with the booking.
 *
 * @param int $booking_id A booking that already exists.
 */
function bookify_booking_send_confirmation( $booking_id ) {
	$booking = get_post( absint( $booking_id ) );

	if ( ! $booking instanceof WP_Post || 'bookify_booking' !== $booking->post_type ) {
		return;
	}

	$email = get_post_meta( $booking->ID, 'bookify_customer_email', true );

	// The write path has already rejected a bad address; a booking whose address is unusable
	// gets no message rather than a broken one.
	if ( ! is_email( $email ) ) {
		return;
	}

	$name      = (string) get_post_meta( $booking->ID, 'bookify_customer_name', true );
	$reference = (string) get_post_meta( $booking->ID, 'bookify_reference', true );

	// Printed exactly as they were chosen. Turning them into a timestamp would move the
	// booking if the site's timezone were ever changed.
	$date  = (string) get_post_meta( $booking->ID, 'bookify_date', true );
	$time  = (string) get_post_meta( $booking->ID, 'bookify_time', true );
	$party = (int) get_post_meta( $booking->ID, 'bookify_party_size', true );

	/*
	 * The opening line is the one thing that has to change when the message is diverted: it is not
	 * being sent to the client, it is arriving at the studio, so it reads as a note about the booking
	 * rather than thanks for making it. Everything below it — the reference, the session, the date —
	 * is the same either way.
	 */
	$diverted = '' !== bookify_booking_mail_redirect_to();

	$opening = $diverted
		? sprintf(
			/* translators: %s: the client's name. */
			__( 'A booking from %s', 'bookify-booking' ),
			$name
		)
		: sprintf(
			/* translators: %s: the customer's name. */
			__( 'Thank you, %s — we have your booking.', 'bookify-booking' ),
			$name
		);

	$lines = array(
		$opening,
		'',
		sprintf(
			/* translators: %s: booking reference, for example BK-00042. */
			__( 'Reference: %s', 'bookify-booking' ),
			$reference
		),
	);

	/*
	 * What the booking is for, named by the one function that knows (Stage 7): a session's title, or
	 * an event and the tier it was sold on. The line reads "Booking:" rather than "Session:"
	 * because one wording now serves two products, and a ticket buyer is not booked into a session.
	 * A session that has since been unpublished is still left out rather than named wrongly.
	 */
	$item = bookify_booking_item_label( $booking->ID );

	if ( '' !== $item ) {
		$lines[] = sprintf(
			/* translators: %s: what the booking is for — a session, or an event and a ticket tier. */
			__( 'Booking: %s', 'bookify-booking' ),
			$item
		);
	}

	/*
	 * The two lines below say the same thing to both products in the words each one uses: a party is
	 * guests at an appointment and tickets at an event, and what a lapsed payment gives up is a slot
	 * in the diary or a place at the event. One word for both would be wrong for one of them.
	 */
	$is_ticket = (bool) bookify_booking_event( $booking->ID );

	$lines = array_merge(
		$lines,
		array(
			sprintf(
				/* translators: %s: the booking date, as chosen. */
				__( 'Date: %s', 'bookify-booking' ),
				$date
			),
			sprintf(
				/* translators: %s: the booking time, as chosen. */
				__( 'Time: %s', 'bookify-booking' ),
				$time
			),
			sprintf(
				$is_ticket
					/* translators: %d: number of tickets. */
					? __( 'Tickets: %d', 'bookify-booking' )
					/* translators: %d: number of guests. */
					: __( 'Guests: %d', 'bookify-booking' ),
				$party
			),
		)
	);

	/*
	 * Said only to the client. A diverted message is addressed to the studio, so "we will be in
	 * touch" would be the studio promising itself a call.
	 */
	if ( ! $diverted ) {
		$lines[] = '';
		$lines[] = __( 'We will be in touch if anything needs to change.', 'bookify-booking' );
	}

	/*
	 * T23: what is due, how much, and by when.
	 *
	 * Read from what the booking stored rather than from the service: the amount was decided when
	 * the booking was written, so this sentence and the request T24 composes for Stripe are the
	 * same number even if the owner repriced the service a minute later. The wording never says a
	 * card, a provider or a link of its own — the manage link below is where paying happens.
	 */
	$payable = bookify_booking_payment_is_due( $booking->ID );
	$amount  = (float) get_post_meta( $booking->ID, 'bookify_payment_amount', true );
	$due_at  = bookify_booking_payment_due_at( $booking->ID );

	if ( $payable && $amount > 0 ) {
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the amount to pay, for example GBP 25.00. */
			__( 'To keep this booking, please pay %s.', 'bookify-booking' ),
			bookify_booking_amount_label( $amount )
		);

		if ( $due_at ) {
			$lines[] = sprintf(
				$is_ticket
					/* translators: 1: the time of day, 2: the date the payment window closes. */
					? __( 'It is due by %1$s on %2$s, after which the tickets go back on sale.', 'bookify-booking' )
					/* translators: 1: the time of day, 2: the date the payment window closes. */
					: __( 'It is due by %1$s on %2$s, after which the time goes back to the diary.', 'bookify-booking' ),
				wp_date( (string) get_option( 'time_format' ), $due_at, wp_timezone() ),
				wp_date( (string) get_option( 'date_format' ), $due_at, wp_timezone() )
			);
		}
	}

	// The customer's own way back to this booking, with no account and no password. Since T20 this
	// is the manage link rather than T17's cancel-only one: it shows the booking and offers exactly
	// the actions its status allows, and T17's cancel URL still works for anyone holding an older
	// email.
	$manage = bookify_booking_manage_url( $booking->ID );

	/*
	 * The client's own way back to this booking. Not when diverted: the link is theirs, a message
	 * arriving at the studio does not need it, and the studio has the booking list.
	 */
	if ( ! $diverted && '' !== $manage ) {
		$lines[] = '';
		$lines[] = $payable
			? __( 'To pay for it, or to see, move or cancel it, open this link:', 'bookify-booking' )
			: __( 'To see this booking, move it or cancel it, open this link:', 'bookify-booking' );
		$lines[] = $manage;
	}

	$headers = array(
		'From: ' . bookify_booking_mail_from(),
		sprintf( 'Reply-To: %s <%s>', $name, $email ),
	);

	$subject = sprintf(
		/* translators: %s: booking reference, for example BK-00042. */
		__( 'Booking %s received', 'bookify-booking' ),
		$reference
	);

	$due_line = '';

	if ( $payable && $amount > 0 && $due_at ) {
		$due_line = sprintf(
			$is_ticket
				/* translators: 1: the time of day, 2: the date the payment window closes. */
				? __( 'It is due by %1$s on %2$s, after which the tickets go back on sale.', 'bookify-booking' )
				/* translators: 1: the time of day, 2: the date the payment window closes. */
				: __( 'It is due by %1$s on %2$s, after which the time goes back to the diary.', 'bookify-booking' ),
			wp_date( (string) get_option( 'time_format' ), $due_at, wp_timezone() ),
			wp_date( (string) get_option( 'date_format' ), $due_at, wp_timezone() )
		);
	}

	/*
	 * The same facts as a table, for the HTML part. Built from the same variables the text above uses,
	 * so the two cannot disagree about a date, a reference or an amount — only about how to lay them
	 * out, which is the one thing they are supposed to disagree about.
	 */
	$rows = array(
		__( 'Reference', 'bookify-booking' ) => $reference,
	);

	if ( '' !== $item ) {
		$rows[ __( 'Booking', 'bookify-booking' ) ] = $item;
	}

	$rows[ __( 'Date', 'bookify-booking' ) ] = $date;
	$rows[ __( 'Time', 'bookify-booking' ) ] = $time;
	$rows[ $is_ticket ? __( 'Tickets', 'bookify-booking' ) : __( 'Guests', 'bookify-booking' ) ] = (string) $party;

	/*
	 * Testing only. When every message is diverted to the studio, the client's address has to be
	 * inside the message: the envelope no longer carries it, so without this there is nothing to
	 * write back to. Conditional, because a client reading their own confirmation has no need to be
	 * told their own address.
	 */
	if ( '' !== bookify_booking_mail_redirect_to() ) {
		$rows[ __( 'Client email', 'bookify-booking' ) ] = $email;
		$lines[]                                        = '';
		$lines[]                                        = sprintf(
			/* translators: %s: the client's email address. */
			__( 'Client email: %s', 'bookify-booking' ),
			$email
		);
	}

	$html = bookify_booking_email_html(
		array(
			'preheader' => $subject,
			'title'     => __( 'Booking received', 'bookify-booking' ),
			'greeting'  => $lines[0],
			'rows'      => $rows,
			'notes'     => $diverted ? array() : array( __( 'We will be in touch if anything needs to change.', 'bookify-booking' ) ),
			'highlight' => ( $payable && $amount > 0 ) ? array(
				'title' => sprintf(
					/* translators: %s: the amount to pay, for example GBP 25.00. */
					__( 'To keep this booking, please pay %s.', 'bookify-booking' ),
					bookify_booking_amount_label( $amount )
				),
				'body'  => $due_line,
			) : null,
			// No button when diverted either, for the same reason as the link above: it belongs to
			// the client, and this message is not for the client.
			'action'    => ( ! $diverted && '' !== $manage ) ? array(
				'label' => $payable
					? __( 'Pay for this booking', 'bookify-booking' )
					: __( 'See, move or cancel it', 'bookify-booking' ),
				'url'   => $manage,
				'note'  => __( 'This link is yours alone: it shows this booking and nothing else, and it needs no password.', 'bookify-booking' ),
			) : null,
			'footer'    => bookify_booking_email_footer(),
		)
	);

	// The result is deliberately ignored: the booking exists whether or not the message leaves, and
	// a retry queue is a later task. What the transport did with it is recorded instead, and shown on
	// the settings screen.
	bookify_booking_send_mail(
		array(
			'to'      => $email,
			'subject' => $subject,
			'text'    => implode( "\n", $lines ),
			'html'    => $html,
			'headers' => $headers,
		)
	);
}

/**
 * Send the manage link again, to the address already on the booking.
 *
 * Composed from the booking's own stored address and never from anything a request carried: the
 * lookup form's address is only a claim, and a claim must not be able to redirect a customer's
 * own link. The message says nothing about what was asked for, so the same mail goes out whether
 * or not anything matched (it is only ever called when something did).
 *
 * @param int $booking_id The booking.
 */
function bookify_booking_send_manage_link( $booking_id ) {
	$booking = get_post( absint( $booking_id ) );

	if ( ! $booking instanceof WP_Post || 'bookify_booking' !== $booking->post_type ) {
		return;
	}

	$email = (string) get_post_meta( $booking->ID, 'bookify_customer_email', true );
	$link  = bookify_booking_manage_url( $booking->ID );

	if ( ! is_email( $email ) || '' === $link ) {
		return;
	}

	$reference = (string) get_post_meta( $booking->ID, 'bookify_reference', true );

	$lines = array(
		__( 'Here is the link to your booking again.', 'bookify-booking' ),
		'',
		sprintf(
			/* translators: %s: booking reference, for example BK-00042. */
			__( 'Reference: %s', 'bookify-booking' ),
			$reference
		),
		$link,
		'',
		__( 'If you did not ask for this, you can ignore it — the link only shows your own booking.', 'bookify-booking' ),
	);

	$headers = array( 'From: ' . bookify_booking_mail_from() );

	$subject = sprintf(
		/* translators: %s: booking reference, for example BK-00042. */
		__( 'Your booking %s link', 'bookify-booking' ),
		$reference
	);

	$diverted = '' !== bookify_booking_mail_redirect_to();
	$rows     = array( __( 'Reference', 'bookify-booking' ) => $reference );

	// The same testing-only addition as the confirmation, for the same reason: diverted to the
	// studio, the client's address is no longer in the envelope.
	if ( $diverted ) {
		$rows[ __( 'Client email', 'bookify-booking' ) ] = $email;

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the client's email address. */
			__( 'Client email: %s', 'bookify-booking' ),
			$email
		);
	}

	$html = bookify_booking_email_html(
		array(
			'preheader' => $subject,
			'title'     => __( 'Your booking link', 'bookify-booking' ),
			'greeting'  => __( 'Here is the link to your booking again.', 'bookify-booking' ),
			'rows'      => $rows,
			'notes'     => array( __( 'If you did not ask for this, you can ignore it — the link only shows your own booking.', 'bookify-booking' ) ),
			'action'    => array(
				'label' => __( 'Open my booking', 'bookify-booking' ),
				'url'   => $link,
				'note'  => $diverted ? '' : __( 'This link is yours alone: it shows this booking and nothing else, and it needs no password.', 'bookify-booking' ),
			),
			'footer'    => bookify_booking_email_footer(),
		)
	);

	bookify_booking_send_mail(
		array(
			'to'      => $email,
			'subject' => $subject,
			'text'    => implode( "\n", $lines ),
			'html'    => $html,
			'headers' => $headers,
		)
	);
}
