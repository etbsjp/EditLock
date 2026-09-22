( function( $ ) {
	'use strict';

	if ( typeof EdlkData === 'undefined' ) { return; }

	var i18n         = EdlkData.i18n;
	var postId       = EdlkData.postId;
	var storageKey   = 'edlk_session_' + postId;
	var sessionId    = sessionStorage.getItem( storageKey );
	if ( ! sessionId ) {
		sessionId = ( window.crypto && crypto.randomUUID ) ? crypto.randomUUID() : ( 'edlk-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 ) );
		sessionStorage.setItem( storageKey, sessionId );
	}

	/*
	 * True once this screen has actually held the lock. Heartbeat's renew() is `WHERE session_id = %s`,
	 * so a second screen that never got the lock is told "lost" on every beat (every 15 seconds).
	 * Reporting a loss only makes sense for a screen that had something to lose.
	 * この画面が実際にロックを握ったことがあるか。renew() は WHERE session_id = %s なので、ロックを一度も
	 * 取れていない2枚目の画面には毎回（15秒ごとに）「失った」が返る。失うものがあった画面にだけ知らせる。
	 */
	var hasHeldLock = false;
	var dialog      = null;
	var dialogParts = null;
	var retryAction = null;

	/**
	 * Replaces every %1$s in a template with the name (one sentence may need the name more than once,
	 * so replacing only the first occurrence is not enough). The replacement is returned from a function
	 * so that a $& or $1 inside a display name is not read as a replacement pattern.
	 * テンプレートの %1$s をすべて名前に置換する（1文に名前が複数入る文があるため、最初の1個だけでは
	 * 足りない）。置換文字列は関数で返す。表示名に含まれる $& や $1 が置換パターンとして解釈されないようにするため。
	 */
	function fill( template, name ) {
		return template.replace( /%1\$s/g, function() { return name; } );
	}

	/**
	 * Creates an element with an optional class and text (textContent, so nothing is parsed as HTML).
	 * クラスとテキストを指定して要素を作る（textContent なので HTML としては解釈されない）。
	 */
	function makeElement( tag, className, text ) {
		var el = document.createElement( tag );
		if ( className ) { el.className = className; }
		if ( text ) { el.textContent = text; }
		return el;
	}

	/**
	 * Builds the dialog once and reuses it; the title and body are rewritten on every call.
	 * The padding lives on an inner div: with padding on the dialog itself, a click on the padding
	 * has e.target === dialog and would close it.
	 * ダイアログは1度だけ作って使い回す。中身（タイトル・本文）は呼び出しのたびに書き換える。
	 * padding は内側の div に持たせる（dialog 自体に持たせると、padding のクリックが e.target === dialog に
	 * なって閉じてしまう）。
	 */
	function buildDialog() {
		if ( dialog ) { return dialog; }

		dialog = makeElement( 'dialog', 'edlk-dialog' );
		dialog.id = 'edlk-lock-dialog';
		dialog.setAttribute( 'aria-labelledby', 'edlk-lock-dialog-title' );
		dialog.setAttribute( 'aria-describedby', 'edlk-lock-dialog-body' );

		var inner   = makeElement( 'div', 'edlk-dialog__inner' );
		var title   = makeElement( 'h2', 'edlk-dialog__title' );
		var body    = makeElement( 'div', 'edlk-dialog__body' );
		var admin   = makeElement( 'p', 'edlk-dialog__admin' );
		var buttons = makeElement( 'div', 'edlk-dialog__buttons' );
		var close   = makeElement( 'button', 'button', i18n.closeButton );
		var retry   = makeElement( 'button', 'button button-primary', i18n.saveAgainButton );

		title.id   = 'edlk-lock-dialog-title';
		body.id    = 'edlk-lock-dialog-body';
		close.type = 'button';
		retry.type = 'button';

		close.addEventListener( 'click', function() { dialog.close(); } );
		retry.addEventListener( 'click', function() {
			var action = retryAction;
			dialog.close();
			if ( action ) { action(); }
		} );
		// The dialog has no padding of its own, so the only click that reaches it is on the ::backdrop (outside).
		// dialog 自体に padding は無いので、ここに届くクリックは ::backdrop（ダイアログの外）だけ。
		dialog.addEventListener( 'click', function( e ) { if ( e.target === dialog ) { dialog.close(); } } );

		buttons.appendChild( close );
		buttons.appendChild( retry );
		inner.appendChild( title );
		inner.appendChild( body );
		inner.appendChild( admin );
		inner.appendChild( buttons );
		dialog.appendChild( inner );
		document.body.appendChild( dialog );

		dialogParts = { title: title, body: body, admin: admin };
		return dialog;
	}

	/**
	 * Replaces the children of a container with one paragraph per line.
	 * コンテナの中身を、1行1段落で置き換える。
	 */
	function setParagraphs( container, lines ) {
		while ( container.firstChild ) { container.removeChild( container.firstChild ); }
		lines.forEach( function( line ) {
			container.appendChild( makeElement( 'p', '', line ) );
		} );
	}

	/**
	 * Shows administrators, and only them, the way to the settings screen where a lock can be released.
	 * It opens in a new tab and says so: in the same tab it would make the user throw away what the
	 * dialog just said is still on this screen.
	 * 管理者にだけ、ロックを強制解除できる設定画面への導線を出す。同じタブで開くと、直前に
	 * 「内容はこの画面に残っています」と言った内容を自分で破棄させることになるので、必ず新しいタブで開き、
	 * その旨を添える。
	 */
	function setAdminHint( container, visible ) {
		while ( container.firstChild ) { container.removeChild( container.firstChild ); }
		container.hidden = ! visible;
		if ( ! visible ) { return; }

		var link = makeElement( 'a', '', i18n.adminLink );
		link.href   = EdlkData.settingsUrl;
		link.target = '_blank';
		link.rel    = 'noopener noreferrer';

		container.appendChild( document.createTextNode( i18n.adminHint + ' ' ) );
		container.appendChild( link );
		container.appendChild( document.createTextNode( ' ' + i18n.newTab ) );
	}

	/**
	 * Shows the modal that says the save was stopped.
	 * 保存を止めたことを伝えるモーダルを出す。
	 *
	 * @param {Object}   info  { holderName, holderIsSelf }
	 * @param {Function} retry What "Save again" runs / 「もう一度保存」で実行する処理
	 */
	function showLockedModal( info, retry ) {
		var d    = buildDialog();
		var name = info.holderName;

		if ( info.holderIsSelf ) {
			dialogParts.title.textContent = i18n.selfTitle;
			setParagraphs( dialogParts.body, [ fill( i18n.selfBody, name ), i18n.stillHere, i18n.selfAction ] );
			setAdminHint( dialogParts.admin, false );
		} else if ( name ) {
			dialogParts.title.textContent = fill( i18n.otherTitle, name );
			setParagraphs( dialogParts.body, [ fill( i18n.otherBody, name ), i18n.stillHere, fill( i18n.otherAction, name ) ] );
			setAdminHint( dialogParts.admin, !! EdlkData.settingsUrl );
		} else {
			// The holder is a deleted user and has no name. The other party cannot be identified, so no action is suggested.
			// 保持者が削除済みのユーザーで名前が分からない。相手を特定できないので、行動は案内しない。
			dialogParts.title.textContent = i18n.unknownTitle;
			setParagraphs( dialogParts.body, [ i18n.unknownBody, i18n.stillHere ] );
			setAdminHint( dialogParts.admin, false );
		}

		retryAction = retry;

		// showModal() on an open dialog throws InvalidStateError (a rapid double click on a save button does this).
		// 開いているダイアログへの showModal() は InvalidStateError を投げる（保存ボタンの連打で起きる）。
		if ( ! d.open ) { d.showModal(); }
	}

	/**
	 * Shows a warning notice at the top of the screen. The PHP admin_notices are effectively invisible
	 * in the block editor, so both editors get their notice from JS.
	 * 画面上部に警告の通知を出す。ブロックエディタでは PHP の admin_notices は実質見えないので、
	 * 両エディタとも JS で出す。
	 *
	 * @param {string}   id    Notice ID; a notice with the same ID is replaced / 通知の ID（同じ ID の通知は置き換える）
	 * @param {string}   title Bold heading; not used in the block editor, whose notice is one string / 太字の見出し。ブロックエディタの通知は1つの文字列なので使わない
	 * @param {string[]} lines Body lines / 本文
	 */
	function showNotice( id, title, lines ) {
		if ( document.body.classList.contains( 'block-editor-page' ) ) {
			try {
				var notices = window.wp && wp.data && wp.data.dispatch( 'core/notices' );
				if ( notices && notices.createWarningNotice ) {
					notices.createWarningNotice( lines.join( ' ' ), { id: id, isDismissible: true } );
					return;
				}
			} catch ( err ) {
				// When the store is unavailable, fall through to the ordinary notice below.
				// ストアが使えないときは、下の通常の通知に落とす。
			}
		}

		$( '#' + id ).remove();
		var $notice = $( '<div class="notice notice-warning is-dismissible edlk-notice" role="alert"></div>' ).attr( 'id', id );
		if ( title ) {
			$notice.append( $( '<p></p>' ).append( $( '<strong></strong>' ).text( title ) ) );
		}
		lines.forEach( function( line ) {
			$notice.append( $( '<p></p>' ).text( line ) );
		} );

		var $anchor = $( '.wp-header-end' ).first();
		if ( $anchor.length ) {
			$anchor.after( $notice );
		} else {
			$( '#wpbody-content' ).prepend( $notice );
		}
		// Core's common.js adds the close button to an .is-dismissible notice that was added later.
		// コアの common.js が、動的に足された .is-dismissible の通知に閉じるボタンを付ける。
		$( document ).trigger( 'wp-notice-added' );
	}

	/**
	 * Removes a notice made by showNotice(), in both editors. A notice that says "you cannot save" must not
	 * outlive the situation it describes.
	 * showNotice() で出した通知を、両エディタから消す。「保存できません」と言う通知が、その状況が
	 * 終わったあとも残り続けてはいけない。
	 */
	function clearNotice( id ) {
		try {
			var notices = window.wp && wp.data && wp.data.dispatch( 'core/notices' );
			if ( notices && notices.removeNotice ) { notices.removeNotice( id ); }
		} catch ( err ) {
			// When the store is unavailable there is no block-editor notice to remove.
			// ストアが使えないときは、ブロックエディタ側に消す通知が無い。
		}
		$( '#' + id ).remove();
	}

	/**
	 * Tries to acquire the lock (keeps it if already held; returns the holder if someone else has it).
	 * The callback receives { ok, locked, holderName, holderIsSelf }; ok means the server answered properly.
	 * ロック取得を試みる（既に保持していれば維持、他者保持中ならその情報を返す）。
	 * callback には { ok, locked, holderName, holderIsSelf } を渡す。ok は「サーバから正しい応答が返った」。
	 */
	function acquire( callback ) {
		$.post( EdlkData.ajaxUrl, {
			action:     'edlk_acquire',
			nonce:      EdlkData.nonce,
			post_id:    postId,
			session_id: sessionId
		} ).done( function( res ) {
			if ( ! res || ! res.success ) {
				callback( { ok: false, locked: false, holderName: '', holderIsSelf: false } );
				return;
			}
			var result = {
				ok:           true,
				locked:       !! res.data.locked,
				holderName:   res.data.holderName || '',
				holderIsSelf: !! res.data.holderIsSelf
			};
			if ( ! result.locked ) {
				hasHeldLock = true;
				// The lock is ours now, so neither "open in another edit screen" nor "taken over" is true any more.
				// ロックはこの画面のものになったので、「別の編集画面で開かれている」も「奪われた」ももう偽になる。
				clearNotice( 'edlk-self-lock' );
				clearNotice( 'edlk-lock-lost' );
			}
			callback( result );
		} ).fail( function() {
			// On a network error the save is left to the server-side gate.
			// 通信エラー時は保存自体はサーバー側ゲートに委ねる
			callback( { ok: false, locked: false, holderName: '', holderIsSelf: false } );
		} );
	}

	/**
	 * Runs a save action after checking the lock. When the lock cannot be had, shows the modal, and
	 * "Save again" starts over from the same check.
	 * 保存系の操作をロック確認つきで実行する。ロックを取れなければモーダルを出し、
	 * 「もう一度保存」で同じ確認からやり直す。
	 */
	function attempt( proceed ) {
		acquire( function( result ) {
			if ( result.locked ) {
				showLockedModal( result, function() { attempt( proceed ); } );
				return;
			}
			proceed();
		} );
	}

	/* ---------- Try to acquire the lock on load / 初回ロード時にロック取得を試みる ---------- */
	acquire( function( result ) {
		// Only when another edit screen of the same account already holds the lock. When another user holds it,
		// core shows its own full-screen modal, so this would be a duplicate.
		// 同じアカウントの別の編集画面が先にロックを握っている場合だけ知らせる。他のユーザーが握っている場合は
		// コアが全画面のモーダルを出しているので、二重にしない。
		if ( result.locked && result.holderIsSelf ) {
			showNotice( 'edlk-self-lock', '', [
				fill( i18n.selfNoticeLine1, result.holderName ),
				i18n.selfNoticeLine2
			] );
		}
	} );

	/* ---------- Extend by Heartbeat and watch for a lost lock / Heartbeatで延長し、途中でロックを失っていないか監視 ---------- */
	$( document ).on( 'heartbeat-send.edlk', function( e, data ) {
		data.edlk = { post_id: postId, session_id: sessionId };
	} );
	$( document ).on( 'heartbeat-tick.edlk', function( e, data ) {
		if ( ! data || ! data.edlk || ! data.edlk.lost ) { return; }
		// When another user takes over, core's post-taken-over dialog and revision saving deal with it, so stay
		// quiet. Only a takeover by another edit screen of the same account is reported.
		// 他のユーザーに奪われた場合はコアの post-taken-over ダイアログとリビジョン保存が面倒を見るので、
		// ここでは黙る。同じアカウントの別の編集画面に奪われた場合だけ知らせる。
		if ( ! hasHeldLock || ! data.edlk.holderIsSelf ) { return; }
		// Stay "lost" until the lock is won back, so later beats (every 15 seconds) do not repeat the notice.
		// 取り戻すまでは失ったまま。以後の beat（15秒ごと）で同じ通知を繰り返さない。
		hasHeldLock = false;
		showNotice( 'edlk-lock-lost', i18n.lostTitle, [
			fill( i18n.lostBody, data.edlk.holderName || '' ),
			i18n.stillHere,
			i18n.lostAction
		] );
	} );

	/* ---------- Release explicitly on leaving / 離脱時にロックを明示解放 ---------- */
	// A navigation caused by saving does not release here; the server-side save_post handles it.
	// 保存によるページ遷移では解放しない（サーバー側save_postでの解放に任せる）
	var isSubmitting = false;

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

	/* ---------- Classic editor: gate the submit of #post / クラシックエディタ: #post のsubmitをゲート ---------- */
	var classicForm = document.getElementById( 'post' );
	if ( classicForm ) {
		var hidden = document.createElement( 'input' );
		hidden.type  = 'hidden';
		hidden.name  = 'edlk_session_id';
		hidden.value = sessionId;
		classicForm.appendChild( hidden );

		classicForm.addEventListener( 'submit', function( e ) {
			e.preventDefault();

			/*
			 * HTMLFormElement.submit() has no submitter, so the pressed button's name and value never
			 * reach $_POST. Core reads $_POST['publish'] both to move a draft to "publish"
			 * (_wp_translate_postdata) and to pick the admin notice, so without carrying it over a
			 * draft's "Publish" saves as a draft AND still reports "Post updated." -- the user has no
			 * way to notice. Take the submitter and re-add it as a hidden field.
			 * HTMLFormElement.submit() は submitter を持たないため、押されたボタンの name/value が
			 * $_POST に乗らない。コアは $_POST['publish'] を見て下書きを公開へ移し
			 * （_wp_translate_postdata）、管理画面のメッセージもこれで選ぶので、引き継がないと
			 * 下書きの「公開」が下書きのまま保存されたうえ「投稿を更新しました。」と出る。
			 * 利用者には気づく手段が無い。submitter を hidden として付け直す。
			 */
			var submitter = e.submitter;
			if ( ! submitter ) {
				// Older browsers have no e.submitter. Only accept a focused element that really is a
				// submit control, so that pressing Enter in a text field does not duplicate its value.
				// 古いブラウザには e.submitter が無い。テキスト欄で Enter を押したときにその値を
				// 二重に送らないよう、フォーカスされている要素が送信コントロールのときだけ採る。
				var active = document.activeElement;
				if ( active && classicForm.contains( active ) &&
					( 'submit' === active.type || 'BUTTON' === active.tagName ) ) {
					submitter = active;
				}
			}

			attempt( function() {
				if ( submitter && submitter.name ) {
					var carried   = document.createElement( 'input' );
					carried.type  = 'hidden';
					carried.name  = submitter.name;
					carried.value = submitter.value;
					classicForm.appendChild( carried );
				}
				isSubmitting = true;
				// submit() does not fire the submit event again, so the gate is not re-entered.
				// submit()経由はsubmitイベントを再発火しないためガードを再帰しない
				classicForm.submit();
			} );
		} );
	}

	/* ---------- Block editor: gate clicks on the save buttons / Gutenberg: 保存系ボタンのクリックをゲート ---------- */
	var GUARD_SELECTOR = '.editor-post-save-draft, .editor-post-publish-button, .editor-post-publish-panel__header-publish-button .editor-post-publish-button';

	document.addEventListener( 'click', function( e ) {
		var el = e.target.closest( GUARD_SELECTOR );
		if ( ! el || el.getAttribute( 'data-edlk-bypass' ) === '1' ) { return; }

		e.preventDefault();
		e.stopImmediatePropagation();

		attempt( function() {
			// The modal's "Save again" can come back long after the click, and the block editor may have
			// re-mounted the button by then. A detached element's click() does nothing and throws nothing,
			// so look the button up again when the one we captured is no longer in the document.
			// モーダルの「もう一度保存」は最初のクリックからかなり後に返ってくることがあり、その間に
			// ブロックエディタがボタンを作り直していることがある。DOM から切り離された要素の click() は
			// 何も起きず例外も出ないため、捕まえた要素が文書内に無ければ引き直す。
			var target = document.body.contains( el ) ? el : document.querySelector( GUARD_SELECTOR );
			if ( ! target ) { return; }
			target.setAttribute( 'data-edlk-bypass', '1' );
			target.click();
			target.removeAttribute( 'data-edlk-bypass' );
		} );
	}, true );

	/* ---------- Block editor: send the session ID with REST saves (for the server-side gate) / Gutenberg: REST保存リクエストに session_id を付与（サーバー側ゲート用） ---------- */
	if ( window.wp && wp.apiFetch ) {
		wp.apiFetch.use( function( options, next ) {
			options.headers = options.headers || {};
			options.headers[ 'X-Edlk-Session' ] = sessionId;
			return next( options );
		} );
	}

} )( jQuery );
