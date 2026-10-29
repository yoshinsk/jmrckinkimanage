-- Path: design/phase2/010_profile_corrections.sql
-- 機能: 事務局による氏名・生年月日の訂正履歴を暗号化して保存。本番未適用。
CREATE TABLE portal_profile_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 previous_version BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 snapshot_cipher MEDIUMBLOB NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE(user_id,previous_version),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
