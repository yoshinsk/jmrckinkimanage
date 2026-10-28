-- Path: design/phase2/005_general_cards.sql
-- 機能: 会員証の採番元とQRを結び付け、旧番号/旧QRの資格照会を拒否する。本番未適用。
-- 既存複数の未失効トークンは事前照合が必要。自動の番号再発行/移行は行わない。
ALTER TABLE portal_member_numbers ADD source_fingerprint BINARY(32) NULL, ADD UNIQUE KEY uq_portal_number_member (id,membership_id);
ALTER TABLE portal_card_tokens
 ADD member_number_id BIGINT UNSIGNED NULL,
 ADD token_cipher MEDIUMBLOB NULL,
 ADD current_member_key BIGINT UNSIGNED AS (CASE WHEN revoked_at IS NULL THEN membership_id ELSE NULL END) PERSISTENT,
 ADD UNIQUE KEY uq_portal_current_card_token (current_member_key),
 ADD FOREIGN KEY (member_number_id,membership_id) REFERENCES portal_member_numbers(id,membership_id) ON DELETE RESTRICT;
