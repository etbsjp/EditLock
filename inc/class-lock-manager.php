<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Edlk_Lock_Manager {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'edlk_locks';
	}

	public static function create_table() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			post_id BIGINT UNSIGNED NOT NULL,
			session_id VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			locked_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (post_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * post_id に対して session_id でロックの取得を試みる。
	 * 既存ロックが期限切れ、または同一 session_id の場合のみ上書きする単一UPSERT文で
	 * InnoDBの行ロックにより競合を解消するため、この関数はアトミック。
	 */
	public static function acquire( $post_id, $session_id, $user_id, $ttl_seconds ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (post_id, session_id, user_id, locked_at, expires_at)
			VALUES (%d, %s, %d, NOW(), DATE_ADD(NOW(), INTERVAL %d SECOND))
			ON DUPLICATE KEY UPDATE
				session_id = IF(expires_at < NOW() OR session_id = VALUES(session_id), VALUES(session_id), session_id),
				user_id    = IF(expires_at < NOW() OR session_id = VALUES(session_id), VALUES(user_id), user_id),
				locked_at  = IF(expires_at < NOW() OR session_id = VALUES(session_id), VALUES(locked_at), locked_at),
				expires_at = IF(expires_at < NOW() OR session_id = VALUES(session_id), VALUES(expires_at), expires_at)",
			$post_id,
			$session_id,
			$user_id,
			(int) $ttl_seconds
		) );

		return self::status( $post_id );
	}

	/**
	 * 現在保持している session_id を条件に TTL を延長する(Heartbeatからの呼び出し用)。
	 * 既に他セッションに奪われている場合は延長せず false を返す。
	 */
	public static function renew( $post_id, $session_id, $ttl_seconds ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table}
			SET expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
			WHERE post_id = %d AND session_id = %s AND expires_at >= NOW()",
			(int) $ttl_seconds,
			$post_id,
			$session_id
		) );

		// UPDATEの affected rows は「値が変化した行数」であり、TTLが秒単位で
		// 直前の値と一致した場合は0になり得るため、成否は現在の保持状況で判定する。
		return self::is_holder( $post_id, $session_id );
	}

	/**
	 * 自分の session_id が保持者である場合のみ解放する。
	 */
	public static function release( $post_id, $session_id ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE post_id = %d AND session_id = %s",
			$post_id,
			$session_id
		) );
	}

	public static function force_release( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE post_id = %d",
			$post_id
		) );
	}

	/**
	 * post_id の有効なロック保持状況を返す。期限切れ・未ロックなら null。
	 */
	public static function status( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT post_id, session_id, user_id, locked_at, expires_at
			FROM {$table}
			WHERE post_id = %d AND expires_at >= NOW()",
			$post_id
		), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * session_id が現在そのpost_idのロック保持者かどうか。
	 */
	public static function is_holder( $post_id, $session_id ) {
		$status = self::status( $post_id );
		return $status && hash_equals( $status['session_id'], (string) $session_id );
	}

	public static function cleanup_expired() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DELETE FROM {$table} WHERE expires_at < NOW()" );
	}

	/**
	 * 管理画面「現在ロック中の一覧」表示用。
	 */
	public static function get_active_locks() {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			"SELECT post_id, session_id, user_id, locked_at, expires_at
			FROM {$table}
			WHERE expires_at >= NOW()
			ORDER BY locked_at DESC",
			ARRAY_A
		);
	}
}
