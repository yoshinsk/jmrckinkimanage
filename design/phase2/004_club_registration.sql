-- Path: design/phase2/004_club_registration.sql
-- 機能: 年度クラブ情報と一意年会費請求を追加する。001～003後、隔離検証用・本番未適用。
ALTER TABLE portal_club_years ADD name_full VARCHAR(255) NULL, ADD name_short VARCHAR(128) NULL;
ALTER TABLE portal_invoice_lines ADD UNIQUE KEY uq_portal_line_invoice_club (id,invoice_id,club_year_id);
CREATE TABLE portal_club_charges (
  club_year_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
  invoice_line_id BIGINT UNSIGNED NOT NULL UNIQUE,
  amount_yen BIGINT NOT NULL CHECK (amount_yen>0),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (club_year_id) REFERENCES portal_club_years(id) ON DELETE RESTRICT,
  FOREIGN KEY (invoice_line_id,invoice_id,club_year_id) REFERENCES portal_invoice_lines(id,invoice_id,club_year_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE VIEW portal_invoice_charge_targets AS
  SELECT invoice_id,invoice_line_id,amount_yen,membership_id,NULL AS club_year_id FROM portal_membership_charges
  UNION ALL SELECT invoice_id,invoice_line_id,amount_yen,NULL AS membership_id,club_year_id FROM portal_club_charges;
