<?php
// Path: tests/club/club_boundary_http.php
// 機能: 架空DBの年度日程だけを動かして、実HTTPの受付境界・再送・失効権限・読取準備画面と拒否時の原子性を確認する。
declare(strict_types=1);
(function()use($db):void{
    $cfg=require '/srv/private/auth.config.php';$service=new \Jmrc\Club\ClubIntakeService($db,$cfg);
    $prep=new \Jmrc\Operations\PreparationService($db,[]);$path='/manage/club-register.php?format=json';
    $saved=$db->query('SELECT source_mode,club_open_at,club_close_at,valid_until,updated_at FROM portal_years WHERE year=2027')->fetch(PDO::FETCH_ASSOC);
    $grants=$db->query('SELECT id,valid_until FROM portal_user_role_grants WHERE user_id=7')->fetchAll(PDO::FETCH_ASSOC);
    $legacy=$db->query('CHECKSUM TABLE users,entrants,clubs,jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships')->fetchAll(PDO::FETCH_ASSOC);
    $tables=$db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name NOT IN ('audit_logs','portal_audit_events') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    $fingerprint=static fn()=>$db->query('CHECKSUM TABLE '.implode(',',array_map(static fn($n)=>'`'.$n.'`',$tables)))->fetchAll(PDO::FETCH_ASSOC);
    $owner=new Browser();$owner->login(1);$office=new Browser();$office->login(7);$other=new Browser();$other->login(2);$finance=new Browser();$finance->login(13);$anon=new Browser();
    $in=array_fill_keys(array_keys(\Jmrc\Club\ClubRegistrationData::LABELS),'架空C7');
    $in=array_replace($in,['club_name'=>'架空境界C7Ⅲ','club_short'=>'C7Ⅲ','jaf_number'=>'99888','registration_kind'=>'new','jaf_kind'=>'organization','membership_kind'=>'supporting','region'=>'27','company'=>'','same_contact'=>'1','club_postcode'=>'0000000','rep_postcode'=>'0000000','rep_email'=>'c7@example.invalid','candidate_ref'=>'']);
    $submit=static fn(array $extra=[])=>$owner->request($path,array_replace($in,['action'=>'submit','year'=>'2027','request_key'=>bin2hex(random_bytes(24)),'_csrf'=>identity($owner)['csrf']],$extra));
    $reject=static function(string $name,int $expected,callable $request)use($fingerprint):void{
        $before=$fingerprint();$r=$request();check($name,$r['status']===$expected);check($name.' 業務表不変',$before===$fingerprint());
    };
    $window=static function(string $from,string $until,string $mode='shadow')use($db):void{
        $db->exec("UPDATE portal_years SET source_mode=".$db->quote($mode).",club_open_at=$from,club_close_at=$until,valid_until='2027-12-31 15:00:00.000000' WHERE year=2027");
    };
    try{
        $window('UTC_TIMESTAMP(6)+INTERVAL 1 DAY','UTC_TIMESTAMP(6)+INTERVAL 2 DAY');
        check('C7 開始前の画面は新規提出不可',json_decode($owner->request($path)['body'],true)['open']===false);
        $reject('C7 開始前のPOSTを拒否',422,fn()=>$submit());
        $reject('C7 URLの時計指定で開始前を迂回しない',422,fn()=>$owner->request($path.'&now=2099-01-01T00:00:00Z',array_replace($in,['action'=>'submit','year'=>2027,'request_key'=>bin2hex(random_bytes(24)),'_csrf'=>identity($owner)['csrf']])));
        foreach([['NULL','UTC_TIMESTAMP(6)+INTERVAL 1 DAY'],['UTC_TIMESTAMP(6)-INTERVAL 1 DAY','NULL']] as $i=>[$from,$to]){
            $before=$fingerprint();$denied=false;
            try{$window($from,$to);}catch(PDOException $e){$denied=(int)$e->errorInfo[1]===1048;}
            check('C7 日程未設定はDB制約でも拒否 '.$i,$denied&&$before===$fingerprint());
        }
        $reject('C7 未登録年度への提出を拒否',422,fn()=>$submit(['year'=>2099]));
        $window('UTC_TIMESTAMP(6)-INTERVAL 1 DAY','UTC_TIMESTAMP(6)+INTERVAL 1 DAY','legacy');
        $reject('C7 既存正本年度へクラブ申請を作らない',422,fn()=>$submit());
        $window('UTC_TIMESTAMP(6)-INTERVAL 1 DAY','UTC_TIMESTAMP(6)+INTERVAL 1 DAY');
        $key=bin2hex(random_bytes(24));$r=$submit(['request_key'=>$key]);$record=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR);$id=(int)($record['id']??0);
        check('C7 期間内はshadowのまま新規提出',$r['status']===200&&$id>0&&$record['review_state']==='submitted'&&$record['club_year_id']===null);
        $window('UTC_TIMESTAMP(6)-INTERVAL 2 DAY','UTC_TIMESTAMP(6)-INTERVAL 1 DAY');
        $reject('C7 締切後の新規POSTを拒否',422,fn()=>$submit());
        $before=$fingerprint();$replay=$submit(['request_key'=>$key]);$again=json_decode($replay['body'],true);
        check('C7 締切後の同一再送は既存結果の読取だけ',$replay['status']===200&&$again['replayed']&&(int)$again['id']===$id&&$before===$fingerprint());
        $reject('C7 締切後の同キー別内容は拒否',409,fn()=>$submit(['request_key'=>$key,'club_name'=>'別内容']));
        $before=$fingerprint();$held=new \Jmrc\Club\ClubIntakeService($db,array_replace($cfg,['club_intake_enabled'=>false]));
        try{$held->submit(1,2027,$in,bin2hex(random_bytes(24)));check('C7 期間変更でも停止設定は迂回不可',false);}catch(\Jmrc\Auth\HttpFailure $e){check('C7 期間変更でも停止設定は迂回不可',$e->status===503&&$before===$fingerprint());}
        $reject('C7 他人の申請参照を拒否',403,fn()=>$other->request($path.'&id='.$id));
        $reject('C7 本人の事務局照合を拒否',403,fn()=>$submit(['action'=>'link','id'=>$id,'row_version'=>1,'checked'=>'1']));
        $reject('C7 財務単独の事務局照合を拒否',403,fn()=>$finance->request($path,['action'=>'link','id'=>$id,'row_version'=>1,'checked'=>'1','_csrf'=>identity($finance)['csrf']]));
        $reject('C7 準備確認は未認証を拒否',401,fn()=>$anon->request('/manage/preparation.php?section=club_opening'));
        $reject('C7 準備確認は一般本人を拒否',403,fn()=>$owner->request('/manage/preparation.php?section=club_opening'));
        $reject('C7 準備確認のPOST開放命令を拒否',405,fn()=>$office->request('/manage/preparation.php?section=club_opening',['action'=>'open']));
        $reject('C7 準備確認の配列年度を拒否',422,fn()=>$office->request('/manage/preparation.php?section=club_opening&year[]=2027'));
        $reject('C7 準備確認の未登録年度を拒否',404,fn()=>$office->request('/manage/preparation.php?section=club_opening&year=2099'));
        $s=$db->prepare('UPDATE portal_years SET source_mode=?,club_open_at=?,club_close_at=?,valid_until=?,updated_at=? WHERE year=2027');$s->execute(array_values($saved));
        $dates=['2026-11-30T23:59:59.999999+09:00'=>'not_open','2026-12-01T00:00:00+09:00'=>'open','2027-02-07T23:59:59.999999+09:00'=>'open','2027-02-08T00:00:00+09:00'=>'closed'];
        // 固定時計はPHP引数だけ。HTTPで任意時計を受け取る入口は増やさない。
        $window($db->quote('2026-11-30 15:00:00.000000'),$db->quote('2027-02-07 15:00:00.000000'));
        foreach($dates as $time=>$expected){$read=$prep->clubOpening(7,2027,new DateTimeImmutable($time));check('C7 日程と停止を分ける '.$time,$read['period_state']===$expected&&!$read['intake_enabled']&&!$read['new_submission_open']&&!$read['opening_authorized']&&!$read['writes_enabled']);}
        $before=$fingerprint();$read=$office->request('/manage/preparation.php?section=club_opening&format=json&now=2099-01-01');$data=json_decode($read['body'],true)['data'];
        check('C7 準備画面は日本時間と締切境界を返す',$read['status']===200&&$data['opens_at_jst']==='2026-12-01T00:00:00.000000+09:00'&&$data['last_submission_jst']==='2027-02-07T23:59:59.999999+09:00'&&$data['closes_at_exclusive_jst']==='2027-02-08T00:00:00.000000+09:00');
        check('C7 URLの時計は確認画面へ反映しない',!str_starts_with($data['checked_at'],'2099-')&&$data['correction_deadline_confirmed']===false);
        check('C7 準備画面は全業務表を変更しない',$before===$fingerprint());
        $db->exec('UPDATE portal_user_role_grants SET valid_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE user_id=7 AND valid_from<UTC_TIMESTAMP(6)-INTERVAL 1 SECOND');
        $reject('C7 事務局権限の期限切れを即時拒否',403,fn()=>$office->request('/manage/preparation.php?section=club_opening'));
        $reject('C7 期限切れ事務局の照合を拒否',403,fn()=>$office->request($path,['action'=>'link','id'=>$id,'row_version'=>1,'checked'=>'1','_csrf'=>identity($office)['csrf']]));
    }finally{
        $s=$db->prepare('UPDATE portal_years SET source_mode=?,club_open_at=?,club_close_at=?,valid_until=?,updated_at=? WHERE year=2027');$s->execute(array_values($saved));
        foreach($grants as $g){$db->prepare('UPDATE portal_user_role_grants SET valid_until=? WHERE id=?')->execute([$g['valid_until'],$g['id']]);}
    }
    check('C7 試験後も既存アカウント・エントリー・ダート設定は不変',$legacy===$db->query('CHECKSUM TABLE users,entrants,clubs,jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships')->fetchAll(PDO::FETCH_ASSOC));
})();
