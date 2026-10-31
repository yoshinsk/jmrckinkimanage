# 共通認証の隔離HTTP検証

2026-10-31旧HP情報ページ: `information_http.php`で事務局の認可、権限取消、一覧・詳細・複合検索、旧年度の保持、POST拒否、カタログの非公開配置、既存entryを含む全業務表のCHECKSUM不変を検証する。`information_browser.cjs`はChromium/WebKitで一覧・詳細・検索・予定絞込、320～1,280px、200%文字、44pxの操作対象を確認する。配布HTMLは `python tests/information/validate_offline.py` で別途再生成・台帳照合・ネットワークなしの表示検証を行う。本番接続・公開・記事保存はしない。

2026-10-30マイページ: `dashboard_http.php`で権限別メニュー、失効反映、shadowの準備中表示、HTMLエスケープ、GETで業務記録を作らないことを検証する。実ブラウザーはエントラント・事務局・クラブ担当の3種類で、通常設定と本番相当の保留設定を確認する。後者は全業務試験が終わってから隔離DBの年度をshadowへ変更し、隔離設定の3機能を無効化する。JavaScriptなしの開閉・遷移・ログアウト、320～1,280px、200%文字拡大、長いメール、44px以上の操作対象も対象とする。本番設定は読み込まない。

2026-10-30: 共通サインインの表示検証を追加。既存のapp.css・validation.jsを隔離コピーし、ヘッダーのBootstrap 5.3.3 CSSを固定ファイルから供給する。Chromium/WebKitの320・375・390・412・844・1,280pxで入力欄の縦配置、幅、余白を確認し、空欄送信・認証エラー・manageへの戻り先も検証する。外部通信は引き続き遮断する。

追加回答への対応: 010・profile_permissions.sql・tests/profileを含めて実行する。事務局の氏名・生年月日訂正、暗号化履歴、CSRF、更新版、監査障害時のロールバック、削除候補の読み取り確認を検証する。公開QRの氏名・会員番号と無効時の消去、保険のTemporary拒否と初回3名/4名、受付日程案のJST/UTCも対象とする。個人情報は削除しない。

口座設定追加: 009・bank_master_permissions.sql・tests/bank_masterを含む。事務局の専用権限、暗号化変更履歴、同時更新・同時期間競合、発行済み請求スナップショット不変とCSV対応の保護を検証する。履歴はruntimeでINSERTのみ許可し、実ブラウザーでも作成・URL変更・履歴閲覧を確認する。

銀行追加: 008・tests/bankとPHP mbstringを含む。実口座ごとの架空CSV、UTF-8/CP932、再取込/同時取込・同時確定、既存手動入金の紐付け・重複、証跡分離とロールバックを検証する。実CSVは使わない。実ブラウザーでもCSVファイルをアップロードする。

保険追加: 007・insurance_permissions.sql・tests/insuranceと固定原本を含めて実行する。PHP ZIP/DOM拡張が必要。HTTPの申請/権限/帳票生成に加え、独立Python ZIP/XML照合とChromium/WebKitの実ダウンロードを検証する。架空帳票はtmp/insurance_validation、実ブラウザーの画像/帳票はtmp/mobile_validationに保存する。

ライセンス追加: 006・`license_permissions.sql`・`tests/license`・移行CLIを含めて実行する。固定Playwrightイメージとnpm依存をビルドし、専用internalネットワークでChromium/WebKitの実ブラウザー検証を行う。画像は`tmp/mobile_validation/`へ保存する。初回はブラウザーイメージ・日本語フォント等の取得に時間がかかる。

会員証追加: 005と`tests/card`を実行する。事前に`npm.cmd ci --prefix tests/card --ignore-scripts --no-audit --no-fund`が必要。HTTP判定に加え、ローカルSVGの独立デコーダーによる復号・NodeでのDOM模擬検証を行う。実ブラウザーや実カメラの試験とは区別する。

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

実メールの配送、実カメラによるQR読取、通常の競技会申込完走、実銀行・実決済は対象外。登録・確認・パスワード再設定はメール送信を止めた隔離環境で検証する。画面とExcelの検証範囲は、上記の実ブラウザー・帳票の各項目を参照する。
