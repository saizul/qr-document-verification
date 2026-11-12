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

		// Revoke / reinstate several entries at once from the list table.
		add_filter( 'bulk_actions-edit-' . QDV_POST_TYPE, array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . QDV_POST_TYPE, array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
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
			<?php self::validity_rows( $post ); ?>
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

	/**
	 * Meta box rows for the expiry date and revocation.
	 *
	 * @param WP_Post $post Entry being edited.
	 */
	private static function validity_rows( $post ) {
		$state = qdv_entry_status( $post->ID );
		?>
		<tr>
			<th><label for="qdv_expires"><?php esc_html_e( 'Expiry date', 'qr-document-verification' ); ?></label></th>
			<td>
				<input type="date" id="qdv_expires" name="qdv_expires" value="<?php echo esc_attr( $state['expires'] ); ?>">
				<p class="description"><?php esc_html_e( 'Optional. The document is valid up to and including this day; afterwards the verification page shows it as expired. Leave empty for no expiry.', 'qr-document-verification' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Revocation', 'qr-document-verification' ); ?></th>
			<td>
				<input type="hidden" name="qdv_validity_present" value="1">
				<label><input type="checkbox" id="qdv_revoked" name="qdv_revoked" value="1" <?php checked( QDV_Status::REVOKED, $state['status'] ); ?>> <?php esc_html_e( 'Revoke this document', 'qr-document-verification' ); ?></label>
				<?php if ( '' !== $state['revoked_at'] ) : ?>
					<?php /* translators: %s: the revocation date. */ ?>
					<span class="description">&mdash; <?php echo esc_html( sprintf( __( 'revoked on %s', 'qr-document-verification' ), qdv_format_date( $state['revoked_at'] ) ) ); ?></span>
				<?php endif; ?>
				<p><input type="text" id="qdv_revoke_reason" name="qdv_revoke_reason" class="large-text" maxlength="<?php echo (int) QDV_Status::MAX_REASON_LENGTH; ?>" value="<?php echo esc_attr( $state['reason'] ); ?>" placeholder="<?php esc_attr_e( 'Reason (optional, shown publicly)', 'qr-document-verification' ); ?>"></p>
				<p class="description"><?php esc_html_e( 'A revoked document keeps its link and QR code, but the verification page shows "Revoked" and the PDF is no longer served. Untick to reinstate it.', 'qr-document-verification' ); ?></p>
			</td>
		</tr>
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

		// Expiry date and revocation.
		if ( isset( $_POST['qdv_expires'] ) ) {
			$expires = QDV_Status::sanitize_date( sanitize_text_field( wp_unslash( $_POST['qdv_expires'] ) ) );
			if ( '' === $expires ) {
				delete_post_meta( $post_id, QDV_Status::META_EXPIRES );
			} else {
				update_post_meta( $post_id, QDV_Status::META_EXPIRES, $expires );
			}
		}
		if ( isset( $_POST['qdv_validity_present'] ) ) {
			$reason = isset( $_POST['qdv_revoke_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['qdv_revoke_reason'] ) ) : '';
			qdv_set_revoked( $post_id, ! empty( $_POST['qdv_revoked'] ), $reason );
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
		$new               = array();
		$new['cb']         = isset( $cols['cb'] ) ? $cols['cb'] : '';
		$new['title']      = __( 'Name', 'qr-document-verification' );
		$opts              = qdv_get_options();
		$new['qdv_id']     = $opts['label_id'];
		$new['qdv_pdf']    = __( 'PDF', 'qr-document-verification' );
		$new['qdv_status'] = __( 'Status', 'qr-document-verification' );
		$new['qdv_link']   = __( 'Verification Link', 'qr-document-verification' );
		$new['qdv_qr']     = __( 'QR', 'qr-document-verification' );
		$new['date']       = isset( $cols['date'] ) ? $cols['date'] : __( 'Date', 'qr-document-verification' );
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

			case 'qdv_status':
				self::status_column( $post_id );
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

	/**
	 * Status cell: Valid / Expires soon / Expired / Revoked.
	 *
	 * @param int $post_id Entry ID.
	 */
	private static function status_column( $post_id ) {
		$state   = qdv_entry_status( $post_id );
		$expires = qdv_format_date( $state['expires'] );
		switch ( $state['status'] ) {
			case QDV_Status::REVOKED:
				echo '<span class="qdv-st qdv-st-revoked">&#10007; ' . esc_html__( 'Revoked', 'qr-document-verification' ) . '</span>';
				break;
			case QDV_Status::EXPIRED:
				echo '<span class="qdv-st qdv-st-expired">&#9679; ' . esc_html__( 'Expired', 'qr-document-verification' ) . '</span>';
				break;
			default:
				echo '<span class="qdv-st qdv-st-valid">&#10003; ' . esc_html__( 'Valid', 'qr-document-verification' ) . '</span>';
		}
		if ( '' !== $expires ) {
			/* translators: %s: the expiry date. */
			echo '<br><small>' . esc_html( sprintf( __( 'until %s', 'qr-document-verification' ), $expires ) ) . '</small>';
		}
	}

	/**
	 * Add "Revoke" and "Reinstate" to the list table's bulk actions.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public static function register_bulk_actions( $actions ) {
		$actions['qdv_revoke']    = __( 'Revoke', 'qr-document-verification' );
		$actions['qdv_reinstate'] = __( 'Reinstate', 'qr-document-verification' );
		return $actions;
	}

	/**
	 * Apply a revoke / reinstate bulk action. WordPress core has already checked
	 * the list table nonce before this filter runs.
	 *
	 * @param string $redirect Redirect URL after the action.
	 * @param string $action   Selected bulk action.
	 * @param int[]  $post_ids Selected entries.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( 'qdv_revoke' !== $action && 'qdv_reinstate' !== $action ) {
			return $redirect;
		}
		$done = 0;
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( QDV_POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			qdv_set_revoked( $post_id, 'qdv_revoke' === $action );
			++$done;
		}
		if ( 'qdv_revoke' === $action ) {
			/* translators: %d: number of entries. */
			$msg = sprintf( _n( '%d entry revoked.', '%d entries revoked.', $done, 'qr-document-verification' ), $done );
		} else {
			/* translators: %d: number of entries. */
			$msg = sprintf( _n( '%d entry reinstated.', '%d entries reinstated.', $done, 'qr-document-verification' ), $done );
		}
		set_transient(
			'qdv_notice_' . get_current_user_id(),
			array(
				'type' => 'info',
				'msg'  => $msg,
			),
			60
		);
		return $redirect;
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
		if ( ! self::can_import() ) {
			wp_die( esc_html__( 'Permission denied.', 'qr-document-verification' ) );
		}

		$report  = array();
		$preview = null;
		$action = isset( $_POST['qdv_action'] ) ? sanitize_key( wp_unslash( $_POST['qdv_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified below before any processing.

		// Step 1: create entries from CSV.
		if ( 'create' === $action && check_admin_referer( 'qdv_bulk', 'qdv_bulk_nonce' ) ) {
			$report = self::handle_csv_create();
		}

		// Step 1 (CSV file): validate an uploaded file and show a preview, nothing is created yet.
		if ( 'csv_preview' === $action && check_admin_referer( 'qdv_bulk', 'qdv_bulk_nonce' ) ) {
			$preview = self::handle_csv_preview();
			if ( ! empty( $preview['error'] ) ) {
				$report  = array( $preview['error'] );
				$preview = null;
			}
		}

		// Step 1 (CSV file): the user confirmed the preview.
		if ( 'csv_confirm' === $action && check_admin_referer( 'qdv_bulk', 'qdv_bulk_nonce' ) ) {
			$report = self::handle_csv_confirm();
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

			<?php if ( $preview ) : ?>
				<?php self::render_csv_preview( $preview ); ?>
			<?php endif; ?>

			<div style="display:flex;gap:24px;flex-wrap:wrap;max-width:1100px">

				<div class="card" style="flex:1;min-width:340px;padding:16px">
					<h2><?php esc_html_e( 'Step 1 · Create entries (Name + ID number)', 'qr-document-verification' ); ?></h2>
					<h3><?php esc_html_e( 'From a CSV file', 'qr-document-verification' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Upload a .csv file with two columns: Name and ID number (a header row is optional). You will see a preview with any problems before anything is created.', 'qr-document-verification' ); ?></p>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'qdv_bulk', 'qdv_bulk_nonce' ); ?>
						<input type="hidden" name="qdv_action" value="csv_preview">
						<p><input type="file" name="qdv_csv_file" accept=".csv,.txt,text/csv,text/plain" required></p>
						<p><?php submit_button( __( 'Upload & Preview', 'qr-document-verification' ), 'secondary', 'submit', false ); ?></p>
					</form>
					<hr>
					<h3><?php esc_html_e( 'Or paste a list', 'qr-document-verification' ); ?></h3>
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

	/**
	 * Whether the current user may bulk create entries.
	 */
	public static function can_import() {
		$type = get_post_type_object( QDV_POST_TYPE );
		$cap  = ( $type && ! empty( $type->cap->create_posts ) ) ? $type->cap->create_posts : 'edit_posts';
		return current_user_can( 'edit_posts' ) && current_user_can( $cap );
	}

	/**
	 * Create one published entry with a token. Returns the post ID or WP_Error.
	 */
	private static function create_entry( $name, $idn ) {
		$post_id = wp_insert_post( array(
			'post_type'   => QDV_POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => $name,
		), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_qdv_id_number', $idn );
		QDV_CPT::ensure_token( $post_id );
		return $post_id;
	}

	/**
	 * Read and validate an uploaded CSV, keep the valid rows for 15 minutes and
	 * return what the preview needs. Creates nothing.
	 */
	private static function handle_csv_preview() {
		if ( ! self::can_import() ) {
			return array( 'error' => __( 'Permission denied.', 'qr-document-verification' ) );
		}
		$file = isset( $_FILES['qdv_csv_file'] ) ? self::clean_file( wp_unslash( $_FILES['qdv_csv_file'] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in clean_file(); nonce checked by the caller.
		if ( ! $file || '' === $file['name'] || UPLOAD_ERR_OK !== $file['error'] ) {
			return array( 'error' => __( 'Please choose a CSV file to upload.', 'qr-document-verification' ) );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			return array( 'error' => __( 'Only .csv files are accepted.', 'qr-document-verification' ) );
		}
		if ( $file['size'] <= 0 || $file['size'] > 1024 * 1024 ) {
			return array( 'error' => __( 'The CSV file must be between 1 byte and 1 MB.', 'qr-document-verification' ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array( 'error' => __( 'The upload could not be verified.', 'qr-document-verification' ) );
		}

		$text = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a just-uploaded temp file.
		if ( false === $text ) {
			return array( 'error' => __( 'The CSV file could not be read.', 'qr-document-verification' ) );
		}
		// Spreadsheet exports from older Excel are often Windows-1252, not UTF-8.
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}

		$parsed = QDV_CSV::parse( $text, 'sanitize_text_field' );
		if ( empty( $parsed['rows'] ) ) {
			return array( 'error' => __( 'No data rows were found in the CSV file.', 'qr-document-verification' ) );
		}
		$result = QDV_CSV::validate( $parsed['rows'], 'qdv_find_post_by_idnumber' );

		$valid = array();
		foreach ( $result['rows'] as $row ) {
			if ( $row['valid'] ) {
				$valid[] = array( 'name' => $row['name'], 'id' => $row['id'] );
			}
		}
		$key = '';
		if ( $valid ) {
			$key = strtolower( wp_generate_password( 16, false, false ) );
			set_transient( self::csv_transient_key( $key ), $valid, 15 * MINUTE_IN_SECONDS );
		}

		return array(
			'key'        => $key,
			'filename'   => $file['name'],
			'result'     => $result,
			'had_header' => $parsed['had_header'],
			'truncated'  => $parsed['truncated'],
		);
	}

	/**
	 * Transient name scoped to the current user so a key cannot be replayed by someone else.
	 */
	private static function csv_transient_key( $key ) {
		return 'qdv_csv_' . get_current_user_id() . '_' . sanitize_key( $key );
	}

	private static function render_csv_preview( $preview ) {
		$result  = $preview['result'];
		$limit   = 10;
		$shown   = array_slice( $result['rows'], 0, $limit );
		$invalid = array_values( array_filter( $result['rows'], function ( $r ) {
			return ! $r['valid'];
		} ) );
		?>
		<div class="card" style="max-width:1100px;padding:16px;margin-bottom:24px">
			<h2><?php esc_html_e( 'CSV preview', 'qr-document-verification' ); ?></h2>
			<p>
				<?php
				/* translators: 1: file name, 2: total rows, 3: valid rows, 4: rows with errors. */
				echo esc_html( sprintf( __( '%1$s: %2$d rows read, %3$d valid, %4$d with errors.', 'qr-document-verification' ), $preview['filename'], count( $result['rows'] ), $result['valid'], $result['invalid'] ) );
				?>
			</p>
			<?php if ( $preview['truncated'] ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: row limit. */ __( 'Only the first %d rows were read. Split the file to import the rest.', 'qr-document-verification' ), QDV_CSV::MAX_ROWS ) ); ?></p>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:800px">
				<thead><tr>
					<th><?php esc_html_e( 'Row', 'qr-document-verification' ); ?></th>
					<th><?php esc_html_e( 'Name', 'qr-document-verification' ); ?></th>
					<th><?php esc_html_e( 'ID number', 'qr-document-verification' ); ?></th>
					<th><?php esc_html_e( 'Status', 'qr-document-verification' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $shown as $row ) : ?>
					<tr>
						<td><?php echo (int) $row['line']; ?></td>
						<td><?php echo esc_html( $row['name'] ); ?></td>
						<td><?php echo esc_html( $row['id'] ); ?></td>
						<td><?php echo $row['valid'] ? '<span style="color:#1a7f43">&#10003; ' . esc_html__( 'OK', 'qr-document-verification' ) . '</span>' : '<span style="color:#b32d2e">' . esc_html( implode( ' ', $row['errors'] ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup, dynamic text escaped. ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $result['rows'] ) > $limit ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %d: number of rows shown. */ __( 'Showing the first %d rows.', 'qr-document-verification' ), $limit ) ); ?></p>
			<?php endif; ?>

			<?php if ( $invalid ) : ?>
				<h3><?php esc_html_e( 'Rows that will be skipped', 'qr-document-verification' ); ?></h3>
				<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( array_slice( $invalid, 0, 50 ) as $row ) : ?>
					<li><?php echo esc_html( sprintf( /* translators: 1: row number, 2: error messages. */ __( 'Row %1$d: %2$s', 'qr-document-verification' ), $row['line'], implode( ' ', $row['errors'] ) ) ); ?></li>
				<?php endforeach; ?>
				<?php if ( count( $invalid ) > 50 ) : ?>
					<li><?php echo esc_html( sprintf( /* translators: %d: number of additional rows. */ __( '...and %d more.', 'qr-document-verification' ), count( $invalid ) - 50 ) ); ?></li>
				<?php endif; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $preview['key'] ) : ?>
				<form method="post" style="margin-top:12px">
					<?php wp_nonce_field( 'qdv_bulk', 'qdv_bulk_nonce' ); ?>
					<input type="hidden" name="qdv_action" value="csv_confirm">
					<input type="hidden" name="qdv_csv_key" value="<?php echo esc_attr( $preview['key'] ); ?>">
					<?php submit_button( sprintf( /* translators: %d: number of valid rows. */ __( 'Create %d entries', 'qr-document-verification' ), $result['valid'] ), 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . QDV_POST_TYPE . '&page=qdv-bulk' ) ); ?>"><?php esc_html_e( 'Cancel', 'qr-document-verification' ); ?></a>
				</form>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Nothing can be imported. Fix the file and upload it again.', 'qr-document-verification' ); ?></strong></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Create the entries held from a previewed upload. IDs are re-checked
	 * because the data may have changed since the preview.
	 */
	private static function handle_csv_confirm() {
		if ( ! self::can_import() ) {
			return array( __( 'Permission denied.', 'qr-document-verification' ) );
		}
		$key  = isset( $_POST['qdv_csv_key'] ) ? sanitize_key( wp_unslash( $_POST['qdv_csv_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller via check_admin_referer().
		$rows = ( '' !== $key ) ? get_transient( self::csv_transient_key( $key ) ) : false;
		if ( ! is_array( $rows ) ) {
			return array( __( 'The preview expired. Please upload the file again.', 'qr-document-verification' ) );
		}
		delete_transient( self::csv_transient_key( $key ) );

		$created = 0;
		$skipped = 0;
		$report  = array();
		foreach ( $rows as $row ) {
			if ( qdv_find_post_by_idnumber( $row['id'] ) ) {
				/* translators: %s: the duplicate ID number. */
				$report[] = sprintf( __( 'Skipped duplicate ID number: %s', 'qr-document-verification' ), $row['id'] );
				$skipped++;
				continue;
			}
			$res = self::create_entry( $row['name'], $row['id'] );
			if ( is_wp_error( $res ) ) {
				/* translators: %s: the entry name. */
				$report[] = sprintf( __( 'Failed to create: %s', 'qr-document-verification' ), $row['name'] );
				$skipped++;
				continue;
			}
			$created++;
		}
		/* translators: 1: number of entries created, 2: number skipped. */
		array_unshift( $report, sprintf( __( '%1$d entries created, %2$d skipped.', 'qr-document-verification' ), $created, $skipped ) );
		return $report;
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

			$post_id = self::create_entry( $name, $idn );
			if ( is_wp_error( $post_id ) ) {
				/* translators: %s: the entry name. */
				$report[] = sprintf( __( 'Failed to create: %s', 'qr-document-verification' ), $name );
				continue;
			}
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
