# 会員追加ルールの隔離検証

```powershell
python tests/auth/validate_auth.py
```

共通認証の99項目に続けて、このフォルダーの会員ルール72項目を同じHTTPS/PHP-FPM/MariaDB環境で実行する。結果は`tmp/auth_validation.json`、最終結果の保存先は`tests/auth/validation_result.json`。

- `membership_seed.php`: 架空の年度クラブ、UTCイベント、識別番号、主催権限を作成。
- `membership_http.php`: 本人申請/主催承認のHTTP、マスター、JST日付、DB制約、原子性を確認。
- `concurrent_submit.php`: 別PHPプロセス・別DB接続から同人の同日別イベント申請を同時実行。

実際の会員サービスを実行し、検証専用の会員処理へ置き換えない。管理接続は架空データ作成と状態操作だけに使う。HTTPの実行接続はSELECT/必要テーブルへのINSERT・UPDATEだけで、DDL権限や識別番号の更新権限は持たない。

001は旧設計の作成DDLとして保持し、追加002を適用してからfixtureを投入する。既存データの自動変換を保証する試験ではない。運営保留のCSV/保存/保険条件や会費請求を仮の値で有効化しない。
