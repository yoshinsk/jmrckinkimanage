-- Path: design/phase2/017_regular_organization_fees_2027.sql
-- 機能: L12訂正の正会員団体年会費とC08既回答の新規入会金。既存の料金・請求・受付を変更しない。
ALTER TABLE portal_club_fee_profiles DROP CONSTRAINT CONSTRAINT_3;
ALTER TABLE portal_club_fee_profiles ADD CONSTRAINT CONSTRAINT_3 CHECK(jaf_kind IN('official','affiliated','associate','organization','business','official_org','associate_org'));
INSERT INTO portal_club_fee_profiles(year,jaf_kind,membership_kind,annual_yen,joining_yen,enabled) VALUES
 (2027,'official_org','regular',20000,10000,1),
 (2027,'associate_org','regular',20000,10000,1);
