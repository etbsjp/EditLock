<?php
/**
 * アンインストール処理。
 *
 * EditLock は「一時状態・自分が仕掛けた cron」と「利用者が設定した値」が混在しているため、
 * 削除の可否をひとまとめにせず種別ごとに判断する（task-queue #108 の案A決定）。
 *
 * - 消す: `{$wpdb->prefix}edlk_locks` テーブル（編集中ロックの一時状態）、
 *   `edlk_cleanup_cron`（このプラグインが自分で仕掛けた cron）。
 * - 消さない: `edlk_ttl_seconds` / `edlk_excluded_post_types` / `edlk_guard_trash`
 *   （いずれも利用者が設定した値）。特に `edlk_guard_trash` は元の実装で
 *   delete_option の記載が無かったオプションだが、これは消し忘れではなく、
 *   利用者が設定した値を残すという方針に基づいた意図的な判断である。
 *
 * @package editlock
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

/**
 * EditLock のアンインストール本体。
 *
 * 一時状態のテーブルと自分が仕掛けた cron のみを削除する。利用者が設定した
 * オプション（`edlk_ttl_seconds` / `edlk_excluded_post_types` / `edlk_guard_trash`）は、
 * ファイル冒頭の docblock のとおり意図的に削除しない。
 *
 * @return void
 */
function edlk_editlock_uninstall() {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}edlk_locks" );
	wp_unschedule_hook( 'edlk_cleanup_cron' );
}

edlk_editlock_uninstall();
