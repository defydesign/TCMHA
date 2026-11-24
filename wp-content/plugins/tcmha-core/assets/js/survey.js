/**
 * Polls: focus the save or error notice, keep "pick up to N" limits, check required
 * several-choice questions before sending, and show bigger pictures in a pop-up.
 */
( function () {
	'use strict';

	var polls = document.querySelectorAll( '.tcmha-survey' );
	if ( ! polls.length ) {
		return;
	}

	var notice = document.querySelector( '.tcmha-survey [data-focus]' );
	if ( notice ) {
		notice.focus();
	}

	function describe( el, id, on ) {
		var ids = ( el.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( function ( x ) {
			return x && x !== id;
		} );
		if ( on ) {
			ids.push( id );
		}
		el.setAttribute( 'aria-describedby', ids.join( ' ' ) );
	}

	polls.forEach( function ( root ) {
		// "Pick up to N": undo the extra pick and say why.
		root.querySelectorAll( 'fieldset[data-max]' ).forEach( function ( group ) {
			var max = parseInt( group.getAttribute( 'data-max' ), 10 );
			var msg = group.querySelector( '.tcmha-survey__limit' );
			group.addEventListener( 'change', function ( e ) {
				if ( e.target.type !== 'checkbox' || ! msg ) {
					return;
				}
				var count = group.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
				if ( count > max ) {
					e.target.checked = false;
					msg.textContent = 'You can pick up to ' + max + '. Uncheck one to pick a different one.';
				} else {
					msg.textContent = '';
				}
			} );
		} );

		// Required several-choice questions: browsers can't check these, so we do.
		var form = root.querySelector( '.tcmha-survey__form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				var first = null;
				form.querySelectorAll( 'fieldset[data-required-group]' ).forEach( function ( group ) {
					var err = group.querySelector( '[data-js-error]' );
					var ok = !! group.querySelector( 'input:checked' );
					if ( err ) {
						err.hidden = ok;
						describe( group, err.id, ! ok );
					}
					if ( ! ok && ! first ) {
						first = group.querySelector( 'input' );
					}
				} );
				if ( first ) {
					e.preventDefault();
					first.focus();
				}
			} );
			form.addEventListener( 'change', function ( e ) {
				var group = e.target.closest( 'fieldset[data-required-group]' );
				var err = group && group.querySelector( '[data-js-error]' );
				if ( err && ! err.hidden && group.querySelector( 'input:checked' ) ) {
					err.hidden = true;
					describe( group, err.id, false );
				}
			} );
		}
	} );

	// Bigger pictures in a pop-up. Without dialog support, the link opens the picture in a new tab.
	if ( typeof HTMLDialogElement !== 'function' ) {
		return;
	}
	var dialog = null;
	function getDialog() {
		if ( dialog ) {
			return dialog;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'tcmha-poll tcmha-survey__dialog';
		dialog.setAttribute( 'aria-labelledby', 'tcmha-survey-dialog-cap' );
		var form = document.createElement( 'form' );
		form.method = 'dialog';
		var close = document.createElement( 'button' );
		close.className = 'tcmha-poll__button tcmha-poll__button--secondary';
		close.textContent = 'Close';
		form.appendChild( close );
		var img = document.createElement( 'img' );
		var cap = document.createElement( 'p' );
		cap.id = 'tcmha-survey-dialog-cap';
		cap.className = 'tcmha-survey__dialog-cap';
		dialog.appendChild( form );
		dialog.appendChild( img );
		dialog.appendChild( cap );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'close', function () {
			img.removeAttribute( 'src' );
			if ( dialog.opener ) {
				dialog.opener.focus();
			}
		} );
		document.body.appendChild( dialog );
		return dialog;
	}

	document.querySelectorAll( '.tcmha-survey [data-tcmha-zoom]' ).forEach( function ( link ) {
		link.removeAttribute( 'target' );
		var newTab = link.querySelector( '[data-newtab]' );
		if ( newTab ) {
			newTab.remove();
		}
		link.setAttribute( 'aria-haspopup', 'dialog' );
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var d = getDialog();
			d.opener = link;
			var img = d.querySelector( 'img' );
			img.src = link.href;
			img.alt = link.getAttribute( 'data-alt' ) || '';
			d.querySelector( '.tcmha-survey__dialog-cap' ).textContent = link.getAttribute( 'data-caption' ) || '';
			d.showModal();
			d.querySelector( 'button' ).focus();
		} );
	} );
}() );
