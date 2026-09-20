<?php
/**
 * Tests for Etbs_Ecg_Legacy_Guard.
 * Etbs_Ecg_Legacy_Guard のテスト。
 *
 * @package etbs-edit-conflict-guard
 */

/**
 * Covers detection of the predecessor plugin (EditLock).
 * 前身のプラグイン（EditLock）の検出を確かめる。
 */
class Test_Etbs_Ecg_Legacy_Guard extends WP_UnitTestCase {

	/**
	 * Tests Etbs_Ecg_Legacy_Guard::is_legacy_active().
	 *
	 * @return void
	 */
	public function test_is_legacy_active() {
		$test_cases = array(
			array(
				'test_condition_name' => '標準のフォルダ名（editlock/editlock.php）で有効な場合 => true',
				'active_plugins'      => array( 'editlock/editlock.php' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'フォルダ名を変えて設置され有効な場合 => true（-14 で切っていた頃は常に false だった枝）',
				'active_plugins'      => array( 'editlock-renamed/editlock.php' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => '他のプラグインに混じって有効な場合 => true',
				'active_plugins'      => array( 'akismet/akismet.php', 'my-copy/editlock.php', 'hello-dolly/hello.php' ),
				'expected'            => true,
			),
			array(
				'test_condition_name' => 'このプラグイン自身だけが有効な場合 => false',
				'active_plugins'      => array( 'etbs-edit-conflict-guard/etbs-edit-conflict-guard.php' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => 'ファイル名が editlock.php で終わるだけで区切りの / が無い場合 => false',
				'active_plugins'      => array( 'not-editlock.php' ),
				'expected'            => false,
			),
			array(
				'test_condition_name' => '有効なプラグインが無い場合 => false',
				'active_plugins'      => array(),
				'expected'            => false,
			),
		);

		foreach ( $test_cases as $case ) {
			update_option( 'active_plugins', $case['active_plugins'] );

			$this->assertSame( $case['expected'], Etbs_Ecg_Legacy_Guard::is_legacy_active(), $case['test_condition_name'] );
		}
	}
}
