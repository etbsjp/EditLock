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
`wp_unschedule_hook()` を「方針違反」と読んで消さないこと。8本のうちテーブルと cron を
持つのはこのプラグインだけで、そこだけが「消す」に該当する。

## 版数

版数の置き場は2箇所。**現在は一致している**が、上げる際は両方揃えること。

- `editlock.php` の `Version:` ヘッダ
- `editlock.php` の `define( 'EDLK_VERSION', ... )`（`inc/func.php` で `wp_enqueue_script()` の
  キャッシュバスターとして使用中）

```sh
grep -nE "^ \* Version:|define\( 'EDLK_VERSION'" editlock.php
```

`readme.txt` は無いため、置き場はこの2箇所だけ（ordermemo で踏んだ `ORMM_VERSION` の
ヘッダとのずれと同じ構図が起きうるので、リリース時は必ず両方を grep で確認すること）。

## 配布物

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

★ `README.md` の「必要環境」にもヘッダと同じ情報を書いている（利用者向けの説明のため）。
**ヘッダの `Requires at least` / `Requires PHP` を変更したときは、`README.md` の該当箇所も
同時に見直すこと。** `README.md` は `export-ignore` されておらず配布 zip に含まれるため、
ヘッダだけ直しても README が古いままだと利用者には要件が伝わり続ける。揃えないまま放置すると、
次に見た人がどちらが正しいか分からず、README に合わせてヘッダへ過剰宣言を書き戻す方向に
動きかねない。
