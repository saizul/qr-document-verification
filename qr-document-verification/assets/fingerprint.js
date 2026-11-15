/* QR Document Verification: "check your copy".
 * Hashes the chosen file with SHA-256 in the browser (Web Crypto) and compares
 * it with the fingerprint of the issued PDF. The file never leaves the device.
 */
( function () {
	var card = document.getElementById( 'qdv-fp' );
	if ( ! card ) {
		return;
	}
	var input  = card.querySelector( '.qdv-fp-file' );
	var result = card.querySelector( '.qdv-fp-result' );
	var expected = ( card.getAttribute( 'data-hash' ) || '' ).toLowerCase();
	if ( ! input || ! result || ! /^[0-9a-f]{64}$/.test( expected ) ) {
		return;
	}

	function show( text, cls ) {
		result.textContent = text;
		result.className = 'qdv-fp-result' + ( cls ? ' ' + cls : '' );
	}

	function toHex( buffer ) {
		var bytes = new Uint8Array( buffer );
		var out = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			out += ( bytes[ i ] < 16 ? '0' : '' ) + bytes[ i ].toString( 16 );
		}
		return out;
	}

	// Constant-time style comparison; both values are public, but this avoids
	// short-circuit surprises and keeps the check symmetric.
	function same( a, b ) {
		if ( a.length !== b.length ) {
			return false;
		}
		var diff = 0;
		for ( var i = 0; i < a.length; i++ ) {
			diff |= a.charCodeAt( i ) ^ b.charCodeAt( i );
		}
		return 0 === diff;
	}

	var subtle = window.crypto && window.crypto.subtle;
	if ( ! subtle || ! window.FileReader ) {
		input.disabled = true;
		show( card.getAttribute( 'data-unsupported' ), '' );
		return;
	}

	input.addEventListener( 'change', function () {
		var file = input.files && input.files[ 0 ];
		if ( ! file ) {
			show( '', '' );
			return;
		}
		show( card.getAttribute( 'data-working' ), '' );

		var reader = new FileReader();
		reader.onerror = function () {
			show( card.getAttribute( 'data-error' ), 'qdv-fp-bad' );
		};
		reader.onload = function () {
			subtle.digest( 'SHA-256', reader.result ).then( function ( digest ) {
				if ( same( toHex( digest ), expected ) ) {
					show( card.getAttribute( 'data-match' ), 'qdv-fp-ok' );
				} else {
					show( card.getAttribute( 'data-mismatch' ), 'qdv-fp-bad' );
				}
			}, function () {
				show( card.getAttribute( 'data-error' ), 'qdv-fp-bad' );
			} );
		};
		reader.readAsArrayBuffer( file );
	} );
}() );
