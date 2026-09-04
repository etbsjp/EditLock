# ETBS Edit Conflict Guard（旧 EditLock）

★★★ **2026-08-31 に改名した。** wordpress.org の人手レビューで「`EditLock` は既存の
`Edit Lock`（slug `edit-lock`）と紛らわしい」と指摘され、名前とスラッグを変更した。

| | 旧 | 新 |
|---|---|---|
| 表示名 | EditLock | **ETBS Edit Conflict Guard** |
| スラッグ／フォルダ／テキストドメイン | `editlock` | **`etbs-edit-conflict-guard`** |
| メインファイル | `editlock.php` | **`etbs-edit-conflict-guard.php`** |
| 関数・定数・オプションの接頭辞 | `edlk_` / `EDLK_` | **変更なし**（スラッグ由来ではないため。既存サイトの設定が残る） |

★ `dist`（1.0.4）は**旧名のまま**。改名したのは wp.org 版だけで、両者は WordPress から見て別プラグイン。

etbs が配布する WordPress プラグイン。共通ルールの正本は `~/.claude/etbs-plugin-rules.md`。

## ★★★ ブランチ

| ブランチ | 中身 | 配信経路 |
|---|---|---|
| **`wporg`** | **wp.org 版（`ETBS Edit Conflict Guard`）。ここが wp.org 版の恒久の幹** | wordpress.org の SVN。**人が `svn ci` を押したときだけ** |
| `dist` | 旧 EditLock 1.0.x（別プラグイン） | 同梱 PUC。**push した瞬間に既存 約19サイトへ** |

★★ **`wporg` は `dist` 一本という共通ルール（`~/.claude/etbs-plugin-rules.md` §1）の意図的な例外。**
棚卸しで「残置ブランチ」として削除しないこと。正本の EditLock 節にも同じ記載がある。

★ **`wporg` へのマージは配信ではない。** 配信は SVN commit（人が押す）。この違いが下の「版数」節の
例外の根拠になっている。

### ★★ 接頭辞のルール

`etbs-edit-conflict-guard.php` の **legacy stand-down（`Etbs_Ecg_Legacy_Guard::is_legacy_active()` の
`return`）より前**で宣言する新しいシンボルは、必ず **`etbs_ecg_`** を使う。
旧 EditLock が有効なサイトでもそのコードは実行される。旧版は `edlk_load_textdomain()` を
`function_exists` ガード付きで宣言しており、`active_plugins` はソートされるので **`editlock/` が先**。
そこで同名を使うと、**新版側が Cannot redeclare で Fatal**（新版はガードを付けない方針のため）。
逆順で読まれた場合は**旧版のガードがスキップされ、旧版の翻訳が黙って死ぬ**。
どちらも許容できないので、衝突しえない接頭辞を使う。

`edlk_` を使ってよいのは `inc/func.php` の中（stand-down より後）だけ。**横並びで揃えにこないこと。**

### ★★ `.po` を触ったら必ず `.mo` を再生成する

`languages/` には `.po` と `.mo` の両方を追跡している。`.mo` を作り直さずにコミットすると、
**画面は古い翻訳のまま、エラーも出ない。**

### ★★ 静的解析の基準（このリポジトリは CI 対象外）

EditLock は共通ルール 2.7 節で **CI（WPCS）の対象外**と決まっている（2026-08-28）。
`.phpcs.xml.dist` も `composer.json` も無いのは書き忘れではなく、その決定の結果。

★★ **ただしこの決定は「再提案しないこと」で止まっていない。** 正本 §2.7 は 2026-09-02 に
**除外根拠の一部（「PUC が無い」）の失効を記録し、「CI 除外を維持するかは要再検討」**に戻している
（#195 で PUC を `dist` に復元したため）。**判断は必ず正本を見ること。**
ここに「再提案しないこと」とだけ書くと、正本を開かずに検討をやめてしまう。
**手で回すときは standard とコマンドを固定すること。**「通過」では基準が無い。

```sh
# phpcs + WPCS は一時ディレクトリに入れてよい（リポジトリにファイルを足さない）
phpcs --standard=WordPress-Extra --report=summary $(git ls-files '*.php')
```

| 対象 | 実測（2026-09-04） |
|---|---|
| `wporg`（1.1.0 / `c2ab176`） | **0 ERROR / 6 WARNING** |
| 1.1.1 | **0 ERROR / 6 WARNING**（悪化ゼロ） |

★ 合格線は「0 ERROR かつ **上の基準値からの悪化ゼロ**」。基準値は**その場で測り直してから**比べること。

★★★ **`WordPress-Extra` には `WordPress-Docs` が入っていない。** PHPDoc の欠落はこの検査を
すり抜ける（2026-09-04 に実際にすり抜けた）。**「0 ERROR」は PHPDoc を見ていない。**
穴を知っているだけでは塞げないので、**PHPDoc は別に撃つこと**:

```sh
phpcs --standard=WordPress-Docs --sniffs=Squiz.Commenting.FunctionComment $(git ls-files '*.php')
```

（2026-09-04 実測: 1.1.0・1.1.1 とも指摘 **0 件**）

★ 本来の門は Plugin Check（共通ルール 2.7）。**検査対象は zip の展開物**にすること。
実測（2026-09-04・同一フォルダ名で測定）: 1.1.0 が **0 ERROR / 18 WARNING**、
1.1.1 が **0 ERROR / 20 WARNING**。増えた 2 件は
`PrefixAllGlobals.NonPrefixedFunctionFound`（`etbs_ecg_load_textdomain`）と
`DiscouragedFunctions.load_plugin_textdomain`。**どちらも意図した実装の結果**で、前者は
`function_exists` ガードを外したことで可視化されたもの、後者は同梱翻訳そのものへの指摘。
★ 「`edlk_` 接頭辞なら警告されない」ではない —— `inc/func.php` の `edlk_*` 24 本が出ないのは
**ガードの中にあるため**で、`uninstall.php` の `edlk_uninstall()` はガード外なので**基準の 18 件側に
含まれている**。

★★★ **この 2 件は永久に出続ける。消しにいかないこと。**

- `PrefixAllGlobals` に合わせて `etbs_ecg_load_textdomain` を改名すると、上の**接頭辞ルールと衝突する**
- ★★ **`DiscouragedFunctions` を消す目的で `load_plugin_textdomain()` を外さないこと。**
  外すと同梱の `languages/` が `WP_Textdomain_Registry` に custom path として登録されず、
  JIT はそこを探しに行かない。**画面は英語に戻るが、エラーは一切出ない。**
  この警告の前提（「wp.org がホストするなら言語パックが届くので手動で呼ぶ必要はない」）は、
  **同梱ファイルを持つこのプラグインには当てはまらない**

## レビュー工程に大（シニアエンジニア）を追加する

このリポジトリでは、安藤（`vk-code-reviewer`）のレビューのあと、**PR を作成する前に**
大（`etbs-senior-wp`）の監査を必ず通すこと。大は etbs の申し送りと過去に踏んだ罠に照らして
「リリースできる形になっているか」を見る担当で、安藤の一般的なコード品質レビューとは層が違う。

- `Agent` ツールで `subagent_type: etbs-senior-wp`、`name: etbs-senior-wp`、
  **`run_in_background: false`** で起動する
- **`isolation: "worktree"` は使えるなら付ける**（付けないと起動応答は「成功」と返るのに
  一度も作業せず待機状態に入ることがある）。ただし ★★ **作業ディレクトリが git リポジトリでないと
  使えない**。その場合は **isolation なしで起動してよい**。「必須」ではない。
  **見分け方は起動応答の形**——`output_file` 付きの正常形なら動いている
- prompt には対象リポジトリ・ブランチ・差分（または PR 番号）を渡す
- 大には **出力の末尾に `監査結果: PASS` または `監査結果: FAIL` を必ず書くよう指示する**
  （★ 大の定義ファイルには出力形式の指定が無いため、指示しないと合否を機械判定できない）
- `監査結果: PASS` を受け取るまで PR を作成しない。`FAIL` なら和田へ差し戻して再監査する

★ 大は vk-agents のメンバー表に登録されていないため、指示が無いと**永久に呼ばれない**。

## 検証環境

Local の `editlock`（`editlock.etbs.lc`）。このプラグインは `dirname( __FILE__ )` を
1階層のみ（`inc/func.php` の require、および `inc/func.php` から `inc/class-*.php` への require）
に使っており `dirname( __FILE__, N )` の複数階層遡りは無いため、**シンボリックリンク設置でよい**。

CLI 検証では Local の php.ini を `-c` で渡すこと。渡さないと「データベース接続確立エラー」になり、
**サイトが停止しているように見える**（実際は動いている）。`<runId>` は
`ls -d ~/Library/Application\ Support/Local/run/*/mysql/mysqld.sock` で特定する。

### ★★★ 実画面を見る前に、シンボリックリンクの向き先を必ず確認する

作業エージェントは **worktree**（`<リポジトリ>/.claude/worktrees/agent-xxxx/`）の中で実装する。
一方 Local サイトのプラグインは**本体クローンへの symlink** で、本体は `dist` のまま。

```sh
readlink "$HOME/Local Sites/editlock/app/public/wp-content/plugins/editlock"
ls       "$HOME/Local Sites/editlock/app/public/wp-content/plugins"
```

★★ **`readlink` だけでは足りない。`ls` も必ず見ること。** 検証で zip 展開物を
`wp-content/plugins/etbs-edit-conflict-guard/` に**実体ディレクトリとして**置くことがあり、
それが残っていると `readlink` は「本体クローンを向いている、OK」と答えるのに、
実際に動いているのは置き去りの展開物になる。**このガード自体が無効化される。**

★★ **旧版を退避したら必ず戻すこと。** 旧版（`editlock` symlink）を外して検証するときは
`.editlock-parked` のように**先頭ドット**へ改名する（コアの `get_plugins()` は
`str_starts_with( $file, '.' )` で読み飛ばすため、`active_plugins` の編集と二重に効く）。
**撤収では `active_plugins` を戻す前にフォルダ名を戻す。** 順番を逆にすると、存在しない
プラグインを有効化することになり、`readlink` が空を返す状態で次のセッションが始まる。

```sh
P="$HOME/Local Sites/editlock/app/public/wp-content/plugins"
mv "$P/editlock" "$P/.editlock-parked"   # 検証前
mv "$P/.editlock-parked" "$P/editlock"   # 撤収時（active_plugins を戻す前）
```

→ **そのままでは、画面で見ているのは実装前のコード。** 2026-08-25 の #2（UI文言の英語化）で実際に起きた。
PR 作成時点でサイト側に `languages/` も `Domain Path` も存在せず、「英語表示で日本語が出ないこと」の
確認が**構造的に成立していなかった**。

実画面が完了条件に入る issue では、検証の前に worktree へ張り替え、**終わったら必ず本体クローンへ戻す**:

```sh
P="$HOME/Local Sites/editlock/app/public/wp-content/plugins/editlock"
ln -sfn ~/Downloads/GitHub/editlock/.claude/worktrees/agent-xxxx "$P"   # 検証前
ln -sfn ~/Downloads/GitHub/editlock "$P"                                # 検証後（必須）
```

★ **#4 の Plugin Check も同じ罠を踏む。** 張り替えないと古いコードを検査して Error ゼロになる。

### 文言の検証は PHP CLI で数値にできる（ブラウザ・ログイン不要）

サイト稼働版の PHP は `lsof -p <php-fpm master PID> | grep lightning-services` で特定する
（2026-08-25 時点で **8.3.17**）。`wp-load.php` を読み `switch_to_locale()` で切り替え、
`.po` の msgid を全件 `__()` に通せば「英語で日本語0件／日本語で全件翻訳」が数で出る。

★★★ **`is_textdomain_loaded()` を合格条件にしないこと**（2026-09-04 に実測で否定した）。
WP 7.1 の `load_plugin_textdomain()` は `set_custom_path()` を呼んで `return true` するだけで、
**実際の読み込みを一切しない**（`WP_LANG_DIR` を試す処理も `load_textdomain()` の呼び出しも無い）。
したがって**呼んだ直後は必ず false** になる。読み込みは最初の `__()` による JIT で起きる。

代わりに、ロケールを切り替えた**後**に読み込み元の実パスを断定する:

```php
$GLOBALS['wp_textdomain_registry']->get( 'etbs-edit-conflict-guard', 'ja' );
// → .../plugins/etbs-edit-conflict-guard/languages/ で終わること
```

あわせて `wp-content/languages/plugins/etbs-edit-conflict-guard-ja*` が**無い**ことも毎回見る
（言語パックが来ていれば同梱を読まなくても日本語になるため、同梱の検証にならない）。

★ `set_current_screen()` を使うなら
`wp-admin/includes/class-wp-screen.php` を先に require する。

## アンインストール

★ `uninstall.php` の方針は**案A**（task-queue #108）。判定は3分類。

| 利用者が作ったコンテンツ（投稿・投稿メタ） | 利用者が設定した値（オプション） | 一時状態・自分が仕掛けた cron |
|---|---|---|
| **消さない** | **消さない** | **消す** |

理由は害の非対称性。消さないことの害は「DB に少量のレコードが残る」だけだが、
消すことの害は復旧不可能。迷ったら残す側に倒す。

このプラグインでの当てはめ:

- **残す** … `edlk_ttl_seconds` / `edlk_excluded_post_types` / `edlk_guard_trash`
  （いずれも利用者が設定した値）
- **消す** … `{prefix}edlk_locks` テーブル（編集中ロックの一時状態）、
  `edlk_cleanup_cron`（自分で仕掛けた cron）

★ 「オプションを消していない」のは判断の結果であって書き忘れではない。逆に `DROP TABLE` と
`wp_unschedule_hook()` を「方針違反」と読んで消さないこと。独自テーブルと cron を持つのは
8本のうちこのプラグインだけだが、「消す」に該当するのはこのプラグインと pageguard（別の理由で
`pggd_lockouts` / `pggd_diagnosis_result` を消す）の2本で、他の6本は「何も消さない」が正しい。

## 版数

★★★ **`dist` 系列では、実装の PR で版数を上げてはいけない。** 実装コミットだけを積むこと。

★ **`wporg` 系列（wp.org 版）はこの規定の対象外。** 禁止の根拠は「`dist` は push＝即配信」であって、
`wporg` にその経路は無い（配信は人が押す SVN commit）。加えて Plugin Check の `stable_tag_mismatch` は
`Version:` と `Stable tag:` の一致を見るため、版数を抜いた木は提出物と別物になり、提出用 zip は
`git archive` で作るので**版数が git に無いと提出物を履歴から再現できない**。上の「ブランチ」節を参照。

- `dist` は配信先そのもの。版数を上げてマージした瞬間に、既存の自社配布ユーザー（2026-08-25 実測で
  約19サイト）へ配られる
- 版数上げ・`dist` push は **engine を止めてから人が行う**（`~/.claude/etbs-plugin-rules.md`）

★★★ **PUC は `dist` に入っている**（実測 2026-09-04:
`git ls-tree -r --name-only dist | grep -c update-checker` → **116**）。
#195 で `d7fdc09^` からバイト単位で復元し、**1.0.4 として既に配信済み**。
★★ **移行案内はもう届けた。**「撃てる弾が1発だけ残っている」という状態ではない。

★ このファイルには 2026-09-04 まで「PUC は撤去済み・0件」「`dist` は 1.0.3 のまま凍結」「撃てる弾は1発だけ」と
書かれていたが、**#195 の実施後もその記述が更新されておらず、すべて実態と食い違っていた**ので削除した。
実測ラベル（「実測 2026-08-31」）まで付いていたため、次に読む人が再検証せずに信じる状態だった。
その誤情報は「もう経路は無い」という結論を導き、正本 §1 が禁じている `dist` の private 化・削除への
入口になる。**数字を書くときは、その場でコマンドを走らせてから書くこと。**

→ したがって **`dist` の版数を上げてよいのは ①セキュリティ修正 ②新しい WP での動作不能 の2つだけ**
（`~/.claude/etbs-plugin-rules.md` §1）。

★★★ **改名によって「引き渡し」は成立しなくなった（2026-08-31）。**

コアの更新チェックはインストール済みプラグインを**フォルダ名（スラッグ）**で api.wordpress.org に
照会する。既存 約19サイトのフォルダは `editlock`、wp.org 版は `etbs-edit-conflict-guard` なので、
**この2つは永久に繋がらない。**

→ したがって **`wporg` 系列を `dist` にマージすることは永久に無い。** 「公開されるまで待つ」といった
条件付きの保留ではない。`pr-10`（1.1.0）は 2026-09-02 に CLOSED、ローカルブランチも 2026-09-04 に
削除済み（`69eb1b8`）。両者は WordPress から見て別プラグインなので、**マージは移行の手段にならない。**

★ 乗っ取りリスクは残るが、想定より小さい。今回の審査で `editlock` は `edit-lock` との類似を理由に
弾かれており、同じ門は他人にも立つ【推測】。ただしスラッグは返信で個別に要求できるためゼロではない。

★★★ **新旧を同じサイトに並べてはいけない（2026-08-31・`fa4d2b6` で対策済み）。**

旧版と新版は `{prefix}edlk_locks` テーブル・`edlk_cleanup_cron`・オプションキーを**共有する**。
さらに旧版は `Edlk_Lock_Manager` / `Edlk_Settings_Page` と `EDLK_VERSION` / `EDLK_PLUGIN_FILE` を
新版と同名で宣言していた。

- 同時に有効化 → **クラス再宣言で Fatal**（読み込み順は `editlock` < `etbs-edit-conflict-guard`）
- 旧版を削除 → 旧版の `uninstall.php` が共有テーブルと cron を消す。テーブルは有効化時にしか
  作られないため**二度と戻らず、全ゲートが「ロック無し」で通過するのにエラーは出ない**

対策として新版側に3つ入れた。**オプションキー・テーブル名・cron・nonce・AJAX アクションは
据え置き**（スラッグ由来ではないので、変えると既存サイトの設定が引き継げなくなる）。

1. クラスを `Etbs_Ecg_*`、定数を `ETBS_ECG_*` に改名
2. `Etbs_Ecg_Legacy_Guard` … 旧版が有効な間は新版を読み込まず、理由を管理画面に出す
3. `edlk_repair_storage()`（`admin_init`）… cron の欠落を合図にテーブルと cron を作り直す

★ あわせて `https://etbs.jp/product/editlock/` は `https://etbs.jp/product/etbs-edit-conflict-guard/`
へ作り直すこと（`Plugin URI:` がこの URL を指している）。

★ 2026-08-25 に #1 の PR で 1.0.3 → 1.0.4 に上げられ、マージ前に revert した実績がある。
下の「版数の置き場」は**`dist` 系列で人がリリース時に上げるときの手順**。`wporg` 系列では実装 PR に含める
（上の「版数」節の例外を参照）。

★★★ **版数の置き場は「固定パターンの grep で守れる3箇所」＋「changelog 追記1件」。**
（#3 で `readme.txt` を追加したため 2 → 3 に増えた）

**grep で守れる3箇所**:

- `etbs-edit-conflict-guard.php` の `Version:` ヘッダ
- `etbs-edit-conflict-guard.php` の `define( 'ETBS_ECG_VERSION', ... )`
  （`wp_enqueue_script()` のキャッシュバスターとして使用中。★ **参照箇所に行番号を書かない**——
  2026-09-04 にここへ `inc/func.php:224` と書いたところ、**同じ PR の次のコミットが同ファイルに
  21 行足して 245 になり、その場で無効化した**。`grep -rn ETBS_ECG_VERSION --include=*.php .` で見ること）
- **`readme.txt` の `Stable tag:`**

```sh
grep -nE "^ \* Version:|define\( 'ETBS_ECG_VERSION'" etbs-edit-conflict-guard.php
grep -n  "^Stable tag:" readme.txt
```

★★ **`readme.txt` の `== Changelog ==` にその版のエントリを書くことは、上の3箇所とは別立てにする。**
確認できないわけではない（`grep -n "^= 1\.1\.1 =" readme.txt`）が、**版数ごとにパターンを書き換える
必要がある**ので固定パターンの grep 2本には混ぜない。混ぜると「**grep が緑だから全部揃った**」と
読まれ、changelog が空のまま出る。

★ 上げる際は3つとも揃えること。**現在値はここに書かない**（必ず陳腐化する）。上の grep で確認する。

★★ **`Stable tag` を揃え忘れると2通りに壊れる。** Plugin Check が `stable_tag_mismatch` を出して
止まるか、気付かず wp.org の SVN へ上げた場合は **`Stable tag` が指すタグが配信版になる**ため、
新版を上げたのに利用者には古い版が配られ続ける。ordermemo で踏んだ `ORMM_VERSION` のヘッダとの
ずれと同じ構図なので、リリース時は必ず上の2本の grep を両方通すこと。

## PR の作法

★★ **`Closes` / `Fixes` / `Resolves` を PR 本文に書かない。** 閉じるキーワードは**リポジトリを
またいで効く**ため、親 issue に付くと1本のマージで無関係な issue まで閉じる。参照だけにすること。

```
対象 issue: https://github.com/etbsjp/EditLock/issues/2
```

★ **PR 本文の issue 参照は完全 URL、issue 本文の相互参照はベア `#NN`**（逆にしない）。
★ 2026-08-25 の #1・#2 の PR で2回とも `Closes #N` が入っており、どちらも人が直している。

★ **レビュー結果は必ず issue か PR のコメントとして残す。** PR 本文に「安藤 PASS」と書くだけでは
記録にならない（#2 の PR は本文に4名分の実施状況を書いていたが、issue にも PR にもコメントが
1件も無く、何をもって通したかを後から追えなかった）。

## 配布物

★ **この節は `dist` 系列の話。** `wporg` に PUC は無く、配布物は `git archive` から作って人が SVN へ上げる。

`dist` ブランチへのマージ＝配信。PUC が配る zip には**追跡しているファイルが全部入る**ため、
`.gitignore`（追跡させない）と `.gitattributes` の `export-ignore`（zip から落とす）は役割が別。
両方を維持すること。

## 宣言（Requires）の方針

★★ `Requires at least`（WP）は**実測した下限があるときだけ書く。無ければ書かない。**
`Requires PHP` は実測下限ではなく **「etbs が動作を保証する最低 PHP」の宣言として 7.4 を書く**。
**この2つは過剰宣言したときの害の向きが逆なので、同じ基準で扱わない。他のプラグインと横並びで揃えない。**

| | 過剰に宣言すると | 過小に宣言すると |
|---|---|---|
| `Requires at least`（WP） | **有効化・更新が拒否される**＝修正が届かない個体を作る | 古い WP に入るが、使う API が無ければその場で分かる |
| `Requires PHP` | 入れられる環境が狭まるだけ | 構文エラーで白画面。しかも FTP 手動設置は止められない |

- このリポジトリは `Requires at least: 6.7` を**削除**した（task-queue#88）。
  理由：ブロックを登録しておらず（`block.json` / `register_block_type` / `registerBlockType` は0件）、
  自前コードの最も新しい WP API が `wp_unschedule_hook()`（WP 4.9）、フックでは `pre_trash_post`
  （WP 4.9・関数側と同値）、同梱 PUC を含めても `wp_doing_cron()`（WP 4.8）で、
  **合成した実下限は 4.9。6.x 帯に下限が存在しない**。6.7 は初版からの定型文で、
  特定の API に紐づいたものではなかった（実測は 2026-08-21 の task-queue#88 コメント参照）
- `Requires PHP: 7.4` は**据え置き**。★ これは実測下限ではない。「7.4 で `php -l` が通る」ことは
  7.4 で*足りる*証明であって *必要*である証明ではなく、7.3 以下は未検証。
  そのうえで保守方針として 7.4 を宣言している。次に見た人が「下限じゃないなら消せる」と
  判断しないよう、この理由を残しておく

★★ **宣言の置き場は3つ。**（#3 で `readme.txt` を追加したため 2 → 3 に増えた）
プラグインヘッダ／`README.md` の「必要環境」／**`readme.txt` の `Requires PHP:`**。
**ヘッダの `Requires at least` / `Requires PHP` を変更したときは、`README.md` と `readme.txt` の
該当箇所も同時に見直すこと。** ★とくに `readme.txt` 側は忘れやすいのに影響が大きい ——
**PUC は `setInfoFromRemoteReadme()` で `readme.txt` の値をヘッダに上書きする**ため、
readme が古いと配信判定まで古い値で動く（`~/.claude/etbs-plugin-rules.md`）。★ **これは `dist` 系列の話**
（`wporg` に PUC は無い）。 `README.md` は `export-ignore` されておらず配布 zip に含まれるため、
ヘッダだけ直しても README が古いままだと利用者には要件が伝わり続ける。揃えないまま放置すると、
次に見た人がどちらが正しいか分からず、README に合わせてヘッダへ過剰宣言を書き戻す方向に
動きかねない。

★★ **「据え置き」と「新規に足す」は別問題**（2026-08-25 / task-queue #111 で再確認）。
既に宣言している版を据え置いても新たに締め出す個体は生まれないが、**無宣言のプラグインに
`Requires PHP` を新しく足すと、いま更新が届いている個体を以後届かなくする**。
`woo-checkout-colorbox` と `widget-shortcode-tools` が無宣言なのは、この理由による意図的な判断。
**8本で揃えにこないこと。**
