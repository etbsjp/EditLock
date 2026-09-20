<?php
/**
 * Tests for the functions in inc/func.php that do not need a constant to be defined.
 * inc/func.php の関数のうち、定数の定義を必要としないもののテスト。
 *
 * The ones that do (DOING_AUTOSAVE, REST_REQUEST, WP_CLI ...) live in test-constants.php.
 * 定数の定義が要るもの（DOING_AUTOSAVE・REST_REQUEST・WP_CLI など）は test-constants.php にある。
 *
 * @package etbs-edit-conflict-guard
 */

/**
 * Covers session ID validation, wording, and the gates that can be reached without a constant.
 * セッション ID の検証、文言、定数なしで到達できるゲートを確かめる。
 */
class Test_Etbs_Ecg_Func extends Etbs_Ecg_Test_Case {

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
	 * A post authored by $me.
	 * $me が作者の投稿。
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Creates the users and the post, and makes $me the current user.
	 * ユーザーと投稿を作り、$me を現在のユーザーにする。
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
			)
		);
		wp_set_current_user( $this->me );
	}

	/**
	 * Turns a holder label into a user ID.
	 * 保持者のラベルをユーザー ID に変える。
	 *
	 * @param string $holder 'self', 'other' or 'gone' (an account that no longer exists).
	 * @return int User ID.
	 */
	private function holder_id( $holder ) {
		$map = array(
			'self'  => $this->me,
			'other' => $this->other,
			'gone'  => 999999,
		);
		return $map[ $holder ];
	}

	/**
	 * Tests edlk_sanitize_session_id().
	 *
	 * @return void
	 */
	public function test_edlk_sanitize_session_id() {
		$test_cases = array(
			array(
				'test_condition_name' => '正常な UUID の場合 => そのまま返す',
				'value'               => '3f2b8c1e-7d4a-4f6b-9a10-5c2e8d7b1a90',
				'expected'            => '3f2b8c1e-7d4a-4f6b-9a10-5c2e8d7b1a90',
			),
			array(
				'test_condition_name' => 'crypto.randomUUID() が無い環境の代替 ID の場合 => そのまま返す',
				'value'               => 'edlk-1789000000000-1a2b3c4d5e6f',
				'expected'            => 'edlk-1789000000000-1a2b3c4d5e6f',
			),
			array(
				'test_condition_name' => 'ちょうど 64 文字の場合 => そのまま返す（境界）',
				'value'               => str_repeat( 'a', 64 ),
				'expected'            => str_repeat( 'a', 64 ),
			),
			array(
				'test_condition_name' => '65 文字の場合 => 空文字（列は VARCHAR(64)。切り詰められて別セッションと一致するのを防ぐ）',
				'value'               => str_repeat( 'a', 65 ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => '空文字の場合 => 空文字',
				'value'               => '',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '記号が混じる場合 => 空文字',
				'value'               => 'abc def',
				'expected'            => '',
			),
			array(
				'test_condition_name' => '末尾に改行がある場合 => 空文字（$ ではなく \z で止めている）',
				'value'               => "abc\n",
				'expected'            => '',
			),
			array(
				'test_condition_name' => '文字列でない値（配列）の場合 => 空文字',
				'value'               => array( 'abc' ),
				'expected'            => '',
			),
			array(
				'test_condition_name' => 'null の場合 => 空文字（ヘッダが無いときの get_header() の戻り値）',
				'value'               => null,
				'expected'            => '',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], edlk_sanitize_session_id( $case['value'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests edlk_is_autosave_request() where no constant is involved.
	 * 定数が絡まない範囲で edlk_is_autosave_request() を確かめる。
	 *
	 * @return void
	 */
	public function test_edlk_is_autosave_request() {
		if ( defined( 'DOING_AUTOSAVE' ) ) {
			$this->markTestSkipped( 'DOING_AUTOSAVE is already defined in this process, so the route-only branch cannot be reached.' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '自動保存のルートの場合 => true',
				'request'             => new WP_REST_Request( 'POST', '/wp/v2/posts/12/autosaves' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '固定ページの自動保存のルートの場合 => true',
				'request'             => new WP_REST_Request( 'POST', '/wp/v2/pages/7/autosaves' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '通常の保存のルートの場合 => false',
				'request'             => new WP_REST_Request( 'POST', '/wp/v2/posts/12' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '自動保存の個別リソース（末尾に ID が付く）の場合 => false',
				'request'             => new WP_REST_Request( 'GET', '/wp/v2/posts/12/autosaves/34' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'リクエストが渡されない（REST の外）場合 => false',
				'request'             => null,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->assertSame( $case['expected'], edlk_is_autosave_request( $case['request'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests edlk_get_lock_status_payload() (what edlk_send_lock_status() sends).
	 *
	 * @return void
	 */
	public function test_edlk_get_lock_status_payload() {
		$test_cases = array(
			array(
				'test_condition_name' => 'ロックが無い場合 => locked:false',
				'holder'              => null,
				'session'             => 'S1',
				'expected'            => array( 'locked' => false ),
			),
			array(
				'test_condition_name' => '自分のセッションが保持している場合 => locked:false',
				'holder'              => 'self',
				'session'             => 'S1',
				'expected'            => array( 'locked' => false ),
			),
			array(
				'test_condition_name' => '別ユーザーが保持している場合 => locked:true、holderIsSelf:false、名前あり',
				'holder'              => 'other',
				'session'             => 'S2',
				'expected'            => array(
					'locked'       => true,
					'holderName'   => 'Alice Example',
					'holderIsSelf' => false,
				),
			),
			array(
				'test_condition_name' => '同じアカウントの別セッションが保持している場合 => holderIsSelf:true（自分の表示名）',
				'holder'              => 'self',
				'session'             => 'S2',
				'expected'            => array(
					'locked'       => true,
					'holderName'   => 'Me Myself',
					'holderIsSelf' => true,
				),
			),
			array(
				'test_condition_name' => '保持者のアカウントが削除済みの場合 => 名前は空、holderIsSelf:false',
				'holder'              => 'gone',
				'session'             => 'S2',
				'expected'            => array(
					'locked'       => true,
					'holderName'   => '',
					'holderIsSelf' => false,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['holder'] ) {
				$this->insert_lock( $this->post_id, 'S1', $this->holder_id( $case['holder'] ) );
			}

			$payload = edlk_get_lock_status_payload( Etbs_Ecg_Lock_Manager::status( $this->post_id ), $case['session'] );
			unset( $payload['expiresAt'] ); // The expiry time changes on every run, so it is not compared.

			$this->assertSame( $case['expected'], $payload, $case['test_condition_name'] );
		}
	}

	/**
	 * Tests edlk_get_save_blocked_message() and edlk_get_trash_blocked_message().
	 *
	 * @return void
	 */
	public function test_edlk_get_save_blocked_message() {
		$test_cases = array(
			array(
				'test_condition_name' => '同じアカウントが保持している場合 => 保存: 「同じアカウント (名前)」と書き、「あなた」と断定しない',
				'holder'              => 'self',
				'expected_save'       => 'This post could not be saved because it is open in another edit screen under the same account (Me Myself).',
				'expected_trash'      => 'This post could not be moved to trash because it is open in another edit screen under the same account (Me Myself).',
			),
			array(
				'test_condition_name' => '別ユーザーが保持している場合 => 名前入りの文（既存の msgid）',
				'holder'              => 'other',
				'expected_save'       => 'Alice Example is currently editing this post, so it could not be saved.',
				'expected_trash'      => 'Alice Example is currently editing this post, so it could not be moved to trash.',
			),
			array(
				'test_condition_name' => '保持者が削除済みで名前が分からない場合 => %s に一般名詞を入れず、完全な別の文にする',
				'holder'              => 'gone',
				'expected_save'       => 'Another user is currently editing this post, so it could not be saved.',
				'expected_trash'      => 'Another user is currently editing this post, so it could not be moved to trash.',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->insert_lock( $this->post_id, 'S1', $this->holder_id( $case['holder'] ) );
			$status = Etbs_Ecg_Lock_Manager::status( $this->post_id );

			$this->assertSame( $case['expected_save'], edlk_get_save_blocked_message( $status ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_trash'], edlk_get_trash_blocked_message( $status ), $case['test_condition_name'] );

			$this->clear_locks();
		}
	}

	/**
	 * Tests edlk_pre_post_update_gate() where no constant is involved.
	 * 定数が絡まない範囲で edlk_pre_post_update_gate() を確かめる。
	 *
	 * @return void
	 */
	public function test_edlk_pre_post_update_gate() {
		if ( defined( 'DOING_AUTOSAVE' ) ) {
			$this->markTestSkipped( 'DOING_AUTOSAVE is already defined in this process, so every case would pass through.' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '管理画面で、他ユーザーがロック中で、セッション ID が無い場合 => 保存を止める',
				'admin'               => true,
				'lock'                => 'other',
				'post_session'        => '',
				'expected_blocked'    => true,
				'expected_contains'   => 'Alice Example is currently editing this post, so it could not be saved.',
			),
			array(
				'test_condition_name' => '管理画面で、同じアカウントの別セッションがロック中の場合 => 保存を止める（自己ブロック用の文面）',
				'admin'               => true,
				'lock'                => 'self',
				'post_session'        => 'S2',
				'expected_blocked'    => true,
				'expected_contains'   => 'same account (Me Myself)',
			),
			array(
				'test_condition_name' => '止めるときの本文に、戻って保存し直す案内が入る（「再読み込み」とは言わない）',
				'admin'               => true,
				'lock'                => 'other',
				'post_session'        => '',
				'expected_blocked'    => true,
				'expected_contains'   => esc_html( 'Use your browser\'s Back button to return to the edit screen, then save again.' ), // The message is HTML, so the apostrophe is an entity.
			),
			array(
				'test_condition_name' => '一括編集の場合 => 止めた投稿のタイトルと ID が入り、「編集画面に戻って」は入らない',
				'admin'               => true,
				'lock'                => 'other',
				'post_session'        => '',
				'bulk'                => true,
				'expected_blocked'    => true,
				'expected_contains'   => 'Processing stopped at this post:',
			),
			array(
				'test_condition_name' => 'クイック編集（Ajax）の場合 => 本文はタグの無い文だけ（インラインのエラー表示に出るため）',
				'admin'               => true,
				'ajax'                => true,
				'lock'                => 'other',
				'post_session'        => '',
				'expected_blocked'    => true,
				'expected_exact'      => 'Alice Example is currently editing this post, so it could not be saved.',
			),
			array(
				'test_condition_name' => '管理画面で、自分のセッションがロックを保持している場合 => 通す',
				'admin'               => true,
				'lock'                => 'self',
				'post_session'        => 'S1',
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => '管理画面で、ロックが無い場合 => 通す',
				'admin'               => true,
				'lock'                => null,
				'post_session'        => '',
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => '管理画面の外（フロントの wp_update_post）で、他ユーザーがロック中の場合 => 通す（訪問者の画面を 409 で落とさない）',
				'admin'               => false,
				'lock'                => 'other',
				'post_session'        => '',
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => 'wp-cron の場合 => 通す',
				'admin'               => true,
				'cron'                => true,
				'lock'                => 'other',
				'post_session'        => '',
				'expected_blocked'    => false,
			),
			array(
				'test_condition_name' => 'ログインしていない場合 => 通す',
				'admin'               => true,
				'logged_in'           => false,
				'lock'                => 'other',
				'post_session'        => '',
				'expected_blocked'    => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			wp_set_current_user( isset( $case['logged_in'] ) && false === $case['logged_in'] ? 0 : $this->me );
			set_current_screen( $case['admin'] ? 'edit-post' : 'front' );
			remove_all_filters( 'wp_doing_cron' );
			remove_all_filters( 'wp_doing_ajax' );
			unset( $_REQUEST['bulk_edit'] );
			if ( ! empty( $case['cron'] ) ) {
				add_filter( 'wp_doing_cron', '__return_true' );
			}
			remove_all_filters( 'wp_die_ajax_handler' );
			if ( ! empty( $case['ajax'] ) ) {
				add_filter( 'wp_doing_ajax', '__return_true' );
				// The suite only replaces the non-Ajax wp_die() handler; without this the Ajax one would die() the whole run.
				// スイートが差し替えるのは Ajax でない wp_die() の処理だけ。これが無いと Ajax 用が実行全体を die() で止める.
				add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), 1 );
			}
			if ( ! empty( $case['bulk'] ) ) {
				$_REQUEST['bulk_edit'] = '1';
			}
			if ( $case['lock'] ) {
				$this->insert_lock( $this->post_id, 'S1', $this->holder_id( $case['lock'] ) );
			}
			$_POST['edlk_session_id'] = $case['post_session'];

			$message = $this->run_save_gate( $this->post_id );

			$this->assertSame( $case['expected_blocked'], null !== $message, $case['test_condition_name'] );
			if ( isset( $case['expected_contains'] ) ) {
				$this->assertStringContainsString( $case['expected_contains'], (string) $message, $case['test_condition_name'] );
			}
			if ( ! empty( $case['bulk'] ) ) {
				$this->assertStringNotContainsString( 'Back button', (string) $message, $case['test_condition_name'] );
			}
			if ( isset( $case['expected_exact'] ) ) {
				$this->assertSame( $case['expected_exact'], $message, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * Tests edlk_rest_pre_insert_gate().
	 *
	 * @return void
	 */
	public function test_edlk_rest_pre_insert_gate() {
		if ( defined( 'DOING_AUTOSAVE' ) ) {
			$this->markTestSkipped( 'DOING_AUTOSAVE is already defined in this process, so every case would pass through.' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => '他ユーザーがロック中でセッション ID が無い場合 => 409 の WP_Error（理由と「内容はこの画面に残っています」の2文）',
				'route'               => '/wp/v2/posts/%d',
				'lock'                => 'other',
				'header'              => '',
				'has_id'              => true,
				'expected'            => 'error',
			),
			array(
				'test_condition_name' => '同じアカウントの別セッションがロック中の場合 => 409 の WP_Error',
				'route'               => '/wp/v2/posts/%d',
				'lock'                => 'self',
				'header'              => 'S2',
				'has_id'              => true,
				'expected'            => 'error',
			),
			array(
				'test_condition_name' => '保持しているセッションの場合 => 通す',
				'route'               => '/wp/v2/posts/%d',
				'lock'                => 'self',
				'header'              => 'S1',
				'has_id'              => true,
				'expected'            => 'through',
			),
			array(
				'test_condition_name' => 'ロックが無い場合 => 通す',
				'route'               => '/wp/v2/posts/%d',
				'lock'                => null,
				'header'              => '',
				'has_id'              => true,
				'expected'            => 'through',
			),
			array(
				'test_condition_name' => '新規作成（ID が無い）場合 => 通す',
				'route'               => '/wp/v2/posts',
				'lock'                => 'other',
				'header'              => '',
				'has_id'              => false,
				'expected'            => 'through',
			),
			array(
				'test_condition_name' => '自動保存のルートの場合 => 他ユーザーがロック中でも WP_Error を返さない（コアが is_wp_error() を見ないため）',
				'route'               => '/wp/v2/posts/%d/autosaves',
				'lock'                => 'other',
				'header'              => '',
				'has_id'              => true,
				'expected'            => 'through',
			),
			array(
				'test_condition_name' => '自動保存のルートで、セッション ID が 65 文字の不正値の場合 => それでも WP_Error を返さない',
				'route'               => '/wp/v2/posts/%d/autosaves',
				'lock'                => 'self',
				'header'              => str_repeat( 'a', 65 ),
				'has_id'              => true,
				'expected'            => 'through',
			),
			array(
				'test_condition_name' => '65 文字の不正なセッション ID の場合 => 保持者とは見なされず 409',
				'route'               => '/wp/v2/posts/%d',
				'lock'                => 'other',
				'header'              => str_repeat( 'a', 65 ),
				'has_id'              => true,
				'expected'            => 'error',
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			edlk_current_session_id( '' );
			if ( $case['lock'] ) {
				$this->insert_lock( $this->post_id, 'S1', $this->holder_id( $case['lock'] ) );
			}

			$request = new WP_REST_Request( 'POST', sprintf( $case['route'], $this->post_id ) );
			if ( '' !== $case['header'] ) {
				$request->set_header( 'x_edlk_session', $case['header'] );
			}
			$prepared = new stdClass();
			if ( $case['has_id'] ) {
				$prepared->ID = $this->post_id;
			}

			$result = edlk_rest_pre_insert_gate( $prepared, $request );

			if ( 'error' === $case['expected'] ) {
				$this->assertWPError( $result, $case['test_condition_name'] );
				$this->assertSame( 409, $result->get_error_data()['status'], $case['test_condition_name'] );
				$this->assertStringEndsWith( 'What you have entered is still on this screen.', $result->get_error_message(), $case['test_condition_name'] );
			} else {
				$this->assertSame( $prepared, $result, $case['test_condition_name'] );
			}
		}
	}

	/**
	 * Tests edlk_release_after_save() where no constant is involved (a classic full save).
	 * 定数が絡まない範囲（クラシックのフル保存）で edlk_release_after_save() を確かめる。
	 *
	 * @return void
	 */
	public function test_edlk_release_after_save() {
		if ( defined( 'DOING_AUTOSAVE' ) || defined( 'REST_REQUEST' ) ) {
			$this->markTestSkipped( 'DOING_AUTOSAVE or REST_REQUEST is already defined in this process, so a classic full save cannot be reproduced.' );
		}

		$test_cases = array(
			array(
				'test_condition_name' => 'ゲートが確認済みのセッション ID を持つ場合 => その行が消える',
				'lock_session'        => 'S1',
				'request_session'     => 'S1',
				'expected_rows'       => 0,
			),
			array(
				'test_condition_name' => 'セッション ID が無い（クイック編集・一括編集）場合 => 消さない',
				'lock_session'        => 'S1',
				'request_session'     => '',
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '別セッションの行の場合 => 消さない',
				'lock_session'        => 'S1',
				'request_session'     => 'S2',
				'expected_rows'       => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			$this->insert_lock( $this->post_id, $case['lock_session'], $this->me );
			edlk_current_session_id( $case['request_session'] );

			edlk_release_after_save( $this->post_id );

			$this->assertSame( $case['expected_rows'], $this->count_lock_rows( $this->post_id ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests edlk_heartbeat_received().
	 *
	 * @return void
	 */
	public function test_edlk_heartbeat_received() {
		$test_cases = array(
			array(
				'test_condition_name' => '自分のセッションが保持している場合 => lost を返さない',
				'lock'                => array( 'self', 'S1', false ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '同じアカウントの別セッションに奪われた場合 => lost:true、holderIsSelf:true',
				'lock'                => array( 'self', 'S9', false ),
				'expected'            => array(
					'lost'         => true,
					'holderName'   => 'Me Myself',
					'holderIsSelf' => true,
				),
			),
			array(
				'test_condition_name' => '他ユーザーに奪われた場合 => lost:true、holderIsSelf:false',
				'lock'                => array( 'other', 'S9', false ),
				'expected'            => array(
					'lost'         => true,
					'holderName'   => 'Alice Example',
					'holderIsSelf' => false,
				),
			),
			array(
				'test_condition_name' => '行が期限切れで status() が null の場合 => lost を返さない（M-2: 誰にも奪われていないので「別の画面が取得した」は偽になる）',
				'lock'                => array( 'self', 'S9', true ),
				'expected'            => null,
			),
			array(
				'test_condition_name' => '行が無い場合 => lost を返さない',
				'lock'                => null,
				'expected'            => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['lock'] ) {
				$this->insert_lock( $this->post_id, $case['lock'][1], $this->holder_id( $case['lock'][0] ), $case['lock'][2] );
			}

			$response = edlk_heartbeat_received(
				array(),
				array(
					'edlk' => array(
						'post_id'    => $this->post_id,
						'session_id' => 'S1',
					),
				)
			);

			$this->assertSame( $case['expected'], $response['edlk'] ?? null, $case['test_condition_name'] );
		}
	}

	/**
	 * Tests edlk_filter_override_post_lock() (the classic "take over" button).
	 *
	 * @return void
	 */
	public function test_edlk_filter_override_post_lock() {
		$post  = get_post( $this->post_id );
		$other = get_userdata( $this->other );

		$test_cases = array(
			array(
				'test_condition_name' => '別アカウントが Edit Conflict Guard のロックを持つ場合 => 「引き継ぐ」を消す',
				'lock'                => 'other',
				'override'            => true,
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ロックが無い場合 => 「引き継ぐ」を残す（コアのロックだけが残っている状態）',
				'lock'                => null,
				'override'            => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '自分のアカウントのロックの場合 => 「引き継ぐ」を残す',
				'lock'                => 'self',
				'override'            => true,
				'expected'            => true,
			),
			array(
				'test_condition_name' => '他のフックが既に false にしている場合 => false のまま',
				'lock'                => null,
				'override'            => false,
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['lock'] ) {
				$this->insert_lock( $this->post_id, 'S1', $this->holder_id( $case['lock'] ) );
			}

			$this->assertSame( $case['expected'], edlk_filter_override_post_lock( $case['override'], $post ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests edlk_render_post_locked_dialog_notice() (the line added to core's dialog).
	 *
	 * @return void
	 */
	public function test_edlk_render_post_locked_dialog_notice() {
		$post  = get_post( $this->post_id );
		$other = get_userdata( $this->other );

		$test_cases = array(
			array(
				'test_condition_name' => '別アカウントのロックがあり、現在のユーザーが管理者の場合 => 1行と、強制解除への導線（新しいタブ）が出る',
				'lock'                => 'other',
				'contains'            => array( 'Edit Conflict Guard is protecting this post.', 'You cannot save until Alice Example closes the edit screen.', 'target="_blank"', 'page=edlk-settings' ),
				'not_contains'        => array(),
			),
			array(
				'test_condition_name' => '別アカウントのロックがあり、現在のユーザーが管理者でない場合 => 1行だけで、導線は出ない',
				'lock'                => 'other',
				'as_editor'           => true,
				'contains'            => array( 'Edit Conflict Guard is protecting this post.' ),
				'not_contains'        => array( 'page=edlk-settings' ),
			),
			array(
				'test_condition_name' => 'ロックが無い場合 => 何も出ない',
				'lock'                => null,
				'contains'            => array(),
				'not_contains'        => array( 'Edit Conflict Guard' ),
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			wp_set_current_user( empty( $case['as_editor'] ) ? $this->me : $this->factory()->user->create( array( 'role' => 'editor' ) ) );
			if ( $case['lock'] ) {
				$this->insert_lock( $this->post_id, 'S1', $this->holder_id( $case['lock'] ) );
			}

			ob_start();
			edlk_render_post_locked_dialog_notice( $post, $other );
			$output = ob_get_clean();

			foreach ( $case['contains'] as $needle ) {
				$this->assertStringContainsString( $needle, $output, $case['test_condition_name'] );
			}
			foreach ( $case['not_contains'] as $needle ) {
				$this->assertStringNotContainsString( $needle, $output, $case['test_condition_name'] );
			}
		}
	}
}
