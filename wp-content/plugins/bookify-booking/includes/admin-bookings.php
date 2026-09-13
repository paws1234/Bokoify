<?php
/**
 * The Bookings screen.
 *
 * Read-only on purpose: a booking is written by the form's own path, and editing one by hand
 * is a feature nobody has asked for yet. This file only makes what is stored visible.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The stored fields of a booking, as key => label.
 *
 * One list, used by both the read-only meta box and anything else that needs to name every
 * field, so a new key cannot be shown in one place and forgotten in the other. The status is
 * deliberately absent: since T17 it has an editing box of its own, and printing it twice on the
 * same screen would leave an operator wondering which one is the truth.
 *
 * @return array<string,string>
 */
function bookify_booking_fields() {
	return array(
		'bookify_reference'        => __( 'Reference', 'bookify-booking' ),
		'bookify_service_id'       => __( 'Session', 'bookify-booking' ),
		// Stage 7: what a ticket is for. A booking holds one or the other, never both, and the two
		// lines above and below a booking names itself with are printed for whichever it is.
		'bookify_event_id'         => __( 'Event', 'bookify-booking' ),
		'bookify_tier_id'          => __( 'Ticket tier', 'bookify-booking' ),
		'bookify_customer_name'    => __( 'Customer', 'bookify-booking' ),
		'bookify_customer_email'   => __( 'Email', 'bookify-booking' ),
		'bookify_customer_phone'   => __( 'Phone', 'bookify-booking' ),
		'bookify_party_size'       => __( 'People', 'bookify-booking' ),
		'bookify_date'             => __( 'Date', 'bookify-booking' ),
		'bookify_time'             => __( 'Time', 'bookify-booking' ),
		// T23/T25: what is owed, whether it arrived, and the two things a person needs to act on
		// it — the deadline and the note Stripe's events leave behind.
		'bookify_payment_status'   => __( 'Payment', 'bookify-booking' ),
		'bookify_payment_amount'   => __( 'Amount', 'bookify-booking' ),
		'bookify_payment_due'      => __( 'Payment due by', 'bookify-booking' ),
		'bookify_payment_reference' => __( 'Stripe reference', 'bookify-booking' ),
		'bookify_payment_note'     => __( 'Payment note', 'bookify-booking' ),
	);
}

/**
 * The status a booking can be in, as value => label.
 *
 * One list: the editing box writes from it, the list table names them from it, and a value that
 * is not in it is refused rather than stored. Only the statuses in
 * bookify_booking_slot_holding_statuses() hold a place in a slot, which is why cancelling and
 * expiring both free one — and why `awaiting_payment` is here at all (T23): a customer who is in
 * the middle of paying must not lose the slot to the next visitor.
 *
 * `expired` is what an unpaid booking becomes when its window closes, by the cron appointment or
 * by the sweep on the next availability read. An operator can also choose it by hand.
 *
 * @return array<string,string>
 */
function bookify_booking_statuses() {
	return array(
		'awaiting_payment' => __( 'Awaiting payment', 'bookify-booking' ),
		'pending'          => __( 'Pending', 'bookify-booking' ),
		'confirmed'        => __( 'Confirmed', 'bookify-booking' ),
		'expired'          => __( 'Expired', 'bookify-booking' ),
		'cancelled'        => __( 'Cancelled', 'bookify-booking' ),
	);
}

/**
 * The columns of the Bookings list.
 *
 * The reference is the title column: it is what the row is called everywhere else, and the
 * list table hangs the edit link and the row actions off it. The publish date is dropped —
 * the booking's own date is the one an operator works from.
 *
 * @param array<string,string> $columns Registered columns.
 * @return array<string,string>
 */
function bookify_booking_columns( $columns ) {
	$bookings = array();

	foreach ( $columns as $key => $label ) {
		if ( 'title' === $key ) {
			$bookings['title'] = __( 'Reference', 'bookify-booking' );
		} elseif ( 'date' !== $key ) {
			$bookings[ $key ] = $label;
		}
	}

	$bookings['bookify_service']  = __( 'Booked', 'bookify-booking' );
	$bookings['bookify_when']     = __( 'Date and time', 'bookify-booking' );
	$bookings['bookify_customer'] = __( 'Customer', 'bookify-booking' );
	$bookings['bookify_status']   = __( 'Status', 'bookify-booking' );

	return $bookings;
}

/**
 * Print one cell of the Bookings list.
 *
 * @param string $column  Column key.
 * @param int    $post_id The booking.
 */
function bookify_booking_column_content( $column, $post_id ) {
	switch ( $column ) {
		case 'bookify_service':
			// One name for both products (Stage 7): the session's title, or the event and its tier.
			$item = bookify_booking_item_label( $post_id );

			echo '' === $item ? '—' : esc_html( $item );
			break;

		case 'bookify_when':
			$date = (string) get_post_meta( $post_id, 'bookify_date', true );
			$time = (string) get_post_meta( $post_id, 'bookify_time', true );

			echo esc_html( trim( $date . ' ' . $time ) ? trim( $date . ' ' . $time ) : '—' );
			break;

		case 'bookify_customer':
			$name  = (string) get_post_meta( $post_id, 'bookify_customer_name', true );
			$email = (string) get_post_meta( $post_id, 'bookify_customer_email', true );

			echo esc_html( $name ? $name : '—' );

			if ( '' !== $email ) {
				echo '<br>' . esc_html( $email );
			}
			break;

		case 'bookify_status':
			$status = (string) get_post_meta( $post_id, 'bookify_status', true );

			$statuses = bookify_booking_statuses();

			echo esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : ( $status ? $status : '—' ) );
			break;
	}
}

/**
 * Make the booking date column sortable.
 *
 * The stored date is YYYY-MM-DD, so it sorts as text in the right order. Two bookings on the
 * same day keep their insertion order.
 *
 * @param array<string,string> $columns Sortable columns.
 * @return array<string,string>
 */
function bookify_booking_sortable_columns( $columns ) {
	$columns['bookify_when'] = 'bookify_date';

	return $columns;
}

/**
 * Sort the Bookings list by the booking date rather than by when it was submitted.
 *
 * @param WP_Query $query The query being prepared.
 */
function bookify_booking_sort_by_date( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'bookify_booking' !== $query->get( 'post_type' ) || 'bookify_date' !== $query->get( 'orderby' ) ) {
		return;
	}

	$query->set( 'meta_key', 'bookify_date' );
	$query->set( 'orderby', 'meta_value' );
}

/**
 * Print the booking's stored fields, and nothing that can be typed into.
 *
 * @param WP_Post $booking The booking being edited.
 */
function bookify_booking_fields_box( $booking ) {
	?>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( bookify_booking_fields() as $key => $label ) : ?>
				<?php
				$value = (string) get_post_meta( $booking->ID, $key, true );

				if ( 'bookify_service_id' === $key ) {
					$service = bookify_booking_bookable_service( $value );

					if ( $service ) {
						$value .= ' — ' . $service->post_title;
					}
				}

				// A ticket names another two ids; named here so an operator reading a booking can see
				// which event and which kind of ticket it is without looking anything up (Stage 7).
				if ( 'bookify_event_id' === $key ) {
					$event = bookify_booking_event( $booking->ID );

					if ( $event ) {
						$value .= ' — ' . $event->post_title;
					}
				}

				if ( 'bookify_tier_id' === $key ) {
					$tier = bookify_booking_tier( $booking->ID );

					if ( $tier ) {
						$value .= ' — ' . $tier->post_title;
					}
				}

				// Four stored values that a person cannot read raw: a key, a number with no currency, a
				// Unix timestamp and a status with no label. Each is formatted where it is printed rather
				// than stored differently, so the stored shape stays the one the code compares against.
				if ( 'bookify_payment_status' === $key ) {
					$value = bookify_booking_payment_label( $booking->ID );
				}

				if ( 'bookify_payment_amount' === $key && '' !== $value ) {
					$value = bookify_booking_amount_label( (float) $value );
				}

				if ( 'bookify_payment_due' === $key && '' !== $value ) {
					$value = wp_date(
						(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
						absint( $value ),
						wp_timezone()
					);
				}
				?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><?php echo '' === $value ? '—' : esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p><?php esc_html_e( 'These fields are read-only: bookings are written by the booking form.', 'bookify-booking' ); ?></p>
	<?php
}

/**
 * Put the read-only box on the booking edit screen.
 */
function bookify_booking_add_fields_box() {
	add_meta_box(
		'bookify_booking_fields',
		__( 'Booking', 'bookify-booking' ),
		'bookify_booking_fields_box',
		'bookify_booking',
		'normal',
		'high'
	);
}

/**
 * The one field an operator may change.
 *
 * Cancelling is the operator's counterpart to the customer's own cancel link: it frees the slot
 * the same way, because the slot count only reads pending and confirmed bookings.
 *
 * @param WP_Post $booking The booking being edited.
 */
function bookify_booking_status_box( $booking ) {
	wp_nonce_field( 'bookify_booking_status', 'bookify_status_nonce' );

	$status = (string) get_post_meta( $booking->ID, 'bookify_status', true );

	if ( ! isset( bookify_booking_statuses()[ $status ] ) ) {
		$status = 'pending';
	}
	?>
	<p>
		<label for="bookify_status" class="screen-reader-text"><?php echo esc_html__( 'Status', 'bookify-booking' ); ?></label>
		<select id="bookify_status" name="bookify_status">
			<?php foreach ( bookify_booking_statuses() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<p class="description"><?php echo esc_html__( 'Cancelling gives the time back to the diary. A customer can also cancel for themselves from the link in their confirmation.', 'bookify-booking' ); ?></p>
	<p class="description"><?php echo esc_html__( 'A booking that is awaiting payment holds its time until its payment window closes, and then gives it back by itself. Choose Confirmed to keep it regardless, or Expired to give the time back now.', 'bookify-booking' ); ?></p>
	<?php
}

/**
 * Add the status box.
 */
function bookify_booking_add_status_box() {
	add_meta_box(
		'bookify_booking_status',
		__( 'Status', 'bookify-booking' ),
		'bookify_booking_status_box',
		'bookify_booking',
		'side',
		'default'
	);
}

/**
 * Save the status a person chose, and nothing else.
 *
 * Nothing is written without our nonce, which keeps autosaves, quick edits and bulk edits out;
 * the capability is checked separately, and a value that is not one of the three statuses is
 * dropped rather than stored.
 *
 * @param int $post_id The booking being saved.
 */
function bookify_booking_save_status( $post_id ) {
	if ( ! isset( $_POST['bookify_status_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_key( wp_unslash( $_POST['bookify_status_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, 'bookify_booking_status' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$status = isset( $_POST['bookify_status'] ) ? sanitize_key( wp_unslash( $_POST['bookify_status'] ) ) : '';

	if ( ! isset( bookify_booking_statuses()[ $status ] ) ) {
		return;
	}

	update_post_meta( $post_id, 'bookify_status', $status );

	// An operator who moves a booking out of "waiting for payment" takes its deadline with it (T23),
	// which is what the customer's own cancel path does too.
	if ( 'awaiting_payment' !== $status ) {
		bookify_booking_unschedule_expiry( $post_id );
	}

	// The reminder follows the place, not the operator: a booking moved to cancelled or expired
	// stops being one somebody is coming to, and anything still holding a place asks again (T30).
	if ( in_array( $status, bookify_booking_slot_holding_statuses(), true ) ) {
		bookify_booking_schedule_reminder( $post_id );
	} else {
		bookify_booking_unschedule_reminder( $post_id );
	}
}

/**
 * Register the Bookings screen.
 */
function bookify_booking_register_admin() {
	add_filter( 'manage_bookify_booking_posts_columns', 'bookify_booking_columns' );
	add_action( 'manage_bookify_booking_posts_custom_column', 'bookify_booking_column_content', 10, 2 );
	add_filter( 'manage_edit-bookify_booking_sortable_columns', 'bookify_booking_sortable_columns' );
	add_action( 'pre_get_posts', 'bookify_booking_sort_by_date' );
	add_action( 'add_meta_boxes_bookify_booking', 'bookify_booking_add_fields_box' );
	add_action( 'add_meta_boxes_bookify_booking', 'bookify_booking_add_status_box' );
	add_action( 'save_post_bookify_booking', 'bookify_booking_save_status' );
}
