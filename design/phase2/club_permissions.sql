-- Path: design/phase2/club_permissions.sql
-- 機能: 年度クラブ登録は代表と事務局、年度承認は事務局へ明示付与。grant自体は作らない。
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
 SELECT id,'club.registration.write' FROM portal_roles WHERE code IN ('OFFICE','CLUB_REP');
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
 SELECT id,'club.registration.approve' FROM portal_roles WHERE code='OFFICE';
