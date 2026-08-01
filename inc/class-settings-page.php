<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Edlk_Settings_Page {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_post_edlk_save_settings', [ __CLASS__, 'handle_save_settings' ] );
		add_action( 'wp_ajax_edlk_force_release', [ __CLASS__, 'ajax_force_release' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'add_dashboard_widget' ] );
	}

	public static function add_menu_page() {
		add_options_page(
			__( 'EditLock 設定', 'editlock' ),
			'EditLock',
			'manage_options',
			'edlk-settings',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		wp_add_dashboard_widget(
			'edlk_dashboard_widget',
			'EditLock',
			[ __CLASS__, 'render_dashboard_widget' ]
		);
	}

	public static function render_dashboard_widget() {
		$settings_url = admin_url( 'options-general.php?page=edlk-settings' );
		?>
		<p>投稿を先に開いて編集している人がいる間、他のユーザーの保存を確実にブロックします。WordPress標準の「編集中です」表示は残したまま、実際の保存競合だけを防ぎます。</p>

		<strong>サポート</strong>
		<p style="margin:6px 0 12px;">有償サポートやカスタマイズは<a href="https://etbs.jp/product-category/wordpress-tools/?utm_source=editlock&utm_medium=plugin" target="_blank" rel="noopener">こちらのページ</a>からお問い合わせください。開発の継続は<a href="https://etbs.jp/product/donate/?utm_source=editlock&utm_medium=plugin" target="_blank" rel="noopener">ご支援</a>で応援いただけます。</p>

		<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">EditLock設定を開く</a>
		<?php
	}

	public static function handle_save_settings() {
		check_admin_referer( 'edlk_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( '権限がありません', 'editlock' ) );
		}

		$ttl = (int) ( $_POST['edlk_ttl_seconds'] ?? 120 );
		update_option( 'edlk_ttl_seconds', $ttl > 0 ? $ttl : 120 );

		$excluded = array_map( 'sanitize_key', (array) ( $_POST['edlk_excluded_post_types'] ?? [] ) );
		update_option( 'edlk_excluded_post_types', array_values( $excluded ) );

		update_option( 'edlk_guard_trash', ! empty( $_POST['edlk_guard_trash'] ) );

		wp_safe_redirect( add_query_arg( [ 'page' => 'edlk-settings', 'updated' => '1' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function ajax_force_release() {
		check_ajax_referer( 'edlk_force_release', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '権限がありません', 'editlock' ) );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( __( '投稿IDが不正です', 'editlock' ) );
		}

		Edlk_Lock_Manager::force_release( $post_id );
		wp_send_json_success();
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$ttl              = edlk_get_ttl();
		$excluded         = edlk_get_excluded_post_types();
		$post_types       = edlk_get_editable_post_types();
		$guard_trash      = edlk_is_trash_guard_enabled();
		$active_locks     = Edlk_Lock_Manager::get_active_locks();
		$force_nonce      = wp_create_nonce( 'edlk_force_release' );
		?>
		<div class="wrap">
			<h1>EditLock 設定</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
			<?php endif; ?>

			<div style="background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;margin:12px 0 20px;">
				<p style="margin:0 0 8px;">EditLockは、投稿を先に開いて編集している人がいる間、他のユーザーの保存を確実にブロックします。WordPress標準の「編集中です」表示は残したまま、実際の保存競合だけを防ぎます。</p>
				<p style="margin:0;">ブロックの判定は「保存」「更新」「公開」ボタンをクリックした瞬間に行われます。編集画面を開いただけの状態では何も表示されません。</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="edlk_save_settings">
				<?php wp_nonce_field( 'edlk_save_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="edlk_ttl_seconds">ロックの有効期限（秒）</label></th>
						<td>
							<input type="number" id="edlk_ttl_seconds" name="edlk_ttl_seconds" value="<?php echo esc_attr( $ttl ); ?>" min="30" step="10" style="width:100px;">
							<p class="description">編集画面を開いている間はHeartbeatで自動延長されます。タブを閉じる等で更新が止まると、この秒数後にロックが自然に解除されます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">対象から除外する投稿タイプ</th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="edlk_excluded_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $excluded, true ) ); ?>>
									<?php echo esc_html( $pt->label ); ?>（<?php echo esc_html( $pt->name ); ?>）
								</label>
							<?php endforeach; ?>
							<p class="description">チェックした投稿タイプはEditLockの対象外になります（未チェックのタイプは全て対象）。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">ゴミ箱への移動</th>
						<td>
							<label>
								<input type="checkbox" id="edlk_guard_trash" name="edlk_guard_trash" value="1" <?php checked( $guard_trash ); ?>>
								ゴミ箱への移動もロック対象にする
							</label>
							<p class="description">オンにすると、ロック中の投稿は保持者が誰であれ（本人の別タブでも）ゴミ箱への移動がブロックされます。一覧画面での複数選択による一括ゴミ箱移動は、対象にロック中の投稿が含まれていると、その時点で処理が中断され後続の投稿が未処理のまま残る場合があります。</p>
							<p class="description" style="color:#b45309;">注意: ブロック編集画面（Gutenberg）内の「ゴミ箱へ移動」ボタンでブロックされた場合、実際には移動していない（投稿データは無事）にもかかわらず、画面上には「ゴミ箱へ移動しました」という成功通知が一瞬表示されることがあります（WordPress側のエラーハンドリングの挙動によるもので、投稿一覧を再読み込みすると公開状態のままであることが確認できます）。</p>
						</td>
					</tr>
				</table>

				<?php submit_button( '設定を保存' ); ?>
			</form>

			<h2>現在ロック中の投稿</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>投稿</th>
						<th>編集者</th>
						<th>ロック開始</th>
						<th>期限</th>
						<th></th>
					</tr>
				</thead>
				<tbody id="edlk-lock-list">
					<?php if ( empty( $active_locks ) ) : ?>
						<tr><td colspan="5">現在ロック中の投稿はありません。</td></tr>
					<?php else : ?>
						<?php foreach ( $active_locks as $lock ) :
							$post = get_post( $lock['post_id'] );
							$user = get_userdata( $lock['user_id'] );
							?>
							<tr data-post-id="<?php echo esc_attr( $lock['post_id'] ); ?>">
								<td><?php echo $post ? esc_html( $post->post_title ) . '（ID:' . (int) $lock['post_id'] . '）' : 'ID:' . (int) $lock['post_id']; ?></td>
								<td><?php echo esc_html( $user ? $user->display_name : '不明' ); ?></td>
								<td><?php echo esc_html( $lock['locked_at'] ); ?></td>
								<td><?php echo esc_html( $lock['expires_at'] ); ?></td>
								<td><button type="button" class="button edlk-force-release" style="color:#d63638;border-color:#d63638;">強制解除</button></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top:40px;font-size:14px;color:#50575e;">
				<strong>開発者向け</strong><br>
				保存ブロックは wp_insert_post() / wp_update_post() を経由する更新であれば、呼び出し元（管理画面・フロント側フォーム・他プラグイン・REST API等）を問わず機能します。<br>
				ゴミ箱ブロックは「ゴミ箱への移動もロック対象にする」が有効な場合のみ、wp_trash_post() を経由する処理、またはREST APIのDELETEリクエストに対して機能します。<br>
				$wpdb->update() 等でDBを直接書き換えるプラグイン/自作コードの場合は、いずれも機能しません。<br>
			</p>

			<p style="font-size:14px;color:#50575e;">
				<strong>サポート</strong><br>
				有償サポートやカスタマイズをご希望の方は、<a href="https://etbs.jp/product-category/wordpress-tools/" target="_blank" rel="noopener">こちらのページ</a>からお問い合わせください。<br>
			</p>
		</div>

		<script>
		jQuery( function( $ ) {
			var nonce = '<?php echo esc_js( $force_nonce ); ?>';

			$( '#edlk-lock-list' ).on( 'click', '.edlk-force-release', function() {
				if ( ! confirm( 'このロックを強制解除しますか？編集中のユーザーの保存が失敗する可能性があります。' ) ) { return; }
				var $row   = $( this ).closest( 'tr' );
				var postId = $row.data( 'post-id' );

				$( this ).prop( 'disabled', true ).text( '解除中...' );

				$.post( ajaxurl, {
					action:  'edlk_force_release',
					nonce:   nonce,
					post_id: postId
				}, function( res ) {
					if ( res.success ) {
						$row.remove();
						if ( ! $( '#edlk-lock-list tr' ).length ) {
							$( '#edlk-lock-list' ).html( '<tr><td colspan="5">現在ロック中の投稿はありません。</td></tr>' );
						}
					} else {
						alert( '解除に失敗しました' );
					}
				} );
			} );
		} );
		</script>
		<?php
	}
}

Edlk_Settings_Page::init();
