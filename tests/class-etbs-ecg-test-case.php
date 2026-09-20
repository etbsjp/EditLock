<?php
/**
 * Shared base class for this plugin's tests.
 * このプラグインのテスト共通の基底クラス。
 *
 * @package etbs-edit-conflict-guard
 */

/**
 * Helpers for tests that read and write lock rows.
 * ロック行を読み書きするテスト用の補助メソッド。
 */
abstract class Etbs_Ecg_Test_Case extends WP_UnitTestCase {

	/**
	 * Empties the lock table and resets per-request state before each test.
	 * 各テストの前に、ロックテーブルとリクエスト内の状態を初期化する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->clear_locks();
		// The stored session ID is a function-level static, so it leaks from one test into the next.
		// 保持しているセッション ID は関数内 static なので、放っておくと次のテストへ漏れる.
		edlk_current_session_id( '' );
		unset( $_POST['edlk_session_id'], $_REQUEST['bulk_edit'] );
	}

	/**
	 * Restores the screen and the current user after each test.
	 * 各テストの後に、画面と現在のユーザーを元に戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_doing_cron' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		unset( $_POST['edlk_session_id'], $_REQUEST['bulk_edit'] );
		parent::tear_down();
	}

	/**
	 * Deletes every lock row.
	 * ロック行をすべて消す。
	 *
	 * @return void
	 */
	protected function clear_locks() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Etbs_Ecg_Lock_Manager::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from $wpdb->prefix.
	}

	/**
	 * Writes a lock row directly, bypassing acquire().
	 * acquire() を通さず、ロック行を直接書く。
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $session_id Session ID (may be '' to reproduce a corrupt row).
	 * @param int    $user_id    Holder's user ID.
	 * @param bool   $expired    Whether the row is already expired.
	 * @return void
	 */
	protected function insert_lock( $post_id, $session_id, $user_id, $expired = false ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from $wpdb->prefix.
				'INSERT INTO ' . Etbs_Ecg_Lock_Manager::table_name() . ' (post_id, session_id, user_id, locked_at, expires_at) VALUES (%d, %s, %d, NOW(), DATE_ADD(NOW(), INTERVAL %d SECOND))',
				$post_id,
				$session_id,
				$user_id,
				$expired ? -60 : 120
			)
		);
	}

	/**
	 * Counts the lock rows for a post.
	 * 投稿のロック行の数を返す。
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of rows, expired ones included.
	 */
	protected function count_lock_rows( $post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from $wpdb->prefix.
				'SELECT COUNT(*) FROM ' . Etbs_Ecg_Lock_Manager::table_name() . ' WHERE post_id = %d',
				$post_id
			)
		);
	}

	/**
	 * Puts the request into the admin (wp-admin) context that the classic save gate looks for.
	 * クラシックの保存ゲートが見る管理画面の文脈に、リクエストを置く。
	 *
	 * @return void
	 */
	protected function enter_admin_context() {
		set_current_screen( 'edit-post' );
	}

	/**
	 * Runs the classic save gate and reports whether it stopped the save.
	 * クラシックの保存ゲートを走らせ、保存を止めたかどうかを返す。
	 *
	 * @param int $post_id Post ID.
	 * @return string|null The wp_die() message when the save was stopped, or null when it went through.
	 */
	protected function run_save_gate( $post_id ) {
		try {
			edlk_pre_post_update_gate( $post_id, array() );
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		}
		return null;
	}
}
