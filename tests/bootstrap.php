<?php
/**
 * PHPUnit bootstrap for ETBS Edit Conflict Guard.
 * ETBS Edit Conflict Guard の PHPUnit ブートストラップ。
 *
 * The WordPress test suite location is read from the WP_TESTS_DIR environment variable, and its
 * database settings from the wp-tests-config.php that suite is pointed at (WP_PHPUNIT__TESTS_CONFIG
 * when wp-phpunit is used). Neither belongs to this repository: the test suite DROPs and re-creates
 * every table in the database it connects to, so point it at a scratch database, never at a site.
 * See the "PHPUnit" section of CLAUDE.md for the procedure.
 * WordPress テストスイートの場所は環境変数 WP_TESTS_DIR から、DB の設定はそのスイートが指す
 * wp-tests-config.php（wp-phpunit なら WP_PHPUNIT__TESTS_CONFIG）から読む。どちらもこのリポジトリには
 * 置かない。テストスイートは接続先 DB のテーブルを削除して作り直すため、必ず使い捨ての DB を指すこと。
 * 実サイトの DB には絶対に向けない。手順は CLAUDE.md の「PHPUnit」節を参照。
 *
 * @package etbs-edit-conflict-guard
 */

$etbs_ecg_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $etbs_ecg_tests_dir || ! is_readable( $etbs_ecg_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set, or does not point at a WordPress test suite (includes/functions.php not found).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $etbs_ecg_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin and creates its lock table.
 * プラグインを読み込み、ロックテーブルを作る。
 *
 * The table is created here, once, as a real table (not through the suite's temporary-table
 * filter): every test then runs inside the suite's transaction and is rolled back.
 * テーブルはここで1度だけ、通常のテーブルとして作る（スイートの一時テーブル化フィルタを通さない）。
 * 各テストはスイートのトランザクション内で動き、ロールバックされる。
 *
 * @return void
 */
function etbs_ecg_tests_load_plugin() {
	require dirname( __DIR__ ) . '/etbs-edit-conflict-guard.php';
	Etbs_Ecg_Lock_Manager::create_table();
}
tests_add_filter( 'muplugins_loaded', 'etbs_ecg_tests_load_plugin' );

// Installs WordPress into the scratch database, then loads it.
// 使い捨て DB に WordPress をインストールしてから読み込む.
require $etbs_ecg_tests_dir . '/includes/bootstrap.php';

/*
 * Tests that must define a constant (DOING_AUTOSAVE, REST_REQUEST, WP_CLI ...) run in a separate
 * process, because a constant can never be undefined. That child process bootstraps again, and the
 * suite would DROP the tables the parent process is using. The install above has already run, so
 * tell every child to skip it.
 * 定数を定義する必要があるテスト（DOING_AUTOSAVE・REST_REQUEST・WP_CLI など）は、定数を取り消せない
 * ため別プロセスで動かす。その子プロセスもここから読み込み直され、親が使っているテーブルを
 * スイートが DROP してしまう。インストールは上で済んでいるので、子にはスキップさせる。
 */
putenv( 'WP_TESTS_SKIP_INSTALL=1' );

require_once __DIR__ . '/class-etbs-ecg-test-case.php';
