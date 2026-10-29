-- Path: design/phase2/009_bank_master_management.sql
-- 機能: 口座設定の排他制御と暗号化変更履歴、取込元の楽観ロックを追加。本番未適用。
CREATE TABLE portal_bank_master_lock (id TINYINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO portal_bank_master_lock VALUES(1);
ALTER TABLE portal_bank_sources
 ADD COLUMN row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN updated_by BIGINT UNSIGNED NULL,
 ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 ADD FOREIGN KEY(updated_by) REFERENCES users(id);
CREATE TABLE portal_bank_master_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 entity_type VARCHAR(16) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NOT NULL,
 changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 changed_fields VARCHAR(512) NOT NULL,
 reason_cipher MEDIUMBLOB NOT NULL,
 snapshot_cipher MEDIUMBLOB NOT NULL,
 FOREIGN KEY(actor_user_id) REFERENCES users(id),
 UNIQUE(entity_type,entity_id,row_version),
 CHECK(entity_type IN ('account','source'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
