( function( $ ) {
	'use strict';

	if ( typeof EdlkData === 'undefined' ) { return; }

	var postId       = EdlkData.postId;
	var storageKey    = 'edlk_session_' + postId;
	var sessionId     = sessionStorage.getItem( storageKey );
	if ( ! sessionId ) {
		sessionId = ( window.crypto && crypto.randomUUID ) ? crypto.randomUUID() : ( 'edlk-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 ) );
		sessionStorage.setItem( storageKey, sessionId );
	}

	var lockLostHolder = '';
	var dialog          = null;

	function buildDialog() {
		if ( dialog ) { return dialog; }
		dialog = document.createElement( 'dialog' );
		dialog.id = 'edlk-lock-dialog';
		dialog.style.cssText = 'border:1px solid #c3c4c7;border-radius:4px;padding:24px 28px;min-width:320px;max-width:420px;box-shadow:0 4px 16px rgba(0,0,0,.15);';
		dialog.innerHTML =
			'<p style="margin:0 0 8px;font-size:15px;font-weight:600;">' + escapeHtml( EdlkData.i18n.lockedTitle ) + '</p>' +
			'<p id="edlk-lock-dialog-body" style="margin:0 0 16px;font-size:13px;color:#3c434a;"></p>' +
			'<div style="display:flex;justify-content:flex-end;">' +
				'<button type="button" id="edlk-lock-dialog-close" class="button button-primary">' + escapeHtml( EdlkData.i18n.closeButton ) + '</button>' +
			'</div>';
		document.body.appendChild( dialog );
		dialog.querySelector( '#edlk-lock-dialog-close' ).addEventListener( 'click', function() { dialog.close(); } );
		dialog.addEventListener( 'click', function( e ) { if ( e.target === dialog ) { dialog.close(); } } );
		return dialog;
	}

	function escapeHtml( str ) {
		return $( '<div>' ).text( str ).html();
	}

	function showLockedModal( holderName ) {
		var name = holderName || EdlkData.i18n.lockedUnknown;
		var body = EdlkData.i18n.lockedBody.replace( '%s', name );
		var d    = buildDialog();
		d.querySelector( '#edlk-lock-dialog-body' ).textContent = body;
		d.showModal();
	}

	/**
	 * ロック取得を試みる（既に保持していれば維持、他者保持中ならその情報を返す）。
	 */
	function acquire( callback ) {
		$.post( EdlkData.ajaxUrl, {
			action:     'edlk_acquire',
			nonce:      EdlkData.nonce,
			post_id:    postId,
			session_id: sessionId
		} ).done( function( res ) {
			if ( ! res.success ) { callback( false, '' ); return; }
			callback( !! res.data.locked, res.data.holderName || '' );
		} ).fail( function() {
			callback( false, '' ); // 通信エラー時は保存自体はサーバー側ゲートに委ねる
		} );
	}

	/* ---------- 初回ロード時にロック取得を試みる ---------- */
	acquire( function() {} );

	/* ---------- Heartbeatで延長し、途中でロックを失っていないか監視 ---------- */
	$( document ).on( 'heartbeat-send.editlock', function( e, data ) {
		data.edlk = { post_id: postId, session_id: sessionId };
	} );
	$( document ).on( 'heartbeat-tick.editlock', function( e, data ) {
		if ( data && data.edlk && data.edlk.lost ) {
			lockLostHolder = data.edlk.holderName || '';
		} else {
			lockLostHolder = '';
		}
	} );

	/* ---------- 離脱時にロックを明示解放 ---------- */
	var isSubmitting = false; // 保存によるページ遷移では解放しない（サーバー側save_postでの解放に任せる）

	window.addEventListener( 'beforeunload', function() {
		if ( isSubmitting ) { return; }
		if ( navigator.sendBeacon ) {
			var fd = new FormData();
			fd.append( 'action', 'edlk_release' );
			fd.append( 'nonce', EdlkData.nonce );
			fd.append( 'post_id', postId );
			fd.append( 'session_id', sessionId );
			navigator.sendBeacon( EdlkData.ajaxUrl, fd );
		}
	} );

	/* ---------- クラシックエディタ: #post のsubmitをゲート ---------- */
	var classicForm = document.getElementById( 'post' );
	if ( classicForm ) {
		var hidden = document.createElement( 'input' );
		hidden.type  = 'hidden';
		hidden.name  = 'edlk_session_id';
		hidden.value = sessionId;
		classicForm.appendChild( hidden );

		classicForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			acquire( function( locked, holderName ) {
				if ( locked ) {
					showLockedModal( holderName );
					return;
				}
				isSubmitting = true;
				classicForm.submit(); // submit()経由はsubmitイベントを再発火しないためガードを再帰しない
			} );
		} );
	}

	/* ---------- Gutenberg: 保存系ボタンのクリックをゲート ---------- */
	var GUARD_SELECTOR = '.editor-post-save-draft, .editor-post-publish-button, .editor-post-publish-panel__header-publish-button .editor-post-publish-button';

	document.addEventListener( 'click', function( e ) {
		var el = e.target.closest( GUARD_SELECTOR );
		if ( ! el || el.getAttribute( 'data-edlk-bypass' ) === '1' ) { return; }

		e.preventDefault();
		e.stopImmediatePropagation();

		acquire( function( locked, holderName ) {
			if ( locked ) {
				showLockedModal( holderName );
				return;
			}
			el.setAttribute( 'data-edlk-bypass', '1' );
			el.click();
			el.removeAttribute( 'data-edlk-bypass' );
		} );
	}, true );

	/* ---------- Gutenberg: REST保存リクエストに session_id を付与（サーバー側ゲート用） ---------- */
	if ( window.wp && wp.apiFetch ) {
		wp.apiFetch.use( function( options, next ) {
			options.headers = options.headers || {};
			options.headers[ 'X-EditLock-Session' ] = sessionId;
			return next( options );
		} );
	}

} )( jQuery );
