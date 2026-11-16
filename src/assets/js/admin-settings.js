/**
 * ChatScribe settings page: shows fields only while a checkbox is checked.
 *   data-chatscribe-show-when="id" - visible while that checkbox is checked
 */
( function () {
	function sync() {
		document.querySelectorAll( '[data-chatscribe-show-when]' ).forEach( function ( el ) {
			var control = document.getElementById( el.getAttribute( 'data-chatscribe-show-when' ) );

			if ( control ) {
				el.hidden = !control.checked;
			}
		} );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.closest( '.chatscribe-settings' ) && 'checkbox' === event.target.type ) {
			sync();
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', sync );
	} else {
		sync();
	}
} )();
