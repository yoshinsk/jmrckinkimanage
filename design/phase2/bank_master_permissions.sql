-- Path: design/phase2/bank_master_permissions.sql
-- 機能: 事務局に専用設定権限を付与。入金確認だけの財務・旧管理者には設定変更を許可しない。
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
SELECT id,'billing.accounts.manage' FROM portal_roles WHERE code='OFFICE';
