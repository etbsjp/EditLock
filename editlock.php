<?php
/**
 * Plugin Name:       EditLock
 * Description:       A per-post exclusive lock plugin that actually blocks saving while another user is editing the post.
 * Version:           1.0.3
 * Requires PHP:      7.4
 * Author:            ETBS (DAI)
 * Author URI:        https://etbs.jp
 * Plugin URI:        https://etbs.jp/product/editlock/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       editlock
 * Domain Path:       /languages
 *
 * @package editlock
 */

define( 'EDLK_VERSION', '1.0.3' );
define( 'EDLK_PLUGIN_FILE', __FILE__ );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once( dirname( __FILE__ ) . '/inc/func.php' );

register_activation_hook( __FILE__, 'edlk_activate' );
register_deactivation_hook( __FILE__, 'edlk_deactivate' );

/*-------------------------------------------*/
/*  プラグインのアップデートチェック
/*  Update check for the self-distributed copy.
/*  ★ wordpress.org 版（ETBS Edit Conflict Guard）とはフォルダ名が異なるため経路は繋がらない。
/*  ★ The wordpress.org release uses a different folder name, so core cannot update this copy.
/*     この経路は移行案内を届けるために残している。
/*     This channel is kept so that the migration notice can still be delivered.
/*-------------------------------------------*/
require 'inc/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/etbsjp/editlock/',
	__FILE__,
	'editlock'
);
$myUpdateChecker->setBranch( 'dist' );
