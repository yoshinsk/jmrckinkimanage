-- Path: design/phase2/014_club_review_closure.sql
-- 機能: L09の年度全体の再申請終了記録と、11/17回答のクラブ区分を追加する。受付・料金・既存申請は変更しない。
ALTER TABLE portal_club_categories DROP CONSTRAINT chk_portal_category_confirmed;
ALTER TABLE portal_club_categories ADD CONSTRAINT chk_portal_category_confirmed CHECK(category_digit IN('1','2','3','5','6','7','8'));
INSERT INTO portal_club_categories(category_digit,name,enabled) VALUES('5','公認団体',1),('7','特別団体',1),('8','準加盟団体',1);
CREATE TABLE portal_club_intake_closures (
 year SMALLINT UNSIGNED PRIMARY KEY,
 closed_by BIGINT UNSIGNED NOT NULL,
 closed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 reason VARCHAR(2000) NOT NULL,
 FOREIGN KEY(year) REFERENCES portal_years(year), FOREIGN KEY(closed_by) REFERENCES users(id),
 CHECK(CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
