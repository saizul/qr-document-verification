<?php
/**
 * Admin settings: URL slug + full customization of the verification page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QDV_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . QDV_POST_TYPE,
			__( 'Settings', 'qr-document-verification' ),
			__( 'Settings', 'qr-document-verification' ),
			'manage_options',
			'qdv-settings',
			array( __CLASS__, 'page' )
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'qr-document-verification' ) );
		}

		if ( isset( $_POST['qdv_save'] ) && check_admin_referer( 'qdv_settings', 'qdv_nonce' ) ) {
			self::save();
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'qr-document-verification' ) . '</p></div>';
		}

		$o       = qdv_get_options();
		$slug    = qdv_get_slug();
		$example = qdv_verify_url( 'a1b2c3d4e5f6a1b2c3d4e5f6' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'QR Document Verification — Settings', 'qr-document-verification' ); ?></h1>
			<form method="post">
				<?php wp_nonce_field( 'qdv_settings', 'qdv_nonce' ); ?>

				<h2><?php esc_html_e( 'Verification URL', 'qr-document-verification' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="qdv_slug"><?php esc_html_e( 'URL slug', 'qr-document-verification' ); ?></label></th>
						<td>
							<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
							<input type="text" id="qdv_slug" name="qdv_slug" class="regular-text" value="<?php echo esc_attr( $slug ); ?>">
							<code>/{code}/</code>
							<p class="description"><?php esc_html_e( 'The path after your domain for verification links. You can also include a language prefix, e.g. "en/verify".', 'qr-document-verification' ); ?></p>
							<p><strong><?php esc_html_e( 'Example link', 'qr-document-verification' ); ?>:</strong> <code><?php echo esc_html( $example ); ?></code></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Header', 'qr-document-verification' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="header_title"><?php esc_html_e( 'Header title', 'qr-document-verification' ); ?></label></th>
						<td><input type="text" id="header_title" name="header_title" class="regular-text" value="<?php echo esc_attr( $o['header_title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Header colors', 'qr-document-verification' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Gradient from', 'qr-document-verification' ); ?> <input type="color" id="header_bg" name="header_bg" value="<?php echo esc_attr( $o['header_bg'] ); ?>"></label>
							&nbsp; <label><?php esc_html_e( 'to', 'qr-document-verification' ); ?> <input type="color" id="header_bg2" name="header_bg2" value="<?php echo esc_attr( $o['header_bg2'] ); ?>"></label>
							&nbsp; <label><?php esc_html_e( 'Text', 'qr-document-verification' ); ?> <input type="color" id="header_color" name="header_color" value="<?php echo esc_attr( $o['header_color'] ); ?>"></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Verified badge', 'qr-document-verification' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show badge', 'qr-document-verification' ); ?></th>
						<td><label><input type="checkbox" id="show_badge" name="show_badge" value="1" <?php checked( $o['show_badge'], 1 ); ?>> <?php esc_html_e( 'Show the verified badge', 'qr-document-verification' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="badge_text"><?php esc_html_e( 'Badge text', 'qr-document-verification' ); ?></label></th>
						<td>
							<input type="text" id="badge_text" name="badge_text" class="regular-text" value="<?php echo esc_attr( $o['badge_text'] ); ?>">
							&nbsp; <label><?php esc_html_e( 'Bg', 'qr-document-verification' ); ?> <input type="color" name="badge_bg" value="<?php echo esc_attr( $o['badge_bg'] ); ?>"></label>
							&nbsp; <label><?php esc_html_e( 'Text', 'qr-document-verification' ); ?> <input type="color" id="badge_color" name="badge_color" value="<?php echo esc_attr( $o['badge_color'] ); ?>"></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Fields', 'qr-document-verification' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Name field', 'qr-document-verification' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_name" value="1" <?php checked( $o['show_name'], 1 ); ?>> <?php esc_html_e( 'Show', 'qr-document-verification' ); ?></label>
							&nbsp; <?php esc_html_e( 'Label', 'qr-document-verification' ); ?> <input type="text" name="label_name" value="<?php echo esc_attr( $o['label_name'] ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'ID field', 'qr-document-verification' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_id" value="1" <?php checked( $o['show_id'], 1 ); ?>> <?php esc_html_e( 'Show', 'qr-document-verification' ); ?></label>
							&nbsp; <?php esc_html_e( 'Label', 'qr-document-verification' ); ?> <input type="text" name="label_id" value="<?php echo esc_attr( $o['label_id'] ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'This label is also used on the entry edit screen and the list column.', 'qr-document-verification' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Document', 'qr-document-verification' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Download link', 'qr-document-verification' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_download" value="1" <?php checked( $o['show_download'], 1 ); ?>> <?php esc_html_e( 'Show the open / download link under the PDF', 'qr-document-verification' ); ?></label>
							<br><br><?php esc_html_e( 'Link text', 'qr-document-verification' ); ?> <input type="text" name="download_text" value="<?php echo esc_attr( $o['download_text'] ); ?>" class="regular-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="notfound_text"><?php esc_html_e( 'Not-found text', 'qr-document-verification' ); ?></label></th>
						<td><input type="text" id="notfound_text" name="notfound_text" class="regular-text" value="<?php echo esc_attr( $o['notfound_text'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Live preview', 'qr-document-verification' ); ?></h2>
				<div id="qdv-prev" style="max-width:560px;border-radius:14px;overflow:hidden;border:1px solid #e6e9f2;box-shadow:0 8px 24px rgba(20,30,60,.08)">
					<div id="qdv-prev-band" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;color:#fff">
						<span id="qdv-prev-title" style="font-weight:700"></span>
						<span id="qdv-prev-badge" style="display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:13px;border-radius:999px;padding:6px 13px"></span>
					</div>
					<div style="background:#fff;padding:12px 20px">
						<div style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #eef0f6"><span id="qdv-prev-ln" style="color:#6b7280;font-size:12px;font-weight:700;text-transform:uppercase"></span><strong>Jane Doe</strong></div>
						<div style="display:flex;justify-content:space-between;padding:12px 0"><span id="qdv-prev-li" style="color:#6b7280;font-size:12px;font-weight:700;text-transform:uppercase"></span><strong>12345</strong></div>
					</div>
				</div>

				<p style="margin-top:18px"><button type="submit" name="qdv_save" class="button button-primary"><?php esc_html_e( 'Save Settings', 'qr-document-verification' ); ?></button></p>
			</form>
			<p style="margin-top:26px;color:#777">
				<?php
				/* translators: %s: the developer website URL. */
				$qdv_credit = sprintf( __( 'Developed by <a href="%s" target="_blank" rel="noopener">Saizul Amin</a>', 'qr-document-verification' ), 'https://www.saizul.com' );
				echo wp_kses( $qdv_credit, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) );
				?>
			</p>
		</div>

		<script>
		(function(){
			function v(n){var e=document.querySelector('[name="'+n+'"]');return e?(e.type==='checkbox'?e.checked:e.value):'';}
			function upd(){
				var band=document.getElementById('qdv-prev-band');
				band.style.background='linear-gradient(135deg,'+v('header_bg')+' 0%,'+v('header_bg2')+' 100%)';
				band.style.color=v('header_color');
				document.getElementById('qdv-prev-title').textContent=v('header_title')||'Document Verification';
				var b=document.getElementById('qdv-prev-badge');
				if(v('show_badge')){b.style.display='inline-flex';b.textContent='✓ '+(v('badge_text')||'Verified');b.style.background=v('badge_bg');b.style.color=v('badge_color');}else{b.style.display='none';}
				var ln=document.getElementById('qdv-prev-ln').parentNode;
				ln.style.display=v('show_name')?'flex':'none';
				document.getElementById('qdv-prev-ln').textContent=v('label_name')||'Name';
				var li=document.getElementById('qdv-prev-li').parentNode;
				li.style.display=v('show_id')?'flex':'none';
				document.getElementById('qdv-prev-li').textContent=v('label_id')||'ID Number';
			}
			document.addEventListener('input',upd);document.addEventListener('change',upd);upd();
		})();
		</script>
		<?php
	}

	private static function save() {
		if ( ! isset( $_POST['qdv_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qdv_nonce'] ) ), 'qdv_settings' ) ) {
			return;
		}

		// URL slug (flush rewrite if it changed).
		$new_slug = qdv_sanitize_slug( sanitize_text_field( wp_unslash( $_POST['qdv_slug'] ?? '' ) ) );
		if ( $new_slug !== qdv_get_slug() ) {
			update_option( 'qdv_slug', $new_slug );
			QDV_Frontend::add_rewrite();
			flush_rewrite_rules();
		}

		$o = array(
			'header_title'  => sanitize_text_field( wp_unslash( $_POST['header_title'] ?? '' ) ),
			'header_bg'     => sanitize_hex_color( wp_unslash( $_POST['header_bg'] ?? '' ) ) ?: '#242a7d',
			'header_bg2'    => sanitize_hex_color( wp_unslash( $_POST['header_bg2'] ?? '' ) ) ?: '#1a1f66',
			'header_color'  => sanitize_hex_color( wp_unslash( $_POST['header_color'] ?? '' ) ) ?: '#ffffff',
			'show_badge'    => isset( $_POST['show_badge'] ) ? 1 : 0,
			'badge_text'    => sanitize_text_field( wp_unslash( $_POST['badge_text'] ?? '' ) ),
			'badge_bg'      => sanitize_hex_color( wp_unslash( $_POST['badge_bg'] ?? '' ) ) ?: '#e9f9ef',
			'badge_color'   => sanitize_hex_color( wp_unslash( $_POST['badge_color'] ?? '' ) ) ?: '#137a3e',
			'show_name'     => isset( $_POST['show_name'] ) ? 1 : 0,
			'label_name'    => sanitize_text_field( wp_unslash( $_POST['label_name'] ?? '' ) ),
			'show_id'       => isset( $_POST['show_id'] ) ? 1 : 0,
			'label_id'      => sanitize_text_field( wp_unslash( $_POST['label_id'] ?? '' ) ),
			'show_download' => isset( $_POST['show_download'] ) ? 1 : 0,
			'download_text' => sanitize_text_field( wp_unslash( $_POST['download_text'] ?? '' ) ),
			'notfound_text' => sanitize_text_field( wp_unslash( $_POST['notfound_text'] ?? '' ) ),
		);
		update_option( 'qdv_options', $o );
	}
}
