-- Path: design/phase2/013_club_fee_profiles_2027.sql
-- 機能: 確定済み2027年度クラブ年会費・新規入会金。既存年度・旧料金表・受付設定は変更しない。
INSERT INTO portal_club_fee_profiles VALUES
 (2027,'official','regular',50000,10000,1),(2027,'affiliated','regular',30000,10000,1),
 (2027,'associate','regular',20000,10000,1),(2027,'organization','regular',20000,10000,1),
 (2027,'official','supporting',50000,10000,1),(2027,'affiliated','supporting',30000,10000,1),
 (2027,'associate','supporting',20000,10000,1),(2027,'organization','supporting',10000,10000,1),
 (2027,'business','supporting',50000,0,1);
