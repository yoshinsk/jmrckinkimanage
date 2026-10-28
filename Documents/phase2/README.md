# フェーズ2 共通認証・DB設計

> 2026-10-28追記: [ライセンス登録・更新・暗号移行](20261028_ライセンス登録更新・暗号移行.md)と006を追加。旧JAF情報の確認付き移行と、スマートフォン相当の実ブラウザー検証を実装しました。本番未適用です。

> 2026-10-28追記: [General会員証・QR照会](20261028_General会員証・QR照会.md)とDDL 005を追加。匿名照会は真偽だけを返します。公開項目・番号再発行方針は未確定のまま保留し、本番適用は行っていません。

> 2026-10-28追記: [正会員クラブ年度登録・年会費](20261028_正会員クラブ年度登録.md)を実装。DDL 004を追加し、クラブの入金・承認と個人会員資格を連動させました。実年会費額と既存登録の入金照合は導入前の確認が必要です。本番未適用。

> 2026-10-28追記: [個人会費・入金確認・資格有効化](20261028_会費請求・資格有効化.md)を実装。DDL 003と権限設定SQL、既存entry会費ブリッジを追加しました。以下の旧時点の「業務アプリ未実装」より、この追記を優先してください。本番未適用。

> 2026-10-28: [確定回答の追加実装](20261028_確定回答実装・検証.md)を完了。001+002でGeneral条件、クラブ区分、Temporaryの同日制限・主催承認を検証しました。以下の旧41項目/99項目に加え、新しい統合検証171項目を参照してください。本番未適用。

作成: 2026-10-27。対象URL: `https://gymkinki.com/manage/`。状態: **36表DDLと共通認証アダプターを隔離環境で検証済み。業務アプリ未実装・本番未適用**。

## 成果物

- [共通認証・権限](共通認証・権限設計.md)
- [DB・状態・整合性](DB設計.md)
- [entry連携・移行・受入](連携・移行設計.md)
- [本番事前確認SQL](../../design/phase2/preflight_readonly.sql)（SELECTのみ。対象DBを明示した同等クエリで確認済み）
- [本番確認・DDL検証結果](20261027_本番確認・DDL検証結果.md)
- [追加DDL](../../design/phase2/001_portal_schema.sql) / [隔離検証手順](../../tests/phase2/README.md)
- [共通認証アダプター実装・隔離HTTPS検証](20261027_共通認証アダプター検証.md)

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

本番読み取り調査→DDL生成→隔離MariaDB 10.5.29で41項目検証に加え、共通認証アダプターを隔離HTTPS/PHP-FPM 8.4.27で検証済み。次工程は登録/訂正経路の切替と業務サービス。運営保留事項、復元/本番移行試験は残っており、フェーズ2全体の完了ではない。
