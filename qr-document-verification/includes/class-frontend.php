<?php
/**
 * Front-end: pretty URLs route to a REAL WordPress page that contains the
 * [qdv_viewer] shortcode. This way the active theme + Elementor header/footer
 * render natively on every device (no virtual-page hacks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QDV_Frontend {

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_stream_pdf' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical' ) );
		add_filter( 'the_title', array( __CLASS__, 'hide_page_title' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_shortcode( 'qdv_viewer', array( __CLASS__, 'viewer_shortcode' ) );
	}

	/**
	 * /{slug}/{token}/       -> the real viewer page (page_id) with the token
	 * /{slug}/{token}/pdf/   -> the raw PDF stream
	 * "verify" stays as a legacy alias.
	 */
	public static function add_rewrite() {
		$slug = qdv_get_slug();
		self::register_slug( $slug );
		if ( 'verify' !== $slug ) {
			self::register_slug( 'verify' );
		}
	}

	private static function register_slug( $slug ) {
		add_rewrite_rule(
			'^' . $slug . '/([^/]+)/pdf/?$',
			'index.php?qdv_token=$matches[1]&qdv_pdf=1',
			'top'
		);

		$page_id = (int) get_option( 'qdv_page_id' );
		if ( $page_id ) {
			add_rewrite_rule(
				'^' . $slug . '/([^/]+)/?$',
				'index.php?page_id=' . $page_id . '&qdv_token=$matches[1]',
				'top'
			);
		} else {
			add_rewrite_rule(
				'^' . $slug . '/([^/]+)/?$',
				'index.php?qdv_token=$matches[1]',
				'top'
			);
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'qdv_token';
		$vars[] = 'qdv_pdf';
		return $vars;
	}

	/**
	 * Keep our pretty URL — don't let WordPress redirect page_id to the page's
	 * own permalink.
	 */
	public static function no_canonical( $redirect ) {
		if ( get_query_var( 'qdv_token' ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Hide the theme's page title on the viewer page (front-end only), so the
	 * only heading shown is the one inside our verification card. The page
	 * still keeps its name in the admin.
	 */
	public static function hide_page_title( $title, $post_id = 0 ) {
		if ( is_admin() ) {
			return $title;
		}
		$pid = (int) get_option( 'qdv_page_id' );
		if ( $pid && (int) $post_id === $pid && is_page( $pid ) && is_main_query() ) {
			return '';
		}
		return $title;
	}

	/**
	 * Stream the raw PDF (separate, theme-less endpoint).
	 */
	public static function maybe_stream_pdf() {
		if ( ! get_query_var( 'qdv_pdf' ) ) {
			return;
		}
		$token   = sanitize_key( get_query_var( 'qdv_token' ) );
		$post_id = $token ? qdv_find_post_by_token( $token ) : 0;
		if ( ! $post_id ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}
		// Revoked (and, if configured, expired) documents are withheld.
		$state = qdv_entry_status( $post_id );
		$o     = qdv_get_options();
		if ( ! QDV_Status::pdf_allowed( $state['status'], ! empty( $o['hide_expired_pdf'] ) ) ) {
			status_header( QDV_Status::withheld_http_code( $state['status'] ) );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
			exit;
		}
		self::stream_pdf( $post_id );
	}

	private static function stream_pdf( $post_id ) {
		$file = get_post_meta( $post_id, '_qdv_pdf_file', true );
		$path = trailingslashit( qdv_protected_dir() ) . basename( (string) $file );

		if ( empty( $file ) || ! is_readable( $path ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$idn = get_post_meta( $post_id, '_qdv_id_number', true );
		$dl_name = ( $idn ? sanitize_file_name( $idn ) : 'document' ) . '.pdf';
		// This is a public endpoint reached via a secret token in the URL, not a
		// form submission, so no nonce applies.
		$disposition = isset( $_GET['qdv_dl'] ) ? 'attachment' : 'inline'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $dl_name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ob_get_level() ) {
			ob_end_clean();
		}
		// readfile() streams the file with low memory use; WP_Filesystem has no
		// streaming equivalent for serving a file to the browser.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * [qdv_viewer] — the content of the viewer page.
	 */
	public static function viewer_shortcode() {
		$token = sanitize_key( get_query_var( 'qdv_token' ) );

		ob_start();
		echo '<div class="qdv-wrap">';

		$post_id = $token ? qdv_find_post_by_token( $token ) : 0;
		if ( ! $post_id ) {
			self::not_found_card();
		} else {
			self::viewer_card( $post_id, $token );
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function not_found_card() {
		$o = qdv_get_options();
		echo '<div class="qdv-card">';
		echo '<div class="qdv-band qdv-band-bad">';
		echo '<div class="qdv-brand"><span class="qdv-shield" aria-hidden="true">&#128737;</span><span>' . esc_html( $o['header_title'] ) . '</span></div>';
		echo '<div class="qdv-status qdv-status-bad"><span class="qdv-check" aria-hidden="true">&#10007;</span>' . esc_html__( 'Not Verified', 'qr-document-verification' ) . '</div>';
		echo '</div>';
		echo '<div class="qdv-notfound">';
		echo '<p class="qdv-nf-title">' . esc_html( $o['notfound_text'] ) . '</p>';
		echo '<p class="qdv-muted">' . esc_html__( 'This link is invalid or the document does not exist.', 'qr-document-verification' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	private static function viewer_card( $post_id, $token ) {
		$o       = qdv_get_options();
		$state   = qdv_entry_status( $post_id );
		$expires = qdv_format_date( $state['expires'] );
		$file    = get_post_meta( $post_id, '_qdv_pdf_file', true );
		$pdf_url = qdv_verify_url( $token, true );
		$name    = get_the_title( $post_id );
		$idn     = get_post_meta( $post_id, '_qdv_id_number', true );

		// Info card: customizable header band + optional Name / ID fields.
		echo '<div class="qdv-card qdv-is-' . esc_attr( $state['status'] ) . '">';
		if ( QDV_Status::REVOKED === $state['status'] ) {
			echo '<div class="qdv-band qdv-band-bad">';
		} elseif ( QDV_Status::EXPIRED === $state['status'] ) {
			echo '<div class="qdv-band qdv-band-warn">';
		} else {
			echo '<div class="qdv-band">';
		}
		echo '<div class="qdv-brand"><span class="qdv-shield" aria-hidden="true">&#128737;</span><span>' . esc_html( $o['header_title'] ) . '</span></div>';
		// The "Verified" badge can be hidden, but a revoked / expired state is always shown.
		if ( QDV_Status::REVOKED === $state['status'] ) {
			echo '<div class="qdv-status qdv-status-bad"><span class="qdv-check" aria-hidden="true">&#10007;</span>' . esc_html( $o['revoked_text'] ) . '</div>';
		} elseif ( QDV_Status::EXPIRED === $state['status'] ) {
			echo '<div class="qdv-status qdv-status-warn"><span class="qdv-check" aria-hidden="true">!</span>' . esc_html( $o['expired_text'] ) . '</div>';
		} elseif ( ! empty( $o['show_badge'] ) ) {
			echo '<div class="qdv-status"><span class="qdv-check" aria-hidden="true">&#10003;</span>' . esc_html( $o['badge_text'] ) . '</div>';
		}
		echo '</div>'; // band

		self::status_notice( $state );

		$show_expiry = ! empty( $o['show_expiry'] ) && '' !== $expires;
		if ( ( ! empty( $o['show_name'] ) && $name ) || ( ! empty( $o['show_id'] ) && $idn ) || $show_expiry ) {
			echo '<div class="qdv-body">';
			if ( ! empty( $o['show_name'] ) && $name ) {
				echo '<div class="qdv-field"><span class="qdv-label">' . esc_html( $o['label_name'] ) . '</span><span class="qdv-value">' . esc_html( $name ) . '</span></div>';
			}
			if ( ! empty( $o['show_id'] ) && $idn ) {
				echo '<div class="qdv-field"><span class="qdv-label">' . esc_html( $o['label_id'] ) . '</span><span class="qdv-value">' . esc_html( $idn ) . '</span></div>';
			}
			if ( $show_expiry ) {
				echo '<div class="qdv-field"><span class="qdv-label">' . esc_html( $o['label_expiry'] ) . '</span><span class="qdv-value">' . esc_html( $expires ) . '</span></div>';
			}
			echo '</div>';
		}
		echo '</div>'; // info card

		// Document card.
		if ( ! QDV_Status::pdf_allowed( $state['status'], ! empty( $o['hide_expired_pdf'] ) ) ) {
			echo '<div class="qdv-doc qdv-pending"><p>' . esc_html__( 'This document is no longer available.', 'qr-document-verification' ) . '</p></div>';
		} elseif ( ! empty( $file ) ) {
			wp_enqueue_script( 'qdv-pdfview', QDV_URL . 'assets/pdf-view.js', array(), QDV_VERSION, true );

			$pdfjs  = QDV_URL . 'assets/pdf.min.js';
			$worker = QDV_URL . 'assets/pdf.worker.min.js';
			echo '<div class="qdv-doc">';
			// Server-rendered <iframe> => desktop native viewer with no JS dependency.
			// On mobile, pdf-view.js swaps it for inline PDF.js rendering.
			echo '<div class="qdv-pdf" id="qdv-pdf"'
				. ' data-src="' . esc_url( $pdf_url ) . '"'
				. ' data-pdfjs="' . esc_url( $pdfjs ) . '"'
				. ' data-worker="' . esc_url( $worker ) . '"'
				. ' data-fallback="' . esc_attr( $o['download_text'] ) . '">';
			echo '<iframe class="qdv-frame" src="' . esc_url( $pdf_url ) . '" title="' . esc_attr( $o['header_title'] ) . '"></iframe>';
			echo '</div>';
			if ( ! empty( $o['show_download'] ) ) {
				echo '<p class="qdv-open"><a href="' . esc_url( $pdf_url ) . '" target="_blank" rel="noopener">' . esc_html( $o['download_text'] ) . '</a></p>';
			}
			echo '</div>';
		} else {
			echo '<div class="qdv-doc qdv-pending"><p>' . esc_html__( 'The document for this entry has not been uploaded yet.', 'qr-document-verification' ) . '</p></div>';
		}
	}

	/**
	 * Explain a revoked / expired state under the header band.
	 *
	 * @param array $state Output of qdv_entry_status().
	 */
	private static function status_notice( $state ) {
		if ( QDV_Status::REVOKED === $state['status'] ) {
			$when = qdv_format_date( $state['revoked_at'] );
			if ( $when ) {
				/* translators: %s: the revocation date. */
				$msg = sprintf( __( 'This document was revoked on %s and is no longer valid.', 'qr-document-verification' ), $when );
			} else {
				$msg = __( 'This document has been revoked and is no longer valid.', 'qr-document-verification' );
			}
			echo '<div class="qdv-alert qdv-alert-bad" role="alert"><p>' . esc_html( $msg ) . '</p>';
			if ( '' !== $state['reason'] ) {
				echo '<p class="qdv-reason"><strong>' . esc_html__( 'Reason:', 'qr-document-verification' ) . '</strong> ' . esc_html( $state['reason'] ) . '</p>';
			}
			echo '</div>';
		} elseif ( QDV_Status::EXPIRED === $state['status'] ) {
			/* translators: %s: the expiry date. */
			$msg = sprintf( __( 'This document expired on %s. It was issued by this site but is no longer valid.', 'qr-document-verification' ), qdv_format_date( $state['expires'] ) );
			echo '<div class="qdv-alert qdv-alert-warn" role="alert"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * Content styles (scoped to .qdv-*), driven by the customizable colors.
	 */
	public static function styles() {
		$o   = qdv_get_options();
		$bg1 = esc_html( $o['header_bg'] );
		$bg2 = esc_html( $o['header_bg2'] );
		$hc  = esc_html( $o['header_color'] );
		$bbg = esc_html( $o['badge_bg'] );
		$bc  = esc_html( $o['badge_color'] );

		return '
.qdv-wrap{--qdv-ink:#20232b;--qdv-muted:#6b7280;--qdv-line:#eef0f6;
	max-width:920px;margin:26px auto;padding:0 16px 44px;
	font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans Bengali",Arial,sans-serif}
.qdv-card,.qdv-doc{background:#fff;border:1px solid #e6e9f2;border-radius:16px;box-shadow:0 10px 30px rgba(20,30,60,.08);overflow:hidden;margin-bottom:22px}
.qdv-band{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;background:linear-gradient(135deg,' . $bg1 . ' 0%,' . $bg2 . ' 100%);color:' . $hc . ';padding:16px 22px}
.qdv-band-bad{background:linear-gradient(135deg,#7d2424 0%,#661a1a 100%)}
.qdv-band-warn{background:linear-gradient(135deg,#8a5a00 0%,#6b4500 100%)}
.qdv-brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px;letter-spacing:.2px}
.qdv-shield{font-size:20px;line-height:1}
.qdv-status{display:inline-flex;align-items:center;gap:8px;font-weight:800;font-size:14px;background:' . $bbg . ';color:' . $bc . ';border:1px solid rgba(0,0,0,.06);border-radius:999px;padding:7px 15px 7px 8px;box-shadow:0 3px 10px rgba(0,0,0,.16)}
.qdv-check{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:' . $bc . ';color:#fff;font-size:12px;font-weight:900;line-height:1}
.qdv-status-bad{background:#fdecec;color:#c0392b}
.qdv-status-bad .qdv-check{background:#c0392b}
.qdv-status-warn{background:#fff4e0;color:#8a5a00}
.qdv-status-warn .qdv-check{background:#b26a00}
.qdv-alert{padding:14px 22px;border-bottom:1px solid var(--qdv-line);font-size:15px}
.qdv-alert p{margin:0}
.qdv-alert .qdv-reason{margin-top:6px}
.qdv-alert-bad{background:#fdecec;color:#8a1f1f}
.qdv-alert-warn{background:#fff8eb;color:#6b4500}
.qdv-body{padding:4px 22px 8px}
.qdv-field{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:16px 0;border-bottom:1px solid var(--qdv-line)}
.qdv-field:last-child{border-bottom:0}
.qdv-label{color:var(--qdv-muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px}
.qdv-value{color:var(--qdv-ink);font-weight:700;font-size:18px;text-align:right;word-break:break-word}
.qdv-pdf{min-height:640px;background:#eceff5}
.qdv-frame{display:block;width:100%;height:82vh;min-height:640px;border:0;background:#525659}
.qdv-page{display:block;width:100%;height:auto;margin:0 auto 14px;background:#fff;box-shadow:0 4px 14px rgba(26,31,102,.14)}
.qdv-page:last-child{margin-bottom:0}
.qdv-loading{text-align:center;color:var(--qdv-muted);padding:80px 20px;font-size:14px}
.qdv-loading a{color:' . $bg1 . ';font-weight:700;text-decoration:none}
.qdv-open{margin:0;padding:14px;text-align:center;background:#f7f8fc;border-top:1px solid var(--qdv-line)}
.qdv-open a{color:' . $bg1 . ';text-decoration:none;font-weight:700;font-size:16px}
.qdv-pending{padding:44px 20px;text-align:center;color:var(--qdv-muted)}
.qdv-notfound{padding:34px 20px;text-align:center}
.qdv-nf-title{font-size:18px;font-weight:700;color:var(--qdv-ink);margin:0 0 6px}
.qdv-muted{color:var(--qdv-muted);margin:0}
@media(max-width:600px){
	.qdv-wrap{margin:14px auto;padding:0 12px 30px}
	.qdv-band{padding:14px 16px}
	.qdv-brand{font-size:14px}
	.qdv-body{padding:2px 16px 6px}
	.qdv-field{flex-direction:column;align-items:flex-start;gap:5px;padding:13px 0}
	.qdv-value{text-align:left;font-size:17px}
	.qdv-pdf{min-height:auto;padding:8px}
	.qdv-frame{height:70vh;min-height:380px}
}
';
	}

	/**
	 * Register + enqueue the (dynamic) verification-page CSS as inline styles.
	 */
	public static function enqueue_styles() {
		if ( ! get_query_var( 'qdv_token' ) ) {
			return;
		}
		wp_register_style( 'qdv-view', false, array(), QDV_VERSION );
		wp_enqueue_style( 'qdv-view' );
		wp_add_inline_style( 'qdv-view', self::styles() );
	}
}
