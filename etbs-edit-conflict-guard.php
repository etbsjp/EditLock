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

define( 'EDLK_VERSION', '1.1.0' );
define( 'EDLK_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/inc/func.php';

register_activation_hook( __FILE__, 'edlk_activate' );
register_deactivation_hook( __FILE__, 'edlk_deactivate' );
