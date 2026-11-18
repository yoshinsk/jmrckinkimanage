<?php
// Path: tests/club/club_closure_http.php
// 機能: 年度全体の再申請終了、未確認・差し戻し保護、直接POST、権限・CSRF・他年度不変を隔離検証する。
declare(strict_types=1);
(function()use($db):void{
    $cfg=require '/srv/private/auth.config.php';$service=new \Jmrc\Club\ClubIntakeService($db,$cfg);$closure=new \Jmrc\Club\ClubReviewClosure($db,$cfg);
    $path='/manage/club-register.php?format=json';$saved=$db->query('SELECT club_open_at,club_close_at,valid_until,source_mode FROM portal_years WHERE year=2027')->fetch(PDO::FETCH_ASSOC);
    $owner=new Browser();$owner->login(1);$office=new Browser();$office->login(7);$finance=new Browser();$finance->login(13);$other=new Browser();$other->login(2);
    $post=static fn(Browser $b,array $extra=[])=>$b->request($path,array_replace(['action'=>'close_review','year'=>'2027','reason'=>'架空の照合完了記録','confirmed'=>'1','_csrf'=>identity($b)['csrf']],$extra));
    $legacy=$db->query('CHECKSUM TABLE users,entrants,clubs,jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships')->fetchAll(PDO::FETCH_ASSOC);
    $race=static function(array $tasks):array{
        $when=microtime(true)+0.5;$workers=[];$result=[];
        foreach($tasks as $task){$pipes=[];$p=proc_open([PHP_BINARY,'/srv/tests/concurrent_closure.php',(string)$when,...array_map('strval',$task)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
        foreach($workers as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check('L09 並行ワーカーは正常終了 '.count($result),proc_close($p)===0&&$err==='');$result[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
        return $result;
    };
    try{
        check('L09 本人の年度終了拒否',$post($owner)['status']===403);
        check('L09 財務単独の年度終了拒否',$post($finance)['status']===403);
        check('L09 CSRFなし終了拒否',$post($office,['_csrf'=>''])['status']===400);
        check('L09 明示確認なし終了拒否',$post($office,['confirmed'=>'0'])['status']===422);
        $db->exec("UPDATE portal_years SET club_open_at=UTC_TIMESTAMP()-INTERVAL 1 DAY,club_close_at=UTC_TIMESTAMP()+INTERVAL 1 DAY,valid_until='2027-12-31 15:00:00',source_mode='shadow' WHERE year=2027");
        check('L09 新規提出期間中は終了しない',$post($office)['status']===409);
        $db->exec("UPDATE portal_years SET club_close_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE year=2027");
        check('L09 理由なし終了拒否',$post($office,['reason'=>''])['status']===422);
        check('L09 未確認が残る年度終了拒否',$post($office)['status']===409&&!$closure->state(2027)['closed']);
        // 合成申請だけを一時的に照合済みへ移して、年度全体の終了境界を観測する。
        // 行の原本は全保存し、finallyで復元する。本番では一切実行しない。
        $db->exec("UPDATE portal_years SET club_close_at=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE year=2027");
        $in=array_fill_keys(array_keys(\Jmrc\Club\ClubRegistrationData::LABELS),'架空');$in=array_replace($in,['jaf_number'=>'99879','club_name'=>'終了試験Ⅲ','club_short'=>'終了Ⅲ','registration_kind'=>'new','jaf_kind'=>'organization','membership_kind'=>'supporting','region'=>'27','company'=>'','same_contact'=>'1','club_postcode'=>'0000000','rep_postcode'=>'0000000','rep_email'=>'closure@example.invalid','candidate_ref'=>'']);
        $record=$service->submit(1,2027,$in,bin2hex(random_bytes(24)));$id=(int)$record['id'];$returned=$service->returnForCorrection(7,$id,1,'架空の番号確認');
        $db->exec("UPDATE portal_years SET club_close_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE year=2027");
        check('L09 締切後の本人訂正ボタン有効',str_contains($owner->request('/manage/club-register.php?id='.$id)['body'],'訂正して再申請する</button>')&&!str_contains($owner->request('/manage/club-register.php?id='.$id)['body'],'disabled>訂正して再申請する'));
        check('L09 差し戻し中は終了拒否',$post($office)['status']===409);
        $updated=$service->update(1,$id,2,$in);check('L09 締切後の本人訂正可',$updated['review_state']==='submitted'&&(int)$updated['row_version']===3);
        foreach(['official_org'=>'5','special_org'=>'7','associate_org'=>'8'] as $kind=>$digit){
            check('追加区分の番号 '.$digit,\Jmrc\Club\ClubFeePolicy::PREFIXES[$kind]===$digit);
            $r=$owner->request($path,['action'=>'quote','year'=>2027,'jaf_kind'=>$kind,'membership_kind'=>'regular','registration_kind'=>'new','_csrf'=>identity($owner)['csrf']]);
            check('新区分の未確定料金を流用しない '.$digit,$r['status']===409);
        }
        $savedIntakes=$db->query("SELECT id,review_state,club_year_id,matched_by,matched_at,row_version FROM portal_club_intakes WHERE year=2027 AND review_state<>'linked'")->fetchAll(PDO::FETCH_ASSOC);
        // 各行専用の架空クラブ年度へ一対一で紐付け、DB制約を保って照合完了を用意する。
        $db->beginTransaction();
        foreach($savedIntakes as $row){
            if((int)$row['id']===$id){continue;}
            $code=(string)(70000+(int)$row['id']);
            $s=$db->prepare("INSERT INTO portal_clubs(fixed_code,name_full,name_short,status) VALUES(?,'架空終了試験','終了','active')");$s->execute([$code]);$club=(int)$db->lastInsertId();
            $s=$db->prepare("INSERT INTO portal_applications(kind,applicant_user_id,year,status,request_key) VALUES('club',7,2027,'submitted',?)");$s->execute([bin2hex(random_bytes(24))]);$app=(int)$db->lastInsertId();
            $s=$db->prepare("INSERT INTO portal_club_years(club_id,year,category_digit,display_code,application_id,name_full,name_short,rep_name,contact_name,postcode,address,phone,email) VALUES(?,2027,'6',?,?,'架空','架空','架空','架空','0000000','架空','0000000000','closure@example.invalid')");$s->execute([$club,'6'.$code,$app]);$cy=(int)$db->lastInsertId();$dummy[]=[$cy,$app,$club];
            $s=$db->prepare("UPDATE portal_club_intakes SET review_state='linked',club_year_id=?,matched_by=7,matched_at=UTC_TIMESTAMP(6) WHERE id=?");$s->execute([$cy,$row['id']]);
        }
        $db->commit();
        $results=$race([['link',$id],['close',0]]);$byAction=array_column($results,'status','action');
        check('L09 最後の照合と終了が競合しても未確認を残さない',$byAction['link']===200&&in_array($byAction['close'],[200,409],true)&&$closure->state(2027)['pending_count']===0);
        $raceLinked=$service->read(7,$id);$raceClubYear=(int)$raceLinked['club_year_id'];$raceClub=(int)$db->query('SELECT club_id FROM portal_club_years WHERE id='.$raceClubYear)->fetchColumn();
        check('L09 終了結果と保存状態が一致',$closure->state(2027)['closed']===($byAction['close']===200));
        // 競合の順序に関係なく、独立した二重終了の試験を同じ架空年度で開始する。
        $db->exec('DELETE FROM portal_club_intake_closures WHERE year=2027');
        $statuses=array_column($race([['close',0],['close',0]]),'status');sort($statuses);
        check('L09 同時終了は一件だけ保存',$statuses===[200,409]&&$closure->state(2027)['closed']&&(int)$db->query('SELECT COUNT(*) FROM portal_club_intake_closures WHERE year=2027')->fetchColumn()===1);
        check('L09 終了の重複実行拒否',$post($office)['status']===409);
        check('L09 他年度を終了しない',!$closure->state(2028)['closed']);
        try{$service->update(1,$id,3,$in);check('L09 終了後の直接訂正拒否',false);}catch(\Jmrc\Auth\HttpFailure $e){check('L09 終了後の直接訂正拒否',$e->status===409);}
        check('L09 再開操作は提供しない',$office->request($path,['action'=>'reopen_review','year'=>2027,'_csrf'=>identity($office)['csrf']])['status']===422);
        check('L09 既存エントリー・受付・会費不変',$legacy===$db->query('CHECKSUM TABLE users,entrants,clubs,jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships')->fetchAll(PDO::FETCH_ASSOC));
    }finally{
        if($db->inTransaction()){$db->rollBack();}
        $db->exec('DELETE FROM portal_club_intake_closures WHERE year=2027');
        foreach($savedIntakes??[] as $row){$s=$db->prepare('UPDATE portal_club_intakes SET review_state=?,club_year_id=?,matched_by=?,matched_at=?,row_version=? WHERE id=?');$s->execute([$row['review_state'],$row['club_year_id'],$row['matched_by'],$row['matched_at'],$row['row_version'],$row['id']]);}
        if(isset($raceClubYear)){$db->exec('DELETE FROM portal_club_intake_revisions WHERE intake_id='.$id.' AND row_version=3;DELETE FROM portal_club_years WHERE id='.$raceClubYear.';DELETE FROM portal_clubs WHERE id='.$raceClub);}
        foreach($dummy??[] as [$cy,$app,$club]){foreach([['portal_club_years',$cy],['portal_applications',$app],['portal_clubs',$club]] as [$t,$i]){$s=$db->prepare('DELETE FROM '.$t.' WHERE id=?');$s->execute([$i]);}}
        $s=$db->prepare('UPDATE portal_years SET club_open_at=?,club_close_at=?,valid_until=?,source_mode=? WHERE year=2027');$s->execute(array_values($saved));
    }
})();
