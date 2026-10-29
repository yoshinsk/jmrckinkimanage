-- Path: design/phase2/2027_registration_calendar.proposal.sql
-- 機能: R12回答に基づく2027年度マスターの設定案。自動適用・既存行の上書きは行わない。
-- 日本時間: クラブ2026/12/1～2027/2/7終日、本人2027/1/1～12/31。
-- 既存年度があればINSERTは失敗する。差分確認のうえ別途適用する。source_modeはshadowのまま。
INSERT INTO portal_years(year,valid_from,valid_until,club_open_at,club_close_at,member_self_open_at,staff_open_at,source_mode,policy_version)
VALUES(2027,'2026-12-31 15:00:00','2027-12-31 15:00:00',
 '2026-11-30 15:00:00','2027-02-07 15:00:00','2026-12-31 15:00:00','2026-11-30 15:00:00','shadow','R12-20261029');
