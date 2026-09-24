/**
 * Marginal Schema Markup – live-validering af JSON-LD-felter.
 * Ingen afhængigheder (hverken jQuery eller WordPress-pakker), så det ikke kan
 * gå i stykker når WordPress opdateres.
 */
( function () {
	'use strict';

	function extractJson( text ) {
		// Samme logik som på serveren: tillad indsatte <script>-blokke.
		if ( ! /^\s*<script/i.test( text ) ) {
			return text;
		}
		var re = /<script\b[^>]*>([\s\S]*?)<\/script\s*>/gi;
		var parts = [];
		var m;
		while ( ( m = re.exec( text ) ) !== null ) {
			if ( m[ 1 ].trim() !== '' ) {
				parts.push( m[ 1 ].trim() );
			}
		}
		if ( parts.length === 0 ) {
			return text;
		}
		return parts.length === 1 ? parts[ 0 ] : '[' + parts.join( ',' ) + ']';
	}

	function validate( textarea, status ) {
		var value = textarea.value.trim();
		status.className = 'marginal-schema-status';

		if ( value === '' ) {
			status.textContent = 'Tomt – der udskrives intet.';
			return null;
		}

		try {
			var data = JSON.parse( extractJson( value ) );
			if ( data === null || typeof data !== 'object' ) {
				throw new Error( 'JSON-LD skal være et objekt { ... } eller en liste [ ... ].' );
			}
			var missing = ! Array.isArray( data ) && ! data[ '@context' ];
			status.textContent = missing ? 'Gyldig JSON (bemærk: "@context" mangler).' : 'Gyldig JSON.';
			status.classList.add( missing ? 'is-warning' : 'is-valid' );
			return data;
		} catch ( e ) {
			status.textContent = 'Ugyldig JSON: ' + e.message;
			status.classList.add( 'is-invalid' );
			return null;
		}
	}

	function setup( textarea ) {
		var status = document.querySelector( '.marginal-schema-status[data-for="' + textarea.id + '"]' );
		var button = document.querySelector( '.marginal-schema-format[data-target="' + textarea.id + '"]' );
		if ( ! status ) {
			return;
		}

		var timer;
		textarea.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () {
				validate( textarea, status );
			}, 300 );
		} );

		if ( button ) {
			button.addEventListener( 'click', function () {
				var data = validate( textarea, status );
				if ( data !== null ) {
					textarea.value = JSON.stringify( data, null, '\t' );
					textarea.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		}

		validate( textarea, status );
	}

	function init() {
		var fields = document.querySelectorAll( 'textarea.marginal-schema-json' );
		for ( var i = 0; i < fields.length; i++ ) {
			setup( fields[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
