/**
 * Poll admin: add, move, and remove questions and choices, and pick pictures and files from the Media Library.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'tcmha-survey-builder' );
	if ( ! root ) {
		return;
	}
	var t = window.tcmhaSurveyAdmin || {};
	var list = root.querySelector( '.tcmha-qs' );
	var live = document.getElementById( 'tcmha-survey-live' );
	var qTemplate = ( document.getElementById( 'tcmha-survey-q-template' ) || {} ).innerHTML || '';
	var oTemplate = ( document.getElementById( 'tcmha-survey-o-template' ) || {} ).innerHTML || '';

	function uid( prefix ) {
		return prefix + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function fromHTML( html ) {
		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html.trim();
		return tpl.content.firstElementChild;
	}

	function say( text ) {
		if ( ! live ) {
			return;
		}
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = text;
		}, 50 );
	}

	function questions() {
		return Array.prototype.slice.call( list.querySelectorAll( ':scope > .tcmha-q' ) );
	}

	function renumber() {
		questions().forEach( function ( q, i ) {
			q.querySelector( '.tcmha-q__num' ).textContent = i + 1;
		} );
	}

	function syncType( q ) {
		var type = q.querySelector( '.tcmha-q__type' ).value;
		q.querySelectorAll( '[data-show-for]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-show-for' ).split( ' ' ).indexOf( type ) === -1;
		} );
	}

	function addOption( q, focus ) {
		var html = oTemplate.split( '__Q__' ).join( q.getAttribute( 'data-q' ) ).split( '__O__' ).join( uid( 'o' ) );
		var el = fromHTML( html );
		q.querySelector( '.tcmha-opts' ).appendChild( el );
		if ( focus ) {
			el.querySelector( 'input[type="text"]' ).focus();
		}
		return el;
	}

	function addQuestion() {
		var el = fromHTML( qTemplate.split( '__Q__' ).join( uid( 'q' ) ) );
		list.appendChild( el );
		addOption( el );
		addOption( el );
		syncType( el );
		renumber();
		el.querySelector( '.tcmha-q__text' ).focus();
		say( t.added || 'Added.' );
	}

	function move( item, selector, up ) {
		var sibling = up ? item.previousElementSibling : item.nextElementSibling;
		while ( sibling && ! sibling.matches( selector ) ) {
			sibling = up ? sibling.previousElementSibling : sibling.nextElementSibling;
		}
		if ( ! sibling ) {
			return false;
		}
		if ( up ) {
			sibling.before( item );
		} else {
			sibling.after( item );
		}
		return true;
	}

	// Media Library pickers.
	function pick( box ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var kind = box.getAttribute( 'data-kind' );
		var options = {
			title: kind === 'image' ? t.pickImage : ( kind === 'files' ? t.pickFiles : t.pickFile ),
			button: { text: t.use || 'Use this' },
			multiple: kind === 'files',
		};
		if ( kind === 'image' ) {
			options.library = { type: 'image' };
		}
		var frame = window.wp.media( options );
		frame.on( 'open', function () {
			var current = box.querySelector( '.tcmha-media__id' );
			if ( current && current.value ) {
				var attachment = window.wp.media.attachment( current.value );
				attachment.fetch();
				frame.state().get( 'selection' ).add( attachment );
			}
		} );
		frame.on( 'select', function () {
			var chosen = frame.state().get( 'selection' ).toJSON();
			if ( kind === 'files' ) {
				chosen.forEach( function ( file ) {
					addFileRow( box, file );
				} );
				return;
			}
			if ( chosen[ 0 ] ) {
				setPicked( box, chosen[ 0 ] );
			}
		} );
		frame.open();
	}

	function setPicked( box, att ) {
		var kind = box.getAttribute( 'data-kind' );
		var preview = box.querySelector( '.tcmha-media__preview' );
		box.querySelector( '.tcmha-media__id' ).value = att.id;
		preview.textContent = '';
		if ( kind === 'image' ) {
			var img = document.createElement( 'img' );
			img.alt = '';
			img.src = ( att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url );
			preview.appendChild( img );
			if ( ! att.alt ) {
				var warn = document.createElement( 'span' );
				warn.className = 'tcmha-media__warn';
				warn.textContent = t.noAlt || '';
				preview.appendChild( warn );
			}
		} else {
			var name = document.createElement( 'span' );
			name.className = 'tcmha-media__name';
			name.textContent = att.filename || att.title || '';
			preview.appendChild( name );
		}
		box.querySelector( '.tcmha-media__choose-text' ).textContent = kind === 'image' ? t.changeImage : t.changeFile;
		box.querySelector( '.tcmha-media__clear' ).hidden = false;
	}

	function clearPicked( box ) {
		var kind = box.getAttribute( 'data-kind' );
		box.querySelector( '.tcmha-media__id' ).value = '';
		box.querySelector( '.tcmha-media__preview' ).textContent = '';
		box.querySelector( '.tcmha-media__choose-text' ).textContent = kind === 'image' ? t.addImage : t.addFile;
		box.querySelector( '.tcmha-media__clear' ).hidden = true;
		box.querySelector( '.tcmha-media__choose' ).focus();
		say( t.removed || 'Removed.' );
	}

	function addFileRow( box, file ) {
		var ul = box.querySelector( '.tcmha-files__list' );
		var exists = ul.querySelector( 'input[value="' + String( parseInt( file.id, 10 ) ) + '"]' );
		if ( exists ) {
			return;
		}
		var li = document.createElement( 'li' );
		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = box.getAttribute( 'data-name' );
		input.value = file.id;
		var name = document.createElement( 'span' );
		name.className = 'tcmha-media__name';
		name.textContent = file.filename || file.title || '';
		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link tcmha-files__remove';
		remove.textContent = t.remove || 'Remove';
		var sr = document.createElement( 'span' );
		sr.className = 'screen-reader-text';
		sr.textContent = ': ' + name.textContent;
		remove.appendChild( sr );
		li.appendChild( input );
		li.appendChild( name );
		li.appendChild( document.createTextNode( ' ' ) );
		li.appendChild( remove );
		ul.appendChild( li );
	}

	root.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn || ! root.contains( btn ) ) {
			return;
		}
		var q = btn.closest( '.tcmha-q' );
		var o = btn.closest( '.tcmha-opt' );
		var c = btn.classList;

		if ( c.contains( 'tcmha-q-add' ) ) {
			addQuestion();
		} else if ( c.contains( 'tcmha-q-up' ) || c.contains( 'tcmha-q-down' ) ) {
			var up = c.contains( 'tcmha-q-up' );
			if ( move( q, '.tcmha-q', up ) ) {
				renumber();
				btn.focus();
				say( ( up ? t.movedUp : t.movedDown ) + ' ' + q.querySelector( '.tcmha-q__title' ).textContent.trim() );
			}
		} else if ( c.contains( 'tcmha-q-remove' ) ) {
			if ( ! window.confirm( t.removeQuestion || 'Remove this question?' ) ) {
				return;
			}
			var next = q.nextElementSibling || q.previousElementSibling;
			q.remove();
			renumber();
			( next ? next.querySelector( '.tcmha-q__text' ) : root.querySelector( '.tcmha-q-add' ) ).focus();
			say( t.removed || 'Removed.' );
		} else if ( c.contains( 'tcmha-o-add' ) ) {
			addOption( q, true );
			say( t.added || 'Added.' );
		} else if ( c.contains( 'tcmha-o-up' ) || c.contains( 'tcmha-o-down' ) ) {
			var oUp = c.contains( 'tcmha-o-up' );
			if ( move( o, '.tcmha-opt', oUp ) ) {
				btn.focus();
				say( oUp ? t.movedUp : t.movedDown );
			}
		} else if ( c.contains( 'tcmha-o-remove' ) ) {
			var nextO = o.nextElementSibling || o.previousElementSibling;
			o.remove();
			( nextO ? nextO.querySelector( 'input[type="text"]' ) : q.querySelector( '.tcmha-o-add' ) ).focus();
			say( t.removed || 'Removed.' );
		} else if ( c.contains( 'tcmha-media__choose' ) ) {
			pick( btn.closest( '.tcmha-media' ) );
		} else if ( c.contains( 'tcmha-media__clear' ) ) {
			clearPicked( btn.closest( '.tcmha-media' ) );
		} else if ( c.contains( 'tcmha-files__remove' ) ) {
			var box = btn.closest( '.tcmha-media' );
			btn.closest( 'li' ).remove();
			box.querySelector( '.tcmha-media__choose' ).focus();
			say( t.removed || 'Removed.' );
		}
	} );

	root.addEventListener( 'change', function ( e ) {
		if ( e.target.classList.contains( 'tcmha-q__type' ) ) {
			syncType( e.target.closest( '.tcmha-q' ) );
		}
	} );

	questions().forEach( syncType );
}() );
