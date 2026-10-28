# 既存entryとの認証互換ブリッジ

2026-10-28追記: [個人会費連携](../../Documents/phase2/20261028_会費請求・資格有効化.md)を追加。`common/portal_billing.php`もauthと一体で必要。隔離コピーには`integration/prepare_entry_billing.py`で年度会費参照と会費入力導線を変更する。以下の旧時点の「入金業務未実装」は個人会費について更新済み。本番未適用。

2026-10-27。隔離環境での検証用実装。本番・隣接jmrckinkiの原本には適用していない。

## 構成

| リポジトリ内 | 配置時の役割 |
| --- | --- |
| `src/Auth/SharedAuth.php` | Web非公開の共通認証・CSRF・portal認可 |
| `src/bootstrap.php` | 共通DBとセッションの初期化、障害時503 |
| `config/auth.example.php` | Web非公開設定の例。資格情報は環境から取得 |
| `integration/jmrckinki/common/auth.php` | entry/common/auth.phpの互換実装 |
| `integration/jmrckinki/common/csrf.php` | entry/common/csrf.phpの互換実装 |
| `integration/jmrckinki/login.php` | entryの新ログイン導線、旧header/footerを利用 |
| `integration/jmrckinki/logout.php` | 旧GETリンクは確認画面、実行はPOST+CSRF |
| `public/manage/` | manageの最小認証画面。会員/保険/入金業務は未実装 |

`JMRC_AUTH_BOOTSTRAP`で共通bootstrapの絶対パス、`JMRC_AUTH_CONFIG`で非公開設定ファイルを指定する。PHP-FPMがこの環境設定を読める必要がある。認証設定・ソース・セッションはDocumentRoot外で、Pleskのopen_basedir許可内に置く。保存ディレクトリは事前に用意し、共通実行ユーザーだけが読み書きできる権限とする。

設定例の `JMRC_AUTH_DSN` / `JMRC_AUTH_DB_USER` / `JMRC_AUTH_DB_PASSWORD` / `JMRC_AUTH_SESSION_PATH` は実行環境で指定する。DSNはutf8mb4。実パス・資格情報をリポジトリへ記録しない。

## 互換の範囲と意図した変更

- `uid/rid/verified`、`auth_*`、`is_role`、`csrf_*`を維持。旧role_idは画面切替で変更しない。
- メール/パスワードは既存usersだけを参照。portal_account_controlsの状態・認証版を両アプリで確認。
- `auth_user()`はid/email/role_id/email_verified/pending_email/created_at/updated_atを返す。パスワードハッシュは返さない。現在の呼出元を検索し、使用中の公開フィールドを保持している。
- メール未認証・停止・controls未作成は認証拒否。旧コードの「未認証メールでもログインできる」挙動は継続しない。
- 版なしの既存セッションは再ログイン。ログイン時にIDとCSRFを更新し、セッション固定を拒否。
- パスワードハッシュの指紋をサーバー側セッションに保存。既存reset_handler等が直接users.password_hashを変更しても次要求で全旧セッションが失効する。指紋やハッシュはレスポンス/監査へ出さない。
- portal grantはDBに明示付与されたもののみ。旧JMRC_ADMIN/CLUBという理由でportal権限を増やさない。取消・期限・承認・対象クラブを毎要求で検査。
- 未確定CSV権限は許可コードに含めていない。一般会員本人のログインと年度資格成立は別判定。
- manage復帰は`app=manage&return_key=dashboard`のみ対応。未実装画面のreturn_keyは受け付けない。
- app指定なしの旧ロール別既定先を維持。既存公開一覧が生成する`entrant/entries.php?action=create&event_id=N`も維持。他のquery付きnextは必要性を確認してから追加する。
- GET logoutは副作用なし。旧ナビのリンクは確認フォームとして利用可能。POST logoutは共通セッションを破棄し両アプリから失効。
- 認証/認可DB障害は503。監査書込に失敗した新ログインも503とし認証を成立させない。ログアウト時は監査故障でもセッション破棄を行い、結果は503で通知。

## 認可API

`jmrc_auth()->requireIdentity()`で認証確認、`requirePermission('club.members.read', $clubId)`で対象クラブ付き権限確認、`verifyCsrf($_POST['_csrf'] ?? null)`でPOST検証を行う。

現在の許可コード: `club.members.read`、`club.affiliations.approve`、`billing.confirm`、`billing.correct`、`portal.content.write`、`accounts.manage`、`temporary.approve`。未知コードは拒否。CLUB_REPのglobal付与は誤設定として拒否する。`temporary.approve`はclub scopeのみ許可し、実承認でもイベント主催クラブを再確認する。権限テーブルの本番初期投入や権限付与画面は未実装。

この認可は「担当クラブに対する操作能力」を判定する。member_idから所属を引き当てる処理、承認済み状態、年度資格、会計のトランザクション中再検査は業務サービス側で実装する。対象IDをそのまま信頼してこのAPIを迂回しない。

## 本番適用前に残ること

共通bootstrap/設定/4つのentry連携ファイルは一体で切り替える必要がある。本番への自動適用機能は設けていない。

1. 運営回答と権限マトリクスの承認、既存usersへのcontrols初期作成、明示grantの投入。
2. 新規登録処理でusersとcontrolsを同一トランザクションで作る。現在の旧register_handlerは未変更のため、そのままでは新ユーザーのcontrolsが欠落してログインできない。
3. 未認証再登録時の旧role/プロフィール削除、アカウント訂正・削除などの経路を共通ID設計へ改修。
4. PHP-FPMの実設定、配置/所有権/セッション移行、バックアップと復旧、旧画面のPOST/メール/帳票などの回帰。

今回完了したのは認証アダプターと隔離HTTPSでのログイン/ログアウト/認可検証。会員申請・保険・入金処理や新規登録の全体リリースを完了したものではない。
