/* PDF Verify QR — PDF display.
 * Desktop: the server-rendered <iframe> (native viewer) is used as-is; no JS needed.
 * Mobile:  replace the iframe with inline PDF.js rendering (loaded on demand),
 *          so it never shows a blank/grey box, and reveal the Download button.
 */
( function () {
	var box = document.getElementById( 'qdv-pdf' );
	if ( ! box ) {
		return;
	}

	var src       = box.getAttribute( 'data-src' );
	var pdfjsUrl  = box.getAttribute( 'data-pdfjs' );
	var workerUrl = box.getAttribute( 'data-worker' );
	var fallback  = box.getAttribute( 'data-fallback' ) || 'View PDF';

	function errLink() {
		box.innerHTML = '<p class="qdv-loading"><a href="' + src + '">' + fallback + '</a></p>';
	}

	function renderPdfjs() {
		if ( typeof pdfjsLib === 'undefined' ) {
			errLink();
			return;
		}
		try {
			pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;
		} catch ( e ) {}

		var dpr = window.devicePixelRatio || 1;

		pdfjsLib.getDocument( src ).promise.then( function ( pdf ) {
			box.innerHTML = '';
			var width = box.clientWidth || box.offsetWidth || 600;
			var chain = Promise.resolve();
			for ( var i = 1; i <= pdf.numPages; i++ ) {
				( function ( n ) {
					chain = chain.then( function () {
						return pdf.getPage( n ).then( function ( page ) {
							var base = page.getViewport( { scale: 1 } );
							var cssScale = width / base.width;
							var vp = page.getViewport( { scale: cssScale * dpr } );
							var canvas = document.createElement( 'canvas' );
							canvas.className = 'qdv-page';
							canvas.width = Math.floor( vp.width );
							canvas.height = Math.floor( vp.height );
							canvas.style.width = '100%';
							canvas.style.height = 'auto';
							box.appendChild( canvas );
							return page.render( {
								canvasContext: canvas.getContext( '2d' ),
								viewport: vp
							} ).promise;
						} );
					} );
				} )( i );
			}
			return chain;
		} ).catch( errLink );
	}

	function loadPdfjsThenRender() {
		if ( typeof pdfjsLib !== 'undefined' ) {
			renderPdfjs();
			return;
		}
		var s = document.createElement( 'script' );
		s.src = pdfjsUrl;
		s.onload = renderPdfjs;
		s.onerror = errLink;
		document.head.appendChild( s );
	}

	function start() {
		var isMobile = window.matchMedia( '(max-width: 767px)' ).matches ||
			/Mobi|Android|iPhone|iPod|iPad/i.test( navigator.userAgent || '' );

		if ( ! isMobile ) {
			return; // Desktop: keep the native <iframe> already in the HTML.
		}

		var dl = document.getElementById( 'qdv-dl' );
		if ( dl ) {
			dl.style.display = '';
		}
		box.innerHTML = '<div class="qdv-loading">Loading document…</div>';
		loadPdfjsThenRender();
	}

	if ( document.readyState !== 'loading' ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
} )();
