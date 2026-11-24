/**
 * Meeting poll: show each time in the visitor's own time zone when it differs from Colorado,
 * and move focus to a save or error notice so screen reader users hear it.
 */
( function () {
	'use strict';

	var poll = document.getElementById( 'tcmha-poll' );
	if ( ! poll ) {
		return;
	}

	var notice = poll.querySelector( '[data-focus]' );
	if ( notice ) {
		notice.focus();
	}

	var pollZone = poll.getAttribute( 'data-tz' ) || 'America/Denver';
	var localZone;
	try {
		localZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
	} catch ( e ) {
		return;
	}
	if ( ! localZone || localZone === pollZone ) {
		return;
	}

	// Same offset right now and at each slot means nothing to show (e.g. America/Boise).
	var day = new Intl.DateTimeFormat( undefined, { weekday: 'short', month: 'short', day: 'numeric' } );
	var time = new Intl.DateTimeFormat( undefined, { hour: 'numeric', minute: '2-digit' } );
	var zoneName = new Intl.DateTimeFormat( undefined, { timeZoneName: 'short' } );
	var inPollZone = new Intl.DateTimeFormat( 'en-US', { timeZone: pollZone, hour: 'numeric', minute: '2-digit', day: 'numeric' } );
	var inLocal = new Intl.DateTimeFormat( 'en-US', { hour: 'numeric', minute: '2-digit', day: 'numeric' } );

	poll.querySelectorAll( '.tcmha-poll__local[data-start]' ).forEach( function ( el ) {
		var start = new Date( el.getAttribute( 'data-start' ) );
		var end = new Date( el.getAttribute( 'data-end' ) );
		if ( isNaN( start ) || isNaN( end ) || inPollZone.format( start ) === inLocal.format( start ) ) {
			return;
		}
		var zone = ( zoneName.formatToParts( start ).find( function ( p ) {
			return p.type === 'timeZoneName';
		} ) || {} ).value || localZone;

		el.textContent = 'Your time: ' + day.format( start ) + ', ' + time.format( start ) + '–' + time.format( end ) + ' ' + zone;
		el.hidden = false;
	} );
}() );
