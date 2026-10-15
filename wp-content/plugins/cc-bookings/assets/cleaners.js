/* Cleaner portal: password strength hint (same rules as the server), the weekly-hours toggle and the login tabs. */
( function () {
	'use strict';

	var COMMON = [ 'password', 'passw0rd', '12345678', 'qwerty', 'letmein', 'welcome', 'iloveyou', 'abc123', '11111111', 'cleaning', 'cleaner', 'admin', 'monkey', 'football', 'sunshine', 'princess' ];

	function rate( value ) {
		if ( ! value ) {
			return [ '', '' ];
		}
		var classes = [ /[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/ ].filter( function ( re ) {
			return re.test( value );
		} ).length;
		var lower = value.toLowerCase();
		if ( value.length < 10 ) {
			return [ 'weak', 'Too short — use at least 10 characters.' ];
		}
		if ( /(.)\1{3,}/.test( value ) || COMMON.some( function ( word ) { return lower.indexOf( word ) !== -1; } ) ) {
			return [ 'weak', 'Too easy to guess.' ];
		}
		if ( value.length < 16 && classes < 3 ) {
			return [ 'ok', 'Nearly — mix in capitals, numbers or symbols, or make it longer.' ];
		}
		return [ 'good', value.length >= 16 || classes === 4 ? 'Strong password.' : 'Good password.' ];
	}

	document.querySelectorAll( '[data-cc-strength]' ).forEach( function ( input ) {
		var out = document.getElementById( input.getAttribute( 'data-cc-strength' ) );
		if ( ! out ) {
			return;
		}
		input.addEventListener( 'input', function () {
			var result = rate( input.value );
			out.className = 'cc-strength' + ( result[ 0 ] ? ' cc-strength--' + result[ 0 ] : '' );
			out.textContent = result[ 1 ];
		} );
	} );

	document.querySelectorAll( '[data-cc-hours]' ).forEach( function ( box ) {
		var week = box.querySelector( '[data-cc-week]' );
		var sync = function () {
			var any = box.querySelector( 'input[name="hours_mode"][value="any"]' );
			if ( week && any ) {
				week.hidden = any.checked;
			}
		};
		box.addEventListener( 'change', sync );
		sync();
	} );

	// Login tabs (ARIA tabs pattern). Without JS the tabs are links to ?method=link and back.
	document.querySelectorAll( '[data-cc-tabs]' ).forEach( function ( box ) {
		var tabs = Array.prototype.slice.call( box.querySelectorAll( '[role="tab"]' ) );
		var select = function ( tab, focus ) {
			tabs.forEach( function ( other ) {
				var on = other === tab;
				var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );
				other.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				other.tabIndex = on ? 0 : -1;
				if ( panel ) {
					panel.hidden = ! on;
				}
			} );
			if ( focus ) {
				tab.focus();
			}
			if ( window.history.replaceState ) {
				window.history.replaceState( null, '', tab.href );
			}
		};
		tabs.forEach( function ( tab, i ) {
			tab.tabIndex = tab.getAttribute( 'aria-selected' ) === 'true' ? 0 : -1;
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				select( tab, false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var to = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[ event.key ];
				if ( to === undefined ) {
					return;
				}
				event.preventDefault();
				select( tabs[ ( to + tabs.length ) % tabs.length ], true );
			} );
		} );
	} );
}() );
