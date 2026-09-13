<?php
/**
 * An event's date, times and places, and a tier's event, price and inventory, as fields in wp-admin.
 *
 * Registered meta renders no editing field of its own, so without these boxes the only way to put an
 * event on the calendar is `wp eval` or an agent — the same reason includes/service-fields.php
 * exists (T9).
 *
 * The two boxes share one renderer and one saver rather than each having its own copy: they differ
 * only in the list of fields, and a field cannot be rendered in one and forgotten in the other.
 * The save discipline is T9's: no nonce, no write; the capability is checked separately; every value
 * is unslashed and written through the sanitiser registered for it; and an emptied box deletes the
 * meta rather than storing a zero.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The fields an event is made of, as meta key => input.
 *
 * @return array<string,array<string,mixed>>
 */
function bookify_booking_event_fields() {
	return array(
		'bookify_event_date'     => array(
			'type'  => 'date',
			'label' => __( 'Date', 'bookify-booking' ),
			'hint'  => __( 'The one day this event happens.', 'bookify-booking' ),
		),
		'bookify_event_start'    => array(
			'type'  => 'time',
			'label' => __( 'Starts', 'bookify-booking' ),
			'hint'  => __( 'Tickets stop being sold when the event starts.', 'bookify-booking' ),
		),
		'bookify_event_end'      => array(
			'type'  => 'time',
			'label' => __( 'Ends', 'bookify-booking' ),
			'hint'  => __( 'The reservation summary is sent then. Leave it empty and the event is treated as lasting an hour.', 'bookify-booking' ),
		),
		'bookify_event_capacity' => array(
			'type'  => 'number',
			'step'  => '1',
			'min'   => '0',
			'label' => __( 'Places', 'bookify-booking' ),
			'hint'  => __( 'How many people the event takes in total. Leave it empty for no ceiling of its own, so each ticket tier is limited only by its own number.', 'bookify-booking' ),
		),
	);
}

/**
 * The published events a tier can be attached to, as id => label.
 *
 * Drafts are offered too: a tier is usually written before the event is published, and hiding the
 * event until it is live would make the order of the two edits matter.
 *
 * @return array<int,string>
 */
function bookify_booking_event_choices() {
	$options = array();

	foreach (
		get_posts(
			array(
				'post_type'      => 'bookify_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		) as $event
	) {
		$date = bookify_event_date( $event->ID );

		$options[ $event->ID ] = '' === $date
			? $event->post_title . ' — ' . __( 'no date yet', 'bookify-booking' )
			: $event->post_title . ' — ' . $date;
	}

	return $options;
}

/**
 * The fields a ticket tier is made of, as meta key => input.
 *
 * @return array<string,array<string,mixed>>
 */
function bookify_booking_tier_fields() {
	return array(
		'bookify_event_id'      => array(
			'type'    => 'select',
			'label'   => __( 'Event', 'bookify-booking' ),
			// The placeholder is first, so a tier whose event has not been chosen shows an unset
			// control rather than the first event in the list pretending to be a choice.
			'options' => array( 0 => __( 'Choose an event…', 'bookify-booking' ) ) + bookify_booking_event_choices(),
			'hint'    => __( 'A tier is sold on one event and cannot be used for another.', 'bookify-booking' ),
		),
		'bookify_tier_price'    => array(
			'type'  => 'number',
			'step'  => '0.01',
			'min'   => '0',
			'label' => __( 'Price per ticket', 'bookify-booking' ),
			'hint'  => __( 'In the site currency. Three of these tickets cost three times this.', 'bookify-booking' ),
		),
		'bookify_tier_capacity' => array(
			'type'  => 'number',
			'step'  => '1',
			'min'   => '0',
			'label' => __( 'Tickets', 'bookify-booking' ),
			'hint'  => __( 'How many of this kind are for sale. The event’s own places are a second ceiling.', 'bookify-booking' ),
		),
	);
}

/**
 * Print a field list, filled with what is stored.
 *
 * @param WP_Post               $post   The post being edited.
 * @param array<string,mixed>   $fields Field definitions.
 * @param string                $nonce  The nonce field's name.
 * @param string                $action The nonce's action.
 */
function bookify_booking_fields_box_html( WP_Post $post, array $fields, $nonce, $action ) {
	wp_nonce_field( $action, $nonce );

	foreach ( $fields as $key => $field ) {
		$value = (string) get_post_meta( $post->ID, $key, true );
		$type  = isset( $field['type'] ) ? $field['type'] : 'number';

		// A select falls back to its first option when what is stored is not one of them, so an
		// owner sees the choice the site will actually make rather than a control that looks unset.
		if ( 'select' === $type && ! isset( $field['options'][ (int) $value ] ) ) {
			$value = (string) array_key_first( $field['options'] );
		}
		?>
		<p>
			<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			<?php if ( 'select' === $type ) : ?>
				<select id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
					<?php foreach ( $field['options'] as $option => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $value, (string) $option ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'date' === $type || 'time' === $type ) : ?>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
				/>
			<?php else : ?>
				<input
					type="number"
					id="<?php echo esc_attr( $key ); ?>"
					name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					step="<?php echo esc_attr( $field['step'] ); ?>"
					min="<?php echo esc_attr( $field['min'] ); ?>"
				/>
			<?php endif; ?>
		</p>
		<p class="description"><?php echo esc_html( $field['hint'] ); ?></p>
		<?php
	}
}

/**
 * Save a field list.
 *
 * @param int                 $post_id The post being saved.
 * @param array<string,mixed> $fields  Field definitions.
 * @param string              $nonce   The nonce field's name.
 * @param string              $action  The nonce's action.
 */
function bookify_booking_save_fields_box( $post_id, array $fields, $nonce, $action ) {
	if ( ! isset( $_POST[ $nonce ] ) ) {
		return;
	}

	$value = sanitize_key( wp_unslash( $_POST[ $nonce ] ) );

	if ( ! wp_verify_nonce( $value, $action ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( array_keys( $fields ) as $key ) {
		$field_value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;

		if ( ! is_string( $field_value ) ) {
			continue;
		}

		if ( '' === trim( $field_value ) ) {
			delete_post_meta( $post_id, $key );
			continue;
		}

		// The typecasting is done by the sanitisers registered in includes/events.php: WordPress
		// runs them inside update_post_meta(), so this stays a plain write.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_post_meta( $post_id, $key, $field_value );
	}
}

/**
 * Put the event's fields on its edit screen.
 */
function bookify_booking_add_event_fields_box() {
	add_meta_box(
		'bookify_event_fields',
		__( 'When it happens', 'bookify-booking' ),
		'bookify_booking_event_fields_box',
		'bookify_event',
		'side',
		'default'
	);
}

/**
 * Print the event's fields.
 *
 * @param WP_Post $event The event being edited.
 */
function bookify_booking_event_fields_box( $event ) {
	bookify_booking_fields_box_html( $event, bookify_booking_event_fields(), 'bookify_event_nonce', 'bookify_booking_event_fields' );
}

/**
 * Save the event's fields.
 *
 * @param int $post_id The event being saved.
 */
function bookify_booking_save_event_fields( $post_id ) {
	bookify_booking_save_fields_box( $post_id, bookify_booking_event_fields(), 'bookify_event_nonce', 'bookify_booking_event_fields' );
}

/**
 * Put the tier's fields on its edit screen.
 */
function bookify_booking_add_tier_fields_box() {
	add_meta_box(
		'bookify_tier_fields',
		__( 'Ticket', 'bookify-booking' ),
		'bookify_booking_tier_fields_box',
		'bookify_tier',
		'side',
		'default'
	);
}

/**
 * Print the tier's fields.
 *
 * @param WP_Post $tier The tier being edited.
 */
function bookify_booking_tier_fields_box( $tier ) {
	bookify_booking_fields_box_html( $tier, bookify_booking_tier_fields(), 'bookify_tier_nonce', 'bookify_booking_tier_fields' );
}

/**
 * Save the tier's fields.
 *
 * @param int $post_id The tier being saved.
 */
function bookify_booking_save_tier_fields( $post_id ) {
	bookify_booking_save_fields_box( $post_id, bookify_booking_tier_fields(), 'bookify_tier_nonce', 'bookify_booking_tier_fields' );
}

/**
 * The columns of the Events list.
 *
 * The publish date is dropped the way the Bookings list drops it: when the event happens is the date
 * an operator works from, not when the row was written. The title column is left where it is, so the
 * edit link and the row actions stay where wp-admin puts them.
 *
 * @param array<string,string> $columns Registered columns.
 * @return array<string,string>
 */
function bookify_booking_event_columns( $columns ) {
	$events = array();

	foreach ( $columns as $key => $label ) {
		if ( 'date' !== $key ) {
			$events[ $key ] = $label;
		}
	}

	$events['bookify_event_when']    = __( 'When', 'bookify-booking' );
	$events['bookify_event_tickets'] = __( 'Tickets sold', 'bookify-booking' );

	return $events;
}

/**
 * Print one cell of the Events list.
 *
 * @param string $column  Column key.
 * @param int    $post_id The event.
 */
function bookify_booking_event_column_content( $column, $post_id ) {
	if ( 'bookify_event_when' === $column ) {
		$label = bookify_event_date_label( $post_id );
		$start = bookify_event_start( $post_id );
		$end   = bookify_event_end( $post_id );

		if ( '' === $label ) {
			echo '—';
			return;
		}

		$times = array_filter( array( $start, $end ) );

		echo esc_html( $label . ( $times ? ' ' . implode( '–', $times ) : '' ) );

		if ( bookify_event_is_over( $post_id ) ) {
			echo '<br><em>' . esc_html__( 'Finished', 'bookify-booking' ) . '</em>';
		}

		return;
	}

	if ( 'bookify_event_tickets' === $column ) {
		$sold     = bookify_event_tickets_sold( $post_id );
		$capacity = bookify_event_capacity( $post_id );

		printf(
			/* translators: 1: tickets sold, 2: places left, 3: the event's capacity, or a dash when it has none. */
			esc_html__( '%1$d sold, %2$d left of %3$s', 'bookify-booking' ),
			$sold,
			bookify_event_places_left( $post_id ),
			$capacity > 0 ? (string) $capacity : '—'
		);
	}
}

/**
 * Register the event and tier fields.
 */
function bookify_booking_register_event_fields() {
	add_action( 'add_meta_boxes_bookify_event', 'bookify_booking_add_event_fields_box' );
	add_action( 'add_meta_boxes_bookify_tier', 'bookify_booking_add_tier_fields_box' );
	add_action( 'save_post_bookify_event', 'bookify_booking_save_event_fields' );
	add_action( 'save_post_bookify_tier', 'bookify_booking_save_tier_fields' );
	add_filter( 'manage_bookify_event_posts_columns', 'bookify_booking_event_columns' );
	add_action( 'manage_bookify_event_posts_custom_column', 'bookify_booking_event_column_content', 10, 2 );
}
