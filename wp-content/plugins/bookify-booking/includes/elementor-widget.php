<?php
/**
 * The Elementor widgets: the booking form, the list of services and the ticket form.
 *
 * None of them adds copy of its own: every string any of them can render is a control, and the
 * rendering is done by bookify_booking_form_html(), bookify_service_list_html() and
 * bookify_event_ticket_form_html(), so the shortcodes and the widgets cannot drift apart.
 *
 * The classes extend Elementor classes, and Elementor registers its autoloader on init, so this
 * file is required from the registration callback in the bootstrap — never at file scope, where
 * a site without Elementor would fatal on it.
 *
 * @package bookify-booking
 */

defined( 'ABSPATH' ) || exit;

/**
 * The booking form, as an Elementor widget.
 */
class Bookify_Booking_Widget extends \Elementor\Widget_Base {

	/**
	 * The widget's machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'bookify_booking_form';
	}

	/**
	 * The label in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Booking form', 'bookify-booking' );
	}

	/**
	 * The panel icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * The words the panel search matches on.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() {
		return array( 'bookify', 'booking', 'form', 'appointment', 'reservation' );
	}

	/**
	 * The panel category this widget appears under.
	 *
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'bookify' );
	}

	/**
	 * Every string the form can print, as a control, plus the session rows.
	 *
	 * The defaults come from bookify_booking_form_defaults() rather than being repeated
	 * here, so the shortcode and the widget start from the same copy.
	 */
	protected function register_controls() {
		$defaults = bookify_booking_form_defaults();

		$service_options = array( '' => esc_html__( 'Choose a session…', 'bookify-booking' ) );

		foreach ( bookify_booking_published_services() as $service ) {
			$service_options[ (string) $service->ID ] = $service->post_title;
		}

		$this->start_controls_section(
			'bookify_content',
			array(
				'label' => esc_html__( 'Booking form', 'bookify-booking' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Title', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['title'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'intro',
			array(
				'label'       => esc_html__( 'Intro', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['intro'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'currency',
			array(
				'label'       => esc_html__( 'Currency symbol', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['currency'],
				'description' => esc_html__( 'Shown in front of each session price. Leave empty to show bare numbers.', 'bookify-booking' ),
			)
		);

		$this->add_control(
			'submit_label',
			array(
				'label'       => esc_html__( 'Button label', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['submit_label'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'success',
			array(
				'label'       => esc_html__( 'Success message', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['success'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'error',
			array(
				'label'       => esc_html__( 'Fallback error message', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['error'],
				'description' => esc_html__( 'Shown when a rejection has no message of its own.', 'bookify-booking' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'empty',
			array(
				'label'       => esc_html__( 'No sessions message', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['empty'],
				'description' => esc_html__( 'Shown instead of the form when no sessions are published.', 'bookify-booking' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'services',
			array(
				'label'       => esc_html__( 'Session rows', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => array(
					array(
						'name'    => 'service',
						'label'   => esc_html__( 'Session', 'bookify-booking' ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'options' => $service_options,
						'default' => '',
					),
					array(
						'name'        => 'label',
						'label'       => esc_html__( 'Shown as', 'bookify-booking' ),
						'type'        => \Elementor\Controls_Manager::TEXT,
						'label_block' => true,
					),
					array(
						'name'  => 'price',
						'label' => esc_html__( 'Price shown', 'bookify-booking' ),
						'type'  => \Elementor\Controls_Manager::TEXT,
					),
					array(
						'name'  => 'duration',
						'label' => esc_html__( 'Minutes shown', 'bookify-booking' ),
						'type'  => \Elementor\Controls_Manager::NUMBER,
					),
				),
				'title_field' => '{{{ label }}}',
				'description' => esc_html__( 'Each row offers the session it names; the text here only changes what is shown, and the write path still books the session itself. Leave the rows out to offer every published session.', 'bookify-booking' ),
				'default'     => array(),
				'separator'   => 'before',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'bookify_diary',
			array(
				'label'       => esc_html__( 'Diary and slots', 'bookify-booking' ),
				'description' => esc_html__( 'The times the form offers come from the Bookify settings screen; only the wording is set here.', 'bookify-booking' ),
			)
		);

		$diary = array(
			'choose_label'   => esc_html__( 'Show times button', 'bookify-booking' ),
			'no_days'        => esc_html__( 'No days open', 'bookify-booking' ),
			'closed'         => esc_html__( 'Closed on the chosen day', 'bookify-booking' ),
			'no_slots'       => esc_html__( 'No free times that day', 'bookify-booking' ),
			'cancelled'      => esc_html__( 'Booking cancelled', 'bookify-booking' ),
			'cancel_repeat'  => esc_html__( 'Already cancelled', 'bookify-booking' ),
			'cancel_invalid' => esc_html__( 'Cancellation link not valid', 'bookify-booking' ),
		);

		foreach ( $diary as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'       => $label,
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => $defaults[ $key ],
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'bookify_labels',
			array(
				'label' => esc_html__( 'Field labels', 'bookify-booking' ),
			)
		);

		$labels = array(
			'label_service' => esc_html__( 'Session', 'bookify-booking' ),
			'label_date'    => esc_html__( 'Date', 'bookify-booking' ),
			'label_time'    => esc_html__( 'Time', 'bookify-booking' ),
			'label_name'    => esc_html__( 'Name', 'bookify-booking' ),
			'label_email'   => esc_html__( 'Email', 'bookify-booking' ),
			'label_phone'   => esc_html__( 'Phone', 'bookify-booking' ),
			'label_guests'  => esc_html__( 'People', 'bookify-booking' ),
		);

		foreach ( $labels as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'       => $label,
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => $defaults[ $key ],
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Print the form, with the control values as its copy.
	 */
	protected function render() {
		$args     = bookify_booking_form_defaults();
		$settings = $this->get_settings_for_display();

		foreach ( array_keys( $args ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$args[ $key ] = $settings[ $key ];
			}
		}

		$args['services'] = bookify_booking_widget_service_rows( $args['services'], $args['currency'] );

		// bookify_booking_form_html() escapes every value it prints.
		echo bookify_booking_form_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * The list of sessions, as an Elementor widget.
 */
class Bookify_Service_List_Widget extends \Elementor\Widget_Base {

	/**
	 * The widget's machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'bookify_service_list';
	}

	/**
	 * The label in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Session list', 'bookify-booking' );
	}

	/**
	 * The panel icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-price-list';
	}

	/**
	 * The words the panel search matches on.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() {
		return array( 'bookify', 'sessions', 'treatments', 'classes', 'prices' );
	}

	/**
	 * The panel category this widget appears under.
	 *
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'bookify' );
	}

	/**
	 * Every string the list can print, as a control.
	 */
	protected function register_controls() {
		$defaults = bookify_service_list_defaults();

		$this->start_controls_section(
			'bookify_service_list_content',
			array(
				'label' => esc_html__( 'Session list', 'bookify-booking' ),
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'       => esc_html__( 'Heading', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['heading'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'intro',
			array(
				'label'       => esc_html__( 'Intro', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['intro'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'currency',
			array(
				'label'       => esc_html__( 'Currency symbol', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['currency'],
				'description' => esc_html__( 'Shown in front of each price. Leave empty to show bare numbers.', 'bookify-booking' ),
			)
		);

		$this->add_control(
			'button_label',
			array(
				'label'       => esc_html__( 'Button label', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['button_label'],
				'label_block' => true,
			)
		);

		$this->add_control(
			'more_label',
			array(
				'label'       => esc_html__( 'Read more label', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['more_label'],
				'description' => esc_html__( 'Links each card to that session\'s own page. Leave it empty to hide the link.', 'bookify-booking' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'empty',
			array(
				'label'       => esc_html__( 'No sessions message', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => $defaults['empty'],
				'description' => esc_html__( 'Shown instead of the list when no sessions are published.', 'bookify-booking' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Print the list, with the control values as its copy.
	 */
	protected function render() {
		$args     = bookify_service_list_defaults();
		$settings = $this->get_settings_for_display();

		foreach ( array_keys( $args ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$args[ $key ] = $settings[ $key ];
			}
		}

		// bookify_service_list_html() escapes every value it prints.
		echo bookify_service_list_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Turn the repeater rows into the rows the form offers, as service id => label.
 *
 * A row is only offered when it names a service that is actually bookable, because a
 * booking is always created against a bookify_service post and never against the row:
 * the post type decides what can be booked, and the row only decides how it is worded.
 * Nothing but the id, a label, a price and a length leaves this function, so the raw
 * setting is never trusted, and a row whose only service is deleted simply disappears.
 *
 * @param array  $rows     Repeater rows, as Elementor stores them.
 * @param string $currency Symbol to put in front of a price taken from the service.
 * @return array<int,string> Service id => label.
 */
function bookify_booking_widget_service_rows( array $rows, $currency ) {
	$choices = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$service = bookify_booking_bookable_service( isset( $row['service'] ) ? $row['service'] : 0 );

		if ( null === $service || isset( $choices[ $service->ID ] ) ) {
			// Nothing to book, or this service already has a row: the first one wins.
			continue;
		}

		$label = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';

		if ( '' === $label ) {
			$label = $service->post_title;
		}

		$price = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';

		if ( '' === $price ) {
			$meta  = get_post_meta( $service->ID, 'bookify_price', true );
			$price = '' !== $meta ? $currency . number_format_i18n( (float) $meta, 2 ) : '';
		}

		$minutes = isset( $row['duration'] ) ? absint( $row['duration'] ) : 0;

		if ( 0 === $minutes ) {
			$minutes = (int) get_post_meta( $service->ID, 'bookify_duration', true );
		}

		$choices[ $service->ID ] = bookify_booking_service_row_label( $label, $price, $minutes );
	}

	return $choices;
}

/**
 * The ticket form, as an Elementor widget (T35).
 *
 * The event is a control of its own because a ticket form is not always on the event's page: an
 * owner may put one on a landing page or in a campaign, and choosing the event in the panel is what
 * makes that possible. Left unset, the widget sells tickets for the event whose page it is on, which
 * is what a page built for one event wants. Everything else is copy, exactly as the booking form's
 * controls are.
 */
class Bookify_Event_Tickets_Widget extends \Elementor\Widget_Base {

	/**
	 * The widget's machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'bookify_event_tickets';
	}

	/**
	 * The label in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Ticket form', 'bookify-booking' );
	}

	/**
	 * The panel icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-price-list';
	}

	/**
	 * The words the panel search matches on.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() {
		return array( 'bookify', 'event', 'tickets', 'form', 'booking' );
	}

	/**
	 * The panel category this widget appears under.
	 *
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'bookify' );
	}

	/**
	 * The event, and every string the ticket form can print.
	 *
	 * The defaults come from bookify_event_ticket_form_defaults() rather than being repeated here,
	 * so the shortcode and the widget start from the same copy.
	 */
	protected function register_controls() {
		$defaults = bookify_event_ticket_form_defaults();

		$this->start_controls_section(
			'bookify_event_content',
			array(
				'label' => esc_html__( 'Ticket form', 'bookify-booking' ),
			)
		);

		// String keys, because that is what an Elementor control's options are read as.
		$events = array( '0' => esc_html__( 'The event this page is', 'bookify-booking' ) );

		foreach ( bookify_booking_event_choices() as $event_id => $label ) {
			$events[ (string) $event_id ] = $label;
		}

		$this->add_control(
			'event',
			array(
				'label'       => esc_html__( 'Event', 'bookify-booking' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $events,
				'default'     => '0',
				'description' => esc_html__( 'Leave this as it is to sell tickets for the event whose page the widget is on.', 'bookify-booking' ),
			)
		);

		foreach ( array( 'title', 'intro', 'submit_label', 'success', 'error' ) as $key ) {
			$this->add_control(
				$key,
				array(
					'label'       => esc_html__( 'Copy', 'bookify-booking' ) . ': ' . $key,
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => $defaults[ $key ],
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'bookify_event_labels',
			array(
				'label' => esc_html__( 'Field labels', 'bookify-booking' ),
			)
		);

		$labels = array(
			'label_tier'     => esc_html__( 'Ticket', 'bookify-booking' ),
			'label_quantity' => esc_html__( 'How many', 'bookify-booking' ),
			'label_name'     => esc_html__( 'Name', 'bookify-booking' ),
			'label_email'    => esc_html__( 'Email', 'bookify-booking' ),
			'label_phone'    => esc_html__( 'Phone', 'bookify-booking' ),
		);

		foreach ( $labels as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'       => $label,
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => $defaults[ $key ],
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Print the form, with the control values as its copy.
	 */
	protected function render() {
		$args     = bookify_event_ticket_form_defaults();
		$settings = $this->get_settings_for_display();

		foreach ( array_keys( $args ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$args[ $key ] = $settings[ $key ];
			}
		}

		$args['event'] = absint( $args['event'] );

		// Unset means "the event this page is", which is what an owner placing the widget on an event
		// expects, and what the shortcode does with no attribute either.
		if ( ! $args['event'] && is_singular( 'bookify_event' ) ) {
			$args['event'] = (int) get_the_ID();
		}

		// bookify_event_ticket_form_html() escapes every value it prints.
		echo bookify_event_ticket_form_html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
