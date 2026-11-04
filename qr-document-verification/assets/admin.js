/* PDF Verify QR - admin QR rendering + PNG download */
( function () {
	function renderAll() {
		if ( typeof QRCode === 'undefined' ) {
			return;
		}
		var boxes = document.querySelectorAll( '.qdv-qr[data-url]' );
		boxes.forEach( function ( box ) {
			if ( box.getAttribute( 'data-done' ) ) {
				return;
			}
			box.setAttribute( 'data-done', '1' );
			box.innerHTML = '';
			var size = box.clientWidth || 180;
			try {
				new QRCode( box, {
					text: box.getAttribute( 'data-url' ),
					width: size,
					height: size,
					correctLevel: QRCode.CorrectLevel.M
				} );
			} catch ( e ) {}
		} );

		var buttons = document.querySelectorAll( '.qdv-download' );
		buttons.forEach( function ( btn ) {
			if ( btn.getAttribute( 'data-wired' ) ) {
				return;
			}
			btn.setAttribute( 'data-wired', '1' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var box = document.querySelector( btn.getAttribute( 'data-target' ) );
				if ( ! box ) {
					return;
				}
				var img = box.querySelector( 'img' );
				var canvas = box.querySelector( 'canvas' );
				var data = null;
				if ( img && img.src ) {
					data = img.src;
				} else if ( canvas ) {
					data = canvas.toDataURL( 'image/png' );
				}
				if ( ! data ) {
					return;
				}
				var a = document.createElement( 'a' );
				a.href = data;
				a.download = ( btn.getAttribute( 'data-name' ) || 'qr' ) + '.png';
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
			} );
		} );
	}

	if ( document.readyState !== 'loading' ) {
		renderAll();
	} else {
		document.addEventListener( 'DOMContentLoaded', renderAll );
	}
} )();
