-- Path: design/phase2/003_membership_billing.sql
-- 機能: 会員単位の一意請求と、portal正本年度のentry会費二重加算防止。本番未適用。
-- 001+002後に適用。既存請求の対応を推測して自動移行しない。
ALTER TABLE portal_invoice_lines ADD UNIQUE KEY uq_portal_line_invoice_member (id,invoice_id,membership_id);
CREATE TABLE portal_membership_charges (
  membership_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
  invoice_line_id BIGINT UNSIGNED NOT NULL UNIQUE,
  amount_yen BIGINT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (membership_id) REFERENCES portal_memberships(id) ON DELETE RESTRICT,
  FOREIGN KEY (invoice_line_id,invoice_id,membership_id) REFERENCES portal_invoice_lines(id,invoice_id,membership_id) ON DELETE RESTRICT,
  CHECK (amount_yen>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DELIMITER //
CREATE TRIGGER portal_entry_membership_fee_insert BEFORE INSERT ON jmrc_entry_fees FOR EACH ROW
BEGIN
  DECLARE portal_mode VARCHAR(16) DEFAULT NULL;
  IF NEW.item_name LIKE 'JMRC個人入会金%' OR NEW.item_name LIKE 'JMRC個人会員費%' THEN
    SELECT y.source_mode INTO portal_mode FROM jmrc_entries e
      JOIN jmrc_events ev ON ev.id=e.event_id JOIN portal_years y ON y.year=YEAR(ev.event_date)
      WHERE e.id=NEW.entry_id LOCK IN SHARE MODE;
    IF portal_mode='portal' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Membership fees are billed by portal, not entry';
    END IF;
  END IF;
END//
CREATE TRIGGER portal_entry_membership_fee_update BEFORE UPDATE ON jmrc_entry_fees FOR EACH ROW
BEGIN
  DECLARE portal_mode VARCHAR(16) DEFAULT NULL;
  IF NEW.item_name LIKE 'JMRC個人入会金%' OR NEW.item_name LIKE 'JMRC個人会員費%' THEN
    SELECT y.source_mode INTO portal_mode FROM jmrc_entries e
      JOIN jmrc_events ev ON ev.id=e.event_id JOIN portal_years y ON y.year=YEAR(ev.event_date)
      WHERE e.id=NEW.entry_id LOCK IN SHARE MODE;
    IF portal_mode='portal' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Membership fees are billed by portal, not entry';
    END IF;
  END IF;
END//
DELIMITER ;
