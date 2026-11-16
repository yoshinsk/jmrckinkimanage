-- Path: design/phase2/013_club_intake.sql
-- 機能: 事前台帳なしのクラブ申請、暗号化訂正履歴、確定済み2027年度料金を追加。受付設定・既存業務表は変更しない。
CREATE TABLE portal_club_fee_profiles (
 year SMALLINT UNSIGNED NOT NULL, jaf_kind VARCHAR(16) NOT NULL, membership_kind VARCHAR(16) NOT NULL,
 annual_yen BIGINT NOT NULL, joining_yen BIGINT NOT NULL, enabled BOOLEAN NOT NULL DEFAULT 0,
 PRIMARY KEY(year,jaf_kind,membership_kind), FOREIGN KEY(year) REFERENCES portal_years(year),
 CHECK(annual_yen>0 AND joining_yen>=0), CHECK(enabled IN(0,1)),
 CHECK(jaf_kind IN('official','affiliated','associate','organization','business')),
 CHECK(membership_kind IN('regular','supporting')), CHECK(jaf_kind<>'business' OR membership_kind='supporting')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_club_intakes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, application_id BIGINT UNSIGNED NOT NULL UNIQUE,
 applicant_user_id BIGINT UNSIGNED NOT NULL, year SMALLINT UNSIGNED NOT NULL,
 registration_kind VARCHAR(16) NOT NULL, jaf_kind VARCHAR(16) NOT NULL, membership_kind VARCHAR(16) NOT NULL,
 annual_yen BIGINT NOT NULL, joining_yen BIGINT NOT NULL, category_digit CHAR(1) NOT NULL,
 payload_cipher MEDIUMBLOB NOT NULL, payload_hmac BINARY(32) NOT NULL,
 review_state VARCHAR(20) NOT NULL DEFAULT 'submitted', club_year_id BIGINT UNSIGNED NULL UNIQUE,
 matched_by BIGINT UNSIGNED NULL, matched_at DATETIME(6) NULL,
 row_version BIGINT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 FOREIGN KEY(application_id,year) REFERENCES portal_applications(id,year), FOREIGN KEY(applicant_user_id) REFERENCES users(id),
 FOREIGN KEY(year,jaf_kind,membership_kind) REFERENCES portal_club_fee_profiles(year,jaf_kind,membership_kind), FOREIGN KEY(club_year_id,year) REFERENCES portal_club_years(id,year),
 FOREIGN KEY(matched_by) REFERENCES users(id), CHECK(registration_kind IN('new','renewal','rejoin')),
 CHECK(review_state IN('submitted','renewal_review','returned','linked')), CHECK(annual_yen>0 AND joining_yen>=0),
 CHECK((review_state='linked' AND club_year_id IS NOT NULL AND matched_by IS NOT NULL AND matched_at IS NOT NULL)
    OR (review_state<>'linked' AND club_year_id IS NULL AND matched_by IS NULL AND matched_at IS NULL)),
 INDEX(applicant_user_id,year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_club_intake_revisions (
 intake_id BIGINT UNSIGNED NOT NULL, row_version BIGINT UNSIGNED NOT NULL, actor_user_id BIGINT UNSIGNED NOT NULL,
 snapshot_cipher MEDIUMBLOB NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(intake_id,row_version), FOREIGN KEY(intake_id) REFERENCES portal_club_intakes(id), FOREIGN KEY(actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
