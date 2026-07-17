<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

function edlk_editlock_uninstall() {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}edlk_locks" );
	delete_option( 'edlk_ttl_seconds' );
	delete_option( 'edlk_excluded_post_types' );
	wp_unschedule_hook( 'edlk_cleanup_cron' );
}

edlk_editlock_uninstall();
