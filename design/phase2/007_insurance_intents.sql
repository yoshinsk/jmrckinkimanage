-- Path: design/phase2/007_insurance_intents.sql
-- 機能: 保険加入意思と確認用Excel出力の履歴を記録。加入・課金・保険期間は確定しない。
CREATE TABLE portal_insurance_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 membership_id BIGINT UNSIGNED NOT NULL UNIQUE,
 year SMALLINT UNSIGNED NOT NULL,
 club_year_id BIGINT UNSIGNED NOT NULL,
 consented_at DATETIME(6) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY (membership_id,year) REFERENCES portal_memberships(id,year),
 FOREIGN KEY (club_year_id,year) REFERENCES portal_club_years(id,year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_insurance_exports (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 club_year_id BIGINT UNSIGNED NOT NULL,
 generated_by BIGINT UNSIGNED NOT NULL,
 generated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 template_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 output_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 member_count SMALLINT UNSIGNED NOT NULL,
 FOREIGN KEY (club_year_id) REFERENCES portal_club_years(id),
 FOREIGN KEY (generated_by) REFERENCES users(id),
 CHECK (member_count BETWEEN 1 AND 24)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_insurance_export_items (
 export_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NOT NULL,
 request_version BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(export_id,request_id),
 FOREIGN KEY (export_id) REFERENCES portal_insurance_exports(id),
 FOREIGN KEY (request_id) REFERENCES portal_insurance_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
