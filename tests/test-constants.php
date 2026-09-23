<?php
/**
 * Tests that depend on a PHP constant being defined (DOING_AUTOSAVE, REST_REQUEST, WP_CLI ...).
 * PHP の定数が定義されていることに依存するテスト（DOING_AUTOSAVE・REST_REQUEST・WP_CLI など）。
 *
 * A constant can never be undefined, so each test runs in its own process
 * (@runInSeparateProcess) and checks the control (before the constant) and the effect (after it)
 * in the same test. Without that, whichever constant a test happened to define first would silently
 * mask every later one, because the code under test ORs them together.
 * 定数は取り消せないため、各テストを別プロセスで動かし、同じテストの中で対照（定義前）と効果
 * （定義後）の両方を確かめる。そうしないと、先に定義した定数が、後のすべてのテストを黙って
 * 覆い隠してしまう（テスト対象のコードは条件を OR で束ねているため）。
 *
 * @package etbs-edit-conflict-guard
 */

/**
 * Covers the autosave, REST and non-admin paths that only exist when a constant is set.
 * 定数が立っているときにだけ存在する、自動保存・REST・管理画面外の経路を確かめる。
 */
class Test_Etbs_Ecg_Constants extends Etbs_Ecg_Test_Case {

	/**
	 * The current user (the one saving).
	 * 現在のユーザー（保存する側）。
	 *
	 * @var int
	 */
	private $me;

	/**
	 * Another user who holds the lock.
	 * ロックを保持している別のユーザー。
	 *
	 * @var int
	 */
	private $other;

	/**
	 * A draft authored by $me.
	 * $me が作者の下書き。
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Creates the users and the draft, and makes $me the current user in the admin context.
	 * ユーザーと下書きを作り、$me を管理画面の文脈で現在のユーザーにする。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->me      = $this->factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Me Myself',
			)
		);
		$this->other   = $this->factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Alice Example',
			)
		);
		$this->post_id = $this->factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $this->me,
				'post_title'  => 'Saved title',
			)
		);
		wp_set_current_user( $this->me );
	}

	/**
	 * Tests that the constant DOING_AUTOSAVE really is set by a REST autosave under this test suite.
	 * Also the regression for the autosave path: it writes, and it does not release the lock.
	 *
	 * これが計測器の陽性対照。テストスイート上でも、REST の自動保存で DOING_AUTOSAVE が本当に立つことを確かめる
	 * （立たなければ、以降の「自動保存では…」という試験は何も試験していないことになる）。
	 * あわせて自動保存経路の回帰試験: 実際に書き込み、ロックを解放しない。
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_doing_autosave_is_defined_by_a_rest_autosave() {
		global $wp_rest_server;

		// Precondition: nothing has defined the constants yet, so what follows is caused by the request.
		// 前提: まだ誰も定数を定義していない。以降に起きることはこのリクエストの結果と言える.
		$this->assertFalse( defined( 'DOING_AUTOSAVE' ), 'DOING_AUTOSAVE が既に定義されている（計測器が使えない）' );
		$this->assertFalse( defined( 'REST_REQUEST' ), 'REST_REQUEST が既に定義されている（解放の除外と区別できない）' );

		// The lock is held by another tab (S1) of the same account; this request comes from S2.
		// ロックは同じアカウントの別のタブ（S1）が保持していて、このリクエストは S2 から来る.
		$this->insert_lock( $this->post_id, 'S1', $this->me );

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id . '/autosaves' );
		$request->set_header( 'x_edlk_session', 'S2' );
		$request->set_body_params(
			array(
				'title'   => 'Autosaved title',
				'content' => 'Autosaved content',
			)
		);
		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), '自動保存が 200 を返さなかった: ' . wp_json_encode( $response->get_data() ) );
		$this->assertTrue( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE, 'コアが DOING_AUTOSAVE を立てなかった（テストスイート上でこの定数に頼れない）' );
		$this->assertSame( 'Autosaved title', get_post( $this->post_id )->post_title, '自動保存は 200 を返しながら何も書かなかった（症状 D）' );
		$this->assertTrue( Etbs_Ecg_Lock_Manager::is_holder( $this->post_id, 'S1' ), '自動保存がロックを解放した（症状 B）' );
	}

	/**
	 * Tests a REST autosave under the conditions of a real request: REST_REQUEST is defined as well.
	 * Reproduces symptoms B and D exactly: 200 without writing anything, and the lock released.
	 *
	 * 実際のリクエストと同じ条件（REST_REQUEST も定義済み）での REST の自動保存。症状 B と D をそのまま再現する:
	 * 何も書かずに 200 を返し、ロックが解放される。
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_rest_autosave_in_production_conditions() {
		global $wp_rest_server;

		define( 'REST_REQUEST', true );

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$test_cases = array(
			array(
				'test_condition_name' => 'ロックを保持している本人のタブ（S1）の自動保存の場合 => 書き込まれ、ロックは残る（症状 B: 解放されていた）',
				'header_session'      => 'S1',
				'title'               => 'Autosaved by the holder tab',
			),
			array(
				'test_condition_name' => '同じアカウントの別のタブ（S2）の自動保存の場合 => 書き込まれ、ロックは残る（症状 D: 200 を返しながら何も書かなかった）',
				'header_session'      => 'S2',
				'title'               => 'Autosaved by the other tab',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			$this->insert_lock( $this->post_id, 'S1', $this->me );

			$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id . '/autosaves' );
			$request->set_header( 'x_edlk_session', $case['header_session'] );
			$request->set_body_params(
				array(
					'title'   => $case['title'],
					'content' => 'Autosaved content',
				)
			);
			$response = $wp_rest_server->dispatch( $request );

			$this->assertSame( 200, $response->get_status(), $case['test_condition_name'] . ' / 応答: ' . wp_json_encode( $response->get_data() ) );
			$this->assertSame( $case['title'], get_post( $this->post_id )->post_title, $case['test_condition_name'] . ' / 何も書かれなかった' );
			$this->assertTrue( Etbs_Ecg_Lock_Manager::is_holder( $this->post_id, 'S1' ), $case['test_condition_name'] . ' / ロックが解放された' );
		}
	}

	/**
	 * Tests edlk_release_after_save() with DOING_AUTOSAVE defined.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_edlk_release_after_save_doing_autosave() {
		$this->insert_lock( $this->post_id, 'S1', $this->me );
		edlk_current_session_id( 'S1' );

		// Control: before the constant, the save releases the lock.
		// 対照: 定数が立つ前は、この保存でロックが解放される.
		edlk_release_after_save( $this->post_id );
		$this->assertSame( 0, $this->count_lock_rows( $this->post_id ), '対照: 定義前は解放されるはず' );

		$this->insert_lock( $this->post_id, 'S1', $this->me );
		define( 'DOING_AUTOSAVE', true );
		edlk_release_after_save( $this->post_id );
		$this->assertSame( 1, $this->count_lock_rows( $this->post_id ), '自動保存ではロックを解放しない' );
	}

	/**
	 * Tests edlk_release_after_save() with REST_REQUEST defined.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_edlk_release_after_save_rest_request() {
		$this->insert_lock( $this->post_id, 'S1', $this->me );
		edlk_current_session_id( 'S1' );

		// Control: before the constant, the save releases the lock.
		// 対照: 定数が立つ前は、この保存でロックが解放される.
		edlk_release_after_save( $this->post_id );
		$this->assertSame( 0, $this->count_lock_rows( $this->post_id ), '対照: 定義前は解放されるはず' );

		$this->insert_lock( $this->post_id, 'S1', $this->me );
		define( 'REST_REQUEST', true );
		edlk_release_after_save( $this->post_id );
		$this->assertSame( 1, $this->count_lock_rows( $this->post_id ), 'REST（ブロックエディタ）の明示保存ではロックを解放しない' );
	}

	/**
	 * Tests that edlk_pre_post_update_gate() lets an autosave through.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_edlk_pre_post_update_gate_doing_autosave() {
		$this->enter_admin_context();
		$this->insert_lock( $this->post_id, 'S1', $this->other );

		$this->assertNotNull( $this->run_save_gate( $this->post_id ), '対照: 定義前は他ユーザーのロックで止まるはず' );

		define( 'DOING_AUTOSAVE', true );
		$this->assertNull( $this->run_save_gate( $this->post_id ), '自動保存ではゲートを素通しする' );
	}

	/**
	 * Tests that edlk_pre_post_update_gate() does not run under WP-CLI.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_edlk_pre_post_update_gate_wp_cli() {
		$this->enter_admin_context();
		$this->insert_lock( $this->post_id, 'S1', $this->other );

		$this->assertNotNull( $this->run_save_gate( $this->post_id ), '対照: 定義前は他ユーザーのロックで止まるはず' );

		define( 'WP_CLI', true );
		$this->assertNull( $this->run_save_gate( $this->post_id ), 'WP-CLI では wp_die() しない' );
	}

	/**
	 * Tests that edlk_pre_post_update_gate() does not run for XML-RPC.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_edlk_pre_post_update_gate_xmlrpc() {
		$this->enter_admin_context();
		$this->insert_lock( $this->post_id, 'S1', $this->other );

		$this->assertNotNull( $this->run_save_gate( $this->post_id ), '対照: 定義前は他ユーザーのロックで止まるはず' );

		define( 'XMLRPC_REQUEST', true );
		$this->assertNull( $this->run_save_gate( $this->post_id ), 'XML-RPC では wp_die() しない' );
	}

	/**
	 * Tests that edlk_pre_post_update_gate() leaves a REST request to the REST gate.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_edlk_pre_post_update_gate_rest_request() {
		$this->enter_admin_context();
		$this->insert_lock( $this->post_id, 'S1', $this->other );

		$this->assertNotNull( $this->run_save_gate( $this->post_id ), '対照: 定義前は他ユーザーのロックで止まるはず' );

		define( 'REST_REQUEST', true );
		$this->assertNull( $this->run_save_gate( $this->post_id ), 'REST は rest_pre_insert のゲートが判定するので、ここでは通す' );
	}
}
