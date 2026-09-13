<?php
/**
 * The booking form.
 *
 * Every string it prints comes from $args, so the shortcode and the Elementor widget both
 * supply their own copy and neither has to change this file to reword the form.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The copy the form falls back to.
 *
 * 'services' is not copy: it is the list of rows to offer, as id => label. Leaving it empty
 * — which is what the shortcode does — means "offer every published session". The key keeps its
 * name so a page that already sets it keeps working; only the words a visitor reads changed.
 *
 * @return array
 */
function bookify_booking_form_defaults() {
	return array(
		'title'         => __( 'Book an appointment', 'bookify-booking' ),
		'intro'         => __( 'Tell us when suits you and we will confirm by email.', 'bookify-booking' ),
		'submit_label'  => __( 'Request booking', 'bookify-booking' ),
		'success'       => __( 'Thank you — we have your booking and will be in touch.', 'bookify-booking' ),
		'error'         => __( 'That submission could not be accepted. Please try again.', 'bookify-booking' ),
		'empty'         => __( 'No sessions are on offer yet.', 'bookify-booking' ),
		'currency'      => '',
		'label_service' => __( 'Session', 'bookify-booking' ),
		'label_date'    => __( 'Date', 'bookify-booking' ),
		'label_time'    => __( 'Time', 'bookify-booking' ),
		'label_name'    => __( 'Your name', 'bookify-booking' ),
		'label_email'   => __( 'Email', 'bookify-booking' ),
		'label_phone'   => __( 'Phone', 'bookify-booking' ),
		'label_guests'  => __( 'People', 'bookify-booking' ),
		'choose_label'  => __( 'Show times', 'bookify-booking' ),
		'no_days'       => __( 'There are no days open for booking at the moment.', 'bookify-booking' ),
		'closed'        => __( 'We are closed that day. Please choose one of the days on offer.', 'bookify-booking' ),
		'no_slots'      => __( 'Every time that day is taken. Please choose another day.', 'bookify-booking' ),
		'cancelled'     => __( 'That booking is cancelled, and the time is free again. Thank you for letting us know.', 'bookify-booking' ),
		'cancel_repeat' => __( 'That booking was already cancelled, so nothing has changed.', 'bookify-booking' ),
		'cancel_invalid' => __( 'That cancellation link is not valid, so nothing has changed.', 'bookify-booking' ),
		'services'      => array(),
	);
}

/**
 * A day as it is shown in the picker: the weekday and the date.
 *
 * The weekday matters most when choosing an appointment, so it is printed whatever the site's
 * date format is; the rest of the label follows the site's own format.
 *
 * @param string $date Date in YYYY-MM-DD form.
 * @return string
 */
function bookify_booking_day_label( $date ) {
	// Midday, so no offset or daylight-saving change can move the day being named.
	$when = date_create_immutable( $date . ' 12:00:00', wp_timezone() );

	return wp_date( 'D', $when->getTimestamp() ) . ' ' . wp_date( (string) get_option( 'date_format' ), $when->getTimestamp() );
}

/**
 * Every published service, in the order the form offers them.
 *
 * @return WP_Post[]
 */
function bookify_booking_published_services() {
	return get_posts(
		array(
			'post_type'      => 'bookify_service',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
}

/**
 * The rows the form offers, as service id => label.
 *
 * $args['services'] short-circuits the query: the Elementor widget puts its repeater rows
 * there (T6). An empty list falls back to the published services, so a widget whose rows
 * name nothing bookable still leaves a working form rather than an empty one.
 *
 * @param array $args The form's arguments, after defaults.
 * @return array<int,string>
 */
function bookify_booking_service_choices( array $args ) {
	if ( ! empty( $args['services'] ) ) {
		return $args['services'];
	}

	$choices = array();

	foreach ( bookify_booking_published_services() as $service ) {
		$choices[ $service->ID ] = bookify_booking_service_label( $service, $args['currency'] );
	}

	return $choices;
}

/**
 * A service's price as it should be shown, and its length in minutes.
 *
 * The one place a stored price becomes something a visitor reads, so the form and the listing
 * cannot format the same number two different ways.
 *
 * @param WP_Post $service  The service.
 * @param string  $currency Symbol to put in front of the price, if any.
 * @return array{price:string,minutes:int}
 */
function bookify_booking_service_price_and_length( WP_Post $service, $currency = '' ) {
	$price = get_post_meta( $service->ID, 'bookify_price', true );

	return array(
		'price'   => '' !== $price ? $currency . number_format_i18n( (float) $price, 2 ) : '',
		'minutes' => (int) get_post_meta( $service->ID, 'bookify_duration', true ),
	);
}

/**
 * A service's price and length as one line, skipping whichever is not set.
 *
 * @param string $price   The price as it should be shown, currency included, or ''.
 * @param int    $minutes Length in minutes, or 0.
 * @return string
 */
function bookify_booking_service_meta_label( $price, $minutes ) {
	$parts = array();

	if ( '' !== $price ) {
		$parts[] = $price;
	}

	if ( $minutes > 0 ) {
		$parts[] = sprintf(
			/* translators: %d: appointment length in minutes. */
			__( '%d min', 'bookify-booking' ),
			$minutes
		);
	}

	return implode( ' — ', $parts );
}

/**
 * The label for one service row from its three parts, skipping the ones that say nothing.
 *
 * @param string $title   The service's name as it should be shown.
 * @param string $price   The price as it should be shown, currency included, or ''.
 * @param int    $minutes Length in minutes, or 0.
 * @return string
 */
function bookify_booking_service_row_label( $title, $price, $minutes ) {
	$meta = bookify_booking_service_meta_label( $price, $minutes );

	return '' === $meta ? $title : $title . ' — ' . $meta;
}

/**
 * The label for one service: its title, and the price and length when they are set.
 *
 * @param WP_Post $service  The service.
 * @param string  $currency Symbol to put in front of the price, if any.
 * @return string
 */
function bookify_booking_service_label( WP_Post $service, $currency = '' ) {
	$parts = bookify_booking_service_price_and_length( $service, $currency );

	return bookify_booking_service_row_label( $service->post_title, $parts['price'], $parts['minutes'] );
}

/**
 * The error code the redirect carries, when a submission was rejected.
 *
 * @return string Code, or '' when this request is not the result of a rejection.
 */
function bookify_booking_error_code() {
	$state = isset( $_GET['bookify'] ) ? sanitize_key( wp_unslash( $_GET['bookify'] ) ) : '';

	if ( 'error' !== $state ) {
		return '';
	}

	return isset( $_GET['bookify_code'] ) ? sanitize_key( wp_unslash( $_GET['bookify_code'] ) ) : '';
}

/**
 * What the visitor typed before their submission was rejected.
 *
 * The redirect carries a random token rather than the values, so nothing the visitor typed
 * reaches the URL. The transient behind the token is deleted as it is read: a reload after
 * the correction is an empty form, not a replay of the rejected one.
 *
 * @return array<string,string>
 */
function bookify_booking_form_values() {
	$token = isset( $_GET['bookify_token'] ) ? (string) wp_unslash( $_GET['bookify_token'] ) : '';

	if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', $token ) ) {
		return array();
	}

	$values = get_transient( 'bookify_draft_' . $token );

	if ( ! is_array( $values ) ) {
		return array();
	}

	delete_transient( 'bookify_draft_' . $token );

	return $values;
}

/**
 * Render a name => value map as HTML attributes.
 *
 * True prints the attribute bare, which is what required and other boolean attributes want;
 * false and null are dropped entirely.
 *
 * @param array<string,mixed> $attributes Attributes to print.
 * @return string
 */
function bookify_booking_attributes( array $attributes ) {
	$html = array();

	foreach ( $attributes as $name => $value ) {
		if ( false === $value || null === $value ) {
			continue;
		}

		$html[] = true === $value
			? esc_attr( $name )
			: sprintf( '%s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
	}

	return implode( ' ', $html );
}

/**
 * One labelled control, marked up for the error state the redirect named.
 *
 * The single place a field is wrapped, so a rejected submission can never come back with
 * the message at the top and the field itself looking untouched.
 *
 * @param string $key   Field key: the control's id is bookify_<key>, and the error code is
 *                      matched against it.
 * @param string $label Visible label.
 * @param array  $args  tag (input or select), type, value, options for a select, after for markup
 *                      the caller wants inside the field below the control, id_suffix to keep this
 *                      field's id unique when a page shows more than one of these forms, and any
 *                      extra attributes as name => value.
 * @return string
 */
function bookify_booking_form_field( $key, $label, array $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'tag'       => 'input',
			'type'      => 'text',
			'value'     => '',
			'options'   => array(),
			'attrs'     => array(),
			'after'     => '',
			'id_suffix' => '',
		)
	);

	$code    = bookify_booking_error_code();
	$invalid = ( '' !== $code && bookify_booking_error_field( $code ) === $key );

	// The suffix is what keeps two bookings' fields apart on a page that lists several of them,
	// where the ids and the labels' "for" attributes would otherwise collide (T22).
	$id = 'bookify_' . $key . $args['id_suffix'];

	// The key is a class of its own, so the stylesheet and the calendar's script can address one
	// field without ids or nth-child guesses.
	$classes = array( 'bookify-booking__field', 'bookify-booking__field--' . sanitize_html_class( $key ) );

	if ( $invalid ) {
		$classes[] = 'bookify-booking__field--invalid';
	}

	$attributes = array(
		'id'   => $id,
		'name' => $id,
	);

	if ( $invalid ) {
		$attributes['aria-invalid']     = 'true';
		$attributes['aria-describedby'] = $id . '_message';
	}

	if ( 'select' === $args['tag'] ) {
		$options = '';

		foreach ( $args['options'] as $option_value => $option_label ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option_value ),
				(string) $option_value === (string) $args['value'] ? ' selected' : '',
				esc_html( $option_label )
			);
		}

		// Our own attributes win: an argument can add to them but not replace them.
		$control = sprintf(
			'<select %s>%s</select>',
			bookify_booking_attributes( $attributes + $args['attrs'] ),
			$options
		);
	} else {
		$attributes['type']  = $args['type'];
		$attributes['value'] = $args['value'];

		$control = sprintf( '<input %s />', bookify_booking_attributes( $attributes + $args['attrs'] ) );
	}

	$message = $invalid ? bookify_booking_error_message( $code ) : '';

	ob_start();
	?>
	<p class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<?php
		// Built above from escaped parts.
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<?php
		// Markup the caller built itself — the calendar container — never anything a visitor typed.
		echo $args['after']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<?php if ( '' !== $message ) : ?>
			<span class="bookify-booking__message" id="<?php echo esc_attr( $id . '_message' ); ?>"><?php echo esc_html( $message ); ?></span>
		<?php endif; ?>
	</p>
	<?php
	return (string) ob_get_clean();
}

/**
 * The notice to show above the form, when the visitor has just submitted it.
 *
 * @param array<string,string> $args The form's copy, for the messages below.
 * @return array{class:string,text:string}|null
 */
function bookify_booking_form_notice( array $args ) {
	$state = isset( $_GET['bookify'] ) ? sanitize_key( wp_unslash( $_GET['bookify'] ) ) : '';

	if ( '' === $state ) {
		return null;
	}

	if ( 'booked' === $state ) {
		return array(
			'class' => 'bookify-booking__notice--success',
			'text'  => $args['success'],
		);
	}

	// The three outcomes of the cancel link in the confirmation (T17). Each one is its own
	// wording, because "already cancelled" and "that link is not valid" are not the same news:
	// one means nothing was done to a booking that was already cancelled, the other is a link
	// that proved nothing. Neither is the visitor's mistake, so only the second is an error.
	if ( in_array( $state, array( 'cancelled', 'cancel_repeat', 'cancel_invalid' ), true ) ) {
		$classes = array(
			'cancelled'      => 'bookify-booking__notice--success',
			'cancel_repeat'  => '',
			'cancel_invalid' => 'bookify-booking__notice--error',
		);

		return array(
			'class' => $classes[ $state ],
			'text'  => isset( $args[ $state ] ) ? $args[ $state ] : $args['error'],
		);
	}

	return array(
		'class' => 'bookify-booking__notice--error',
		'text'  => bookify_booking_error_message( bookify_booking_error_code(), $args['error'] ),
	);
}

/**
 * Render the booking form.
 *
 * @param array $args Copy overrides; see bookify_booking_form_defaults().
 * @return string
 */
function bookify_booking_form_html( array $args = array() ) {
	$args = wp_parse_args( $args, bookify_booking_form_defaults() );

	$services = bookify_booking_service_choices( $args );

	$notice = bookify_booking_form_notice( $args );

	$values = bookify_booking_form_values();

	/*
	 * A service followed from a listing arrives in the URL. Only an id that is genuinely on offer can
	 * select anything, because the options are built from the services themselves.
	 *
	 * `bookify_service_id` rather than `bookify_service`: the shorter name is the post type key, and a
	 * registered post type's key is a public query var, so WordPress would read the argument as a
	 * session slug and answer 404 before this file ran. `bookify_service_id` is the name this form's
	 * own field already uses, and it is not a name WordPress claims.
	 */
	$preselected = isset( $_GET['bookify_service_id'] ) ? absint( $_GET['bookify_service_id'] ) : 0;

	// What the visitor typed, when there is anything of theirs left to show.
	$service_value = isset( $values['service_id'] ) ? absint( $values['service_id'] ) : $preselected;
	$time_value    = isset( $values['time'] ) ? (string) $values['time'] : '';
	$name_value    = isset( $values['name'] ) ? (string) $values['name'] : '';
	$email_value   = isset( $values['email'] ) ? (string) $values['email'] : '';
	$phone_value   = isset( $values['phone'] ) ? (string) $values['phone'] : '';
	$party_value   = isset( $values['party_size'] ) ? (string) $values['party_size'] : '1';

	// The times belong to one service, so the value has to name one that is actually offered.
	if ( ! isset( $services[ $service_value ] ) ) {
		$service_value = (int) array_key_first( $services );
	}

	// The day being looked at: the one the visitor chose, or the first day this service can
	// still be booked on. A real date is kept even when the diary is shut on it, so a hand-made
	// URL is answered with "we are closed that day" rather than quietly ignored.
	$chosen_day = isset( $values['date'] ) ? (string) $values['date'] : '';

	if ( ! bookify_booking_is_date( $chosen_day ) ) {
		$chosen_day = isset( $_GET['bookify_date'] ) ? sanitize_text_field( wp_unslash( $_GET['bookify_date'] ) ) : '';
	}

	$open_days = bookify_available_days( $service_value );

	$day = bookify_booking_is_date( $chosen_day ) ? $chosen_day : ( $open_days ? $open_days[0] : '' );

	$day_options = array();

	foreach ( $open_days as $open_day ) {
		$day_options[ $open_day ] = bookify_booking_day_label( $open_day );
	}

	// A day that was asked for by name stays in the list even when it is shut or full, so the
	// picker shows what was asked for and the message below explains it.
	if ( '' !== $day && ! isset( $day_options[ $day ] ) ) {
		$day_options = array( $day => bookify_booking_day_label( $day ) ) + $day_options;
	}

	// The one list: rendered here, and the only list bookify_create_booking() accepts.
	$slots = '' !== $day ? bookify_available_slots( $service_value, $day ) : array();

	// WordPress prints a style enqueued after wp_head in the footer, so only a request that
	// actually renders the form loads the stylesheet. The script is asked for the same way and
	// for the same reason, and it carries the datepicker WordPress itself ships (plan D12).
	wp_enqueue_style( 'bookify-booking-form' );
	wp_enqueue_script( 'bookify-booking-form' );

	/*
	 * Everything the calendar's script is told: where to ask for a day's times, and the days this
	 * service can still be booked on — the output of the same function the picker renders, so the
	 * calendar's idea of an available day cannot differ from the server's (T18).
	 *
	 * It rides on the wrapper rather than through wp_localize_script() so the data stays with the
	 * markup that needs it, and the list is the *only* source the script uses to decide which days
	 * are selectable: a day it cannot find there is a day it will not offer.
	 */
	// Built key by key rather than with array_map(): the service ids are integers, and array_map()
	// would re-index them 0, 1, 2 and leave the script looking up the wrong ceiling.
	$capacities = array();

	foreach ( array_keys( $services ) as $offered_service ) {
		$capacities[ (string) $offered_service ] = bookify_slot_capacity( $offered_service );
	}

	$script_data = array(
		'rest'       => rest_url( BOOKIFY_BOOKING_REST_NAMESPACE . '/slots' ),
		'days'       => array_values( $open_days ),
		'capacities' => $capacities,
	);

	ob_start();
	?>
	<div
		class="bookify-booking"
		data-bookify-rest="<?php echo esc_url( $script_data['rest'] ); ?>"
		data-bookify-days="<?php echo esc_attr( wp_json_encode( $script_data['days'] ) ); ?>"
		data-bookify-capacities="<?php echo esc_attr( wp_json_encode( $script_data['capacities'] ) ); ?>"
	>
		<?php if ( $notice ) : ?>
			<div class="bookify-booking__notice <?php echo esc_attr( $notice['class'] ); ?>" role="status" aria-live="polite"><?php echo esc_html( $notice['text'] ); ?></div>
		<?php endif; ?>

		<?php
		/*
		 * The confirmation page is where a customer who has just booked is offered an account (T22),
		 * and this prints nothing at all on any other kind of request: it needs the new booking's
		 * own link in the URL, which only the redirect after a booking puts there.
		 */
		echo bookify_customer_account_offer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
		?>

		<?php if ( '' !== $args['title'] ) : ?>
			<h2 class="bookify-booking__title"><?php echo esc_html( $args['title'] ); ?></h2>
		<?php endif; ?>

		<?php if ( '' !== $args['intro'] ) : ?>
			<p class="bookify-booking__intro"><?php echo esc_html( $args['intro'] ); ?></p>
		<?php endif; ?>

		<?php if ( ! $services ) : ?>
			<p class="bookify-booking__empty"><?php echo esc_html( $args['empty'] ); ?></p>
		<?php elseif ( ! $day_options ) : ?>
			<p class="bookify-booking__empty"><?php echo esc_html( $args['no_days'] ); ?></p>
		<?php else : ?>
			<?php
			/*
			 * Choosing when is a form of its own, and that is the point of it.
			 *
			 * "Show times" has to be a submitting control to work with JavaScript off, and a form
			 * submits its *first* submit button when Enter is pressed in a field. In one form with
			 * both buttons, Enter while typing a name would re-pick the day instead of booking it.
			 * Two forms, and Enter in the booking form books (measured in a browser, both ways).
			 *
			 * What the visitor has already been given back travels with the picker as hidden
			 * fields, so changing the day after a rejection does not throw their details away.
			 * Those values go in the body of the POST, never in the URL (T12's rule).
			 */
			?>
			<form class="bookify-booking__choose" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bookify-picker>
				<input type="hidden" name="action" value="bookify_booking" />
				<?php wp_nonce_field( 'bookify_booking_submit', 'bookify_nonce' ); ?>
				<input type="hidden" name="bookify_step" value="choose" />

				<?php
				$carried = array(
					'bookify_time'       => $time_value,
					'bookify_name'       => $name_value,
					'bookify_email'      => $email_value,
					'bookify_phone'      => $phone_value,
					'bookify_party_size' => $party_value,
				);

				foreach ( $carried as $field_name => $field_value ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $field_value ); ?>" />
				<?php endforeach; ?>

				<?php
				/*
				 * One session on offer is not a choice — a session's own page passes exactly one row —
				 * so the control becomes a hidden field rather than a dropdown holding a single
				 * option. It keeps the name the write path reads and the data-bookify-service
				 * attribute the picker's script requires, so the times still load and the form still
				 * posts exactly what it posted before; it just stops asking a question with one
				 * answer.
				 */
				if ( 1 === count( $services ) ) {
					printf(
						'<input type="hidden" name="bookify_service_id" value="%s" data-bookify-service />',
						esc_attr( (string) $service_value )
					);
				} else {
					echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						'service_id',
						$args['label_service'],
						array(
							'tag'     => 'select',
							'value'   => $service_value,
							'options' => $services,
							'attrs'   => array(
								'required'           => true,
								'data-bookify-service' => true,
							),
						)
					);
				}

				echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					'date',
					$args['label_date'],
					array(
						'tag'     => 'select',
						'value'   => $day,
						'options' => $day_options,
						'attrs'   => array(
							'required'        => true,
							'data-bookify-date' => true,
						),
						/*
						 * The calendar is drawn where the day select is, and the select itself is left alone:
						 * the script hides it only once the widget has initialised, so a visitor whose
						 * JavaScript never runs still picks a day the way T16 built. The group's accessible
						 * name is the same copy as the visible label, because the label points at a control
						 * that is hidden by then.
						 */
						'after'   => sprintf(
							'<span class="bookify-booking__calendar" role="group" aria-label="%s" data-bookify-calendar></span>',
							esc_attr( $args['label_date'] )
						),
					)
				);
				?>

				<p class="bookify-booking__submit bookify-booking__submit--choose" data-bookify-choose-submit>
					<button type="submit"><?php echo esc_html( $args['choose_label'] ); ?></button>
				</p>
			</form>

			<?php
			/*
			 * The times, and the two sentences for a day that has none.
			 *
			 * Both sentences are in the DOM whichever one applies, and the one that does not apply is
			 * hidden: the script has to be able to reveal the form for a day it fetched, and it has to
			 * be able to put a sentence back when a day it fetched turns out to have nothing left.
			 * A visitor without JavaScript sees exactly one of them, chosen by the server — and the
			 * script chooses the same way the server did, by asking whether that day is in the day
			 * list. A shut day and a full day are different news, and neither one leaves an empty box.
			 */
			$day_is_open = bookify_is_open_on( $day );
			?>
				<form class="bookify-booking__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bookify-form<?php echo $slots ? '' : ' hidden'; ?>>
					<input type="hidden" name="action" value="bookify_booking" />
					<?php wp_nonce_field( 'bookify_booking_submit', 'bookify_nonce' ); ?>

					<?php // What the picker above chose. The write path checks both again, and so does the script. ?>
					<input type="hidden" name="bookify_service_id" value="<?php echo esc_attr( (string) $service_value ); ?>" data-bookify-times-service />
					<input type="hidden" name="bookify_date" value="<?php echo esc_attr( $day ); ?>" data-bookify-times-date />

					<?php
					echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						'time',
						$args['label_time'],
						array(
							'tag'     => 'select',
							'value'   => $time_value,
							'options' => array_combine( $slots, $slots ),
							'attrs'   => array(
								'required'           => true,
								'data-bookify-time'  => true,
							),
						)
					);

					echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						'name',
						$args['label_name'],
						array(
							'value' => $name_value,
							'attrs' => array(
								'autocomplete' => 'name',
								'required'     => true,
							),
						)
					);

					echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						'email',
						$args['label_email'],
						array(
							'type'  => 'email',
							'value' => $email_value,
							'attrs' => array(
								'autocomplete' => 'email',
								'required'     => true,
							),
						)
					);

					echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						'phone',
						$args['label_phone'],
						array(
							'type'  => 'tel',
							'value' => $phone_value,
							'attrs' => array( 'autocomplete' => 'tel' ),
						)
					);

					/*
					 * One slot's capacity is the ceiling on a party: a one-to-one session takes one person,
					 * and a group class takes as many as its capacity says. The write path refuses a larger
					 * party either way — this is the same rule, printed where the visitor can read it, and the
					 * script re-applies it when the session changes.
					 */
					$party_max = bookify_slot_capacity( $service_value );

					echo bookify_booking_form_field( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						'party_size',
						$args['label_guests'],
						array(
							'type'  => 'number',
							'value' => $party_value,
							'attrs' => array(
								'min'                => '1',
								'max'                => (string) $party_max,
								'step'               => '1',
								'required'           => true,
								'data-bookify-party' => true,
							),
						)
					);
					?>

					<?php // Must stay empty: a bot fills every field it can see. A real visitor never reaches it. ?>
					<p class="bookify-booking__honeypot" aria-hidden="true">
						<input type="text" id="bookify_website" name="bookify_website" tabindex="-1" autocomplete="off" />
					</p>

					<?php
					/*
					 * Turnstile, when the owner has configured keys for it (T19). It is printed inside the
					 * form that writes and not the picker above it, which only asks the diary a question.
					 * Nothing is printed and no script is loaded unless both keys are present, so a site that
					 * has never heard of Turnstile loads exactly the page it loaded before.
					 */
					echo bookify_bots_turnstile_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, from an escaped key.
					?>

					<p class="bookify-booking__submit">
						<button type="submit"><?php echo esc_html( $args['submit_label'] ); ?></button>
					</p>
				</form>

				<p class="bookify-booking__message bookify-booking__message--info" data-bookify-empty="full"<?php echo ( $slots || ! $day_is_open ) ? ' hidden' : ''; ?>>
					<?php echo esc_html( $args['no_slots'] ); ?>
				</p>
				<p class="bookify-booking__message bookify-booking__message--info" data-bookify-empty="closed"<?php echo ( $slots || $day_is_open ) ? ' hidden' : ''; ?>>
					<?php echo esc_html( $args['closed'] ); ?>
				</p>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Register the form's stylesheet.
 *
 * Nothing is enqueued here: the render function asks for the handle, so a page that does not
 * hold the form never loads the file.
 */
function bookify_booking_register_form_style() {
	wp_register_style(
		'bookify-booking-form',
		BOOKIFY_BOOKING_URL . 'assets/booking-form.css',
		array(),
		BOOKIFY_BOOKING_VERSION
	);
}

add_action( 'wp_enqueue_scripts', 'bookify_booking_register_form_style' );

/**
 * Register the form's script.
 *
 * The calendar is WordPress's own jQuery UI datepicker — 'jquery-ui-datepicker' and the
 * 'jquery-ui-core' it depends on are in every install (plan D12), and core localises both the
 * month and day names and the date format for the site's own locale, so nothing here needs a
 * vendored library, a CDN or a build step. The one file this plugin adds is the small script that
 * wires that widget to the server's answer.
 *
 * Nothing is enqueued here either: the render function asks for the handle.
 */
function bookify_booking_register_form_script() {
	wp_register_script(
		'bookify-booking-form',
		BOOKIFY_BOOKING_URL . 'assets/booking-form.js',
		array( 'jquery', 'jquery-ui-datepicker' ),
		BOOKIFY_BOOKING_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}

add_action( 'wp_enqueue_scripts', 'bookify_booking_register_form_script' );

/**
 * Render the form from a shortcode.
 *
 * @param array|string $atts Shortcode attributes, named after the form's copy keys.
 * @return string
 */
function bookify_booking_form_shortcode( $atts = array() ) {
	return bookify_booking_form_html( shortcode_atts( bookify_booking_form_defaults(), $atts, 'bookify_booking_form' ) );
}

/**
 * Register the shortcode.
 */
function bookify_booking_register_shortcodes() {
	add_shortcode( 'bookify_booking_form', 'bookify_booking_form_shortcode' );
}
