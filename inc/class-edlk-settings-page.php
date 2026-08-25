<?php
/**
 * Renders and handles the EditLock settings screen.
 *
 * @package editlock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the Settings > EditLock admin screen: rendering, saving, and force-release AJAX.
 */
class Edlk_Settings_Page {

	/**
	 * Registers the hooks used by the settings screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_post_edlk_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_edlk_force_release', array( __CLASS__, 'ajax_force_release' ) );
	}

	/**
	 * Registers the Settings > EditLock submenu page.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_options_page(
			__( 'EditLock Settings', 'editlock' ),
			'EditLock',
			'manage_options',
			'edlk-settings',
			array( __CLASS__, 'render_page' )
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'editlock' ) );
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
			wp_send_json_error( __( 'You do not have permission to do this.', 'editlock' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'editlock' ) );
		}

		Edlk_Lock_Manager::force_release( $post_id );
		wp_send_json_success();
	}

	/**
	 * Renders the Settings > EditLock screen.
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
		$active_locks = Edlk_Lock_Manager::get_active_locks();
		$force_nonce  = wp_create_nonce( 'edlk_force_release' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EditLock Settings', 'editlock' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'editlock' ); ?></p></div>
			<?php endif; ?>

			<div style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin:12px 0 20px;">
				<p style="margin:0 0 8px;">
					<?php esc_html_e( 'EditLock reliably blocks other users from saving while someone already has a post open for editing.', 'editlock' ); ?>
					<?php esc_html_e( 'It keeps the standard WordPress "currently editing" notice as-is, and only prevents the actual save conflict.', 'editlock' ); ?>
				</p>
				<p style="margin:0;">
					<?php esc_html_e( 'The block check happens the moment you click the Save, Update, or Publish button.', 'editlock' ); ?>
					<?php esc_html_e( 'Nothing is shown just from opening the edit screen.', 'editlock' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="edlk_save_settings">
				<?php wp_nonce_field( 'edlk_save_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="edlk_ttl_seconds"><?php esc_html_e( 'Lock expiration (seconds)', 'editlock' ); ?></label></th>
						<td>
							<input type="number" id="edlk_ttl_seconds" name="edlk_ttl_seconds" value="<?php echo esc_attr( $ttl ); ?>" min="30" step="10" style="width:100px;">
							<p class="description">
								<?php esc_html_e( 'While the edit screen is open, the lock is automatically extended via Heartbeat.', 'editlock' ); ?>
								<?php esc_html_e( 'If updates stop (for example, the tab is closed), the lock is released automatically after this many seconds.', 'editlock' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types to exclude', 'editlock' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="edlk_excluded_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $excluded, true ) ); ?>>
									<?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Checked post types are excluded from EditLock (all unchecked types are included).', 'editlock' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Moving to trash', 'editlock' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="edlk_guard_trash" name="edlk_guard_trash" value="1" <?php checked( $guard_trash ); ?>>
								<?php esc_html_e( 'Also lock trash actions', 'editlock' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, moving a locked post to trash is blocked no matter who holds the lock (even from the holder\'s own other tab).', 'editlock' ); ?>
								<?php esc_html_e( 'For bulk trash actions from the list screen, if a locked post is included, processing stops at that point and the remaining posts are left unprocessed.', 'editlock' ); ?>
							</p>
							<p class="description" style="color:#b45309;"><?php esc_html_e( 'Note: If the "Move to trash" button in the block editor (Gutenberg) is blocked, WordPress\'s own error handling may briefly show a "Moved to trash" success notice even though the post was not actually moved (the post data is unaffected); reloading the post list will confirm it is still published.', 'editlock' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'editlock' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Currently Locked Posts', 'editlock' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'editlock' ); ?></th>
						<th><?php esc_html_e( 'Editor', 'editlock' ); ?></th>
						<th><?php esc_html_e( 'Locked At', 'editlock' ); ?></th>
						<th><?php esc_html_e( 'Expires At', 'editlock' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="edlk-lock-list">
					<?php if ( empty( $active_locks ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No posts are currently locked.', 'editlock' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $active_locks as $lock ) :
							$post = get_post( $lock['post_id'] );
							$user = get_userdata( $lock['user_id'] );
							?>
							<tr data-post-id="<?php echo esc_attr( $lock['post_id'] ); ?>">
								<td><?php echo $post ? esc_html( $post->post_title ) . ' (ID:' . (int) $lock['post_id'] . ')' : 'ID:' . (int) $lock['post_id']; ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : __( 'Unknown', 'editlock' ) ); ?></td>
								<td><?php echo esc_html( $lock['locked_at'] ); ?></td>
								<td><?php echo esc_html( $lock['expires_at'] ); ?></td>
								<td><button type="button" class="button edlk-force-release" style="color:#d63638;border-color:#d63638;"><?php esc_html_e( 'Force Release', 'editlock' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top:40px;font-size:14px;color:#50575e;">
				<strong><?php esc_html_e( 'For Developers', 'editlock' ); ?></strong><br>
				<?php esc_html_e( 'Save blocking works for any update that goes through wp_insert_post() / wp_update_post(), regardless of the caller (admin screen, front-end form, other plugins, REST API, etc.).', 'editlock' ); ?><br>
				<?php esc_html_e( 'Trash blocking, when "Also lock trash actions" is enabled, works for processing that goes through wp_trash_post(), or DELETE requests to the REST API.', 'editlock' ); ?><br>
				<?php esc_html_e( 'Neither applies to plugins or custom code that writes to the database directly, such as via $wpdb->update().', 'editlock' ); ?><br>
			</p>

			<p style="font-size:14px;color:#50575e;">
				<strong><?php esc_html_e( 'Support', 'editlock' ); ?></strong><br>
				<?php
				printf(
					/* translators: %s: お問い合わせページへのリンク */
					esc_html__( 'For paid support or custom development, please get in touch %s.', 'editlock' ),
					'<a href="https://etbs.jp/product-category/wordpress-tools/?utm_source=editlock&utm_medium=plugin" target="_blank" rel="noopener noreferrer">' . esc_html__( 'via this page', 'editlock' ) . '</a>'
				);
				?>
				<br>
			</p>
		</div>

		<script>
		jQuery( function( $ ) {
			var nonce             = '<?php echo esc_js( $force_nonce ); ?>';
			var confirmMessage    = '<?php echo esc_js( __( 'Force release this lock? The user currently editing may fail to save.', 'editlock' ) ); ?>';
			var releasingText     = '<?php echo esc_js( __( 'Releasing...', 'editlock' ) ); ?>';
			var noLocksText       = '<?php echo esc_js( __( 'No posts are currently locked.', 'editlock' ) ); ?>';
			var releaseFailedText = '<?php echo esc_js( __( 'Failed to release the lock.', 'editlock' ) ); ?>';

			$( '#edlk-lock-list' ).on( 'click', '.edlk-force-release', function() {
				if ( ! confirm( confirmMessage ) ) { return; }
				var $row   = $( this ).closest( 'tr' );
				var postId = $row.data( 'post-id' );

				$( this ).prop( 'disabled', true ).text( releasingText );

				$.post( ajaxurl, {
					action:  'edlk_force_release',
					nonce:   nonce,
					post_id: postId
				}, function( res ) {
					if ( res.success ) {
						$row.remove();
						if ( ! $( '#edlk-lock-list tr' ).length ) {
							$( '#edlk-lock-list' ).html( '<tr><td colspan="5">' + noLocksText + '</td></tr>' );
						}
					} else {
						alert( releaseFailedText );
					}
				} );
			} );
		} );
		</script>
		<?php
	}
}

Edlk_Settings_Page::init();
