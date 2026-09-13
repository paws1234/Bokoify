/*
 * The ticket form's behaviour, and nothing else.
 *
 * It is an enhancement, never the working part: the quantity control is rendered complete and usable
 * by the server with the ceiling of the ticket that is preselected, and everything here only moves
 * that ceiling when the visitor changes their mind. A visitor without JavaScript still gets a form
 * that posts, and an over-large party is refused by the write path with its own message.
 *
 * The numbers are the server's own: they are read from the wrapper the form rendered, from the same
 * functions the write path counts with, so nothing here can offer a quantity the server would
 * refuse. No globals, no inline script, nothing to keep in step by hand. Every ticket form on the
 * page is handled, each in its own scope.
 */
( function () {
	'use strict';

	/**
	 * Move one form's quantity ceiling onto the ticket it currently names.
	 *
	 * @param {Element} form The ticket form.
	 */
	function applyCeiling( form ) {
		var wrapper = form.closest( '[data-bookify-tickets]' );
		var tier    = form.querySelector( '[data-bookify-tier]' );
		var amount  = form.querySelector( '[data-bookify-quantity]' );

		if ( ! wrapper || ! tier || ! amount ) {
			return;
		}

		var available;

		try {
			available = JSON.parse( wrapper.getAttribute( 'data-bookify-tier-available' ) || '{}' );
		} catch ( error ) {
			// Nothing readable: leave the server's ceiling where it is rather than guessing one.
			return;
		}

		var left = parseInt( available[ tier.value ], 10 );

		if ( isNaN( left ) ) {
			return;
		}

		amount.max = String( left );

		if ( parseInt( amount.value, 10 ) > left ) {
			amount.value = String( left > 0 ? left : '' );
		}

		// Nothing left of what was chosen: the control cannot lead anywhere, so it says so.
		amount.disabled = left < 1;
	}

	/**
	 * Wire up every ticket form on the page.
	 */
	function init() {
		var forms = document.querySelectorAll( '[data-bookify-ticket-form]' );

		for ( var index = 0; index < forms.length; index++ ) {
			var form = forms[ index ];
			var tier = form.querySelector( '[data-bookify-tier]' );

			applyCeiling( form );

			if ( tier ) {
				tier.addEventListener( 'change', function ( event ) {
					applyCeiling( event.target.form );
				} );
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
