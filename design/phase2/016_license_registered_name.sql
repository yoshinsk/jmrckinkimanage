-- Path: design/phase2/016_license_registered_name.sql
-- 機能: 本人が申告するJAF登録名を暗号保管する。既存の氏名・カナ・エントリー名から補完しない。
ALTER TABLE portal_license_details ADD COLUMN registered_name_cipher MEDIUMBLOB NULL;
