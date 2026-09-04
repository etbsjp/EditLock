<?php
/**
 * Plugin Name:       ETBS Edit Conflict Guard
 * Description:       A per-post exclusive lock plugin that actually blocks saving while another user is editing the post.
 * Version:           1.1.1
 * Requires PHP:      7.4
 * Author:            ETBS (DAI)
 * Author URI:        https://etbs.jp
 * Plugin URI:        https://etbs.jp/product/etbs-edit-conflict-guard/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       etbs-edit-conflict-guard
 * Domain Path:       /languages
 *
 * @package etbs-edit-conflict-guard
 */

// Exit if accessed directly. Must come before any executable code, including the defines below.
// 直接アクセスされた場合は終了する。下の define も実行されるコードなので、それより前に置く.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETBS_ECG_VERSION', '1.1.1' );
define( 'ETBS_ECG_PLUGIN_FILE', __FILE__ );

/*
 * Register the bundled translations here, above the require_once and the legacy stand-down that
 * follow, so that the notice explaining the stand-down is translated as well -- the stand-down
 * returns before inc/func.php is ever read.
 *
 * The load is hooked to init because that is where WordPress expects translations to be set up.
 * From 6.7 on, _load_textdomain_just_in_time() emits a _doing_it_wrong() notice for any __() that
 * runs before after_setup_theme. That gate decides whether to warn, not whether to look: just-in-time
 * loading still happens wherever the __() is, and the gate does not exist at all below 6.7. Note the
 * notice comes from the __() side, not from this call -- load_plugin_textdomain() never emits it,
 * and on 7.1 it does not even read the file, it only registers the path for the first __() to use.
 *
 * The etbs_ecg_ prefix is deliberate and does not match the edlk_ prefix used by every other
 * function in this plugin. The predecessor declares edlk_load_textdomain() itself behind a
 * function_exists() guard, and this call site runs even when the predecessor is active. WordPress
 * sorts active_plugins, so editlock/ loads first and declares that name; sharing it here would
 * then be a fatal Cannot redeclare. Declared the other way round, the predecessor's own guard
 * would skip and its translations would die silently. Neither is acceptable, so use a name that
 * cannot collide. There is no guard here on purpose: nothing in either plugin declares
 * etbs_ecg_load_textdomain(), and adding one would convert a future collision from a visible
 * error into a silent skip.
 *
 * 同梱翻訳の登録をここに置く。下の require_once と legacy stand-down より前に置くのは、
 * stand-down が inc/func.php を読む前に return するため、stand-down の理由を説明する通知
 * 自体を翻訳するには、それより前で登録するしかないから。
 *
 * 読み込みを init に掛けるのは、WordPress が翻訳の準備を想定している場所がそこだから。
 * 6.7 以降は、after_setup_theme より前に走った __() に対して
 * _load_textdomain_just_in_time() が _doing_it_wrong() の通知を出す。★ この門が決めるのは
 * 「警告するかどうか」であって「探索するかどうか」ではない。just-in-time の読み込みは
 * __() があればその場で起きるし、6.7 未満にはこの門自体が無い。★ 通知を出すのは
 * __() の側であって、この呼び出しではない。load_plugin_textdomain() は通知を出さないし、
 * 7.1 ではファイルを読みもせず、最初の __() が使うパスを登録するだけである。
 *
 * etbs_ecg_ 接頭辞は意図的で、このプラグインの他の関数が使う edlk_ とは揃えていない。
 * 前身は function_exists() ガード付きで edlk_load_textdomain() を自分で宣言しており、
 * この呼び出しは前身が有効なときも実行される。WordPress は active_plugins をソートするため
 * editlock/ が先に読まれてその名前を宣言する。ここで同名にすると Cannot redeclare の
 * Fatal になる。逆順なら前身側のガードがスキップされ、前身の翻訳が黙って死ぬ。
 * どちらも許容できないので、衝突しえない名前を使う。ここにガードを置かないのも意図的で、
 * どちらのプラグインも etbs_ecg_load_textdomain() を宣言しておらず、
 * ガードは将来の衝突を「見えるエラー」から「黙った無視」に変えてしまうため。
 */

/**
 * Loads the translations bundled with this plugin.
 * このプラグインに同梱した翻訳を読み込む。
 *
 * @return void
 */
function etbs_ecg_load_textdomain() {
	load_plugin_textdomain(
		'etbs-edit-conflict-guard',
		false,
		dirname( plugin_basename( ETBS_ECG_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'etbs_ecg_load_textdomain' );

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
