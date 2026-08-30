<?php
/**
 * Plugin Name:       ETBS Edit Conflict Guard
 * Description:       A per-post exclusive lock plugin that actually blocks saving while another user is editing the post.
 * Version:           1.1.0
 * Requires PHP:      7.4
 * Author:            ETBS (DAI)
 * Author URI:        https://etbs.jp
 * Plugin URI:        https://etbs.jp/product/etbs-edit-conflict-guard/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       etbs-edit-conflict-guard
 *
 * @package etbs-edit-conflict-guard
 */

// Exit if accessed directly. Must come before any executable code, including the defines below.
// 直接アクセスされた場合は終了する。下の define も実行されるコードなので、それより前に置く.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETBS_ECG_VERSION', '1.1.0' );
define( 'ETBS_ECG_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/inc/class-etbs-ecg-legacy-guard.php';

/*
 * Stand down while the self-distributed predecessor (EditLock) is still active. It shares this
 * plugin's option keys, lock table and cron hook and declares functions under the same names,
 * so loading both would leave one of them silently inert. Say so instead of failing quietly.
 *
 * 自社配布版の前身（EditLock）が有効な間は動かない。オプションキー・ロックテーブル・cron を
 * 共有し、同名の関数を宣言するため、両方を読み込むと片方が黙って不発になる。
 * 黙って壊れるのではなく、理由を画面に出す。
 */
if ( Etbs_Ecg_Legacy_Guard::is_legacy_active() ) {
	add_action( 'admin_notices', array( 'Etbs_Ecg_Legacy_Guard', 'render_notice' ) );
	return;
}

require_once __DIR__ . '/inc/func.php';

register_activation_hook( __FILE__, 'edlk_activate' );
register_deactivation_hook( __FILE__, 'edlk_deactivate' );
