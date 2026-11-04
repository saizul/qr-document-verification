<?php
/**
 * Admin: meta box, columns, asset loading, PDF handling, bulk import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QDV_Admin {

	public static function init() {
		// Use the classic editor for our CPT so the file <input> + enctype work reliably.
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . QDV_POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );

		add_filter( 'manage_' . QDV_POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . QDV_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function disable_block_editor( $use, $post_type ) {
		return ( QDV_POST_TYPE === $post_type ) ? false : $use;
	}

	public static function form_enctype() {
		$screen = get_current_screen();
		if ( $screen && QDV_POST_TYPE === $screen->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	/* ----------------------------------------------------------------------
	 * Assets
	 * -------------------------------------------------------------------- */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading the current admin screen slug to decide whether to enqueue assets.
		$is_ours = ( QDV_POST_TYPE === $screen->post_type ) || ( 'qdv-bulk' === $page );
		if ( ! $is_ours ) {
			return;
		}

		wp_enqueue_script( 'qdv-qrcode', QDV_URL . 'assets/qrcode.min.js', array(), '1.0.0', true );
		wp_enqueue_script( 'qdv-admin', QDV_URL . 'assets/admin.js', array( 'qdv-qrcode' ), QDV_VERSION, true );
		wp_enqueue_style( 'qdv-admin', QDV_URL . 'assets/admin.css', array(), QDV_VERSION );
	}

	/* ----------------------------------------------------------------------
	 * Meta box
	 * -------------------------------------------------------------------- */
	public static function meta_box() {
		add_meta_box(
			'qdv_details',
			__( 'PDF Verification', 'qr-document-verification' ),
			array( __CLASS__, 'meta_box_html' ),
			QDV_POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function meta_box_html( $post ) {
		wp_nonce_field( 'qdv_save', 'qdv_nonce' );

		$idn      = get_post_meta( $post->ID, '_qdv_id_number', true );
		$token    = get_post_meta( $post->ID, '_qdv_token', true );
		$file     = get_post_meta( $post->ID, '_qdv_pdf_file', true );
		$orig     = get_post_meta( $post->ID, '_qdv_pdf_original', true );
		$opts     = qdv_get_options();
		$label_id = $opts['label_id'];
		?>
		<style>.qdv-mb td{padding:10px 12px;vertical-align:top}.qdv-mb th{text-align:left;padding:10px 12px;width:150px;vertical-align:top}</style>
		<table class="qdv-mb widefat" style="border:0">
			<tr>
				<th><label for="qdv_id_number"><?php echo esc_html( $label_id ); ?> <span style="color:#d63638">*</span></label></th>
				<td><input type="text" id="qdv_id_number" name="qdv_id_number" class="regular-text" value="<?php echo esc_attr( $idn ); ?>" required>
				<?php /* translators: %s: the ID field label, e.g. "ID Number". */ ?>
				<p class="description"><?php printf( esc_html__( 'The name goes in the "Title" field above. Enter the %s here.', 'qr-document-verification' ), esc_html( $label_id ) ); ?></p></td>
			</tr>
			<tr>
				<th><label for="qdv_pdf"><?php esc_html_e( 'PDF File', 'qr-document-verification' ); ?></label></th>
				<td>
					<input type="file" id="qdv_pdf" name="qdv_pdf" accept="application/pdf,.pdf">
					<?php if ( $file ) : ?>
						<?php /* translators: %s: the uploaded file name. */ ?>
						<p class="description" style="color:#1a7f43">&#10003; <?php echo esc_html( sprintf( __( 'Uploaded: %s', 'qr-document-verification' ), $orig ? $orig : $file ) ); ?> &mdash; <?php esc_html_e( 'choose a file to replace it.', 'qr-document-verification' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Optional now. You can add the PDF later (Step 2) once the QR is placed into it.', 'qr-document-verification' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $token ) :
				$url = qdv_verify_url( $token );
				?>
			<tr>
				<th><?php esc_html_e( 'Verification Link', 'qr-document-verification' ); ?></th>
				<td>
					<input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $url ); ?>">
					<p class="description"><?php esc_html_e( 'Secret link. Only reachable by scanning the QR or holding this exact URL.', 'qr-document-verification' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'QR Code', 'qr-document-verification' ); ?></th>
				<td>
					<div class="qdv-qr" data-url="<?php echo esc_attr( $url ); ?>" style="width:180px;height:180px"></div>
					<p><button type="button" class="button qdv-download" data-target="#qdv-qr-single" data-name="<?php echo esc_attr( 'qr-' . ( $idn ? $idn : $token ) ); ?>"><?php esc_html_e( 'Download QR (PNG)', 'qr-document-verification' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'Download this QR and place it into your PDF design, then upload the finished PDF above.', 'qr-document-verification' ); ?></p>
				</td>
			</tr>
			<?php else : ?>
			<tr><td colspan="2"><p class="description"><?php esc_html_e( 'Save this entry to generate the secret link and QR code.', 'qr-document-verification' ); ?></p></td></tr>
			<?php endif; ?>
		</table>
		<script>
		// Give the single meta-box QR box a stable id for the download button.
		(function(){var b=document.querySelector('#qdv_details .qdv-qr');if(b)b.id='qdv-qr-single';})();
		</script>
		<?php
	}

	/* ----------------------------------------------------------------------
	 * Save
	 * -------------------------------------------------------------------- */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['qdv_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qdv_nonce'] ) ), 'qdv_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( isset( $_POST['qdv_id_number'] ) ) {
			update_post_meta( $post_id, '_qdv_id_number', sanitize_text_field( wp_unslash( $_POST['qdv_id_number'] ) ) );
		}

		// Always guarantee a token exists.
		QDV_CPT::ensure_token( $post_id );

		// Handle an uploaded PDF, if any. ($_FILES is validated by clean_file() + wp_handle_upload().)
		if ( isset( $_FILES['qdv_pdf'] ) && ! empty( $_FILES['qdv_pdf']['name'] ) ) {
			$result = self::store_pdf( $post_id, self::clean_file( wp_unslash( $_FILES['qdv_pdf'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in clean_file() and validated by wp_handle_upload().
			if ( is_wp_error( $result ) ) {
				set_transient( 'qdv_notice_' . get_current_user_id(), array( 'type' => 'error', 'msg' => $result->get_error_message() ), 60 );
			}
		}
	}

	/**
	 * Normalize one $_FILES entry into a clean array for wp_handle_upload().
	 */
	public static function clean_file( $file ) {
		return array(
			'name'     => isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '',
			'type'     => isset( $file['type'] ) ? sanitize_text_field( $file['type'] ) : '',
			'tmp_name' => isset( $file['tmp_name'] ) ? $file['tmp_name'] : '',
			'error'    => isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
		);
	}

	/**
	 * Validate an uploaded PDF (via WordPress core) and store it in the
	 * protected folder as {token}.pdf.
	 *
	 * @param int   $post_id Entry ID.
	 * @param array $file    A $_FILES-style array (name, type, tmp_name, error, size).
	 * @return true|WP_Error
	 */
	public static function store_pdf( $post_id, $file ) {
		if ( empty( $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			/* translators: %d: PHP file-upload error code. */
			return new WP_Error( 'upload', sprintf( __( 'PDF upload failed (server error code %d).', 'qr-document-verification' ), (int) $file['error'] ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'pdf' => 'application/pdf' ),
			)
		);

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			$msg = ( is_array( $moved ) && isset( $moved['error'] ) ) ? $moved['error'] : __( 'The file is not a valid PDF.', 'qr-document-verification' );
			return new WP_Error( 'type', $msg );
		}
		if ( 'application/pdf' !== $moved['type'] ) {
			wp_delete_file( $moved['file'] );
			return new WP_Error( 'type', __( 'Only PDF files are allowed.', 'qr-document-verification' ) );
		}

		$token = QDV_CPT::ensure_token( $post_id );
		$dir   = qdv_protected_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$dest = trailingslashit( $dir ) . $token . '.pdf';

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}
		if ( $wp_filesystem->exists( $dest ) ) {
			$wp_filesystem->delete( $dest );
		}
		if ( ! $wp_filesystem->move( $moved['file'], $dest, true ) ) {
			wp_delete_file( $moved['file'] );
			return new WP_Error( 'move', __( 'Could not save the PDF to the server.', 'qr-document-verification' ) );
		}
		$wp_filesystem->chmod( $dest, 0640 );

		update_post_meta( $post_id, '_qdv_pdf_file', $token . '.pdf' );
		update_post_meta( $post_id, '_qdv_pdf_original', sanitize_file_name( $file['name'] ) );
		return true;
	}

	/* ----------------------------------------------------------------------
	 * List table columns
	 * -------------------------------------------------------------------- */
	public static function columns( $cols ) {
		$new = array();
		$new['cb']    = isset( $cols['cb'] ) ? $cols['cb'] : '';
		$new['title'] = __( 'Name', 'qr-document-verification' );
		$opts            = qdv_get_options();
		$new['qdv_id']   = $opts['label_id'];
		$new['qdv_pdf']  = __( 'PDF', 'qr-document-verification' );
		$new['qdv_link'] = __( 'Verification Link', 'qr-document-verification' );
		$new['qdv_qr']   = __( 'QR', 'qr-document-verification' );
		$new['date']  = isset( $cols['date'] ) ? $cols['date'] : __( 'Date', 'qr-document-verification' );
		return $new;
	}

	public static function column_content( $col, $post_id ) {
		switch ( $col ) {
			case 'qdv_id':
				echo esc_html( get_post_meta( $post_id, '_qdv_id_number', true ) );
				break;

			case 'qdv_pdf':
				$file = get_post_meta( $post_id, '_qdv_pdf_file', true );
				if ( $file ) {
					echo '<span style="color:#1a7f43;font-weight:600">&#10003; ' . esc_html__( 'Attached', 'qr-document-verification' ) . '</span>';
				} else {
					echo '<span style="color:#b26a00;font-weight:600">&#9679; ' . esc_html__( 'Pending', 'qr-document-verification' ) . '</span>';
				}
				break;

			case 'qdv_link':
				$token = get_post_meta( $post_id, '_qdv_token', true );
				if ( $token ) {
					$url = qdv_verify_url( $token );
					echo '<input type="text" readonly onclick="this.select()" value="' . esc_attr( $url ) . '" style="width:100%;max-width:280px;font-size:11px">';
				}
				break;

			case 'qdv_qr':
				$token = get_post_meta( $post_id, '_qdv_token', true );
				$idn   = get_post_meta( $post_id, '_qdv_id_number', true );
				if ( $token ) {
					$url = qdv_verify_url( $token );
					$bid = 'qdv-qr-' . $post_id;
					echo '<div id="' . esc_attr( $bid ) . '" class="qdv-qr" data-url="' . esc_attr( $url ) . '" style="width:96px;height:96px"></div>';
					echo '<button type="button" class="button button-small qdv-download" data-target="#' . esc_attr( $bid ) . '" data-name="' . esc_attr( 'qr-' . ( $idn ? $idn : $token ) ) . '">' . esc_html__( 'PNG', 'qr-document-verification' ) . '</button>';
				}
				break;
		}
	}

	/* ----------------------------------------------------------------------
	 * Bulk import page
	 * -------------------------------------------------------------------- */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . QDV_POST_TYPE,
			__( 'Bulk Import', 'qr-document-verification' ),
			__( 'Bulk Import', 'qr-document-verification' ),
			'edit_posts',
			'qdv-bulk',
			array( __CLASS__, 'bulk_page' )
		);
	}

	public static function bulk_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'qr-document-verification' ) );
		}

		$report = array();
		$action = isset( $_POST['qdv_action'] ) ? sanitize_key( wp_unslash( $_POST['qdv_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below before any processing.

		// Step 1: create entries from CSV.
		if ( 'create' === $action && check_admin_referer( 'qdv_bulk', 'qdv_bulk_nonce' ) ) {
			$report = self::handle_csv_create();
		}

		// Step 2: attach PDFs by matching filename to ID number.
		if ( 'attach' === $action && check_admin_referer( 'qdv_bulk', 'qdv_bulk_nonce' ) ) {
			$report = self::handle_bulk_attach();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Import', 'qr-document-verification' ); ?></h1>

			<?php if ( $report ) : ?>
				<div class="notice notice-info"><p><strong><?php esc_html_e( 'Result', 'qr-document-verification' ); ?>:</strong></p>
				<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( $report as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<div style="display:flex;gap:24px;flex-wrap:wrap;max-width:1100px">

				<div class="card" style="flex:1;min-width:340px;padding:16px">
					<h2><?php esc_html_e( 'Step 1 · Create entries (Name + ID number)', 'qr-document-verification' ); ?></h2>
					<p class="description"><?php esc_html_e( 'One entry per line as: Name , ID number. A header row "name,id" is skipped automatically. Each entry gets a secret link + QR you can download from the list.', 'qr-document-verification' ); ?></p>
					<form method="post">
						<?php wp_nonce_field( 'qdv_bulk', 'qdv_bulk_nonce' ); ?>
						<input type="hidden" name="qdv_action" value="create">
						<p><textarea name="qdv_csv_text" rows="8" class="large-text code" placeholder="Jane Doe, 12345&#10;John Smith, 12346"></textarea></p>
						<p><?php submit_button( __( 'Create Entries', 'qr-document-verification' ), 'primary', 'submit', false ); ?></p>
					</form>
				</div>

				<div class="card" style="flex:1;min-width:340px;padding:16px">
					<h2><?php esc_html_e( 'Step 2 · Attach PDFs', 'qr-document-verification' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Select many PDF files at once. Each file is matched to an entry by its filename = ID number. Example: 12345.pdf attaches to the entry with that permit number.', 'qr-document-verification' ); ?></p>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'qdv_bulk', 'qdv_bulk_nonce' ); ?>
						<input type="hidden" name="qdv_action" value="attach">
						<p><input type="file" name="qdv_pdfs[]" accept="application/pdf,.pdf" multiple></p>
						<p><?php submit_button( __( 'Upload & Attach', 'qr-document-verification' ), 'primary', 'submit', false ); ?></p>
					</form>
				</div>

			</div>
		</div>
		<?php
	}

	private static function handle_csv_create() {
		if ( ! isset( $_POST['qdv_bulk_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qdv_bulk_nonce'] ) ), 'qdv_bulk' ) ) {
			return array();
		}

		$raw     = isset( $_POST['qdv_csv_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qdv_csv_text'] ) ) : '';
		$lines   = preg_split( '/\r\n|\r|\n/', $raw );
		$created = 0;
		$skipped = 0;
		$report  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$cols = str_getcsv( $line );
			$name = isset( $cols[0] ) ? sanitize_text_field( trim( $cols[0] ) ) : '';
			$idn  = isset( $cols[1] ) ? sanitize_text_field( trim( $cols[1] ) ) : '';

			// Skip a header row.
			if ( 0 === strcasecmp( $name, 'name' ) && 0 === strcasecmp( $idn, 'id' ) ) {
				continue;
			}
			if ( '' === $name || '' === $idn ) {
				/* translators: %s: the skipped input line. */
				$report[] = sprintf( __( 'Skipped invalid line: %s', 'qr-document-verification' ), $line );
				$skipped++;
				continue;
			}
			if ( qdv_find_post_by_idnumber( $idn ) ) {
				/* translators: %s: the duplicate ID number. */
				$report[] = sprintf( __( 'Skipped duplicate ID number: %s', 'qr-document-verification' ), $idn );
				$skipped++;
				continue;
			}

			$post_id = wp_insert_post( array(
				'post_type'   => QDV_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			), true );

			if ( is_wp_error( $post_id ) ) {
				/* translators: %s: the entry name. */
				$report[] = sprintf( __( 'Failed to create: %s', 'qr-document-verification' ), $name );
				continue;
			}
			update_post_meta( $post_id, '_qdv_id_number', $idn );
			QDV_CPT::ensure_token( $post_id );
			$created++;
		}

		/* translators: 1: number of entries created, 2: number skipped. */
		array_unshift( $report, sprintf( __( '%1$d entries created, %2$d skipped.', 'qr-document-verification' ), $created, $skipped ) );
		return $report;
	}

	private static function handle_bulk_attach() {
		if ( ! isset( $_POST['qdv_bulk_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qdv_bulk_nonce'] ) ), 'qdv_bulk' ) ) {
			return array();
		}

		$report    = array();
		$matched   = 0;
		$unmatched = 0;

		if ( empty( $_FILES['qdv_pdfs']['name'] ) || ! is_array( $_FILES['qdv_pdfs']['name'] ) ) {
			return array( __( 'No files were uploaded.', 'qr-document-verification' ) );
		}

		// $_FILES entries are validated per-file by clean_file() + wp_handle_upload() below.
		$files = wp_unslash( $_FILES['qdv_pdfs'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in clean_file() and validated by wp_handle_upload().
		$count = count( $files['name'] );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] || '' === $files['name'][ $i ] ) {
				continue;
			}
			$orig = sanitize_file_name( $files['name'][ $i ] );
			$base = pathinfo( $orig, PATHINFO_FILENAME );
			$idn  = sanitize_text_field( trim( $base ) );

			$post_id = qdv_find_post_by_idnumber( $idn );
			if ( ! $post_id ) {
				/* translators: 1: ID number, 2: file name. */
				$report[] = sprintf( __( 'No entry for ID number "%1$s" (file %2$s) — skipped.', 'qr-document-verification' ), $idn, $orig );
				$unmatched++;
				continue;
			}

			$res = self::store_pdf( $post_id, self::clean_file( array(
				'name'     => $files['name'][ $i ],
				'type'     => isset( $files['type'][ $i ] ) ? $files['type'][ $i ] : '',
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => isset( $files['size'][ $i ] ) ? $files['size'][ $i ] : 0,
			) ) );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: ID number, 2: error message. */
				$report[] = sprintf( __( 'ID %1$s: %2$s', 'qr-document-verification' ), $idn, $res->get_error_message() );
				continue;
			}
			$matched++;
		}

		/* translators: 1: number of PDFs attached, 2: number unmatched. */
		array_unshift( $report, sprintf( __( '%1$d PDFs attached, %2$d unmatched.', 'qr-document-verification' ), $matched, $unmatched ) );
		return $report;
	}

	/* ----------------------------------------------------------------------
	 * Notices (e.g. bad PDF on single save)
	 * -------------------------------------------------------------------- */
	public static function notices() {
		$key = 'qdv_notice_' . get_current_user_id();
		$n = get_transient( $key );
		if ( $n && ! empty( $n['msg'] ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $n['type'] ? 'error' : 'info' ),
				esc_html( $n['msg'] )
			);
		}
	}
}
