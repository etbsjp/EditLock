<?php
/**
 * Plugin Name:       EditLock
 * Description:       他のユーザーが編集中の投稿への保存を実際にブロックする、投稿単位の排他ロックプラグイン
 * Version:           1.0.1
 * Requires PHP:      7.4
 * Author:            DAI
 * Author URI:        https://etbs.jp
 * Plugin URI:        https://etbs.jp/product-category/wordpress-tools/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       editlock
 *
 * @package editlock
 */

define( 'EDLK_VERSION', '1.0.1' );
define( 'EDLK_PLUGIN_FILE', __FILE__ );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once( dirname( __FILE__ ) . '/inc/func.php' );

register_activation_hook( __FILE__, 'edlk_activate' );
register_deactivation_hook( __FILE__, 'edlk_deactivate' );

/*-------------------------------------------*/
/*  プラグインのアップデートチェック
/*-------------------------------------------*/
require 'inc/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/etbsjp/editlock/',
	__FILE__,
	'editlock'
);
$myUpdateChecker->setBranch( 'dist' );
