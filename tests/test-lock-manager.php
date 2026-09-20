<?php
/**
 * Tests for Etbs_Ecg_Lock_Manager.
 * Etbs_Ecg_Lock_Manager のテスト。
 *
 * @package etbs-edit-conflict-guard
 */

/**
 * Covers who can take, keep and give up a lock.
 * 誰がロックを取れて、保てて、手放せるかを確かめる。
 */
class Test_Etbs_Ecg_Lock_Manager extends Etbs_Ecg_Test_Case {

	/**
	 * Post ID used by every case. The lock table has no foreign key, so no real post is needed.
	 * 全ケースで使う投稿 ID。ロックテーブルに外部キーは無いので、実際の投稿は要らない。
	 *
	 * @var int
	 */
	const POST_ID = 123;

	/**
	 * Tests Etbs_Ecg_Lock_Manager::acquire().
	 *
	 * @return void
	 */
	public function test_acquire() {
		$test_cases = array(
			array(
				'test_condition_name' => 'ロックが無い場合 => 取得できる',
				'existing'            => null,
				'request_session'     => 'S1',
				'request_user'        => 1,
				'expected_session'    => 'S1',
				'expected_user'       => 1,
			),
			array(
				'test_condition_name' => '他セッションの有効なロックがある場合 => 奪えず、元の保持者のまま',
				'existing'            => array( 'S1', 1, false ),
				'request_session'     => 'S2',
				'request_user'        => 2,
				'expected_session'    => 'S1',
				'expected_user'       => 1,
			),
			array(
				'test_condition_name' => '同じアカウントの別セッションでも、有効なロックは奪えない => 元の保持者のまま',
				'existing'            => array( 'S1', 1, false ),
				'request_session'     => 'S2',
				'request_user'        => 1,
				'expected_session'    => 'S1',
				'expected_user'       => 1,
			),
			array(
				'test_condition_name' => '他セッションのロックが期限切れの場合 => 奪う',
				'existing'            => array( 'S1', 1, true ),
				'request_session'     => 'S2',
				'request_user'        => 2,
				'expected_session'    => 'S2',
				'expected_user'       => 2,
			),
			array(
				'test_condition_name' => '同一セッションの場合 => 保持者は変わらず延長される',
				'existing'            => array( 'S1', 1, false ),
				'request_session'     => 'S1',
				'request_user'        => 1,
				'expected_session'    => 'S1',
				'expected_user'       => 1,
			),
			array(
				'test_condition_name' => '空のセッション ID の場合 => 何も書き込まない',
				'existing'            => null,
				'request_session'     => '',
				'request_user'        => 1,
				'expected_session'    => null,
				'expected_user'       => null,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['existing'] ) {
				$this->insert_lock( self::POST_ID, $case['existing'][0], $case['existing'][1], $case['existing'][2] );
			}

			$status = Etbs_Ecg_Lock_Manager::acquire( self::POST_ID, $case['request_session'], $case['request_user'], 120 );

			$this->assertSame( $case['expected_session'], $status ? $status['session_id'] : null, $case['test_condition_name'] );
			$this->assertSame( $case['expected_user'], $status ? (int) $status['user_id'] : null, $case['test_condition_name'] );
		}
	}

	/**
	 * Tests Etbs_Ecg_Lock_Manager::is_holder().
	 *
	 * @return void
	 */
	public function test_is_holder() {
		$test_cases = array(
			array(
				'test_condition_name' => '保持しているセッション自身の場合 => true',
				'existing'            => array( 'S1', 1, false ),
				'ask_session'         => 'S1',
				'expected'            => true,
			),
			array(
				'test_condition_name' => '同じアカウントの別セッションの場合 => false（案B を採らないので期待値は変わらない）',
				'existing'            => array( 'S1', 1, false ),
				'ask_session'         => 'S2',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '別ユーザーのセッションの場合 => false',
				'existing'            => array( 'S1', 1, false ),
				'ask_session'         => 'S9',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ロックが期限切れの場合 => false',
				'existing'            => array( 'S1', 1, true ),
				'ask_session'         => 'S1',
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ロックが無い場合 => false',
				'existing'            => null,
				'ask_session'         => 'S1',
				'expected'            => false,
			),
			array(
				'test_condition_name' => '空のセッション ID の場合（session_id が空の行があっても）=> false。hash_equals("","") は true なので、ガードが無いと誰でも保持者になる',
				'existing'            => array( '', 1, false ),
				'ask_session'         => '',
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['existing'] ) {
				$this->insert_lock( self::POST_ID, $case['existing'][0], $case['existing'][1], $case['existing'][2] );
			}

			$this->assertSame( $case['expected'], Etbs_Ecg_Lock_Manager::is_holder( self::POST_ID, $case['ask_session'] ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests Etbs_Ecg_Lock_Manager::renew().
	 *
	 * @return void
	 */
	public function test_renew() {
		$test_cases = array(
			array(
				'test_condition_name' => '保持しているセッションの場合 => true',
				'existing'            => array( 'S1', 1, false ),
				'renew_session'       => 'S1',
				'expected'            => true,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '別セッションの場合 => false（延長しない）',
				'existing'            => array( 'S1', 1, false ),
				'renew_session'       => 'S2',
				'expected'            => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '行が無い場合 => false、行を再作成もしない（Heartbeat が失ったロックを黙って復活させない）',
				'existing'            => null,
				'renew_session'       => 'S1',
				'expected'            => false,
				'expected_rows'       => 0,
			),
			array(
				'test_condition_name' => '期限切れの行の場合 => false',
				'existing'            => array( 'S1', 1, true ),
				'renew_session'       => 'S1',
				'expected'            => false,
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => '空のセッション ID の場合（session_id が空の行があっても）=> false',
				'existing'            => array( '', 1, false ),
				'renew_session'       => '',
				'expected'            => false,
				'expected_rows'       => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['existing'] ) {
				$this->insert_lock( self::POST_ID, $case['existing'][0], $case['existing'][1], $case['existing'][2] );
			}

			$this->assertSame( $case['expected'], Etbs_Ecg_Lock_Manager::renew( self::POST_ID, $case['renew_session'], 120 ), $case['test_condition_name'] );
			$this->assertSame( $case['expected_rows'], $this->count_lock_rows( self::POST_ID ), $case['test_condition_name'] );
		}
	}

	/**
	 * Tests Etbs_Ecg_Lock_Manager::release().
	 *
	 * @return void
	 */
	public function test_release() {
		$test_cases = array(
			array(
				'test_condition_name' => '保持しているセッションが解放する場合 => 行が消える',
				'existing'            => array( 'S1', 1, false ),
				'release_session'     => 'S1',
				'expected_rows'       => 0,
			),
			array(
				'test_condition_name' => '保持していないセッションが解放する場合 => 消さない',
				'existing'            => array( 'S1', 1, false ),
				'release_session'     => 'S2',
				'expected_rows'       => 1,
			),
			array(
				'test_condition_name' => 'ロックが無い場合 => 何も起きない',
				'existing'            => null,
				'release_session'     => 'S1',
				'expected_rows'       => 0,
			),
			array(
				'test_condition_name' => '空のセッション ID の場合（session_id が空の行があっても）=> 消さない',
				'existing'            => array( '', 1, false ),
				'release_session'     => '',
				'expected_rows'       => 1,
			),
		);

		foreach ( $test_cases as $case ) {
			$this->clear_locks();
			if ( $case['existing'] ) {
				$this->insert_lock( self::POST_ID, $case['existing'][0], $case['existing'][1], $case['existing'][2] );
			}

			Etbs_Ecg_Lock_Manager::release( self::POST_ID, $case['release_session'] );

			$this->assertSame( $case['expected_rows'], $this->count_lock_rows( self::POST_ID ), $case['test_condition_name'] );
		}
	}
}
