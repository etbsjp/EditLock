/**
 * Settings screen behaviour: force-releasing a lock from the "Currently Locked Posts" table.
 * 設定画面の挙動。「ロック中の投稿」一覧からロックを強制解除する。
 *
 * Data is provided by wp_localize_script() as EdlkSettings.
 * データは wp_localize_script() から EdlkSettings として渡される。
 */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '#edlk-lock-list' ).on( 'click', '.edlk-force-release', function () {
			if ( ! window.confirm( EdlkSettings.i18n.confirm ) ) {
				return;
			}

			var $button = $( this );
			var $row    = $button.closest( 'tr' );
			var postId  = $row.data( 'post-id' );

			$button.prop( 'disabled', true ).text( EdlkSettings.i18n.releasing );

			$.post(
				EdlkSettings.ajaxUrl,
				{
					action: 'edlk_force_release',
					nonce: EdlkSettings.nonce,
					post_id: postId
				},
				function ( res ) {
					if ( res.success ) {
						$row.remove();

						// Show the empty-state row once the last lock is gone.
						// 最後のロックが消えたら空状態の行を出す。
						if ( ! $( '#edlk-lock-list tr' ).length ) {
							$( '#edlk-lock-list' ).append(
								$( '<tr/>' ).append(
									$( '<td/>' ).attr( 'colspan', 5 ).text( EdlkSettings.i18n.noLocks )
								)
							);
						}
					} else {
						window.alert( EdlkSettings.i18n.releaseFailed );
					}
				}
			);
		} );
	} );
}( jQuery ) );
