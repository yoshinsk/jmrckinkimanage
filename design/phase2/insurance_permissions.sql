-- Path: design/phase2/insurance_permissions.sql
-- 機能: 既存の事務局とクラブ代表へ保険確認帳票の明示権限を追加。財務・旧role_idへ自動付与しない。
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
SELECT id,'insurance.export' FROM portal_roles WHERE code IN ('OFFICE','CLUB_REP');
