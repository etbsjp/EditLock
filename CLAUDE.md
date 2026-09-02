# EditLock

etbs が配布する WordPress プラグイン。共通ルールの正本は `~/.claude/etbs-plugin-rules.md`。

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
`is_textdomain_loaded()` も併せて見ること。`set_current_screen()` を使うなら
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

★★★ **実装の PR で版数を上げてはいけない。** 実装コミットだけを積むこと。
版数上げ・`dist` push は **engine を止めてから人が行う**（`~/.claude/etbs-plugin-rules.md`）。

- `dist` は配信先そのもの。版数を上げてマージした瞬間に、既存の自社配布ユーザー
  （2026-08-25 実測で **約19サイト。いずれも etbs.jp のショップから落とした第三者**で、
  こちらは管理画面に入れない）へ配られる

## ★★★ 現在地（2026-09-02 更新。ここより古い記述を信じないこと）

**wordpress.org での公開は完了した。**

| | |
|---|---|
| 公開物 | `ETBS Edit Conflict Guard` / スラッグ `etbs-edit-conflict-guard` / 1.1.0 / SVN r3676958 |
| 公開 URL | https://wordpress.org/plugins/etbs-edit-conflict-guard/ |
| `dist`（この系列） | **EditLock 1.0.x のまま維持する。1.1.0 にはしない** |

★★★ **新旧はコアの更新機能では永久に繋がらない。**
コアはインストール済みプラグインを**フォルダ名（スラッグ）**で照会するが、既存サイトのフォルダは
`editlock`、wp.org 版は `etbs-edit-conflict-guard`。**別物として扱われる。**
19サイトは**手で移行するしかない**。

★★★ **`pr-10`（1.1.0）を `dist` にマージしてはいけない。破棄する。**
`pr-10` は PUC を1行も含まず版数が 1.1.0 なので、**マージ・push した瞬間に19サイトの PUC が取得し、
移行案内の無い版に置き換わる。以後こちらから何も届かない。不可逆。**

★ **これ以前のこのファイルには「公開後に 1.1.0 を `dist` へマージするのが引き渡しリリースとして必要。
マージしないままだと誰も移らない」と書かれていた。これは誤りだったので削除した。**
その記述は「wp.org に `editlock` というスラッグで公開される」という前提で書かれていたが、
審査で `edit-lock` との類似を理由に改名させられ、前提が消えた。

## ★★★ PUC は `dist` に復元済み（task-queue #195）

`d7fdc09` で撤去したものを **`d7fdc09^` からバイト単位で復元した**（`inc/plugin-update-checker/`・
`editlock.php` のブートストラップ）。理由は2つ。

1. **19サイトへ移行案内を届ける経路がこれしか無い。**
   PUC を残せば、文面を誤っても次の版で訂正できる。**終端リリースにすると一発勝負になる**
2. ★★★ **PUC の `excludeEntityFromWordPressAPI`（`UpdateChecker.php` `installHooks()` で条件分岐なしに登録）が、
   コアが `api.wordpress.org/plugins/update-check/1.x/` へ送る payload から `editlock/editlock.php` の行を
   削除している。**つまり **PUC が生きている限り、19サイトの `editlock` は wp.org から見えていない。**
   旧スラッグ `editlock` を第三者が取得しても、その更新は提示されない

→ **PUC を外した版を配ることが、乗っ取りの窓を自分で開ける行為になる。**（かつてこのファイルには
逆のことが書かれていた。撤去済みであることが危険なのであって、同梱が危険なのではない）

## リリース手順（人が行う）

★★★ **版数の置き場は4箇所。**

1. `editlock.php` の `Version:` ヘッダ
2. `editlock.php` の `define( 'EDLK_VERSION', ... )`（`inc/func.php` で `wp_enqueue_script()` の
   キャッシュバスターとして使用中）
3. `readme.txt` の `Stable tag:`
4. ★ **`readme.txt` の `== Changelog ==` と `== Upgrade Notice ==` に、その版のエントリを書く**

★★★ **4 を落とすと、配ったのに誰も読まない。**
PUC は `setInfoFromRemoteReadme()`（`Vcs/PluginUpdateChecker.php:195-197`）で `readme.txt` の
`== Upgrade Notice ==` からその版のエントリを拾い、コアがプラグイン一覧の行直下に太字で表示する。
**ここが空だと、更新を当てない管理者は更新の目的を知る機会がゼロになる。**
`== Description ==` も PUC 経由で「詳細を表示」モーダルに流れるので、内容が古いままにしない。

**現在は 1〜3 が 1.0.3 で一致し、4 には 1.0.4 のエントリが先に用意されている**
（文面だけ先に置き、版数を上げるのはリリース時）。

```sh
grep -nE "^ \* Version:|define\( 'EDLK_VERSION'" editlock.php
grep -n  "^Stable tag:" readme.txt
grep -n  "^= 1\." readme.txt
```

★★ **`Stable tag` を揃え忘れると2通りに壊れる。** Plugin Check が `stable_tag_mismatch` を出して
止まるか、気付かず wp.org の SVN へ上げた場合は **`Stable tag` が指すタグが配信版になる**ため、
新版を上げたのに利用者には古い版が配られ続ける。ordermemo で踏んだ `ORMM_VERSION` のヘッダとの
ずれと同じ構図なので、リリース時は必ず上の grep を通すこと。

★ 2026-08-25 に #1 の PR で 1.0.3 → 1.0.4 に上げられ、マージ前に revert した実績がある。
この手順は**人がリリース時に上げるときのもの**であって、実装 PR の話ではない。

★ `https://etbs.jp/product/editlock/` の配布物を wp.org へ向け直す作業は **2026-09-02 に完了済み**
（商品ページの遷移先を wp.org に変更）。

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

`dist` ブランチへのマージ＝配信。PUC が配る zip には**追跡しているファイルが全部入る**ため、
`.gitignore`（追跡させない）と `.gitattributes` の `export-ignore`（zip から落とす）は役割が別。
両方を維持すること。

★★★ **`.gitattributes` のパターンは必ず先頭 `/` でアンカーすること。**
未アンカーだと**あらゆる階層の同名に当たる。** PUC を `dist` に戻した（#195）ため、
`composer.json` や `vendor/` を無印で足すと `inc/plugin-update-checker/composer.json` と
`inc/plugin-update-checker/vendor/` に当たり、`Puc/v5p5/Autoloader.php` の静的マップが参照する
`PucReadmeParser` / `Parsedown` が配布物から消える。`readme.txt` を持つこのプラグインでは
**更新チェックのたびに Class not found の Fatal**（widget-shortcode-tools で実際に踏んだ形）。
2026-09-02 に既存4行をアンカー済み。落ちるのは `.gitignore` / `.gitattributes` / `CLAUDE.md` の
3ファイルだけであることを実測で確認している。

### ★★★ 提出用 zip は `git archive` で作る

```sh
git archive --format=zip --prefix=editlock/ -o /tmp/editlock-<版数>.zip HEAD
```

`export-ignore` が効くのは **`git archive` と GitHub の zipball だけ**。`zip -r` や Finder の
「圧縮」では `.git` / `.gitattributes` / `.gitignore` / `CLAUDE.md` が全部入る。

★ Plugin Check を作業ディレクトリに掛けると `.git` と `.gitattributes` で `hidden_files` の
**Error が2件**出る（`.gitignore` は検証環境では Warning だが、**production 環境では Error に昇格する**
——`File_Type_Check.php:227` が `wp_get_environment_type()` を見ている）。
**検査対象は必ず zip の展開物にすること。**

★★ **本体クローンを検査対象にしない。** `.claude/worktrees/` にプラグイン一式のコピーが
複数入っているため全件が二重計上され、`ai_instruction_directory` の指摘まで出る。

★★★ **承認後の SVN import には `export-ignore` が一切効かない。** 作業ツリーをそのまま
import すると `CLAUDE.md`（自社配布の実測サイト数・移行計画・社内レビュー体制）が公開され、
**SVN は削除コミットを積んでも過去リビジョンを誰でも読める**。import 元は必ず
`git archive` の展開物にすること → task-queue #36

## ダッシュボードウィジェットを外したのは意図的

`~/.claude/etbs-plugin-rules.md` の「サポート導線3面」はダッシュボードウィジェットを
初版から入れると定めているが、**このプラグインだけは 1.1.0 で外した**（#1）。
wordpress.org のガイドラインが管理画面の乗っ取り・一方的な広告を禁じているため。

★ **横並びで揃えにきて戻さないこと。** 戻すと wp.org 側で指摘されうる。
現在の自社リンクは「プラグイン一覧行」と「設定画面フッター」の2面だけが正しい。

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
readme が古いと配信判定まで古い値で動く（`~/.claude/etbs-plugin-rules.md`）。 `README.md` は `export-ignore` されておらず配布 zip に含まれるため、
ヘッダだけ直しても README が古いままだと利用者には要件が伝わり続ける。揃えないまま放置すると、
次に見た人がどちらが正しいか分からず、README に合わせてヘッダへ過剰宣言を書き戻す方向に
動きかねない。

★★ **「据え置き」と「新規に足す」は別問題**（2026-08-25 / task-queue #111 で再確認）。
既に宣言している版を据え置いても新たに締め出す個体は生まれないが、**無宣言のプラグインに
`Requires PHP` を新しく足すと、いま更新が届いている個体を以後届かなくする**。
`woo-checkout-colorbox` と `widget-shortcode-tools` が無宣言なのは、この理由による意図的な判断。
**8本で揃えにこないこと。**
