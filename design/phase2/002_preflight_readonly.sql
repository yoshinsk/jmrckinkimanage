-- Path: design/phase2/002_preflight_readonly.sql
-- 機能: 002適用前に停止して確認すべき既存データを集計する。SELECTのみ。
-- 全件0なら空領域への適用候補。1件以上はライセンス分類/日付の移行レビューが必要。
SELECT COUNT(*) AS unsupported_categories FROM portal_club_categories
WHERE category_digit NOT IN ('1','2','3','6');
SELECT COUNT(*) AS general_needs_license_mapping FROM portal_memberships WHERE member_type='general';
SELECT COUNT(*) AS temporary_needs_jst_date_mapping FROM portal_memberships WHERE member_type='temporary';
-- 同日候補も日本時間で計算する。複数日イベントや日付変更は別途レビューする。
SELECT COUNT(*) AS duplicate_temporary_day_groups FROM (
 SELECT m.user_id,DATE(e.starts_at + INTERVAL 9 HOUR) AS jst_day
 FROM portal_memberships m JOIN portal_events e ON e.id=m.event_id
 WHERE m.member_type='temporary'
 GROUP BY m.user_id,DATE(e.starts_at + INTERVAL 9 HOUR) HAVING COUNT(*)>1
) duplicates;
