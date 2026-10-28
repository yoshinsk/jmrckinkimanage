-- Path: design/phase2/license_permissions.sql
-- 機能: 確認済み移行の実行権限を明示的な事務局ロールへ設定。grant自体は作らない。
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
 SELECT id,'identifiers.migrate' FROM portal_roles WHERE code='OFFICE';
