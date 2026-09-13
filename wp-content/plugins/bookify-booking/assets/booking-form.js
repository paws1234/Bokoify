/*
 * The booking pickers' behaviour, and nothing else.
 *
 * It is an enhancement, never the working part: every picker is rendered complete and usable by the
 * server, and everything here only replaces one round trip with a faster one. Two rules keep that
 * honest —
 *
 *   1. a calendar can only offer days the server already listed as bookable, and the times it shows
 *      come from the same function the server renders, so the two cannot disagree;
 *   2. when anything at all goes wrong the day is handed back to the server by submitting that
 *      picker, which is exactly what a visitor without JavaScript does.
 *
 * Everything a picker needs is read from its own markup: the days its service can be booked on and
 * the URL of the read-only route that answers with a day's times. No globals, no inline script,
 * nothing to keep in step by hand.
 *
 * Every picker on the page is enhanced, each in its own scope: the booking form has one, and the My
 * bookings page has one per booking, and none of them may reach into another's fields.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.fetch || ! $.fn.datepicker ) {
		return;
	}

	// One counter for the whole file, so a page with several pickers still gets unique panel ids.
	var calendarCount = 0;

	/**
	 * Two digits, for a date string.
	 *
	 * @param {number} value The number to pad.
	 * @return {string} The padded number.
	 */
	function pad( value ) {
		return ( value < 10 ? '0' : '' ) + value;
	}

	/**
	 * A date as the server spells a day, YYYY-MM-DD.
	 *
	 * Read from the calendar's own local parts on purpose: the visitor clicks the square labelled
	 * 14, and the answer has to be the 14th — not the 14th of some other timezone.
	 *
	 * @param {Date} date The date.
	 * @return {string} The day, in YYYY-MM-DD form.
	 */
	function dayValue( date ) {
		return date.getFullYear() + '-' + pad( date.getMonth() + 1 ) + '-' + pad( date.getDate() );
	}

	/**
	 * A day string as a Date, at local midnight.
	 *
	 * @param {string} value The day, in YYYY-MM-DD form.
	 * @return {Date} The date.
	 */
	function dayDate( value ) {
		var parts = String( value ).split( '-' );

		return new Date( Number( parts[ 0 ] ), Number( parts[ 1 ] ) - 1, Number( parts[ 2 ] ) );
	}

	/**
	 * Take charge of one picker.
	 *
	 * Anything that is not a complete picker is left exactly as the server rendered it, which is a
	 * working picker — the day select, with its button. That includes a picker whose day list is
	 * empty, where there is nothing for a calendar to show.
	 *
	 * @param {Element} root The element carrying the picker and its data.
	 */
	function enhance( root ) {
		var days = [];

		try {
			days = JSON.parse( root.getAttribute( 'data-bookify-days' ) || '[]' );
		} catch ( error ) {
			// A picker whose data cannot be read is a picker that keeps the server's own controls.
			return;
		}

		if ( ! days.length ) {
			return;
		}

		var rest = root.getAttribute( 'data-bookify-rest' ) || '';
		var picker = root.querySelector( '[data-bookify-picker]' );
		var service = root.querySelector( '[data-bookify-service]' );
		var dateSelect = root.querySelector( '[data-bookify-date]' );
		var calendar = root.querySelector( '[data-bookify-calendar]' );
		var timesSelect = root.querySelector( '[data-bookify-time]' );
		var form = root.querySelector( '[data-bookify-form]' );
		var submit = root.querySelector( '[data-bookify-choose-submit]' );
		var timesDay = root.querySelector( '[data-bookify-times-date]' );
		var timesService = root.querySelector( '[data-bookify-times-service]' );
		var party = root.querySelector( '[data-bookify-party]' );

		if ( ! rest || ! picker || ! service || ! dateSelect || ! calendar || ! timesSelect || ! form || ! timesDay || ! timesService ) {
			return;
		}

		var bookable = {};

		days.forEach( function ( day ) {
			bookable[ day ] = true;
		} );

		var notices = root.querySelectorAll( '[data-bookify-empty]' );

		/*
		 * How many people one slot of each session takes, so the party field can stop offering more
		 * than a slot holds. A limit that cannot be read leaves the server's own max attribute in
		 * place, which is already correct for the session the page was rendered for.
		 */
		var capacities = {};

		try {
			capacities = JSON.parse( root.getAttribute( 'data-bookify-capacities' ) || '{}' );
		} catch ( error ) {
			capacities = {};
		}

		/**
		 * Put the chosen session's ceiling on the party field.
		 *
		 * The server renders the ceiling for the session it rendered, so this only matters after the
		 * visitor has changed session: without it, a class of eight would keep the one-person ceiling
		 * of the session they were looking at first.
		 */
		function applyCapacity() {
			if ( ! party ) {
				return;
			}

			var limit = Number( capacities[ String( service.value ) ] );

			if ( ! limit ) {
				return;
			}

			party.max = String( limit );

			if ( Number( party.value ) > limit ) {
				party.value = String( limit );
			}
		}

		/**
		 * Put one day's times in the form that submits.
		 *
		 * The time chosen by the server keeps its place in the list when it is still offered, so
		 * changing the day and changing back does not re-choose it.
		 *
		 * @param {string} day   The day the times belong to.
		 * @param {Array}  times The times, in HH:MM form.
		 */
		function showTimes( day, times ) {
			var wanted = timesSelect.value;

			timesSelect.innerHTML = '';

			times.forEach( function ( time ) {
				var option = document.createElement( 'option' );

				option.value = time;
				option.textContent = time;

				if ( time === wanted ) {
					option.selected = true;
				}

				timesSelect.appendChild( option );
			} );

			/*
			 * The form names the service and the day whose times it is showing. Setting both here,
			 * in the one place the times are rendered, is what keeps those three from ever drifting
			 * apart — including while a new day is being fetched, when the form is hidden precisely
			 * because the list on screen no longer belongs to the day in its own hidden field.
			 */
			timesDay.value = day;
			timesService.value = service.value;
			dateSelect.value = day;

			form.hidden = ! times.length;

			/*
			 * The same decision the server makes when it renders this form: a day that is not in
			 * the day list is a day the diary is shut on, and a day that is in the list but has no
			 * times left is a day that filled up since the page was sent. A shut day and a full day
			 * are different news, and neither one leaves an empty box behind.
			 */
			var message = times.length ? '' : ( bookable[ day ] ? 'full' : 'closed' );

			notices.forEach( function ( notice ) {
				notice.hidden = notice.getAttribute( 'data-bookify-empty' ) !== message;
			} );

			timesSelect.disabled = false;

			applyCapacity();
		}

		/**
		 * Ask the server for one day's times and put them in the form.
		 *
		 * The old list goes as soon as a new day is chosen: it belongs to another day, and a
		 * visitor who submits in between must not be able to book it by accident.
		 *
		 * @param {string} day The day to ask about.
		 */
		function loadDay( day ) {
			if ( ! day ) {
				return;
			}

			form.hidden = true;
			timesSelect.disabled = true;

			window
				.fetch(
					rest +
						( rest.indexOf( '?' ) === -1 ? '?' : '&' ) +
						'service=' +
						encodeURIComponent( service.value ) +
						'&date=' +
						encodeURIComponent( day ),
					{
						credentials: 'same-origin',
						headers: { Accept: 'application/json' }
					}
				)
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}

					return response.json();
				} )
				.then( function ( times ) {
					showTimes( day, times );
				} )
				.catch( function () {
					/*
					 * The one honest fallback: hand the day back to the server, which is exactly
					 * what the picker's own button does. Never keep a list that belongs to another
					 * day.
					 *
					 * submit() rather than requestSubmit(): the day select is hidden by then, and a
					 * hidden required control is the kind of thing a browser refuses to submit.
					 */
					HTMLFormElement.prototype.submit.call( picker );
				} );
		}

		/*
		 * The panel is named here, before the widget is built rather than after.
		 *
		 * jQuery UI gives the element it is bound to an id of its own when that element has none
		 * ("dp1", "dp2", ...), and then resolves every day click through it: the handler it attaches
		 * does `$( "#" + inst.id )` and reads the instance back off that element. Naming it afterwards
		 * leaves the widget looking for an id that no longer exists, and the click is dropped in
		 * silence - no error, no selection, nothing to see in the console. Naming it first means the
		 * widget finds one already there and keeps it.
		 */
		calendar.id = 'bookify-calendar-' + ( ++calendarCount );
		calendar.hidden = true;

		// The days this service can still be booked on, and nothing else: a square the server did
		// not list is a square that cannot be clicked, so the calendar cannot invent a bookable day.
		$( calendar ).datepicker( {
			beforeShowDay: function ( date ) {
				return [ Boolean( bookable[ dayValue( date ) ] ), '', '' ];
			},
			defaultDate: dateSelect.value ? dayDate( dateSelect.value ) : dayDate( days[ 0 ] ),
			minDate: 0,
			maxDate: ( function () {
				var last = dayDate( days[ days.length - 1 ] );
				var today = new Date();

				today.setHours( 0, 0, 0, 0 );

				return Math.max( 0, Math.round( ( last - today ) / 86400000 ) );
			}() ),
			onSelect: function ( dateText, instance ) {
				/*
				 * The text is in the site's own date format — WordPress localises this widget with
				 * whichever format the site uses — so the day is read from the widget's own numbers
				 * rather than parsed back out of that string.
				 */
				var day = dayValue( new Date( instance.selectedYear, instance.selectedMonth, instance.selectedDay ) );

				if ( ! bookable[ day ] ) {
					return;
				}

					nameChosenDay( day );
					closeCalendar( false );
					loadDay( day );
				}
			} );

		/*
		 * The calendar is a dropdown, not a month grid parked in the middle of the form.
		 *
		 * The field names the day that is chosen and the grid appears only while it is open, so what a
		 * visitor sees first is a form rather than a wall of dates. Nothing about the closed state is a
		 * state without a date: the server rendered one, the select still holds it, and that day's times
		 * are already listed below.
		 *
		 * The trigger is built here rather than in the markup because it only means anything once this
		 * script can drive it — a visitor without JavaScript keeps the select, which is the control T16
		 * built, and never sees a button that does nothing.
		 */
		var trigger = document.createElement( 'button' );
		var triggerValue = document.createElement( 'span' );
		var triggerCaret = document.createElement( 'span' );
		var fieldLabel = calendar.getAttribute( 'aria-label' ) || '';
		var isOpen = false;

		trigger.type = 'button';
		trigger.className = 'bookify-booking__date-trigger';
		trigger.setAttribute( 'aria-expanded', 'false' );
		trigger.setAttribute( 'aria-controls', calendar.id );

		triggerValue.className = 'bookify-booking__date-value';
		triggerCaret.className = 'bookify-booking__date-caret';
		triggerCaret.setAttribute( 'aria-hidden', 'true' );

		trigger.appendChild( triggerValue );
		trigger.appendChild( triggerCaret );
		dateSelect.parentNode.insertBefore( trigger, calendar );

		/**
		 * Name the chosen day on the field.
		 *
		 * The text is the option the select would have shown, so the field and the control it stands in
		 * for say exactly the same thing about the same day — in the site's own date format — rather
		 * than the field inventing a format of its own. The accessible name repeats the field's label,
		 * because the label points at the select, and the select is hidden by now.
		 *
		 * @param {string} day The day, in YYYY-MM-DD form.
		 */
		function nameChosenDay( day ) {
			var value = String( day || dateSelect.value );
			var text = value;

			for ( var i = 0; i < dateSelect.options.length; i++ ) {
				if ( dateSelect.options[ i ].value === value ) {
					text = dateSelect.options[ i ].textContent;
					break;
				}
			}

			triggerValue.textContent = text;
			trigger.setAttribute( 'aria-label', fieldLabel ? fieldLabel + ': ' + text : text );
		}

		/**
		 * A click anywhere outside the panel, or on its trigger, shuts it.
		 *
		 * Guarded on the trigger as well as the panel because the click that opens the calendar reaches
		 * this listener too, and it must not be the click that closes it again.
		 *
		 * @param {Event} event The click.
		 */
		function onOutsideClick( event ) {
			if ( ! calendar.contains( event.target ) && ! trigger.contains( event.target ) ) {
				closeCalendar( false );
			}
		}

		/**
		 * Escape shuts the panel and hands focus back to the field.
		 *
		 * @param {KeyboardEvent} event The key press.
		 */
		function onEscape( event ) {
			if ( 'Escape' === event.key ) {
				closeCalendar( true );
			}
		}

		/**
		 * Shut the calendar, and say so on the trigger.
		 *
		 * @param {boolean} refocus Whether the trigger takes focus back whatever had it.
		 */
		function closeCalendar( refocus ) {
			if ( ! isOpen ) {
				return;
			}

			// Only a visitor who was inside the panel gets moved; a mouse click elsewhere does not.
			var hadFocus = calendar.contains( document.activeElement );

			isOpen = false;
			calendar.hidden = true;
			trigger.setAttribute( 'aria-expanded', 'false' );

			document.removeEventListener( 'click', onOutsideClick );
			document.removeEventListener( 'keydown', onEscape );

			if ( refocus || hadFocus ) {
				trigger.focus();
			}
		}

		/**
		 * Open the calendar on the day that is already chosen.
		 */
		function openCalendar() {
			isOpen = true;
			calendar.hidden = false;
			trigger.setAttribute( 'aria-expanded', 'true' );

			$( calendar ).datepicker( 'setDate', dateSelect.value ? dayDate( dateSelect.value ) : null );

			/*
			 * Focus goes into the grid, so a keyboard visitor lands where the choice is instead of having
			 * to tab past the panel's own arrows first. Not every month holds a bookable day, so the
			 * fallback is whatever the panel does offer.
			 */
			var first =
				calendar.querySelector( 'td.ui-datepicker-current-day a' ) ||
				calendar.querySelector( 'td a' ) ||
				calendar.querySelector( 'a' );

			if ( first ) {
				first.focus();
			}

			document.addEventListener( 'click', onOutsideClick );
			document.addEventListener( 'keydown', onEscape );
		}

		trigger.addEventListener( 'click', function () {
			if ( isOpen ) {
				closeCalendar( false );
			} else {
				openCalendar();
			}
		} );

		nameChosenDay( String( dateSelect.value ) );

		// Only now is the calendar in charge: until this line the select is the visitor's control,
		// so a script that fails half way through leaves a working picker, not an empty space.
		root.classList.add( 'bookify-booking--enhanced' );

		if ( submit ) {
			submit.hidden = true;
		}

		/*
		 * Changing the service changes whose times are being asked for. The form is told that by
		 * showTimes(), with the times themselves, so it never names a service whose times are not
		 * the ones on screen.
		 *
		 * The calendar's day list is deliberately not re-read: that would take a page request, and
		 * the list can only be too generous, never wrong — a day with nothing free for the newly
		 * chosen service answers "every time that day is taken" and asks for another day.
		 */
		service.addEventListener( 'change', function () {
			// The ceiling belongs to the session now chosen, and it must not wait for a fetch that
			// may hand the day back to the server.
			applyCapacity();

			loadDay( dateSelect.value );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.bookify-booking' ), enhance );
} )( jQuery );
