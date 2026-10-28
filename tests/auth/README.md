# 共通認証の隔離HTTP検証

クラブ年度登録の追加: 004と`club_permissions.sql`を適用し、`tests/club`も実行する。旧ケースの架空承認済みクラブには架空入金を明示的に準備する。個人申請だけで請求が生じないこと、個人入金の再送が重複しないことの従来検証は、クラブ会費と分けて維持する。

2026-10-28会計追記: 001→002→003と会計権限SQLを適用し、`tests/billing`の実HTTP/独立プロセス検証も実行する。現行件数は`validation_result.json`を参照。個人会費・資格判定と旧会費追加POST拒否も対象とし、通常の競技会申込完走は別工程。

2026-10-28追記: 001+002を適用し、[会員追加ルール](../membership/README.md)も同時実行する。最終171項目（認証99+会員72）、構文確認30ファイル。既存99項目を単独の現行件数と取り違えない。

実行条件: Windows Python、WSL Ubuntu、Docker。隣接 `../jmrckinki/httpdocs/entry` のローカルソースを参照する。本番へ接続する機能はない。

```powershell
python tests/auth/validate_auth.py
```

## 隔離構成

- PHP-FPM 8.4.27 + nginx HTTPS + MariaDB 10.5.29。ベースイメージはdigest固定。
- 専用Docker internalネットワーク、公開ポートなし、DBはtmpfs。実行後は専用コンテナとネットワークを削除する。
- 一時build contextは`tmp/auth_lab_*`。必要ソースのみコピーし、既存config/秘密/セッション/実レコードは読み込まない。
- TLSは検証用の自己署名証明書を都度生成。HTTPクライアントはその証明書を信頼し、ホスト名と証明書を検証する。
- 実行DBユーザーはSELECT、申請/所属/資格/請求/明細/会費対応/入金/充当のINSERT・UPDATE、監査/outboxのINSERTに限定する。fixture管理ユーザーと分離し、DDL・会計記録DELETEを許可しない。

既存のheader/footer/functions/dbおよびadmin/club/entrantの競技会一覧GETは実ソースをコピーして実行。auth/csrf/login/logoutは本リポジトリの互換実装へ差し替える。元ファイルのSHA-256とコミットを検証結果に記録し、原本の不変を確認する。

`identity.php`、`permission.php`はテスト専用観測経路であり、`public/manage`には含めない。セッション注入やDB状態変更はWeb機能ではなく、隔離CLIテストだけが行う。

## 検証内容

旧3ロールの既定先とmanage復帰、HTTP実FPM版、同一uid/rid/verified、セッション/CSRF更新、既存競技会GET、他クラブ/旧管理権限の拒否、複数portalロール、期限/承認/取消、メール認証/停止/controls欠落、SQL文字列、Cookie属性、共通ログアウトと旧ID再送拒否、認証版/パスワード変更、危険next、DB/監査障害を確認する。

結果は`tmp/auth_validation.json`へ生成。最終実行のコピーを`validation_result.json`へ保存する。テストが失敗した場合はコミット済みの過去PASS記録を最新結果と取り違えず、プロセス終了コードも確認する。

ブラウザーのJavaScript/外観、実メール経由の再設定・登録、通常の競技会申込完走、帳票、実銀行・実決済は対象外。認証版/パスワード変更は隔離DB操作で再現する。
