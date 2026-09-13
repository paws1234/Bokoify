<?php
/**
 * A service's price, length and capacity, as fields in wp-admin.
 *
 * Registered meta renders no editing field of its own, so without this box the only way to price
 * a service is `wp eval` or an agent.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The fields this box owns, as meta key => input.
 *
 * The box and the save handler both read this list, so a key cannot be rendered in the form and
 * then forgotten when saving, or saved without ever being rendered. `type` is 'number' unless it
 * says otherwise, and a 'select' carries its own `options` — which is what T23's payment mode
 * needed, and the reason a field is defined rather than hard-coded in the markup below.
 *
 * @return array<string,array<string,mixed>>
 */
function bookify_booking_service_fields() {
	return array(
		'bookify_price'         => array(
			'type'  => 'number',
			'label' => __( 'Price', 'bookify-booking' ),
			'step'  => '0.01',
			'min'   => '0',
			'hint'  => __( 'In the site currency. Leave it empty to show no price.', 'bookify-booking' ),
		),
		'bookify_duration'      => array(
			'type'  => 'number',
			'label' => __( 'Length in minutes', 'bookify-booking' ),
			'step'  => '1',
			'min'   => '0',
			'hint'  => __( 'Shown beside the price, and how long the session takes.', 'bookify-booking' ),
		),
		'bookify_capacity'      => array(
			'type'  => 'number',
			'label' => __( 'People one slot can take', 'bookify-booking' ),
			'step'  => '1',
			'min'   => '1',
			'hint'  => __( 'How many people can be booked for the same time. One is a one-to-one session; a higher number is a group class.', 'bookify-booking' ),
		),
		// T23. The three payment fields are read through includes/payments/amounts.php, which
		// re-validates each one, so a mode this box cannot produce is read as "free" rather than
		// being handed to Stripe.
		'bookify_payment_mode'  => array(
			'type'    => 'select',
			'label'   => __( 'Payment', 'bookify-booking' ),
			'options' => bookify_booking_payment_modes(),
			'hint'    => __( 'A booking that needs paying holds its time until the payment window closes. Payment is taken on Stripe’s own page, so it needs keys on the Settings screen — until then this booking says so rather than offering a button.', 'bookify-booking' ),
		),
		'bookify_deposit_type'  => array(
			'type'    => 'select',
			'label'   => __( 'A deposit is', 'bookify-booking' ),
			'options' => bookify_booking_deposit_types(),
			'hint'    => __( 'Used only when the payment above is a deposit.', 'bookify-booking' ),
		),
		'bookify_deposit_value' => array(
			'type'  => 'number',
			'label' => __( 'Deposit value', 'bookify-booking' ),
			'step'  => '0.01',
			'min'   => '0',
			'hint'  => __( 'An amount, or a percentage of the price when the choice above says so. A deposit is never allowed to come out above the price.', 'bookify-booking' ),
		),
	);
}

/**
 * Put the service fields on the service edit screen.
 */
function bookify_booking_add_service_fields_box() {
	add_meta_box(
		'bookify_service_fields',
		__( 'Session details', 'bookify-booking' ),
		'bookify_booking_service_fields_box',
		'bookify_service',
		'side',
		'default'
	);
}

/**
 * Print the fields, filled with what is stored.
 *
 * A select falls back to its first option when what is stored is not one of them: an owner who has
 * never opened this box sees "Nothing — the booking is confirmed as it is" selected, which is what
 * the site will actually do, rather than a control that looks unset.
 *
 * @param WP_Post $service The service being edited.
 */
function bookify_booking_service_fields_box( $service ) {
	wp_nonce_field( 'bookify_booking_service_fields', 'bookify_service_nonce' );

	foreach ( bookify_booking_service_fields() as $key => $field ) {
		$value = (string) get_post_meta( $service->ID, $key, true );
		$type  = isset( $field['type'] ) ? $field['type'] : 'number';

		if ( 'select' === $type && ! isset( $field['options'][ $value ] ) ) {
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
 * Save the fields.
 *
 * Nothing is written without a nonce of ours, which is also what keeps autosaves, quick edits
 * and bulk edits out: they post no nonce at all.
 *
 * @param int $post_id The service being saved.
 */
function bookify_booking_save_service_fields( $post_id ) {
	if ( ! isset( $_POST['bookify_service_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_key( wp_unslash( $_POST['bookify_service_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, 'bookify_booking_service_fields' ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( array_keys( bookify_booking_service_fields() ) as $key ) {
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;

		if ( ! is_string( $value ) ) {
			continue;
		}

		if ( '' === trim( $value ) ) {
			// An empty box means "not set" rather than zero, so a service the owner left
			// unpriced shows no price instead of 0.00.
			delete_post_meta( $post_id, $key );
			continue;
		}

		// The typecasting is done by the sanitisers registered in post-types.php: WordPress
		// runs them inside update_post_meta(), so this stays a plain write.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_post_meta( $post_id, $key, $value );
	}
}

/**
 * Register the service fields.
 */
function bookify_booking_register_service_fields() {
	add_action( 'add_meta_boxes_bookify_service', 'bookify_booking_add_service_fields_box' );
	add_action( 'save_post_bookify_service', 'bookify_booking_save_service_fields' );
}
