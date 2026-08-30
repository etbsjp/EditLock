<?php
/**
 * Renders and handles the Edit Conflict Guard settings screen.
 *
 * @package etbs-edit-conflict-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the Settings > Edit Conflict Guard admin screen: rendering, saving, and force-release AJAX.
 */
class Etbs_Ecg_Settings_Page {

	/**
	 * Hook suffix of the settings screen, used to enqueue assets only on that screen.
	 * 設定画面の hook suffix。この画面でだけアセットを読み込むために保持する。
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Registers the hooks used by the settings screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_edlk_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_edlk_force_release', array( __CLASS__, 'ajax_force_release' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Registers the Settings > Edit Conflict Guard submenu page.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		self::$hook_suffix = add_options_page(
			__( 'Edit Conflict Guard Settings', 'etbs-edit-conflict-guard' ),
			__( 'Edit Conflict Guard', 'etbs-edit-conflict-guard' ),
			'manage_options',
			'edlk-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueues the stylesheet and script used by the settings screen.
	 * 設定画面で使うスタイルシートとスクリプトを読み込む。
	 *
	 * @param string $hook_suffix Current admin screen's hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		// Only on this plugin's settings screen.
		// このプラグインの設定画面でだけ読み込む.
		if ( '' === self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'edlk-settings',
			plugins_url( 'css/edlk-settings.css', __FILE__ ),
			array(),
			ETBS_ECG_VERSION
		);

		wp_enqueue_script(
			'edlk-settings',
			plugins_url( 'js/edlk-settings.js', __FILE__ ),
			array( 'jquery' ),
			ETBS_ECG_VERSION,
			true
		);

		wp_localize_script(
			'edlk-settings',
			'EdlkSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'edlk_force_release' ),
				'i18n'    => array(
					'confirm'       => __( 'Force release this lock? The user currently editing may fail to save.', 'etbs-edit-conflict-guard' ),
					'releasing'     => __( 'Releasing...', 'etbs-edit-conflict-guard' ),
					'noLocks'       => __( 'No posts are currently locked.', 'etbs-edit-conflict-guard' ),
					'releaseFailed' => __( 'Failed to release the lock.', 'etbs-edit-conflict-guard' ),
				),
			)
		);
	}

	/**
	 * Handles the settings form submission.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		check_admin_referer( 'edlk_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'etbs-edit-conflict-guard' ) );
		}

		$ttl = isset( $_POST['edlk_ttl_seconds'] ) ? absint( wp_unslash( $_POST['edlk_ttl_seconds'] ) ) : 120;
		update_option( 'edlk_ttl_seconds', $ttl > 0 ? $ttl : 120 );

		$excluded = isset( $_POST['edlk_excluded_post_types'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['edlk_excluded_post_types'] ) )
			: array();
		update_option( 'edlk_excluded_post_types', array_values( $excluded ) );

		update_option( 'edlk_guard_trash', ! empty( $_POST['edlk_guard_trash'] ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'edlk-settings',
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX handler that force-releases a lock (administrators only).
	 *
	 * @return void
	 */
	public static function ajax_force_release() {
		check_ajax_referer( 'edlk_force_release', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to do this.', 'etbs-edit-conflict-guard' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'etbs-edit-conflict-guard' ) );
		}

		Etbs_Ecg_Lock_Manager::force_release( $post_id );
		wp_send_json_success();
	}

	/**
	 * Renders the Settings > Edit Conflict Guard screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ttl          = edlk_get_ttl();
		$excluded     = edlk_get_excluded_post_types();
		$post_types   = edlk_get_editable_post_types();
		$guard_trash  = edlk_is_trash_guard_enabled();
		$active_locks = Etbs_Ecg_Lock_Manager::get_active_locks();
		?>
		<div class="wrap edlk-settings">
			<h1><?php esc_html_e( 'Edit Conflict Guard Settings', 'etbs-edit-conflict-guard' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'etbs-edit-conflict-guard' ); ?></p></div>
			<?php endif; ?>

			<div class="edlk-intro">
				<p>
					<?php esc_html_e( 'Edit Conflict Guard reliably blocks other users from saving while someone already has a post open for editing.', 'etbs-edit-conflict-guard' ); ?>
					<?php esc_html_e( 'It keeps the standard WordPress "currently editing" notice as-is, and only prevents the actual save conflict.', 'etbs-edit-conflict-guard' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'The block check happens the moment you click the Save, Update, or Publish button.', 'etbs-edit-conflict-guard' ); ?>
					<?php esc_html_e( 'Nothing is shown just from opening the edit screen.', 'etbs-edit-conflict-guard' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="edlk_save_settings">
				<?php wp_nonce_field( 'edlk_save_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="edlk_ttl_seconds"><?php esc_html_e( 'Lock expiration (seconds)', 'etbs-edit-conflict-guard' ); ?></label></th>
						<td>
							<input type="number" id="edlk_ttl_seconds" name="edlk_ttl_seconds" value="<?php echo esc_attr( $ttl ); ?>" min="30" step="10" class="small-text">
							<p class="description">
								<?php esc_html_e( 'While the edit screen is open, the lock is automatically extended via Heartbeat.', 'etbs-edit-conflict-guard' ); ?>
								<?php esc_html_e( 'If updates stop (for example, the tab is closed), the lock is released automatically after this many seconds.', 'etbs-edit-conflict-guard' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types to exclude', 'etbs-edit-conflict-guard' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label class="edlk-checkbox-row">
									<input type="checkbox" name="edlk_excluded_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $excluded, true ) ); ?>>
									<?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Checked post types are excluded from locking (all unchecked types are included).', 'etbs-edit-conflict-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moving to trash', 'etbs-edit-conflict-guard' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="edlk_guard_trash" name="edlk_guard_trash" value="1" <?php checked( $guard_trash ); ?>>
								<?php esc_html_e( 'Also lock trash actions', 'etbs-edit-conflict-guard' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, moving a locked post to trash is blocked no matter who holds the lock (even from the holder\'s own other tab).', 'etbs-edit-conflict-guard' ); ?>
								<?php esc_html_e( 'For bulk trash actions from the list screen, if a locked post is included, processing stops at that point and the remaining posts are left unprocessed.', 'etbs-edit-conflict-guard' ); ?>
							</p>
							<p class="description edlk-note-warning"><?php esc_html_e( 'Note: If the "Move to trash" button in the block editor (Gutenberg) is blocked, WordPress\'s own error handling may briefly show a "Moved to trash" success notice even though the post was not actually moved (the post data is unaffected); reloading the post list will confirm it is still published.', 'etbs-edit-conflict-guard' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'etbs-edit-conflict-guard' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Currently Locked Posts', 'etbs-edit-conflict-guard' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'etbs-edit-conflict-guard' ); ?></th>
						<th><?php esc_html_e( 'Editor', 'etbs-edit-conflict-guard' ); ?></th>
						<th><?php esc_html_e( 'Locked At', 'etbs-edit-conflict-guard' ); ?></th>
						<th><?php esc_html_e( 'Expires At', 'etbs-edit-conflict-guard' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="edlk-lock-list">
					<?php if ( empty( $active_locks ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No posts are currently locked.', 'etbs-edit-conflict-guard' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $active_locks as $lock ) :
							$post = get_post( $lock['post_id'] );
							$user = get_userdata( $lock['user_id'] );
							?>
							<tr data-post-id="<?php echo esc_attr( $lock['post_id'] ); ?>">
								<td><?php echo $post ? esc_html( $post->post_title ) . ' (ID:' . (int) $lock['post_id'] . ')' : 'ID:' . (int) $lock['post_id']; ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : __( 'Unknown', 'etbs-edit-conflict-guard' ) ); ?></td>
								<td><?php echo esc_html( $lock['locked_at'] ); ?></td>
								<td><?php echo esc_html( $lock['expires_at'] ); ?></td>
								<td><button type="button" class="button edlk-force-release"><?php esc_html_e( 'Force Release', 'etbs-edit-conflict-guard' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="edlk-footnote edlk-footnote--spaced">
				<strong><?php esc_html_e( 'For Developers', 'etbs-edit-conflict-guard' ); ?></strong><br>
				<?php esc_html_e( 'Save blocking works for any update that goes through wp_insert_post() / wp_update_post(), regardless of the caller (admin screen, front-end form, other plugins, REST API, etc.).', 'etbs-edit-conflict-guard' ); ?><br>
				<?php esc_html_e( 'Trash blocking, when "Also lock trash actions" is enabled, works for processing that goes through wp_trash_post(), or DELETE requests to the REST API.', 'etbs-edit-conflict-guard' ); ?><br>
				<?php esc_html_e( 'Neither applies to plugins or custom code that writes to the database directly, such as via $wpdb->update().', 'etbs-edit-conflict-guard' ); ?><br>
			</p>

			<p class="edlk-footnote">
				<strong><?php esc_html_e( 'Support', 'etbs-edit-conflict-guard' ); ?></strong><br>
				<?php
				printf(
					/* translators: %s: お問い合わせページへのリンク */
					esc_html__( 'For paid support or custom development, please get in touch %s.', 'etbs-edit-conflict-guard' ),
					'<a href="https://etbs.jp/product-category/wordpress-tools/?utm_source=etbs-edit-conflict-guard&utm_medium=plugin" target="_blank" rel="noopener noreferrer">' . esc_html__( 'via this page', 'etbs-edit-conflict-guard' ) . '</a>'
				);
				?>
				<br>
			</p>
		</div>

		<?php
	}
}

Etbs_Ecg_Settings_Page::init();
