-- Path: design/phase2/008_yucho_import.sql
-- 機能: 実口座単位のCSV取込と重複候補・手動照合を記録。本番未適用。
CREATE TABLE portal_bank_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(64) NOT NULL UNIQUE,
 display_name VARCHAR(128) NOT NULL,
 dedup_key_check BINARY(32) NULL,
 enabled BOOLEAN NOT NULL DEFAULT 0,
 CHECK(enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_bank_source_accounts (
 account_id BIGINT UNSIGNED PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(account_id) REFERENCES portal_bank_accounts(id),
 FOREIGN KEY(source_id) REFERENCES portal_bank_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE portal_bank_imports
 ADD COLUMN bank_source_id BIGINT UNSIGNED NULL,
 ADD FOREIGN KEY(bank_source_id) REFERENCES portal_bank_sources(id),
 ADD UNIQUE KEY uq_portal_bank_source_file(bank_source_id,file_sha256);
CREATE TABLE portal_yucho_rows (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 fingerprint BINARY(32) NOT NULL,
 receipt_date DATE NULL,
 amount_yen BIGINT NOT NULL,
 payer_hmac BINARY(32) NOT NULL,
 payer_name_cipher MEDIUMBLOB NOT NULL,
 split_from_id BIGINT UNSIGNED NULL,
 evidence_hmac BINARY(32) NULL,
 evidence_cipher MEDIUMBLOB NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'review',
 payment_id BIGINT UNSIGNED NULL UNIQUE,
 duplicate_payment_id BIGINT UNSIGNED NULL,
 invoice_id BIGINT UNSIGNED NULL,
 reviewed_date DATE NULL,
 review_reason_cipher MEDIUMBLOB NULL,
 resolution_mode VARCHAR(16) NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME(6) NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(source_id) REFERENCES portal_bank_sources(id),
 FOREIGN KEY(split_from_id) REFERENCES portal_yucho_rows(id),
 FOREIGN KEY(payment_id) REFERENCES portal_payments(id),
 FOREIGN KEY(duplicate_payment_id) REFERENCES portal_payments(id),
 FOREIGN KEY(invoice_id) REFERENCES portal_invoices(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id),
 UNIQUE(source_id,fingerprint),
 UNIQUE(source_id,evidence_hmac),
 CHECK(amount_yen BETWEEN 1 AND 1000000000),
 CHECK((status='review' AND payment_id IS NULL AND duplicate_payment_id IS NULL AND invoice_id IS NULL)
  OR (status='confirmed' AND payment_id IS NOT NULL AND duplicate_payment_id IS NULL AND invoice_id IS NOT NULL AND reviewed_by IS NOT NULL AND reviewed_date IS NOT NULL)
  OR (status='duplicate' AND payment_id IS NULL AND duplicate_payment_id IS NOT NULL AND reviewed_by IS NOT NULL AND reviewed_date IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_yucho_import_rows (
 import_id BIGINT UNSIGNED NOT NULL,
 record_no INT UNSIGNED NOT NULL,
 row_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(import_id,record_no),
 FOREIGN KEY(import_id) REFERENCES portal_bank_imports(id),
 FOREIGN KEY(row_id) REFERENCES portal_yucho_rows(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
