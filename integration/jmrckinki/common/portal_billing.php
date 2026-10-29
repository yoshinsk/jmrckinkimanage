<?php
// Path: integration/jmrckinki/common/portal_billing.php → entry/common/portal_billing.php
// 機能: portal正本年度の旧会費加算を早期拒否し、支払済み判定をポータル請求へ接続する。
declare(strict_types=1);

/** R10: 救済基金の既存判定を維持し、会員会費だけを正本から置き換える。保険・協議会見舞金とは相互流用しない。 */
function portal_annual_payment_status(PDO $db,int $user,int $year,array|false $legacy): array
{
    $s=$db->prepare('SELECT source_mode FROM portal_years WHERE year=?');$s->execute([$year]);
    if ($s->fetchColumn()!=='portal') { return $legacy?:['is_member_paid'=>0,'is_mutual_aid_paid'=>0]; }
    $s=$db->prepare("SELECT id FROM portal_memberships WHERE user_id=? AND year=? AND member_type='general'");$s->execute([$user,$year]);$id=$s->fetchColumn();
    $state=$id?(new \Jmrc\Billing\Eligibility($db))->evaluate((int)$id):null;
    return ['is_member_paid'=>(int)($state['paid']??false),'is_mutual_aid_paid'=>(int)($legacy['is_mutual_aid_paid']??0)];
}

/** 対象年度の正本を確認する。未登録年度をportalと推測しない。 */
function portal_billing_year(PDO $db,int $year): bool
{
    $s=$db->prepare("SELECT year FROM portal_years WHERE year=? AND source_mode='portal'");$s->execute([$year]);return (bool)$s->fetchColumn();
}

/** 旧POSTが会費を要求した場合は、何も書く前に停止。管理側は対象entryから年度を解決する。 */
function portal_guard_legacy_membership_fee(): void
{
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { return; }
    $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$db=jmrc_db();$year=false;
    if ($path==='/entry/entrant/entries.php' && !empty($_POST['jmrc_join_member'])) {
        $s=$db->prepare('SELECT YEAR(event_date) FROM jmrc_events WHERE id=?');$s->execute([(int)($_GET['event_id']??0)]);$year=$s->fetchColumn();
    } elseif ($path==='/entry/admin/entry_payment_check.php' && ($_POST['action']??'')==='add_admission_fee') {
        $s=$db->prepare('SELECT YEAR(ev.event_date) FROM jmrc_entries e JOIN jmrc_events ev ON ev.id=e.event_id WHERE e.id=?');$s->execute([(int)($_POST['entry_id']??0)]);$year=$s->fetchColumn();
    }
    if ($year) {
        jmrc_auth()->requireIdentity();jmrc_auth()->verifyCsrf($_POST['_csrf']??null);
        if (portal_billing_year($db,(int)$year)) { throw new \Jmrc\Auth\HttpFailure(409,'会費は /manage/billing.php で請求・入金確認してください'); }
    }
}
