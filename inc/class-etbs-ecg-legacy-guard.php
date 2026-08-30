<?php
/**
 * Detects the self-distributed predecessor of this plugin (EditLock) and keeps the two apart.
 * このプラグインの前身（自社配布版の EditLock）を検出し、同居させないようにする。
 *
 * @package etbs-edit-conflict-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against running side by side with the predecessor plugin.
 *
 * The predecessor shares this plugin's option keys, lock table and cron hook, and declares
 * classes and functions under the same names, so the two cannot run at the same time.
 *
 * 前身はオプションキー・ロックテーブル・cron フックを共有し、同名のクラスと関数を宣言するため、
 * 同時に動かすことはできない。
 */
class Etbs_Ecg_Legacy_Guard {

	/**
	 * Basename of the predecessor's main file, as WordPress records it in active_plugins.
	 * 前身のメインファイル。WordPress が active_plugins に記録する形式。
	 */
	const LEGACY_PLUGIN = 'editlock/editlock.php';

	/**
	 * Reports whether the predecessor plugin is currently active.
	 * 前身が現在有効かどうかを返す。
	 *
	 * @return bool True if the predecessor is active on this site or across the network.
	 */
	public static function is_legacy_active() {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			// Network-activated plugins are stored as keys, not values.
			// ネットワーク有効化されたプラグインは値ではなくキーとして保存される.
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active as $plugin ) {
			// Match the folder rename case as well, not just the default path.
			// フォルダ名を変えて設置された場合も拾う（既定のパスだけを見ない）.
			if ( self::LEGACY_PLUGIN === $plugin || '/editlock.php' === substr( (string) $plugin, -14 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shows an admin notice explaining why this plugin is not doing anything.
	 * このプラグインが何もしていない理由を管理画面に表示する。
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'ETBS Edit Conflict Guard is not running because its predecessor, EditLock, is still active on this site.', 'etbs-edit-conflict-guard' ); ?>
				<?php esc_html_e( 'The two share the same settings, lock table and scheduled task, so only one of them can run.', 'etbs-edit-conflict-guard' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Deactivate EditLock to switch over. Your saved settings are kept.', 'etbs-edit-conflict-guard' ); ?>
				<?php esc_html_e( 'Delete EditLock only after this plugin is running, because deleting it removes the shared lock table.', 'etbs-edit-conflict-guard' ); ?>
			</p>
		</div>
		<?php
	}
}
