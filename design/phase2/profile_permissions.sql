-- Path: design/phase2/profile_permissions.sql
-- 機能: 事務局に本人情報訂正権限を付与。財務・クラブ代表へは付与しない。
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
SELECT id,'profiles.correct' FROM portal_roles WHERE code='OFFICE';
