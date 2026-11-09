-- Path: design/phase2/012_editor_drafts.sql
-- 機能: 事務局の予定・通知文面・結果・PDF原本を非公開下書きとして版管理する。既存業務表は変更しない。
CREATE TABLE portal_editor_drafts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 kind VARCHAR(24) NOT NULL,
 item_key VARCHAR(64) NOT NULL,
 row_version BIGINT UNSIGNED NOT NULL,
 edited_by BIGINT UNSIGNED NOT NULL,
 edited_at DATETIME(6) NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 UNIQUE(kind,item_key),
 FOREIGN KEY(edited_by) REFERENCES users(id),
 CHECK(kind IN ('calendar','mail_template','result_csv','document_pdf')),
 CHECK(row_version>0 AND edited_at<expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE portal_editor_revisions (
 draft_id BIGINT UNSIGNED NOT NULL,
 version BIGINT UNSIGNED NOT NULL,
 payload_cipher MEDIUMBLOB NOT NULL,
 payload_sha256 BINARY(32) NOT NULL,
 edited_by BIGINT UNSIGNED NOT NULL,
 edited_at DATETIME(6) NOT NULL,
 PRIMARY KEY(draft_id,version),
 FOREIGN KEY(draft_id) REFERENCES portal_editor_drafts(id),
 FOREIGN KEY(edited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
