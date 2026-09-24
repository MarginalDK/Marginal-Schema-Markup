/**
 * Marginal Schema Markup – live-validering af JSON-LD-felterne.
 * Ingen afhængigheder (hverken jQuery eller WordPress-pakker), så det ikke kan
 * gå i stykker når WordPress opdateres.
 */
( function () {
	'use strict';

	var WHITESPACE = ' \t\n\r\f\u000B';

	function isWhitespace( ch ) {
		return WHITESPACE.indexOf( ch ) !== -1;
	}

	// Find næste "</script>" (evt. med mellemrum før ">") fra offset. Samme logik som på serveren.
	function findClosingTag( text, offset ) {
		while ( offset < text.length ) {
			var end = text.indexOf( '>', offset );
			if ( end === -1 ) {
				return null;
			}
			var pos = end - 1;
			while ( pos >= offset && isWhitespace( text.charAt( pos ) ) ) {
				pos--;
			}
			var start = pos - 7;
			if ( start >= offset && text.substr( start, 8 ).toLowerCase() === '</script' ) {
				return [ start, end + 1 ];
			}
			offset = end + 1;
		}
		return null;
	}

	// Træk JSON ud af indsatte <script>-blokke – i lineær tid (samme logik som
	// Marginal_Schema_Json::strip_script_wrappers()), så browseren ikke går i stå.
	function extractJson( text ) {
		if ( ! /^\s*<script/i.test( text ) ) {
			return text;
		}

		var openTag = /<script/gi;
		var parts = [];
		var found = 0;
		var offset = 0;

		while ( true ) {
			openTag.lastIndex = offset;
			var match = openTag.exec( text );
			if ( match === null ) {
				break;
			}
			var open = match.index;
			// "<scripts" o.l. er ikke et script-tag (der skal være en ordgrænse efter "<script").
			var next = text.charAt( open + 7 );
			if ( next !== '' && /\w/.test( next ) ) {
				offset = open + 7;
				continue;
			}
			var tagEnd = text.indexOf( '>', open );
			if ( tagEnd === -1 ) {
				break;
			}
			var close = findClosingTag( text, tagEnd + 1 );
			if ( close === null ) {
				break;
			}
			found++;
			var inner = text.substring( tagEnd + 1, close[ 0 ] ).trim();
			if ( inner !== '' ) {
				parts.push( inner );
			}
			offset = close[ 1 ];
		}

		if ( found === 0 ) {
			return text;
		}
		if ( parts.length === 0 ) {
			return '';
		}
		return parts.length === 1 ? parts[ 0 ] : '[' + parts.join( ',' ) + ']';
	}

	// Antal bytes som serveren modtager (browseren sender linjeskift som \r\n).
	function submittedBytes( text ) {
		var bytes = typeof TextEncoder === 'function' ? new TextEncoder().encode( text ).length : text.length;
		var newlines = text.match( /\n/g );
		return bytes + ( newlines ? newlines.length : 0 );
	}

	function parseJson( json ) {
		return JSON.parse( json, function ( key, value ) {
			if ( typeof value === 'number' && ! isFinite( value ) ) {
				throw new Error( 'et tal er for stort til at kunne gengives (fx 1e400). Skriv meget store tal som tekst i anførselstegn.' );
			}
			return value;
		} );
	}

	function validate( textarea, status ) {
		var value = textarea.value.trim();
		var maxBytes = parseInt( textarea.getAttribute( 'data-max-bytes' ), 10 ) || 200000;
		status.className = 'marginal-schema-status';

		if ( value === '' ) {
			status.textContent = 'Tomt – der udskrives intet.';
			return null;
		}

		var bytes = submittedBytes( textarea.value );
		if ( bytes > maxBytes ) {
			status.textContent = 'For stor (' + Math.ceil( bytes / 1000 ) + ' KB – maks. ' + Math.floor( maxBytes / 1000 ) + ' KB). Bliver ikke gemt.';
			status.classList.add( 'is-invalid' );
			return null;
		}

		try {
			var data = parseJson( extractJson( value ) );
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
