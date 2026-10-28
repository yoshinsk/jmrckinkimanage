-- Path: design/phase2/billing_permissions.sql
-- 機能: 登録済みの事務局・財務ロールに、確定要件の会計権限を付与する。本番未適用。
-- ロール/grant自体は作成しない。担当者の明示grant・承認・期限を別途設定すること。
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
  SELECT id,'billing.confirm' FROM portal_roles WHERE code IN ('OFFICE','FINANCE');
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
  SELECT id,'billing.correct' FROM portal_roles WHERE code IN ('OFFICE','FINANCE');
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
  SELECT id,'club.affiliations.approve' FROM portal_roles WHERE code='OFFICE';
