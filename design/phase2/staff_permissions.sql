-- Path: design/phase2/staff_permissions.sql
-- 機能: C10/C11の役職・同等業務権限を登録する。アカウントへのgrantは別の承認処理で行う。
INSERT INTO portal_roles(code,name) VALUES ('COMMITTEE','運営委員'),('APPROVER','承認者')
  ON DUPLICATE KEY UPDATE name=VALUES(name);
UPDATE portal_roles SET name='運営委員（旧コード）' WHERE code='FINANCE';
INSERT IGNORE INTO portal_role_permissions(role_id,permission_code)
  SELECT target.id,p.permission_code FROM portal_roles source
  JOIN portal_role_permissions p ON p.role_id=source.id
  CROSS JOIN portal_roles target
  WHERE source.code='OFFICE' AND target.code IN ('FINANCE','COMMITTEE','APPROVER');
