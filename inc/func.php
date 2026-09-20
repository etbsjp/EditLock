<?php
/**
 * Core functions and hooks for Edit Conflict Guard.
 *
 * @package etbs-edit-conflict-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Load module files.
require_once __DIR__ . '/class-etbs-ecg-lock-manager.php';
require_once __DIR__ . '/class-etbs-ecg-settings-page.php';

/*
 * Translations were originally left entirely to translate.wordpress.org, which delivers them to
 * WP_LANG_DIR/plugins for WordPress to load just in time. Nothing was bundled, so neither
 * load_plugin_textdomain() nor the Domain Path header was needed here. That changed in 1.1.1: a
 * Japanese translation is bundled under languages/ to cover the wait for translation-editor
 * approval, so both are back. The registration lives in the main plugin file, above the legacy
 * stand-down rather than here -- see the reason written at that call site.
 *
 * A bundled file never beats a language pack. How that is enforced differs by version (before
 * 6.1 load_plugin_textdomain() tried WP_LANG_DIR itself; 6.1-7.0 returned early once it found a
 * pack there; 7.1 only registers the path and leaves every lookup to WP_Textdomain_Registry),
 * but every one of them looks in WP_LANG_DIR/plugins before the bundled path. This plugin
 * declares no "Requires at least", so all three are in scope -- do not write any single one of
 * them down as "the" mechanism. An approved language pack therefore wins on its own, and the
 * bundled copy needs no removal later.
 *
 * 翻訳は当初 translate.wordpress.org に完全に委ねていた。翻訳は WP_LANG_DIR/plugins に届き
 * WordPress が just-in-time で読み込むため、同梱ファイルを持たず、load_plugin_textdomain() も
 * Domain Path ヘッダもここには不要だった。1.1.1 でこれを変えた。翻訳編集者の承認を待つ間を
 * 埋めるため日本語訳を languages/ に同梱したので、両方が戻っている。登録はここではなく
 * メインファイルの legacy stand-down より上に置いた。理由はその呼び出し位置に書いてある。
 *
 * 同梱ファイルが言語パックに勝つことはない。ただしその実現方法はバージョンで違う
 * （6.1 未満は load_plugin_textdomain() 自身が WP_LANG_DIR を試し、6.1〜7.0 はそこで
 * 見つかれば早期 return、7.1 はパスを登録するだけで探索は WP_Textdomain_Registry に任せる）。
 * どれも WP_LANG_DIR/plugins を同梱パスより先に見る点は共通。このプラグインは
 * 「Requires at least」を宣言していないので3世代とも対象であり、
 * どれか1つを「この機構」と書き切らないこと。したがって承認後は言語パックが自動的に勝ち、
 * 同梱分を後から外す作業は要らない。
 */

/*
 * Self-heal the lock table and the cleanup cron.
 *
 * Both are shared with the self-distributed predecessor (EditLock), whose uninstall.php drops the
 * table and unschedules the cron. Deleting the predecessor after switching over would therefore
 * take this plugin's storage with it, and the table is otherwise only created on activation —
 * so it would never come back, and every gate would silently pass with "no lock". A missing cron
 * is the cheap signal for that case (the cron array is autoloaded; no query is needed to check).
 *
 * ロックテーブルと後始末 cron は自社配布版の前身（EditLock）と共有しており、前身の
 * uninstall.php はその両方を消す。移行後に前身を削除するとこのプラグインの保存先ごと消え、
 * テーブルは有効化時にしか作られないため二度と戻らない。全ゲートが「ロック無し」で
 * 通過するのにエラーは出ない。cron の欠落がその安価な検知手段になる（cron は autoload
 * されるオプションなので、確認にクエリが要らない）。
 */
if ( ! function_exists( 'edlk_repair_storage' ) ) {
	/**
	 * Recreates the lock table and the cleanup cron if either has gone missing.
	 *
	 * @return void
	 */
	function edlk_repair_storage() {
		if ( wp_next_scheduled( 'edlk_cleanup_cron' ) ) {
			return;
		}
		// dbDelta() is idempotent, so running it when the table already exists is harmless.
		// dbDelta() は冪等なので、テーブルが既にある状態で走らせても害はない.
		Etbs_Ecg_Lock_Manager::create_table();
		wp_schedule_event( time(), 'edlk_ten_minutes', 'edlk_cleanup_cron' );
	}
	add_action( 'admin_init', 'edlk_repair_storage' );
}

// 有効化・無効化.
if ( ! function_exists( 'edlk_activate' ) ) {
	/**
	 * Runs on plugin activation: creates the lock table and schedules the cleanup cron.
	 *
	 * @return void
	 */
	function edlk_activate() {
		Etbs_Ecg_Lock_Manager::create_table();
		if ( ! wp_next_scheduled( 'edlk_cleanup_cron' ) ) {
			wp_schedule_event( time(), 'edlk_ten_minutes', 'edlk_cleanup_cron' );
		}
	}
}

if ( ! function_exists( 'edlk_deactivate' ) ) {
	/**
	 * Runs on plugin deactivation: unschedules the cleanup cron.
	 *
	 * @return void
	 */
	function edlk_deactivate() {
		wp_unschedule_hook( 'edlk_cleanup_cron' );
	}
}

if ( ! function_exists( 'edlk_add_cron_interval' ) ) {
	/**
	 * Registers a custom 10-minute cron schedule used by the cleanup event.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	function edlk_add_cron_interval( $schedules ) {
		$schedules['edlk_ten_minutes'] = array(
			'interval' => 600,
			'display'  => __( 'Every 10 Minutes (Edit Conflict Guard)', 'etbs-edit-conflict-guard' ),
		);
		return $schedules;
	}
	add_filter( 'cron_schedules', 'edlk_add_cron_interval' );
}

if ( ! function_exists( 'edlk_cleanup_cron_handler' ) ) {
	/**
	 * Cron callback that removes expired locks.
	 *
	 * @return void
	 */
	function edlk_cleanup_cron_handler() {
		Etbs_Ecg_Lock_Manager::cleanup_expired();
	}
	add_action( 'edlk_cleanup_cron', 'edlk_cleanup_cron_handler' );
}

// 設定値ヘルパー.
if ( ! function_exists( 'edlk_get_ttl' ) ) {
	/**
	 * Gets the configured lock expiration (TTL) in seconds.
	 *
	 * @return int Lock expiration in seconds (always greater than 0).
	 */
	function edlk_get_ttl() {
		$ttl = (int) get_option( 'edlk_ttl_seconds', 120 );
		return $ttl > 0 ? $ttl : 120;
	}
}

if ( ! function_exists( 'edlk_get_excluded_post_types' ) ) {
	/**
	 * Gets the list of post type slugs excluded from locking.
	 *
	 * @return array List of excluded post type slugs.
	 */
	function edlk_get_excluded_post_types() {
		$excluded = get_option( 'edlk_excluded_post_types', array() );
		return is_array( $excluded ) ? $excluded : array();
	}
}

if ( ! function_exists( 'edlk_get_editable_post_types' ) ) {
	/**
	 * Gets the post types with an admin UI, excluding attachments.
	 *
	 * @return WP_Post_Type[] Post type objects keyed by post type slug.
	 */
	function edlk_get_editable_post_types() {
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		unset( $post_types['attachment'] );
		return $post_types;
	}
}

if ( ! function_exists( 'edlk_is_post_type_enabled' ) ) {
	/**
	 * Checks whether Edit Conflict Guard is active for the given post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool True if the post type is locked by Edit Conflict Guard.
	 */
	function edlk_is_post_type_enabled( $post_type ) {
		if ( 'attachment' === $post_type ) {
			return false;
		}
		$post_types = edlk_get_editable_post_types();
		if ( ! isset( $post_types[ $post_type ] ) ) {
			return false;
		}
		return ! in_array( $post_type, edlk_get_excluded_post_types(), true );
	}
}

if ( ! function_exists( 'edlk_is_trash_guard_enabled' ) ) {
	/**
	 * Checks whether the "also lock trash actions" option is enabled.
	 *
	 * @return bool True if trash actions are also guarded.
	 */
	function edlk_is_trash_guard_enabled() {
		return (bool) get_option( 'edlk_guard_trash', false );
	}
}

/*
 * リクエスト内で確認済みの session_id を一時保持
 * （保存ゲート通過後、同一リクエスト内の save_post でロック解放するため）
 */
if ( ! function_exists( 'edlk_current_session_id' ) ) {
	/**
	 * Gets or sets the session ID confirmed earlier in the current request.
	 *
	 * @param string|null $set Session ID to store, or null to just read the current value.
	 * @return string The currently stored session ID.
	 */
	function edlk_current_session_id( $set = null ) {
		static $session_id = '';
		if ( null !== $set ) {
			$session_id = (string) $set;
		}
		return $session_id;
	}
}

if ( ! function_exists( 'edlk_is_autosave_request' ) ) {
	/**
	 * Tells whether the current save is an autosave.
	 * 現在の保存が自動保存かどうかを返す。
	 *
	 * DOING_AUTOSAVE alone is not enough: WP_REST_Autosaves_Controller::create_item() does not
	 * define it when WP_RUN_CORE_TESTS is defined, so the guard would not engage under the
	 * WordPress core test suite and the regression tests would pass without testing anything.
	 * DOING_AUTOSAVE だけに頼らない。WP_RUN_CORE_TESTS が定義されていると
	 * この定数が立たず、テストスイート上で判定がすり抜けるため。
	 *
	 * @param WP_REST_Request|null $request REST request being judged, or null outside REST.
	 * @return bool True when this is an autosave.
	 */
	function edlk_is_autosave_request( $request = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}
		if ( $request instanceof WP_REST_Request ) {
			return 1 === preg_match( '#/autosaves$#', (string) $request->get_route() );
		}
		return false;
	}
}

if ( ! function_exists( 'edlk_sanitize_session_id' ) ) {
	/**
	 * Validates a session ID sent by the client, returning an empty string when it is not acceptable.
	 * クライアントから届いたセッション ID を検証し、不正なら空文字を返す。
	 *
	 * The column is VARCHAR(64). Without a length check a non-strict MySQL truncates a longer value, and
	 * a different session whose first 64 characters match would then be treated as the holder. The
	 * pattern is anchored with \z, not $, because $ also matches before a trailing newline.
	 * 列は VARCHAR(64)。長さを見ないと、strict でない MySQL は長い値を切り詰め、先頭 64 文字が
	 * 一致する別セッションが保持者として通ってしまう。$ は末尾の改行の手前にも一致するので \z で止める。
	 *
	 * @param mixed $value Raw value from the request (already unslashed).
	 * @return string The same value when it is 1-64 characters of [A-Za-z0-9_-], otherwise ''.
	 */
	function edlk_sanitize_session_id( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}\z/', $value ) ? $value : '';
	}
}

if ( ! function_exists( 'edlk_describe_holder' ) ) {
	/**
	 * Describes who holds a lock, relative to the current user.
	 * ロックの保持者を、現在のユーザーから見た形で表す。
	 *
	 * Comparing user IDs cannot prove the holder is the same person (an account is sometimes shared),
	 * so callers must not write "you" in a message; they say "the same account" instead.
	 * user_id の一致は同一人物の証明にならない（アカウントを共用している場合がある）ため、
	 * 呼び出し側は「あなた」と断定せず「同じアカウント」と書く。
	 *
	 * @param array $status Lock row from Etbs_Ecg_Lock_Manager::status().
	 * @return array {
	 *     @type bool   $is_self True when the holder is the current user's account.
	 *     @type string $name    Display name of the holder, or '' when the account no longer exists.
	 * }
	 */
	function edlk_describe_holder( $status ) {
		$user = get_userdata( (int) $status['user_id'] );
		return array(
			'is_self' => ( get_current_user_id() === (int) $status['user_id'] ),
			'name'    => $user ? $user->display_name : '',
		);
	}
}

if ( ! function_exists( 'edlk_get_foreign_lock_status' ) ) {
	/**
	 * Gets the active lock on a post when it belongs to a different account than the current user.
	 * 投稿のロックが現在のユーザーとは別のアカウントのものであれば、その状態を返す。
	 *
	 * @param int $post_id Post ID to look up.
	 * @return array|null Lock row, or null when there is no lock or it is the current user's own.
	 */
	function edlk_get_foreign_lock_status( $post_id ) {
		$status = Etbs_Ecg_Lock_Manager::status( $post_id );
		if ( ! $status || get_current_user_id() === (int) $status['user_id'] ) {
			return null;
		}
		return $status;
	}
}

if ( ! function_exists( 'edlk_get_save_blocked_message' ) ) {
	/**
	 * Builds the sentence that says why a save was blocked (classic wp_die and REST WP_Error).
	 * 保存を止めた理由の1文を組み立てる（クラシックの wp_die と REST の WP_Error 用）。
	 *
	 * Deliberately not shared with edlk_get_trash_blocked_message(): "what you have entered is still
	 * on this screen" means nothing for a trash action, and a shared helper is where it would leak in.
	 * ゴミ箱側とは意図的に共通化しない。「入力した内容は残っています」はゴミ箱操作には意味が無く、
	 * 共通関数はそれが混ざり込む場所になる。
	 *
	 * @param array $status Lock row from Etbs_Ecg_Lock_Manager::status().
	 * @return string Plain-text message (not escaped).
	 */
	function edlk_get_save_blocked_message( $status ) {
		$holder = edlk_describe_holder( $status );
		if ( $holder['is_self'] ) {
			return sprintf(
				/* translators: %s: display name of the current user's own account */
				__( 'This post could not be saved because it is open in another edit screen under the same account (%s).', 'etbs-edit-conflict-guard' ),
				$holder['name']
			);
		}
		if ( '' === $holder['name'] ) {
			return __( 'Another user is currently editing this post, so it could not be saved.', 'etbs-edit-conflict-guard' );
		}
		return sprintf(
			/* translators: %s: name of the user holding the lock */
			__( '%s is currently editing this post, so it could not be saved.', 'etbs-edit-conflict-guard' ),
			$holder['name']
		);
	}
}

if ( ! function_exists( 'edlk_get_trash_blocked_message' ) ) {
	/**
	 * Builds the sentence that says why a move to trash was blocked (non-REST and REST share it).
	 * ゴミ箱への移動を止めた理由の1文を組み立てる（非 REST と REST で同一の文面）。
	 *
	 * @param array $status Lock row from Etbs_Ecg_Lock_Manager::status().
	 * @return string Plain-text message (not escaped).
	 */
	function edlk_get_trash_blocked_message( $status ) {
		$holder = edlk_describe_holder( $status );
		if ( $holder['is_self'] ) {
			return sprintf(
				/* translators: %s: display name of the current user's own account */
				__( 'This post could not be moved to trash because it is open in another edit screen under the same account (%s).', 'etbs-edit-conflict-guard' ),
				$holder['name']
			);
		}
		if ( '' === $holder['name'] ) {
			return __( 'Another user is currently editing this post, so it could not be moved to trash.', 'etbs-edit-conflict-guard' );
		}
		return sprintf(
			/* translators: %s: name of the user holding the lock */
			__( '%s is currently editing this post, so it could not be moved to trash.', 'etbs-edit-conflict-guard' ),
			$holder['name']
		);
	}
}

// 編集画面へのスクリプト読み込み.
if ( ! function_exists( 'edlk_enqueue_editor_script' ) ) {
	/**
	 * Enqueues the editor script on the post edit screen for locked post types.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	function edlk_enqueue_editor_script( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		global $post;
		if ( ! $post || ! edlk_is_post_type_enabled( $post->post_type ) ) {
			return;
		}

		// The dialog's ::backdrop cannot be styled inline, so it needs a stylesheet.
		// ダイアログの ::backdrop はインラインでは装飾できないため、スタイルシートが要る.
		wp_enqueue_style(
			'edlk-editor',
			plugins_url( 'css/edlk-editor.css', __FILE__ ),
			array(),
			ETBS_ECG_VERSION
		);

		wp_enqueue_script(
			'edlk-editor',
			plugins_url( 'js/edlk-editor.js', __FILE__ ),
			array( 'jquery', 'heartbeat', 'wp-data', 'wp-api-fetch' ),
			ETBS_ECG_VERSION,
			true
		);

		/*
		 * Every string that contains the holder's name uses %1$s (not %s) because one sentence may need
		 * the name more than once; the script replaces every occurrence of %1$s.
		 * 保持者の名前を含む文字列はすべて %s ではなく %1$s にする。1つの文に名前が複数回入ることがあり、
		 * スクリプトが %1$s をすべて置換するため。
		 */
		wp_localize_script(
			'edlk-editor',
			'EdlkData',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'edlk_nonce' ),
				'postId'      => (int) $post->ID,
				// Only administrators are shown the way to force-release a lock.
				// ロックの強制解除への導線は管理者にだけ見せる.
				'settingsUrl' => current_user_can( 'manage_options' ) ? admin_url( 'options-general.php?page=edlk-settings' ) : '',
				'i18n'        => array(
					'closeButton'     => __( 'Close', 'etbs-edit-conflict-guard' ),
					'saveAgainButton' => __( 'Save again', 'etbs-edit-conflict-guard' ),
					'stillHere'       => __( 'What you have entered is still on this screen.', 'etbs-edit-conflict-guard' ),
					'selfTitle'       => __( 'Cannot save because this post is open in another edit screen', 'etbs-edit-conflict-guard' ),
					/* translators: %1$s: display name of the current user's own account (this string is used as a JS template). */
					'selfBody'        => __( 'This post\'s edit screen is open in another tab, window, or device under the same account (%1$s).', 'etbs-edit-conflict-guard' ),
					'selfAction'      => __( 'Close that edit screen, then press "Save again".', 'etbs-edit-conflict-guard' ),
					/* translators: %1$s: name of the user holding the lock (this string is used as a JS template). */
					'otherTitle'      => __( '%1$s is editing, so you cannot save', 'etbs-edit-conflict-guard' ),
					/* translators: %1$s: name of the user holding the lock (this string is used as a JS template). */
					'otherBody'       => __( '%1$s has this post open in an edit screen, so saving was cancelled to prevent overwriting.', 'etbs-edit-conflict-guard' ),
					/* translators: %1$s: name of the user holding the lock (this string is used as a JS template). */
					'otherAction'     => __( 'After %1$s closes the edit screen, press "Save again".', 'etbs-edit-conflict-guard' ),
					'adminHint'       => __( 'Administrators can force-release the lock.', 'etbs-edit-conflict-guard' ),
					'adminLink'       => __( 'Open the Edit Conflict Guard settings', 'etbs-edit-conflict-guard' ),
					'newTab'          => __( '(opens in a new tab)', 'etbs-edit-conflict-guard' ),
					'unknownTitle'    => __( 'Cannot save because another user is editing', 'etbs-edit-conflict-guard' ),
					'unknownBody'     => __( 'Another user has this post open in an edit screen, so saving was cancelled.', 'etbs-edit-conflict-guard' ),
					'lostTitle'       => __( 'Editing rights for this post moved to another edit screen', 'etbs-edit-conflict-guard' ),
					/* translators: %1$s: display name of the current user's own account (this string is used as a JS template). */
					'lostBody'        => __( 'An edit screen under the same account (%1$s) has taken over editing rights for this post.', 'etbs-edit-conflict-guard' ),
					'lostAction'      => __( 'Close that edit screen, then save from this screen.', 'etbs-edit-conflict-guard' ),
					/* translators: %1$s: display name of the current user's own account (this string is used as a JS template). */
					'selfNoticeLine1' => __( 'This post is open in another edit screen under the same account (%1$s).', 'etbs-edit-conflict-guard' ),
					'selfNoticeLine2' => __( 'You cannot save from this screen. Continue editing in the edit screen you opened first.', 'etbs-edit-conflict-guard' ),
				),
			)
		);
	}
	add_action( 'admin_enqueue_scripts', 'edlk_enqueue_editor_script' );
}

// AJAX: ロック取得（取得できなければ現在の保持者情報を返す）.
if ( ! function_exists( 'edlk_ajax_acquire' ) ) {
	/**
	 * AJAX handler that tries to acquire a lock on a post for the current session.
	 *
	 * @return void
	 */
	function edlk_ajax_acquire() {
		check_ajax_referer( 'edlk_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'etbs-edit-conflict-guard' ) ) );
		}

		$session_id = isset( $_POST['session_id'] ) ? edlk_sanitize_session_id( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( '' === $session_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session ID.', 'etbs-edit-conflict-guard' ) ) );
		}

		$status = Etbs_Ecg_Lock_Manager::acquire( $post_id, $session_id, get_current_user_id(), edlk_get_ttl() );
		edlk_send_lock_status( $status, $session_id );
	}
	add_action( 'wp_ajax_edlk_acquire', 'edlk_ajax_acquire' );
}

// AJAX: 明示的な解放（編集離脱時に sendBeacon で呼ぶ）.
if ( ! function_exists( 'edlk_ajax_release' ) ) {
	/**
	 * AJAX handler that releases a lock explicitly (called via sendBeacon on unload).
	 *
	 * @return void
	 */
	function edlk_ajax_release() {
		check_ajax_referer( 'edlk_nonce', 'nonce' );

		$post_id    = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$session_id = isset( $_POST['session_id'] ) ? edlk_sanitize_session_id( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( $post_id && '' !== $session_id && current_user_can( 'edit_post', $post_id ) ) {
			Etbs_Ecg_Lock_Manager::release( $post_id, $session_id );
		}
		wp_send_json_success();
	}
	add_action( 'wp_ajax_edlk_release', 'edlk_ajax_release' );
}

if ( ! function_exists( 'edlk_get_lock_status_payload' ) ) {
	/**
	 * Builds the payload that describes the lock status to the requesting client.
	 * 要求元のクライアントへ返すロック状態のペイロードを組み立てる。
	 *
	 * holderIsSelf is decided here on the server so that the wording never depends on the client
	 * comparing user IDs. It says only that the account matches, not that it is the same person.
	 * holderIsSelf はサーバ側で決める。文言がクライアント側の user ID 比較に依存しないようにするため。
	 * 言えるのはアカウントの一致までで、同一人物であることではない。
	 *
	 * @param array|null $status     Lock status row from Etbs_Ecg_Lock_Manager, or null if unlocked.
	 * @param string     $session_id Session ID of the requesting client.
	 * @return array Payload for wp_send_json_success().
	 */
	function edlk_get_lock_status_payload( $status, $session_id ) {
		if ( ! $status || hash_equals( $status['session_id'], (string) $session_id ) ) {
			return array( 'locked' => false );
		}

		$holder = edlk_describe_holder( $status );
		return array(
			'locked'       => true,
			'holderName'   => $holder['name'],
			'holderIsSelf' => $holder['is_self'],
			'expiresAt'    => $status['expires_at'],
		);
	}
}

if ( ! function_exists( 'edlk_send_lock_status' ) ) {
	/**
	 * Sends the AJAX JSON response describing the current lock status.
	 *
	 * @param array|null $status     Lock status row from Etbs_Ecg_Lock_Manager, or null if unlocked.
	 * @param string     $session_id Session ID of the requesting client.
	 * @return void
	 */
	function edlk_send_lock_status( $status, $session_id ) {
		wp_send_json_success( edlk_get_lock_status_payload( $status, $session_id ) );
	}
}

// Heartbeat連携: 編集画面が開いている間、TTLを自動延長.
if ( ! function_exists( 'edlk_heartbeat_received' ) ) {
	/**
	 * Filters the Heartbeat API response to renew or report a lost lock.
	 *
	 * "Lost" is reported only when another session now holds the lock. When the row has simply
	 * expired or was force-released there is nothing to report (status() returns null), and that is
	 * intended: no other screen took over, so the notice's wording ("another edit screen has taken
	 * over") would be false, and the next save re-acquires the lock on its own.
	 * 「失った」を返すのは、別のセッションが現にロックを握っているときだけ。行が期限切れで消えた場合や
	 * 強制解除された場合は返すものが無く（status() が null）、それでよい。別の画面が奪ったわけではないので
	 * 通知の文言（「別の編集画面が取得しました」）が偽になるうえ、次の保存で自動的に取り直されるため。
	 *
	 * @param array $response Heartbeat response data.
	 * @param array $data     Heartbeat request data sent by the client.
	 * @return array Modified Heartbeat response data.
	 */
	function edlk_heartbeat_received( $response, $data ) {
		if ( empty( $data['edlk'] ) || ! is_array( $data['edlk'] ) ) {
			return $response;
		}

		$post_id    = (int) ( $data['edlk']['post_id'] ?? 0 );
		$session_id = edlk_sanitize_session_id( $data['edlk']['session_id'] ?? '' );

		if ( $post_id && '' !== $session_id && current_user_can( 'edit_post', $post_id ) ) {
			$renewed = Etbs_Ecg_Lock_Manager::renew( $post_id, $session_id, edlk_get_ttl() );
			if ( ! $renewed ) {
				// 既に他セッションに奪われている場合は、その保持者情報を返す.
				$status = Etbs_Ecg_Lock_Manager::status( $post_id );
				if ( $status ) {
					$holder           = edlk_describe_holder( $status );
					$response['edlk'] = array(
						'lost'         => true,
						'holderName'   => $holder['name'],
						'holderIsSelf' => $holder['is_self'],
					);
				}
			}
		}
		return $response;
	}
	add_filter( 'heartbeat_received', 'edlk_heartbeat_received', 10, 2 );
}

/*
 * 非REST経路（クラシックエディタのフル保存・クイック編集・一括編集）の実効ゲート
 * wp_insert_post() が既存投稿を更新する直前に必ず発火する pre_post_update を使う。
 * （admin_action_editpost はWordPressコアの post.php では実際には発火しないため使えない。
 *   post.php の case 'editpost' は edit_post() を直接呼ぶだけで do_action() を経由しない）
 * REST経由の更新も内部的に wp_insert_post() を呼ぶためここを通過するが、REST側は
 * rest_pre_insert_gate() で既に判定済み（$_POSTにセッションIDが乗らないため二重判定を避ける）。
 */
if ( ! function_exists( 'edlk_pre_post_update_gate' ) ) {
	/**
	 * Blocks a non-REST save when another session holds the lock on the post.
	 *
	 * @param int   $post_id ID of the post being updated.
	 * @param array $data    Sanitized post data about to be saved.
	 * @return void
	 */
	function edlk_pre_post_update_gate( $post_id, $data ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		/*
		 * Autosave is not the end of an editing session, and WordPress core already keeps another
		 * user's autosave away from the parent post (it stores a per-user revision instead), so
		 * there is nothing for this gate to protect here. Blocking it only breaks the author's own
		 * autosave, silently: wp_autosave() does not send the session id, so the gate cannot tell
		 * the lock holder's own tab from anyone else's.
		 * 自動保存は「編集の終了」ではなく、他ユーザーの自動保存はコア側が親投稿に触れさせない
		 * （ユーザーごとのリビジョンに回す）ため、このゲートで守るものが無い。止めると本人の自動保存を
		 * 黙って壊すだけになる（wp_autosave() はセッション ID を送らないため、保持者本人のタブを見分けられない）。
		 */
		if ( edlk_is_autosave_request() ) {
			return;
		}

		/*
		 * This gate's only way to stop a save is wp_die(), so it must not run where no human-readable
		 * screen can be returned. What actually does the work is ! is_admin(): neither wp-cron.php nor
		 * WP-CLI defines WP_ADMIN. The other three are written to state the intent.
		 * このゲートの中断手段は wp_die() しか無いため、人が読める画面を返せない経路では止めない。
		 * 実際に効いているのは ! is_admin()（cron も WP-CLI も WP_ADMIN を定義しない）。
		 * 残る3つは意図を明示するために書いている。
		 *
		 * What happens if it stops there: for a post type that is updated from the front end through
		 * wp_update_post() (a member form, a legacy WooCommerce order -- every post type with show_ui
		 * is covered by default), a visitor's page would die with a 409 wp_die() just because someone
		 * has that post open in the admin. Those paths have neither a session id nor a way to retry.
		 * 止めると何が起きるか: フロントから wp_update_post() を呼ぶ投稿タイプ
		 * （会員フォーム・WooCommerce のレガシー注文など、show_ui な投稿タイプは既定で全て対象）で、
		 * 管理画面の誰かがその投稿を開いているだけで訪問者の画面が 409 の wp_die() で落ちる。
		 * これらの経路にはセッション ID も、やり直しの導線も無い。
		 *
		 * The trade-off: on those paths the lock no longer holds. Bulk edit is still stopped as before.
		 * トレードオフ: これらの経路ではロックが効かなくなる。一括編集は従来どおり中断する。
		 */
		if ( ! is_admin()
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ! is_user_logged_in()
		) {
			return;
		}

		if ( ! edlk_is_post_type_enabled( get_post_type( $post_id ) ) ) {
			return;
		}

		// WordPressコア（post.php）が投稿保存の直前に既にnonce検証を済ませているため、ここでの再検証は不要.
		$session_id = isset( $_POST['edlk_session_id'] ) ? edlk_sanitize_session_id( wp_unslash( $_POST['edlk_session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		edlk_current_session_id( $session_id );

		if ( '' !== $session_id && Etbs_Ecg_Lock_Manager::is_holder( $post_id, $session_id ) ) {
			return;
		}

		$status = Etbs_Ecg_Lock_Manager::status( $post_id );
		if ( ! $status ) {
			return; // ロックが存在しない＝競合なし.
		}

		$message = esc_html( edlk_get_save_blocked_message( $status ) );
		if ( ! wp_doing_ajax() ) {
			$message = '<p>' . $message . '</p>';
			if ( isset( $_REQUEST['bulk_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// A bulk edit stops midway, so name the post it stopped at. The "go back to the edit screen" advice does not fit there.
				// 一括編集は途中で止まるため、止まった投稿を示す。「編集画面に戻って」という案内はここには合わない.
				$message .= '<p>' . esc_html(
					sprintf(
						/* translators: 1: title of the post the bulk edit stopped at, 2: ID of that post */
						__( 'Processing stopped at this post: %1$s (ID: %2$d)', 'etbs-edit-conflict-guard' ),
						get_the_title( $post_id ),
						$post_id
					)
				) . '</p>';
			} else {
				$message .= '<p>' . esc_html__( 'Use your browser\'s Back button to return to the edit screen, then save again.', 'etbs-edit-conflict-guard' ) . '</p>';
			}
		}

		wp_die(
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
			esc_html__( 'Save Blocked', 'etbs-edit-conflict-guard' ),
			array(
				'response'  => 409,
				'back_link' => true,
			)
		);
	}
	add_action( 'pre_post_update', 'edlk_pre_post_update_gate', 1, 2 );
}

// Gutenberg(REST): 保存リクエストの実効ゲート.
if ( ! function_exists( 'edlk_register_rest_gates' ) ) {
	/**
	 * Registers the REST pre-insert lock gate for every locked post type.
	 *
	 * @return void
	 */
	function edlk_register_rest_gates() {
		foreach ( edlk_get_editable_post_types() as $post_type => $obj ) {
			if ( ! edlk_is_post_type_enabled( $post_type ) ) {
				continue;
			}
			add_filter( "rest_pre_insert_{$post_type}", 'edlk_rest_pre_insert_gate', 10, 2 );
		}
	}
	add_action( 'rest_api_init', 'edlk_register_rest_gates' );
}

if ( ! function_exists( 'edlk_rest_pre_insert_gate' ) ) {
	/**
	 * Blocks a REST save when another session holds the lock on the post.
	 *
	 * @param stdClass        $prepared_post Post data prepared for insertion/update.
	 * @param WP_REST_Request $request       Current REST request.
	 * @return stdClass|WP_Error Unmodified post data, or a WP_Error when the save is blocked.
	 */
	function edlk_rest_pre_insert_gate( $prepared_post, $request ) {
		/*
		 * Never return a WP_Error on the autosave route. WP_REST_Autosaves_Controller::create_item()
		 * does not run is_wp_error() on the result of prepare_item_for_database(), so a WP_Error is
		 * cast to an array and passed to wp_update_post(). Nothing saved is destroyed (wp_update_post()
		 * merges over the existing row and the cast array carries no post fields), but the autosave writes
		 * nothing while reporting 200, save_post still fires, and this plugin's lock is released.
		 * 自動保存ルートでは WP_Error を返さない。コアの WP_REST_Autosaves_Controller::create_item() は
		 * prepare_item_for_database() の戻り値を is_wp_error() で見ないため、WP_Error が配列にキャストされて
		 * wp_update_post() に渡る。保存済みの内容は壊れない（wp_update_post() は既存の行にマージし、
		 * キャストした配列に投稿フィールドが無いため）が、自動保存は 200 を返しながら何も書かず、
		 * save_post だけが発火してこのプラグインのロックが解放される。
		 */
		if ( edlk_is_autosave_request( $request ) ) {
			return $prepared_post;
		}

		$post_id = (int) ( $prepared_post->ID ?? 0 );
		if ( ! $post_id ) {
			return $prepared_post; // 新規作成はロック対象外.
		}

		// Not used to release on a REST save any more (see edlk_release_after_save()), but kept so that
		// restoring that branch later does not break silently.
		// REST の保存では解放に使わなくなったが（edlk_release_after_save() 参照）、
		// 将来その分岐を戻したときに黙って壊れないよう呼び出しは残す.
		$session_id = edlk_sanitize_session_id( (string) $request->get_header( 'x_edlk_session' ) );
		edlk_current_session_id( $session_id );

		if ( '' !== $session_id && Etbs_Ecg_Lock_Manager::is_holder( $post_id, $session_id ) ) {
			return $prepared_post;
		}

		$status = Etbs_Ecg_Lock_Manager::status( $post_id );
		if ( ! $status ) {
			return $prepared_post;
		}

		return new WP_Error(
			'edlk_locked',
			edlk_get_save_blocked_message( $status ) . ' ' . __( 'What you have entered is still on this screen.', 'etbs-edit-conflict-guard' ),
			array( 'status' => 409 )
		);
	}
}

// 保存成功後、自分が保持していたロックを解放する（クラシックのフル保存のみ）.
if ( ! function_exists( 'edlk_release_after_save' ) ) {
	/**
	 * Releases the lock held by the current request's session after a classic full save.
	 *
	 * @param int $post_id ID of the saved post.
	 * @return void
	 */
	function edlk_release_after_save( $post_id ) {
		// Autosaves and revisions are not the end of an editing session, so the lock is kept.
		// 自動保存とリビジョンは「編集の終了」ではないため、ロックは保持したままにする。
		// この除外を外すと、ゲートを素通しさせた自動保存がロックを消す（検証B の再発）。
		if ( edlk_is_autosave_request() ) {
			return;
		}
		// 保険。save_post はリビジョンでも撃たれるが $post_id はリビジョンの ID なので
		// release() は何も消さない。空ガードと読まれないよう意図を残す。
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		/*
		 * Do not release on a REST (block editor) save: the tab stays open and editing continues,
		 * and Heartbeat keeps extending the TTL. beforeunload's sendBeacon and the TTL do the
		 * releasing. Releasing here leaves the post unprotected from the moment it is saved.
		 * Re-acquiring from the client instead would open a window between release and acquire.
		 * REST（ブロックエディタ）の明示保存では解放しない。保存してもタブは開いたままで編集が続き、
		 * Heartbeat が TTL を延長し続ける。解放は離脱時の sendBeacon と TTL が受け持つ。
		 * ここで解放すると「保存した瞬間から無保護」になる（検証C）。クライアントから取り直す案は、
		 * 解放と再取得の間に他セッションが割り込める窓を作るので採らない。
		 * 解放してよいのはクラシックのフル保存だけで、そちらは保存後にページが遷移する。
		 */
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$session_id = edlk_current_session_id();
		if ( '' !== $session_id ) {
			Etbs_Ecg_Lock_Manager::release( $post_id, $session_id );
		}
	}
	add_action( 'save_post', 'edlk_release_after_save' );
}

/*
 * ゴミ箱移動ガード（オプション、デフォルトOFF）
 * ロック保持者を問わず（本人の別タブでも）ブロックする。保存ガードと同じ厳格方針。
 * 完全削除（force=true）は対象外。一括操作で一部がロック中だった場合、その時点で
 * wp_die()し以降の投稿は未処理のまま中断される（既知の制限。README参照）。
 */
if ( ! function_exists( 'edlk_pre_trash_post_gate' ) ) {
	/**
	 * Blocks moving a locked post to trash when the trash guard option is enabled.
	 *
	 * @param bool|null $check           Short-circuit value from another filter, or null.
	 * @param WP_Post   $post            Post being moved to trash.
	 * @param string    $previous_status Post status before this trash action.
	 * @return bool|null Unmodified short-circuit value.
	 */
	function edlk_pre_trash_post_gate( $check, $post, $previous_status ) {
		if ( null !== $check ) {
			return $check;
		}
		if ( ! edlk_is_trash_guard_enabled() ) {
			return $check;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $check; // REST側は rest_pre_dispatch で処理.
		}
		if ( ! edlk_is_post_type_enabled( $post->post_type ) ) {
			return $check;
		}

		$status = Etbs_Ecg_Lock_Manager::status( $post->ID );
		if ( ! $status ) {
			return $check;
		}

		wp_die(
			esc_html( edlk_get_trash_blocked_message( $status ) ),
			esc_html__( 'Move to Trash Blocked', 'etbs-edit-conflict-guard' ),
			array(
				'response'  => 409,
				'back_link' => true,
			)
		);
	}
	add_filter( 'pre_trash_post', 'edlk_pre_trash_post_gate', 10, 3 );
}

/*
 * ゴミ箱移動ガード（REST/Gutenberg側）
 * wp_trash_post()内のpre_trash_postフィルタはWP_Rest_Posts_Controller::delete_item()で
 * is_wp_error()判定されず、WP_Errorを返しても真偽値的にtruthyなためすり抜けて
 * 「成功扱い（実際は未trash）」になってしまう。そのため rest_pre_dispatch で
 * ディスパッチ自体を横取りする。rest_pre_dispatch はルート解決前に発火するため
 * $request->get_url_params() は使えず、ルート文字列を自前でパースする。
 */
if ( ! function_exists( 'edlk_rest_pre_dispatch_trash_gate' ) ) {
	/**
	 * Blocks a REST DELETE (trash) request when the target post is locked.
	 *
	 * @param mixed           $result Response to replace the requested REST response, or null.
	 * @param WP_REST_Server  $server Server instance.
	 * @param WP_REST_Request $request Current REST request.
	 * @return mixed Unmodified $result, or a WP_Error when the trash action is blocked.
	 */
	function edlk_rest_pre_dispatch_trash_gate( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! edlk_is_trash_guard_enabled() ) {
			return $result;
		}
		if ( 'DELETE' !== $request->get_method() ) {
			return $result;
		}
		if ( $request->get_param( 'force' ) ) {
			return $result; // 完全削除は対象外.
		}

		static $route_map = null;
		if ( null === $route_map ) {
			$route_map = array();
			foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $pt ) {
				$base      = ! empty( $pt->rest_base ) ? $pt->rest_base : $pt->name;
				$namespace = ! empty( $pt->rest_namespace ) ? trim( $pt->rest_namespace, '/' ) : 'wp/v2';
				$route_map[ $namespace . '/' . $base ] = $pt->name;
			}
		}

		if ( ! preg_match( '#^/(.+)/(\d+)$#', $request->get_route(), $m ) ) {
			return $result;
		}
		if ( ! isset( $route_map[ $m[1] ] ) ) {
			return $result;
		}

		$post_type = $route_map[ $m[1] ];
		if ( ! edlk_is_post_type_enabled( $post_type ) ) {
			return $result;
		}

		$post_id = (int) $m[2];
		if ( get_post_type( $post_id ) !== $post_type ) {
			return $result;
		}

		$status = Etbs_Ecg_Lock_Manager::status( $post_id );
		if ( ! $status ) {
			return $result;
		}

		return new WP_Error(
			'edlk_locked',
			edlk_get_trash_blocked_message( $status ),
			array( 'status' => 409 )
		);
	}
	add_filter( 'rest_pre_dispatch', 'edlk_rest_pre_dispatch_trash_gate', 10, 3 );
}

/*
 * Core's "take over" button in the classic post-locked dialog.
 * クラシックエディタのコア「引き継ぐ」ボタン。
 *
 * Taking over moves only core's own _edit_lock; this plugin's lock does not follow. The person who
 * clicked "take over" then cannot save, and nothing on the screen says why. So the button is removed
 * while a lock of this plugin exists for another account, and one line says who protects the post.
 * When core's lock has expired and this plugin has no lock either, the condition is false and the
 * button comes back, so the two locks agree.
 * 引き継いでも動くのはコアの _edit_lock だけで、このプラグインのロックは移らない。引き継いだ人は
 * 保存できなくなり、その理由は画面のどこにも書かれない。そこで、別アカウントのロックがこのプラグインに
 * ある間はボタンを消し、誰が守っているかを1行で示す。コアのロックが切れ、このプラグインのロックも
 * 無いときは条件が外れてボタンが戻るので、2つのロックの判断は一致する。
 *
 * This works in the classic editor only. The block editor's post-locked modal is drawn by React from
 * settings that core builds without consulting either hook (edit-form-blocks.php honours only
 * show_post_locked_dialog), so its "Take over" button cannot be removed from PHP.
 * これが効くのはクラシックエディタだけ。ブロックエディタのロックモーダルは React が描き、その設定を
 * 作るコア側はどちらのフックも見ない（edit-form-blocks.php が見るのは show_post_locked_dialog だけ）ため、
 * 「引き継ぐ」ボタンは PHP からは消せない。
 *
 * Only the account is compared: at render time the server cannot know which tab (session) is asking.
 * 比較できるのはアカウントだけ。描画時点ではサーバはどのタブ（セッション）からの要求か分からない。
 */
if ( ! function_exists( 'edlk_filter_override_post_lock' ) ) {
	/**
	 * Removes core's "take over" button while another account holds a lock of this plugin.
	 *
	 * @param bool    $override Whether to allow the post lock to be overridden.
	 * @param WP_Post $post     Post object.
	 * @return bool False while another account holds this plugin's lock, otherwise unchanged.
	 */
	function edlk_filter_override_post_lock( $override, $post ) {
		if ( ! $override || ! ( $post instanceof WP_Post ) || ! edlk_is_post_type_enabled( $post->post_type ) ) {
			return $override;
		}
		return edlk_get_foreign_lock_status( $post->ID ) ? false : $override;
	}
	add_filter( 'override_post_lock', 'edlk_filter_override_post_lock', 10, 2 );
}

if ( ! function_exists( 'edlk_render_post_locked_dialog_notice' ) ) {
	/**
	 * Adds one line to core's post-locked dialog saying that this plugin protects the post.
	 *
	 * @param WP_Post $post Post object.
	 * @param WP_User $user The user with core's lock for the post.
	 * @return void
	 */
	function edlk_render_post_locked_dialog_notice( $post, $user ) {
		if ( ! ( $post instanceof WP_Post ) || ! edlk_is_post_type_enabled( $post->post_type ) ) {
			return;
		}
		$status = edlk_get_foreign_lock_status( $post->ID );
		if ( ! $status ) {
			return;
		}

		// Name the holder of this plugin's lock; if that account is gone, fall back to core's lock holder shown above.
		// このプラグインのロック保持者を示す。そのアカウントが無ければ、直上に出ているコアのロック保持者にする.
		$holder = edlk_describe_holder( $status );
		$name   = '' !== $holder['name'] ? $holder['name'] : ( $user instanceof WP_User ? $user->display_name : '' );
		?>
		<p class="edlk-post-locked">
			<?php esc_html_e( 'Edit Conflict Guard is protecting this post.', 'etbs-edit-conflict-guard' ); ?>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: name of the user holding the lock */
					__( 'You cannot save until %s closes the edit screen.', 'etbs-edit-conflict-guard' ),
					$name
				)
			);
			?>
		</p>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<p class="edlk-post-locked">
				<?php esc_html_e( 'Administrators can force-release the lock.', 'etbs-edit-conflict-guard' ); ?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=edlk-settings' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the Edit Conflict Guard settings', 'etbs-edit-conflict-guard' ); ?></a>
				<?php esc_html_e( '(opens in a new tab)', 'etbs-edit-conflict-guard' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}
	add_action( 'post_locked_dialog', 'edlk_render_post_locked_dialog_notice', 10, 2 );
}

// 開発依頼リンク（プラグイン一覧行）.
if ( ! function_exists( 'edlk_plugin_row_meta' ) ) {
	/**
	 * Adds a "Request development" link to this plugin's row on the Plugins list screen.
	 *
	 * @param string[] $links Existing row meta links.
	 * @param string   $file  Plugin file for the current row.
	 * @return string[] Modified row meta links.
	 */
	function edlk_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( ETBS_ECG_PLUGIN_FILE ) !== $file ) {
			return $links;
		}
		$links[] = '<a href="https://etbs.jp/product-category/wordpress-tools/?utm_source=etbs-edit-conflict-guard&utm_medium=plugin" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Request development', 'etbs-edit-conflict-guard' ) . '</a>';
		return $links;
	}
	add_filter( 'plugin_row_meta', 'edlk_plugin_row_meta', 10, 2 );
}

// 寄付・開発依頼リンク（Edit Conflict Guard 設定画面のフッター）.
if ( ! function_exists( 'edlk_admin_footer_text' ) ) {
	/**
	 * Replaces the admin footer text on the Edit Conflict Guard settings screen with support links.
	 *
	 * @param string $text Default admin footer text.
	 * @return string Modified admin footer text.
	 */
	function edlk_admin_footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_edlk-settings' !== $screen->id ) {
			return $text;
		}

		$donate_link  = '<a href="https://etbs.jp/product/donate/?utm_source=etbs-edit-conflict-guard&utm_medium=plugin" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'consider supporting its development', 'etbs-edit-conflict-guard' ) . '</a>';
		$request_link = '<a href="https://etbs.jp/product-category/wordpress-tools/?utm_source=etbs-edit-conflict-guard&utm_medium=plugin" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'submit a request', 'etbs-edit-conflict-guard' ) . '</a>';

		return sprintf(
			/* translators: %s: link inviting the reader to support development */
			__( 'If Edit Conflict Guard has been useful to you, please %s.', 'etbs-edit-conflict-guard' ),
			$donate_link
		) . ' ' . sprintf(
			/* translators: %s: link for requesting custom development */
			__( 'For custom development, please %s.', 'etbs-edit-conflict-guard' ),
			$request_link
		);
	}
	add_filter( 'admin_footer_text', 'edlk_admin_footer_text' );
}
