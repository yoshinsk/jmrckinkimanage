# フェーズ2 共通認証・DB設計

作成: 2026-10-27。対象URL: `https://gymkinki.com/manage/`。状態: **ローカルコードに基づく設計案。未実装・本番未適用**。

## 成果物

- [共通認証・権限](共通認証・権限設計.md)
- [DB・状態・整合性](DB設計.md)
- [entry連携・移行・受入](連携・移行設計.md)
- [本番事前確認SQL](../../design/phase2/preflight_readonly.sql)（SELECTのみ。未実行）

## 設計判断

1. `users.id` と既存パスワードを共通IDとして継続。新しい認証DBやパスワード複製は作らない。
2. 同一ホストのPHPセッション共有を第一候補とする。PHP実行ユーザー・保存先・Cookie条件を本番で確認するまで成立確定とはしない。
3. 既存 `users.role_id` と `uid/rid/verified` を維持し、ポータル権限を追加テーブルに分離。ポータル権限を得てもentryの管理権限は増やさない。
4. 同一MariaDBへの `portal_` テーブル追加を第一候補とする。既存テーブルの破壊的変更と支払ビューの即時置換をしない。
5. 個人会員・所属・入金・保険を別状態として保存。Generalは年度、Temporaryはイベント単位。
6. 二重書き込みを常態化させず、対象年度ごとに正本と更新主体を切り替える。救済基金とスポーツ安全保険・協議会見舞金を同じフラグにしない。

## 現時点の確認範囲

既存リポジトリ: `C:\Users\Yoshi\Documents\GitHub\jmrckinki`、確認コミット `3db6283ff02adf48d22d12a8dccaf886f1f8a626`。ローカルSQLダンプは `jmrckinki.sql`。ダンプの定義は現在本番の保証ではない。個人の実データ、パスワード、接続秘密は設計資料へ転記していない。

参照箇所:

| 出典（jmrckinki配下） | 設計の根拠 |
| --- | --- |
| `httpdocs/entry/common/auth.php:85` | users認証、セッションuid/rid/verified、ID再生成 |
| `httpdocs/entry/common/auth.php:49` | Cookie path=/、HttpOnly、SameSite=Lax |
| `httpdocs/entry/common/config.php:78` | 30日セッション、entry/storage/sessions設定 |
| `httpdocs/entry/login.php:17` | nextをentry基準で連結、rid=3のみnext優先 |
| `httpdocs/entry/common/csrf.php:51` | 現行CSRF検証 |
| `httpdocs/entry/entrant/entries.php:261` | 既存支払ビュー利用 |
| `httpdocs/entry/entrant/entries.php:435` | 年度会員への直接upsert |
| `httpdocs/entry/register_handler.php:131` | 未認証再登録時にrole/プロフィール/年度会員を変更・削除 |
| `httpdocs/entry/update_profile.php:111` | 年度会員の直接更新 |
| `httpdocs/entry/join_jmrc.php:29` | 年度会員更新 |
| `jmrckinki.sql:1185` | 受理済entryから年度支払を算出するビュー |

## 保留の扱い

運営再確認R01〜R13は[再確認一覧](../20261027_再確認事項.md)を参照。番号例外、Temporary課金、CSV権限、消込キー、保存/削除、制度境界は未決。これらに仮の決定値を入れず、設定と内部IDの設計で変更可能にする。

次工程は本番の読み取り調査→DDL案の生成と隔離MariaDBでの検証→共通認証アダプターの実装。現状はその前段のレビュー可能な設計までで、フェーズ2全体の完了ではない。
