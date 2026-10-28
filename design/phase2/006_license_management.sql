-- Path: design/phase2/006_license_management.sql
-- 機能: 新形式ライセンスの重複確保、暗号化更新履歴、確認済み移行の再実行防止。本番未適用。
CREATE TABLE portal_license_claims (
 kind VARCHAR(24) NOT NULL, lookup_hmac BINARY(32) NOT NULL, key_version SMALLINT UNSIGNED NOT NULL,
 identifier_id BIGINT UNSIGNED NOT NULL UNIQUE, user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(kind,lookup_hmac,key_version),
 FOREIGN KEY(identifier_id,user_id) REFERENCES portal_identifiers(id,user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_identifier_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, identifier_id BIGINT UNSIGNED NOT NULL,
 previous_version BIGINT UNSIGNED NOT NULL, actor_user_id BIGINT UNSIGNED NOT NULL,
 snapshot_cipher MEDIUMBLOB NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE(identifier_id,previous_version),
 FOREIGN KEY(identifier_id) REFERENCES portal_identifiers(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_license_migrations (
 batch_key CHAR(32) CHARACTER SET ascii NOT NULL, source_user_id BIGINT UNSIGNED NOT NULL,
 source_hash BINARY(32) NOT NULL, review_hash BINARY(32) NOT NULL, identifier_id BIGINT UNSIGNED NOT NULL, applied_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(batch_key,source_user_id),
 FOREIGN KEY(identifier_id) REFERENCES portal_identifiers(id) ON DELETE RESTRICT,
 FOREIGN KEY(applied_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
