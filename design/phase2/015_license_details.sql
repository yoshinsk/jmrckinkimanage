-- Path: design/phase2/015_license_details.sql
-- 機能: 旧期限・自由記載を変更せず、本人が確認した指定複数種別と発行日を別表で保持する。
CREATE TABLE portal_license_details (
 identifier_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 types_json JSON NOT NULL,
 issued_on DATE NULL,
 FOREIGN KEY(identifier_id) REFERENCES portal_identifiers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT chk_license_types_array CHECK (JSON_TYPE(types_json)='ARRAY' AND JSON_LENGTH(types_json)>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
