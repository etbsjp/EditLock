<?php
/**
 * Handles the custom lock table used to track which post is being edited by whom.
 *
 * @package etbs-edit-conflict-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads and writes rows in the {$wpdb->prefix}edlk_locks table.
 */
class Etbs_Ecg_Lock_Manager {

	/**
	 * Gets the fully-prefixed name of the lock table.
	 *
	 * @return string Table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'edlk_locks';
	}

	/**
	 * Creates (or updates) the lock table via dbDelta().
	 *
	 * @return void
	 */
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
	 * Tries to acquire the lock on a post for the given session.
	 *
	 * @param int    $post_id     Post ID to lock.
	 * @param string $session_id  Requesting session ID.
	 * @param int    $user_id     Requesting user ID.
	 * @param int    $ttl_seconds Lock expiration in seconds.
	 * @return array|null Current lock status row after the attempt, or null if unlocked.
	 */
	public static function acquire( $post_id, $session_id, $user_id, $ttl_seconds ) {
		global $wpdb;
		$table = self::table_name();

		// post_id に対して session_id でロックの取得を試みる.
		// 既存ロックが期限切れ、または同一 session_id の場合のみ上書きする単一UPSERT文で
		// InnoDBの行ロックにより競合を解消するため、この関数はアトミック.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
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
			)
		);

		return self::status( $post_id );
	}

	/**
	 * Renews the TTL of the lock currently held by the given session.
	 *
	 * @param int    $post_id     Post ID whose lock should be renewed.
	 * @param string $session_id  Session ID expected to be the current holder.
	 * @param int    $ttl_seconds New lock expiration in seconds from now.
	 * @return bool True if $session_id is (still) the lock holder after the attempt.
	 */
	public static function renew( $post_id, $session_id, $ttl_seconds ) {
		global $wpdb;
		$table = self::table_name();

		// 現在保持している session_id を条件に TTL を延長する(Heartbeatからの呼び出し用).
		// 既に他セッションに奪われている場合は延長せず false を返す.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
				"UPDATE {$table}
				SET expires_at = DATE_ADD(NOW(), INTERVAL %d SECOND)
				WHERE post_id = %d AND session_id = %s AND expires_at >= NOW()",
				(int) $ttl_seconds,
				$post_id,
				$session_id
			)
		);

		// UPDATEの affected rows は「値が変化した行数」であり、TTLが秒単位で直前の値と一致した場合は0になり得るため、成否は現在の保持状況で判定する.
		return self::is_holder( $post_id, $session_id );
	}

	/**
	 * Releases a lock, but only when the given session is the current holder.
	 *
	 * @param int    $post_id    Post ID to unlock.
	 * @param string $session_id Session ID requesting the release.
	 * @return void
	 */
	public static function release( $post_id, $session_id ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
				"DELETE FROM {$table} WHERE post_id = %d AND session_id = %s",
				$post_id,
				$session_id
			)
		);
	}

	/**
	 * Releases a post's lock regardless of the current holder (administrator action).
	 *
	 * @param int $post_id Post ID to force-unlock.
	 * @return void
	 */
	public static function force_release( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
				"DELETE FROM {$table} WHERE post_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Gets the active lock status for a post.
	 *
	 * @param int $post_id Post ID to look up.
	 * @return array|null Lock row (post_id, session_id, user_id, locked_at, expires_at), or null.
	 */
	public static function status( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		// post_id の有効なロック保持状況を返す。期限切れ・未ロックなら null.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
				"SELECT post_id, session_id, user_id, locked_at, expires_at FROM {$table}
				WHERE post_id = %d AND expires_at >= NOW()",
				$post_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Checks whether the given session currently holds the lock on a post.
	 *
	 * @param int    $post_id    Post ID to check.
	 * @param string $session_id Session ID to compare against the current holder.
	 * @return bool True if $session_id currently holds the lock.
	 */
	public static function is_holder( $post_id, $session_id ) {
		$status = self::status( $post_id );
		return $status && hash_equals( $status['session_id'], (string) $session_id );
	}

	/**
	 * Deletes all expired lock rows. Called from the cleanup cron.
	 *
	 * @return void
	 */
	public static function cleanup_expired() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
		$wpdb->query( "DELETE FROM {$table} WHERE expires_at < NOW()" );
	}

	/**
	 * Gets every currently active lock, for the settings screen's lock table.
	 *
	 * @return array[] List of active lock rows, most recently locked first.
	 */
	public static function get_active_locks() {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix (table_name()), not user input.
			"SELECT post_id, session_id, user_id, locked_at, expires_at FROM {$table}
			WHERE expires_at >= NOW()
			ORDER BY locked_at DESC",
			ARRAY_A
		);
	}
}
