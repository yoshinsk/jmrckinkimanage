-- Path: design/phase2/002_confirmed_membership_rules.sql
-- 機能: R01/R03/R04確定回答を追加する候補DDL。001適用後の隔離DB用。本番未適用。
-- 既存行がある場合は先に002_preflight_readonly.sqlで移行条件を確認する。
-- 不明なライセンス/日付を自動補完しない。DDLは暗黙commitを伴うため失敗時は差分確認が必要。

ALTER TABLE portal_club_categories
  ADD CONSTRAINT chk_portal_category_confirmed CHECK (category_digit IN ('1','2','3','6'));

INSERT INTO portal_club_categories (category_digit,name,enabled) VALUES
  ('1','公認クラブ',1),('2','加盟クラブ',1),('3','準加盟クラブ',1),('6','加盟団体',1)
ON DUPLICATE KEY UPDATE name=VALUES(name),enabled=VALUES(enabled);

-- kindはシステム内の識別種別コード。一般の運転免許証をGeneral採番元として参照させない。
ALTER TABLE portal_identifiers
  ADD UNIQUE KEY uq_portal_identifier_owner_kind (id,user_id,kind);

-- 申請側の日付改竄も拒否する。UTC日時からのJST日付はセッションtimezoneに依存しない。
ALTER TABLE portal_events
  ADD COLUMN participation_date DATE AS (DATE(starts_at + INTERVAL 9 HOUR)) PERSISTENT,
  ADD UNIQUE KEY uq_portal_event_year_day (id,year,participation_date);

ALTER TABLE portal_memberships
  ADD COLUMN general_license_identifier_id BIGINT UNSIGNED NULL,
  ADD COLUMN general_license_kind VARCHAR(24) NULL,
  ADD COLUMN participation_date DATE NULL COMMENT 'Temporary参加日、日本時間',
  ADD UNIQUE KEY uq_portal_temporary_user_day (temporary_user_key,participation_date),
  ADD CONSTRAINT fk_portal_general_license FOREIGN KEY (general_license_identifier_id,user_id,general_license_kind)
    REFERENCES portal_identifiers (id,user_id,kind) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_portal_temporary_event_day FOREIGN KEY (event_id,year,participation_date)
    REFERENCES portal_events (id,year,participation_date) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT chk_portal_membership_application_fields CHECK (
    (member_type='general' AND general_license_identifier_id IS NOT NULL
      AND general_license_kind IS NOT NULL AND general_license_kind IN ('jaf_competition','jaf_official')
      AND participation_date IS NULL)
    OR (member_type='temporary' AND general_license_identifier_id IS NULL
      AND general_license_kind IS NULL AND participation_date IS NOT NULL)
  );

-- 正会員クラブの年度コードは作成後に変更しない。連絡先/承認状態の訂正は引き続き可能。
DELIMITER //
CREATE TRIGGER portal_club_year_code_insert BEFORE INSERT ON portal_club_years FOR EACH ROW
BEGIN
  DECLARE fixed_part CHAR(5);
  SELECT fixed_code INTO fixed_part FROM portal_clubs WHERE id=NEW.club_id;
  IF fixed_part IS NULL OR NEW.display_code <> CONCAT(NEW.category_digit,fixed_part) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Club code must match category and fixed club number';
  END IF;
END//
CREATE TRIGGER portal_club_year_code_immutable BEFORE UPDATE ON portal_club_years FOR EACH ROW
BEGIN
  IF OLD.club_id<>NEW.club_id OR OLD.year<>NEW.year OR OLD.category_digit<>NEW.category_digit OR OLD.display_code<>NEW.display_code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Annual club code cannot be changed';
  END IF;
END//
DELIMITER ;
