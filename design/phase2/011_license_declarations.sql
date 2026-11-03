-- Path: design/phase2/011_license_declarations.sql
-- 機能: C02の競技・審判員種別を申請時点の申告として保持する。既存会員・ライセンス番号は更新しない。
CREATE TABLE portal_membership_declarations (
  membership_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  competition_type VARCHAR(80) NOT NULL,
  official_type VARCHAR(80) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (membership_id) REFERENCES portal_memberships(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 外部運営者が指定した初期設定と通常の登録承認を区別し、初期設定を本人による承認として記録しない。
-- 通常の申請APIはこの列へ書き込まず、approved_byを別人にする相互承認だけを使う。
ALTER TABLE portal_user_role_grants ADD COLUMN bootstrap_approval_ref VARCHAR(96) NULL AFTER approved_by;
ALTER TABLE portal_user_role_grants ADD CONSTRAINT chk_external_staff_bootstrap CHECK
  (bootstrap_approval_ref IS NULL OR (scope_type='global' AND scope_club_id IS NULL AND approved_by IS NULL AND status IN ('active','revoked')));
