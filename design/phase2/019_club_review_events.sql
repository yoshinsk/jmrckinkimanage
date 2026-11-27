-- Path: design/phase2/019_club_review_events.sql
-- 機能: 訂正受付の終了・再開を追記だけで保存する。旧終了記録・受付・利用者権限は変更しない。
CREATE TABLE portal_club_review_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 year SMALLINT UNSIGNED NOT NULL,
 action ENUM('closed','reopened') NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(2000) NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 KEY idx_club_review_year(year,id),
 FOREIGN KEY(year) REFERENCES portal_years(year),
 FOREIGN KEY(actor_user_id) REFERENCES users(id),
 CHECK(CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
