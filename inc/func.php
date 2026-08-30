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
require_once __DIR__ . '/class-edlk-lock-manager.php';
require_once __DIR__ . '/class-edlk-settings-page.php';

/*
 * Translations are delivered by translate.wordpress.org and land in WP_LANG_DIR/plugins,
 * where WordPress loads them just in time. No translation files are bundled with this
 * plugin, so load_plugin_textdomain() and the Domain Path header are unnecessary here.
 *
 * 翻訳は translate.wordpress.org から WP_LANG_DIR/plugins に届き、WordPress が
 * just-in-time で読み込む。同梱の翻訳ファイルは持たないため、load_plugin_textdomain()
 * と Domain Path ヘッダはこのプラグインには不要。
 */

// 有効化・無効化.
if ( ! function_exists( 'edlk_activate' ) ) {
	/**
	 * Runs on plugin activation: creates the lock table and schedules the cleanup cron.
	 *
	 * @return void
	 */
	function edlk_activate() {
		Edlk_Lock_Manager::create_table();
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
		Edlk_Lock_Manager::cleanup_expired();
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

		wp_enqueue_script(
			'edlk-editor',
			plugins_url( 'js/edlk-editor.js', __FILE__ ),
			array( 'jquery', 'heartbeat', 'wp-data', 'wp-api-fetch' ),
			EDLK_VERSION,
			true
		);

		wp_localize_script(
			'edlk-editor',
			'EdlkData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'edlk_nonce' ),
				'postId'     => (int) $post->ID,
				'currentUid' => get_current_user_id(),
				'i18n'       => array(
					'lockedTitle'   => __( 'Another user is editing', 'etbs-edit-conflict-guard' ),
					/* translators: %s: name of the user holding the lock (this string is used as a JS template). */
					'lockedBody'    => __( '%s is currently editing this post, so you cannot save. Please wait until they are finished.', 'etbs-edit-conflict-guard' ),
					'lockedUnknown' => __( 'Another user', 'etbs-edit-conflict-guard' ),
					'closeButton'   => __( 'Close', 'etbs-edit-conflict-guard' ),
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

		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( '' === $session_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session ID.', 'etbs-edit-conflict-guard' ) ) );
		}

		$status = Edlk_Lock_Manager::acquire( $post_id, $session_id, get_current_user_id(), edlk_get_ttl() );
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
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		if ( $post_id && '' !== $session_id && current_user_can( 'edit_post', $post_id ) ) {
			Edlk_Lock_Manager::release( $post_id, $session_id );
		}
		wp_send_json_success();
	}
	add_action( 'wp_ajax_edlk_release', 'edlk_ajax_release' );
}

if ( ! function_exists( 'edlk_send_lock_status' ) ) {
	/**
	 * Sends the AJAX JSON response describing the current lock status.
	 *
	 * @param array|null $status     Lock status row from Edlk_Lock_Manager, or null if unlocked.
	 * @param string     $session_id Session ID of the requesting client.
	 * @return void
	 */
	function edlk_send_lock_status( $status, $session_id ) {
		if ( ! $status || hash_equals( $status['session_id'], (string) $session_id ) ) {
			wp_send_json_success( array( 'locked' => false ) );
		}

		$user = get_userdata( (int) $status['user_id'] );
		wp_send_json_success(
			array(
				'locked'     => true,
				'holderName' => $user ? $user->display_name : '',
				'expiresAt'  => $status['expires_at'],
			)
		);
	}
}

// Heartbeat連携: 編集画面が開いている間、TTLを自動延長.
if ( ! function_exists( 'edlk_heartbeat_received' ) ) {
	/**
	 * Filters the Heartbeat API response to renew or report a lost lock.
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
		$session_id = sanitize_text_field( $data['edlk']['session_id'] ?? '' );

		if ( $post_id && '' !== $session_id && current_user_can( 'edit_post', $post_id ) ) {
			$renewed = Edlk_Lock_Manager::renew( $post_id, $session_id, edlk_get_ttl() );
			if ( ! $renewed ) {
				// 既に他セッションに奪われている場合は、その保持者情報を返す.
				$status = Edlk_Lock_Manager::status( $post_id );
				if ( $status ) {
					$user             = get_userdata( (int) $status['user_id'] );
					$response['edlk'] = array(
						'lost'       => true,
						'holderName' => $user ? $user->display_name : '',
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
		if ( ! edlk_is_post_type_enabled( get_post_type( $post_id ) ) ) {
			return;
		}

		// WordPressコア（post.php）が投稿保存の直前に既にnonce検証を済ませているため、ここでの再検証は不要.
		$session_id = isset( $_POST['edlk_session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['edlk_session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		edlk_current_session_id( $session_id );

		if ( '' !== $session_id && Edlk_Lock_Manager::is_holder( $post_id, $session_id ) ) {
			return;
		}

		$status = Edlk_Lock_Manager::status( $post_id );
		if ( ! $status ) {
			return; // ロックが存在しない＝競合なし.
		}

		$user = get_userdata( (int) $status['user_id'] );
		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: ロックを保持しているユーザー名 */
					__( '%s is currently editing this post, so it could not be saved. Please reload the page and try again.', 'etbs-edit-conflict-guard' ),
					$user ? $user->display_name : __( 'Another user', 'etbs-edit-conflict-guard' )
				)
			),
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
		$post_id = (int) ( $prepared_post->ID ?? 0 );
		if ( ! $post_id ) {
			return $prepared_post; // 新規作成はロック対象外.
		}

		$session_id = sanitize_text_field( (string) $request->get_header( 'x_edlk_session' ) );
		edlk_current_session_id( $session_id );

		if ( '' !== $session_id && Edlk_Lock_Manager::is_holder( $post_id, $session_id ) ) {
			return $prepared_post;
		}

		$status = Edlk_Lock_Manager::status( $post_id );
		if ( ! $status ) {
			return $prepared_post;
		}

		$user = get_userdata( (int) $status['user_id'] );
		return new WP_Error(
			'edlk_locked',
			sprintf(
				/* translators: %s: ロックを保持しているユーザー名 */
				__( '%s is currently editing this post, so it could not be saved.', 'etbs-edit-conflict-guard' ),
				$user ? $user->display_name : __( 'Another user', 'etbs-edit-conflict-guard' )
			),
			array( 'status' => 409 )
		);
	}
}

// 保存成功後、自分が保持していたロックを解放する.
if ( ! function_exists( 'edlk_release_after_save' ) ) {
	/**
	 * Releases the lock held by the current request's session after a successful save.
	 *
	 * @param int $post_id ID of the saved post.
	 * @return void
	 */
	function edlk_release_after_save( $post_id ) {
		$session_id = edlk_current_session_id();
		if ( '' !== $session_id ) {
			Edlk_Lock_Manager::release( $post_id, $session_id );
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

		$status = Edlk_Lock_Manager::status( $post->ID );
		if ( ! $status ) {
			return $check;
		}

		$user = get_userdata( (int) $status['user_id'] );
		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: ロックを保持しているユーザー名 */
					__( '%s is currently editing this post, so it could not be moved to trash.', 'etbs-edit-conflict-guard' ),
					$user ? $user->display_name : __( 'Another user', 'etbs-edit-conflict-guard' )
				)
			),
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

		$status = Edlk_Lock_Manager::status( $post_id );
		if ( ! $status ) {
			return $result;
		}

		$user = get_userdata( (int) $status['user_id'] );
		return new WP_Error(
			'edlk_locked',
			sprintf(
				/* translators: %s: ロックを保持しているユーザー名 */
				__( '%s is currently editing this post, so it could not be moved to trash.', 'etbs-edit-conflict-guard' ),
				$user ? $user->display_name : __( 'Another user', 'etbs-edit-conflict-guard' )
			),
			array( 'status' => 409 )
		);
	}
	add_filter( 'rest_pre_dispatch', 'edlk_rest_pre_dispatch_trash_gate', 10, 3 );
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
		if ( plugin_basename( EDLK_PLUGIN_FILE ) !== $file ) {
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
			/* translators: %s: 開発支援リンク */
			__( 'If Edit Conflict Guard has been useful to you, please %s.', 'etbs-edit-conflict-guard' ),
			$donate_link
		) . ' ' . sprintf(
			/* translators: %s: 開発依頼リンク */
			__( 'For custom development, please %s.', 'etbs-edit-conflict-guard' ),
			$request_link
		);
	}
	add_filter( 'admin_footer_text', 'edlk_admin_footer_text' );
}
