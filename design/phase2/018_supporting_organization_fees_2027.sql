-- Path: design/phase2/018_supporting_organization_fees_2027.sql
-- 機能: 11/22確定の賛助登録団体年会費。適用CLIの固定原本照合・トランザクション内で実行する。
UPDATE portal_club_fee_profiles SET annual_yen=20000 WHERE year=2027 AND jaf_kind='organization' AND membership_kind='supporting' AND annual_yen=10000 AND joining_yen=10000 AND enabled=1;
INSERT INTO portal_club_fee_profiles(year,jaf_kind,membership_kind,annual_yen,joining_yen,enabled) VALUES
 (2027,'official_org','supporting',20000,10000,1),
 (2027,'associate_org','supporting',20000,10000,1);
