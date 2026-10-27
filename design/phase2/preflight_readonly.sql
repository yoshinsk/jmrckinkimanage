-- Path: design/phase2/preflight_readonly.sql
-- 機能: 共通認証・DB設計前にMariaDBの定義と集計だけを確認するSELECT集。
-- 本番未実行。個人情報や接続秘密を出力せず、DDL/DML/資格変更は行わない。
-- 使用条件: 対象DBを接続側で選択し、SELECT権限のある接続で個別実行する。
-- 現行定義を先に確認し、後半の集計は列が存在することを確認してから実行する。

-- 1. バージョン・時刻・文字コード。PHP側の時刻設定は別途確認が必要。
SELECT DATABASE() AS selected_database, VERSION() AS database_version,
       @@session.time_zone AS session_time_zone,
       @@global.time_zone AS global_time_zone,
       @@character_set_database AS database_charset,
       @@collation_database AS database_collation;

-- 2. 対象テーブル/ビューの存在とエンジン。
SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'roles', 'entrants', 'clubs',
    'jmrc_affiliated_clubs', 'jmrc_annual_memberships',
    'jmrc_entries', 'jmrc_entry_fees', 'jmrc_events',
    'audit_logs', 'view_annual_jmrc_payments')
ORDER BY TABLE_NAME;

-- 3. 列型・NULL・キーを確認。実データは取得しない。
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE,
       IS_NULLABLE, COLUMN_KEY, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'roles', 'entrants', 'clubs',
    'jmrc_affiliated_clubs', 'jmrc_annual_memberships',
    'jmrc_entries', 'jmrc_entry_fees', 'jmrc_events',
    'view_annual_jmrc_payments')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 4. 一意制約・索引と外部キーの実体。
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'entrants', 'clubs', 'jmrc_affiliated_clubs',
    'jmrc_annual_memberships', 'jmrc_entries', 'jmrc_entry_fees')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN ('users', 'entrants', 'clubs', 'jmrc_affiliated_clubs',
    'jmrc_annual_memberships', 'jmrc_entries', 'jmrc_entry_fees')
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

-- 5. 現行ビューの定義。閲覧権限によってはNULLになる。
SELECT TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, SECURITY_TYPE
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'view_annual_jmrc_payments';

-- 6. 既存portal領域がないかを確認。存在時は新設を止めて調査する。
SELECT TABLE_NAME, TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, 7) = 'portal_'
ORDER BY TABLE_NAME;

-- 以下は列定義確認後の集計。ユーザーID・メール・番号の実値は出力しない。
SELECT role_id, email_verified, COUNT(*) AS account_count
FROM users GROUP BY role_id, email_verified ORDER BY role_id, email_verified;

-- 非空の識別番号の重複グループ数。実際の統合は別途本人確認が必要。
SELECT COUNT(*) AS duplicate_driving_license_groups
FROM (
  SELECT license_no FROM entrants
  WHERE license_no IS NOT NULL AND TRIM(license_no) <> ''
  GROUP BY license_no HAVING COUNT(*) > 1
) AS duplicates;

SELECT COUNT(*) AS duplicate_jaf_license_groups
FROM (
  SELECT jaf_license_no FROM entrants
  WHERE jaf_license_no IS NOT NULL AND TRIM(jaf_license_no) <> ''
  GROUP BY jaf_license_no HAVING COUNT(*) > 1
) AS duplicates;

SELECT COUNT(*) AS invalid_six_digit_club_codes
FROM jmrc_affiliated_clubs
WHERE is_deleted = 0 AND club_code NOT REGEXP '^[0-9]{6}$';

-- これは登録状態の集計であり、支払証拠の集計ではない。
SELECT year, is_jmrc_member, mutual_aid_type, COUNT(*) AS membership_count
FROM jmrc_annual_memberships
GROUP BY year, is_jmrc_member, mutual_aid_type
ORDER BY year, is_jmrc_member, mutual_aid_type;

SELECT COUNT(*) AS orphan_annual_memberships
FROM jmrc_annual_memberships AS m
LEFT JOIN users AS u ON u.id = m.user_id
WHERE u.id IS NULL;
