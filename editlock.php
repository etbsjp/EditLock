<?php
/**
 * Plugin Name:       EditLock
 * Description:       A per-post exclusive lock plugin that actually blocks saving while another user is editing the post.
 * Version:           1.0.3
 * Requires PHP:      7.4
 * Author:            DAI
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
