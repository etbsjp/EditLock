<?php
/**
 * Tests that run a real save through WordPress, so that the hook wiring is exercised.
 * 本物の保存を WordPress に通し、フックの配線ごと確かめるテスト。
 *
 * The other test files call the gate functions directly. That proves what each function decides,
 * but not that WordPress ever calls it: deleting `add_action( 'pre_post_update', ... )` or
 * `add_action( 'save_post', ... )` leaves all of them green. The tests here go through core's
 * edit_post() (the function wp-admin/post.php runs for a classic save and for the block editor's
 * meta box save) and through rest_get_server()->dispatch() (the block editor's post save).
 * 他のテストファイルはゲート関数を直接呼ぶ。それは各関数の判定を確かめるが、WordPress がその関数を
 * 呼ぶことまでは確かめない。`add_action( 'pre_post_update', ... )` や `add_action( 'save_post', ... )`
 * を消しても、それらは全部緑のまま。ここでは、コアの edit_post()（wp-admin/post.php が、クラシックの
 * 保存とブロックエディタのメタボックス保存で走らせる関数）と、rest_get_server()->dispatch()
 * （ブロックエディタの投稿本体の保存）を通す。
 *
 * @package etbs-edit-conflict-guard
 */

/**
 * Covers the wiring of the save gate and of the release after a save.
 * 保存ゲートと保存後の解放の配線を確かめる。
 */
class Test_Etbs_Ecg_Hook_Wiring extends Etbs_Ecg_Test_Case {

	/**
	 * The current user (the one saving).
	 * 現在のユーザー（保存する側）。
	 *
	 * @var int
	 */
	private $me;

	/**
	 * Another user, whose display name is known.
	 * 別のユーザー（表示名が分かる）。
	 *
	 * @var int
	 */
	private $other;

	/**
	 * An account that has been deleted, while its lock row is still in the table.
	 * 削除済みのアカウント。ロック行だけがテーブルに残っている。
	 *
	 * @var int
	 */
	private $gone;

	/**
	 * Whether the probe hook added per case in test_edit_post() (just after the save gate's priority) has been reached.
	 * test_edit_post() がケースごとに保存ゲートの直後の優先度へ置く探針フックが走ったかどうか。
	 *
	 * @var bool
	 */
	private $probe_after_gate_ran = false;

	/**
	 * Creates the users, includes core's admin functions and registers a stand-in for a meta box.
	 * ユーザーを作り、コアの管理画面用関数を読み込み、メタボックスの代役を登録する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// edit_post() lives in wp-admin/includes/post.php, which is not loaded outside the admin.
		// edit_post() は wp-admin/includes/post.php にあり、管理画面の外では読み込まれない.
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->me    = $this->factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Me Myself',
			)
		);
		$this->other = $this->factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Alice Example',
			)
		);
		$this->gone  = $this->factory()->user->create( array( 'role' => 'editor' ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->gone );
		wp_set_current_user( $this->me );

		// A real meta box writes its value from save_post. This stands in for it. The "Custom Fields"
		// panel cannot: core saves that one before pre_post_update, so the gate cannot stop it.
		// 本物のメタボックスは save_post で値を保存する。これはその代役。「カスタムフィールド」パネルでは
		// 代用できない（コアは pre_post_update より前にそれを保存するので、ゲートでは止められない）.
		add_action( 'save_post', array( $this, 'save_stand_in_meta_box' ) );
	}

	/**
	 * Clears what the tests put into the request.
	 * テストがリクエストに入れたものを消す。
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_POST['ecg_test_box'], $_POST['post_ID'] );
		parent::tear_down();
	}

	/**
	 * Saves the stand-in meta box's field, the way a meta box plugin does on save_post.
	 * メタボックス用プラグインが save_post でやるのと同じ形で、代役のメタボックスの欄を保存する。
	 *
	 * @param int $post_id ID of the saved post.
	 * @return void
	 */
	public function save_stand_in_meta_box( $post_id ) {
		if ( isset( $_POST['ecg_test_box'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- A test stand-in.
			update_post_meta( $post_id, 'ecg_test_box', sanitize_text_field( wp_unslash( $_POST['ecg_test_box'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * Records that a callback placed just after the save gate was reached.
	 * 保存ゲートの直後に置いた探針が走ったことを記録する。
	 *
	 * @return void
	 */
	public function mark_probe_after_gate() {
		$this->probe_after_gate_ran = true;
	}

	/**
	 * Turns a holder label into a user ID.
	 * 保持者のラベルをユーザー ID に変える。
	 *
	 * @param string $holder 'self', 'other' or 'gone'.
	 * @return int User ID.
	 */
	private function holder_id( $holder ) {
		$map = array(
			'self'  => $this->me,
			'other' => $this->other,
			'gone'  => $this->gone,
		);
		return $map[ $holder ];
	}

	/**
	 * Runs core's edit_post() with the request a form save would send.
	 * フォームの保存が送るリクエストを置いて、コアの edit_post() を走らせる。
	 *
	 * @param int    $post_id    Post ID.
	 * @param bool   $meta_box   Whether the request is the block editor's meta box save.
	 * @param string $session_id Value of the edlk_session_id field ('' leaves the field out).
	 * @return int|null HTTP status when the save gate stopped the save, or null when it went through.
	 */
	private function run_edit_post( $post_id, $meta_box, $session_id ) {
		$this->enter_admin_context();
		unset( $_GET['meta-box-loader'], $_POST['edlk_session_id'] );
		if ( $meta_box ) {
			$_GET['meta-box-loader'] = '1';
		}
		if ( '' !== $session_id ) {
			$_POST['edlk_session_id'] = $session_id;
		}
		$_POST['post_ID']      = $post_id;
		$_POST['ecg_test_box'] = 'saved by save_post';

		try {
			edit_post(
				array(
					'post_ID'      => $post_id,
					'post_type'    => get_post_type( $post_id ),
					'post_status'  => 'draft',
					'post_title'   => 'New title',
					'post_content' => 'New content',
				)
			);
		} catch ( WPDieException $e ) {
			return $e->getCode();
		}
		return null;
	}

	/**
	 * Tests that the hooks are registered, at the priorities the code relies on.
	 *
	 * 保存ゲートが優先度 1 であることに意味がある。コア自身の pre_post_update（リビジョンの保存）より
	 * 前に走るので、止めたときにリビジョンを残さない。
	 *
	 * @return void
	 */
	public function test_hook_registration() {
		$this->assertSame( 1, has_action( 'pre_post_update', 'edlk_pre_post_update_gate' ), '保存ゲートは pre_post_update の優先度 1 に掛かっている' );
		$this->assertSame( 10, has_action( 'save_post', 'edlk_release_after_save' ), '保存後の解放は save_post に掛かっている' );
		$this->assertSame( 10, has_action( 'rest_api_init', 'edlk_register_rest_gates' ), 'REST のゲートは rest_api_init で登録される' );
	}

	/**
	 * Tests edlk_pre_post_update_gate() and edlk_release_after_save() through core's edit_post().
	 *
	 * @return void
	 */
	public function test_edit_post() {
		if ( defined( 'DOING_AUTOSAVE' ) || defined( 'REST_REQUEST' ) ) {
			$this->markTestSkipped( 'DOING_AUTOSAVE or REST_REQUEST is already defined in this process, so every case would pass through.' );
		}

		// 'session' is what the form sends; the stored lock always belongs to session S1.
		// 'session' はフォームが送る値。保存されているロックは常にセッション S1 のもの.
		$test_cases = array(
			array(
				'test_condition_name' => 'クラシックの保存で、ロックを保持している本人のセッションの場合 => 保存され、save_post でロックが解放される（save_post の配線）',
				'meta_box'            => false,
				'lock'                => 'self',
				'session'             => 'S1',
				'expected_blocked'    => false,
				'expected_rows'       => 0,
			),
			array(
				'test_condition_name' => 'クラシックの保存で、ロックが無い場合 => 保存される',
				'meta_box'            => false,
				'lock'                => null,
				'session'             => '',
				'expected_blocked'    => false,
				'expected_rows'       => 0,
			),
			array(
				'test_condition_name' => 'クラシックの保存で、他ユーザーがロック中でセッション ID が無い場合 => 409 で止まり、本文もメタも書かれず、ロックは残る（pre_post_update の配線）',
				'meta_box'            => false,
				'lock'                => 'other',
				'session'             => '',
				'expected_blocked'    => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、ロックを保持している本人のセッションの場合 => 保存され、ロックは解放されない（タブは開いたまま）',
				'meta_box'            => true,
				'lock'                => 'self',
				'session'             => 'S1',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、自分のアカウントのロックでセッション ID が無い場合 => 通る（1.2.0 はここで止め、入力を無言で捨てていた）',
				'meta_box'            => true,
				'lock'                => 'self',
				'session'             => '',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、同じアカウントの別セッション（S2）の ID が付いている場合 => 止まる（保険は ID が無いときだけ。ID があれば ID で厳密に判定する）',
				'meta_box'            => true,
				'lock'                => 'self',
				'session'             => 'S2',
				'expected_blocked'    => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、別アカウントのロックでセッション ID（他人のもの）が付いている場合 => 止まる（保険を「ID があっても同じアカウントなら通す」に緩める変更への柵）',
				'meta_box'            => true,
				'lock'                => 'other',
				'session'             => 'S2',
				'expected_blocked'    => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、別アカウントのロックでセッション ID が無い場合 => 止まる',
				'meta_box'            => true,
				'lock'                => 'other',
				'session'             => '',
				'expected_blocked'    => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、保持者が削除済みのユーザーでセッション ID が無い場合 => 止まる（「自分のアカウント」と見なして通さない）',
				'meta_box'            => true,
				'lock'                => 'gone',
				'session'             => '',
				'expected_blocked'    => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、保持者が削除済みのユーザーで別のセッション ID が付いている場合 => 止まる',
				'meta_box'            => true,
				'lock'                => 'gone',
				'session'             => 'S2',
				'expected_blocked'    => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、ロックが期限切れでセッション ID が付いている場合 => 通る',
				'meta_box'            => true,
				'lock'                => 'other',
				'expired'             => true,
				'session'             => 'S2',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、ロックが期限切れでセッション ID が無い場合 => 通る',
				'meta_box'            => true,
				'lock'                => 'other',
				'expired'             => true,
				'session'             => '',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'メタボックス保存で、対象外の投稿タイプで別アカウントのロックの場合 => 通る',
				'meta_box'            => true,
				'excluded'            => true,
				'lock'                => 'other',
				'session'             => '',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'クラシックの保存で、対象外の投稿タイプで別アカウントのロックの場合 => 通り、ロックには触らない',
				'meta_box'            => false,
				'excluded'            => true,
				'lock'                => 'other',
				'session'             => 'S1',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '参考: メタボックス保存で、別アカウントが握るロックのセッション ID（S1）が付いている場合 => 通る（is_holder() はセッション ID だけを見てユーザーを見ない。ID は持っている者がロックを握る合言葉であり、現状の仕様として固定する）',
				'meta_box'            => true,
				'lock'                => 'other',
				'session'             => 'S1',
				'expected_blocked'    => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '参考: クラシックの保存で、別アカウントが握るロックのセッション ID（S1）が付いている場合 => 通り、そのロックを解放する（同上）',
				'meta_box'            => false,
				'lock'                => 'other',
				'session'             => 'S1',
				'expected_blocked'    => false,
				'expected_rows'       => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			// The stored session ID is a function-level static, so it must not leak from the previous case.
			// 保持しているセッション ID は関数内 static なので、前のケースから漏らさない.
			edlk_current_session_id( '' );
			delete_option( 'edlk_excluded_post_types' );
			if ( ! empty( $case['excluded'] ) ) {
				update_option( 'edlk_excluded_post_types', array( 'post' ) );
			}
			// Creating the post fires save_post too, so the previous case's field must not be in the request.
			// 投稿の作成でも save_post が発火するので、前のケースの欄がリクエストに残っていてはいけない.
			unset( $_POST['ecg_test_box'] );
			$post_id = $this->factory()->post->create(
				array(
					'post_status' => 'draft',
					'post_author' => $this->me,
					'post_title'  => 'Saved title',
				)
			);
			if ( $case['lock'] ) {
				$this->insert_lock( $post_id, 'S1', $this->holder_id( $case['lock'] ), ! empty( $case['expired'] ) );
			}
			$this->probe_after_gate_ran = false;
			// Priority 2 is just after the gate (1). A gate moved to 3 or later no longer stops a save before this probe.
			// Moved to exactly 2 it still runs first (registered earlier), so only the assertSame( 1, ... ) in test_hook_registration catches that.
			// 優先度 2 はゲート（1）の直後。ゲートが 3 以降へ動くと、この探針より前に保存を止められなくなる。
			// ちょうど 2 なら先に登録されている分ゲートが先に走るので、この探針では拾えず、test_hook_registration の assertSame( 1, ... ) が拾う.
			add_action( 'pre_post_update', array( $this, 'mark_probe_after_gate' ), 2 );

			$status = $this->run_edit_post( $post_id, $case['meta_box'], $case['session'] );

			remove_action( 'pre_post_update', array( $this, 'mark_probe_after_gate' ), 2 );
			$name = $case['test_condition_name'];
			$post = get_post( $post_id );
			$this->assertSame( $case['expected_blocked'], null !== $status, $name . ' / 止まったか' );
			if ( $case['expected_blocked'] ) {
				$this->assertSame( 409, $status, $name . ' / ステータス' );
				$this->assertSame( 'Saved title', $post->post_title, $name . ' / 本文が書かれた' );
				$this->assertSame( '', (string) get_post_meta( $post_id, 'ecg_test_box', true ), $name . ' / メタボックスの値が書かれた' );
				$this->assertFalse( $this->probe_after_gate_ran, $name . ' / ゲートより後ろのフックまで走った' );
			} else {
				$this->assertSame( 'New title', $post->post_title, $name . ' / 本文が書かれていない' );
				$this->assertSame( 'saved by save_post', get_post_meta( $post_id, 'ecg_test_box', true ), $name . ' / save_post のメタが書かれていない' );
				$this->assertTrue( $this->probe_after_gate_ran, $name . ' / 探針が走らない（pre_post_update が発火していない）' );
			}
			$this->assertSame( $case['expected_rows'], $this->count_lock_rows( $post_id ), $name . ' / 保存後のロック行の数' );
		}
		delete_option( 'edlk_excluded_post_types' );
	}

	/**
	 * Tests the block editor's post save (REST) through rest_get_server()->dispatch().
	 *
	 * A separate process, because REST_REQUEST is defined for a real REST request and a constant
	 * cannot be undefined.
	 * 実際の REST リクエストでは REST_REQUEST が定義されるので、別プロセスで動かす（定数は取り消せない）。
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rest_save() {
		define( 'REST_REQUEST', true );

		$test_cases = array(
			array(
				'test_condition_name' => 'ロックを保持している本人のタブ（S1）の保存の場合 => 200 で保存され、ロックは残る（REST の保存ではロックを解放しない）',
				'lock'                => 'self',
				'header_session'      => 'S1',
				'expected_status'     => 200,
				'expected_title'      => 'New title',
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '同じアカウントの別のタブ（S2）の保存の場合 => 409 で止まり、本文は書かれない',
				'lock'                => 'self',
				'header_session'      => 'S2',
				'expected_status'     => 409,
				'expected_title'      => 'Saved title',
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '他ユーザーがロック中でセッション ID が無い場合 => 409 で止まり、本文は書かれない（rest_api_init で登録するゲートの配線）',
				'lock'                => 'other',
				'header_session'      => '',
				'expected_status'     => 409,
				'expected_title'      => 'Saved title',
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'ロックが無い場合 => 200 で保存される',
				'lock'                => null,
				'header_session'      => '',
				'expected_status'     => 200,
				'expected_title'      => 'New title',
				'expected_rows'       => 0,
			),
		);

		$server = rest_get_server();

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			$post_id = $this->factory()->post->create(
				array(
					'post_status' => 'draft',
					'post_author' => $this->me,
					'post_title'  => 'Saved title',
				)
			);
			if ( $case['lock'] ) {
				$this->insert_lock( $post_id, 'S1', $this->holder_id( $case['lock'] ) );
			}

			$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
			if ( '' !== $case['header_session'] ) {
				$request->set_header( 'x_edlk_session', $case['header_session'] );
			}
			$request->set_body_params( array( 'title' => 'New title' ) );
			$response = $server->dispatch( $request );

			$name = $case['test_condition_name'];
			$this->assertSame( $case['expected_status'], $response->get_status(), $name . ' / 応答: ' . wp_json_encode( $response->get_data() ) );
			$this->assertSame( $case['expected_title'], get_post( $post_id )->post_title, $name . ' / 本文' );
			$this->assertSame( $case['expected_rows'], $this->count_lock_rows( $post_id ), $name . ' / 保存後のロック行の数' );
		}
	}
}
