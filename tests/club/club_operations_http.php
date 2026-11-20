<?php
// Path: tests/club/club_operations_http.php
// 機能: C5～C6の振込案内・実入金日・入金訂正・年度承認と、旧クラブ/個人情報の本人限定再利用を架空データで検証する。
declare(strict_types=1);
(function()use($db):void{
    $cfg=require '/srv/private/auth.config.php';$ops=new \Jmrc\Club\ClubOperations($db,$cfg);$entry=new \Jmrc\Profile\EntryProfile($db);
    $saved=$db->query('SELECT source_mode,club_open_at,club_close_at FROM portal_years WHERE year=2027')->fetch(PDO::FETCH_ASSOC);
    $db->exec("UPDATE portal_years SET source_mode='shadow',club_open_at=UTC_TIMESTAMP()-INTERVAL 1 DAY,club_close_at=UTC_TIMESTAMP()+INTERVAL 40 DAY WHERE year=2027");
    $db->exec("INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES(90071,3,'u90071@example.invalid',1,".$db->quote(password_hash('lab-password-only',PASSWORD_DEFAULT)).");INSERT INTO portal_account_controls(user_id) VALUES(90071);
      INSERT INTO entrants(user_id,last_name,first_name,last_kana,first_kana,entry_name,postcode,address,phone,contact_mobile,birthday,license_no,license_expiry,jaf_license_no,jaf_license_type,club_abbrev,club_code) VALUES(90071,'旧姓','既存ＡⅠ','キュウセイ','キソン','競技Ａ　Ⅲ','0000000','架空既存住所Ａ','0000000','0000000','1990-01-02','LAB-DRIVER-NEVER-REUSE','2030-12-31','1234599999','国内Ｂ','旧所属Ⅲ','199972');
      INSERT INTO portal_profiles(user_id,last_name,first_name,birthday) VALUES(90071,'訂正後姓','本人Ⅲ','1991-02-03');");
    $db->exec("INSERT INTO users(id,role_id,email,email_verified,password_hash) SELECT 90072,role_id,'u90072@example.invalid',email_verified,password_hash FROM users WHERE id=90071;INSERT INTO portal_account_controls(user_id) VALUES(90072);
      INSERT INTO entrants(user_id,last_name,first_name,last_kana,first_kana,entry_name,postcode,address,phone,contact_mobile,birthday,license_no,license_expiry,jaf_license_no,jaf_license_type,club_abbrev,club_code) SELECT 90072,last_name,first_name,last_kana,first_kana,entry_name,postcode,address,phone,contact_mobile,birthday,license_no,license_expiry,jaf_license_no,jaf_license_type,club_abbrev,club_code FROM entrants WHERE user_id=90071");
    $legacy=$db->query('CHECKSUM TABLE users,entrants,clubs,jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships')->fetchAll(PDO::FETCH_ASSOC);
    $clubUser=new Browser();$clubUser->login(2);$person=new Browser();$person->login(90071);$office=new Browser();$office->login(7);$finance=new Browser();$finance->login(13);$other=new Browser();$other->login(8);$anon=new Browser();
    check('既存CLUBアカウントはentryとportalで同じ本人',identity($clubUser,'entry')['uid']===2&&identity($clubUser)['uid']===2);
    check('既存個人アカウントはentryとportalで同じ本人',identity($person,'entry')['uid']===90071&&identity($person)['uid']===90071);
    $legacyClub=$entry->club(2);check('既存クラブの氏名・所在地・口座を本人の入力候補に再利用',$legacyClub['values']['club_name']==='架空主催'&&$legacyClub['values']['rep_name']==='架空 代表'&&str_contains($legacyClub['values']['bank_details'],'架空銀行')&&!isset($legacyClub['values']['membership_kind']));
    $page=$clubUser->request('/manage/club-register.php?reuse=entry');check('既存クラブ情報を入力欄へ表示',$page['status']===200&&str_contains($page['body'],'value="架空主催"'));
    $personal=$entry->personal(90071);check('個人情報の再利用はportalの訂正済み氏名・生年月日を優先',$personal['last_name']==='訂正後姓'&&$personal['birthday']==='1991-02-03'&&$personal['address']==='架空既存住所A'&&$personal['entry_name']==='競技A Ⅲ');
    check('初回移行後に登録した既存個人も元情報を参照できる',$entry->personal(90072)['name_source']==='entry'&&$entry->personal(90072)['first_name']==='既存AⅠ');
    $l=$entry->license(90071);check('旧JAF番号は再利用し運転免許証の番号・期限を引き継がない',$l['number']==='1234599999'&&$l['expires_on']===''&&!array_key_exists('license_no',$l));
    $page=$person->request('/manage/licenses.php?reuse=entry');check('本人の旧JAF情報を表示',$page['status']===200&&str_contains($page['body'],'value="1234599999"')&&!str_contains($page['body'],'2030-12-31')&&!str_contains($page['body'],'LAB-DRIVER-NEVER-REUSE'));
    $page=$person->request('/manage/memberships.php?reuse=entry&format=json&user_id=2');$data=json_decode($page['body'],true);check('指定IDではなくログインした本人の個人情報だけ再利用',$page['status']===200&&$data['reused_profile']['user_id']===90071);
    try{$entry->personal(5);check('停止アカウントの再利用を拒否',false);}catch(\Jmrc\Auth\HttpFailure $ex){check('停止アカウントの再利用を拒否',$ex->status===401);}
    $intake=new \Jmrc\Club\ClubIntakeService($db,$cfg);
    $make=static function(string $code,string $name)use($intake):array{
        $in=array_fill_keys(array_keys(\Jmrc\Club\ClubRegistrationData::LABELS),'架空');$in=array_replace($in,['club_name'=>$name,'club_short'=>$name,'jaf_number'=>$code,'registration_kind'=>'new','jaf_kind'=>'organization','membership_kind'=>'regular','region'=>'27','company'=>'','same_contact'=>'1','club_postcode'=>'0000000','rep_postcode'=>'0000000','rep_email'=>'club@example.invalid']);
        $r=$intake->submit(2,2027,$in,bin2hex(random_bytes(24)));return $intake->link(7,(int)$r['id'],1,0,$code,true);
    };
    $a=$make('99972','架空C5本体Ⅲ');$id=(int)$a['id'];$path='/manage/club-finance.php';
    $get=static fn(Browser $b,int $i)=>$b->request($path.'?format=json&intake='.$i);
    $post=static fn(Browser $b,int $i,string $act,array $data=[])=>$b->request($path.'?format=json',$data+['intake'=>$i,'action'=>$act,'_csrf'=>identity($b)['csrf']]);
    check('クラブ会計の未認証拒否',$get($anon,$id)['status']===401);check('別クラブの申請者は参照拒否',$get($other,$id)['status']===403);
    check('本人・事務局・財務が照合後のクラブを参照',$get($clubUser,$id)['status']===200&&$get($office,$id)['status']===200&&$get($finance,$id)['status']===200);
    check('クラブ会計のCSRF拒否',$clubUser->request($path,['intake'=>$id,'action'=>'issue'])['status']===400);
    $banks=$db->query("SELECT id,enabled FROM portal_bank_accounts WHERE purpose_code='club_fee'")->fetchAll(PDO::FETCH_ASSOC);$db->exec("UPDATE portal_bank_accounts SET enabled=0 WHERE purpose_code='club_fee'");
    $count=(int)$db->query('SELECT COUNT(*) FROM portal_invoices')->fetchColumn();check('未確定の振込先では請求を発行しない',$post($clubUser,$id,'issue')['status']===409&&(int)$db->query('SELECT COUNT(*) FROM portal_invoices')->fetchColumn()===$count);
    $bm=new \Jmrc\Bank\BankMasterService($db,$cfg);$bank=$bm->saveAccount(7,['purpose_code'=>'club_fee','year'=>'2027','display_name'=>'架空C5専用口座','details'=>"架空ゆうちょ銀行\n架空店 普通 0000000\n架空口座名義 <script>対象外</script>",'enabled'=>'1','reason'=>'架空の一連確認']);
    $res=$post($clubUser,$id,'issue');$r=json_decode($res['body'],true);$invoice=(int)$r['invoice']['id'];
    check('shadowでも請求から振込先へ接続',$res['status']===200&&$r['guide']['ready']&&$r['stage']==='unpaid'&&(int)$r['invoice']['amount_yen']===30000&&$r['invoice']['payment_url_snapshot']===null);
    check('口座暗号文をJSONへ返さない',!str_contains($res['body'],'gcm1:')&&!str_contains($res['body'],'account_snapshot_cipher'));
    check('再発行で請求を二重作成しない',(int)json_decode($post($clubUser,$id,'issue')['body'],true)['invoice']['id']===$invoice);
    $html=$clubUser->request($path.'?intake='.$id);check('案内はHTMLエスケープして表示',str_contains($html['body'],'&lt;script&gt;対象外&lt;/script&gt;')&&!str_contains($html['body'],'<script>対象外'));
    try{(new \Jmrc\Club\ClubOperations($db,array_replace($cfg,['club_intake_enabled'=>false])))->execute(7,$id,'confirm',[]);check('保留中は新会計処理も拒否',false);}catch(\Jmrc\Auth\HttpFailure $ex){check('保留中は新会計処理も拒否',$ex->status===503);}
    $day=(new DateTimeImmutable('today',new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d');$key=bin2hex(random_bytes(24));$data=['amount'=>'5000','reference'=>'C5-FIRST','operation'=>$key,'received_on'=>$day];
    check('代表者本人の自己入金確認を拒否',$post($clubUser,$id,'confirm',$data)['status']===403);
    check('銀行明細の未来日を拒否',$post($office,$id,'confirm',array_replace($data,['received_on'=>'2099-01-01']))['status']===422);
    $res=$post($finance,$id,'confirm',$data);$r=json_decode($res['body'],true);check('財務が実入金日で不足入金を記録',$res['status']===200&&(int)$r['invoice']['balance_yen']===25000&&!$r['eligibility']['ready']);
    $expected=(new DateTimeImmutable($day.'T00:00:00+09:00'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');check('実入金日と確認時刻を区別して保存',$r['payments'][0]['received_at']===$expected&&$r['payments'][0]['confirmed_at']!==$expected);
    $payCount=(int)$db->query('SELECT COUNT(*) FROM portal_payments')->fetchColumn();check('同じ入金を再送しても一件',$post($finance,$id,'confirm',$data)['status']===200&&(int)$db->query('SELECT COUNT(*) FROM portal_payments')->fetchColumn()===$payCount);
    check('同じ処理キーで日付変更は拒否',$post($finance,$id,'confirm',array_replace($data,['received_on'=>(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d')]))['status']===409);
    check('不足入金は年度承認不可',$post($office,$id,'approve',['checked'=>'1'])['status']===409);
    $res=$post($office,$id,'confirm',['amount'=>'27000','reference'=>'C5-SECOND','operation'=>bin2hex(random_bytes(24)),'received_on'=>$day]);$r=json_decode($res['body'],true);
    check('事務局も入金確認でき過入金は未充当で保持',$res['status']===200&&(int)$r['invoice']['balance_yen']===0&&$r['payments'][1]['unallocated_yen']===2000&&!$r['eligibility']['ready']);
    check('財務単独・本人は年度承認不可',$post($finance,$id,'approve',['checked'=>'1'])['status']===403&&$post($clubUser,$id,'approve',['checked'=>'1'])['status']===403);
    $res=$post($office,$id,'approve',['checked'=>'1']);$r=json_decode($res['body'],true);check('満額確認と承認で先行登録済み・個人正本は維持',$res['status']===200&&$r['eligibility']['ready']&&!$r['eligibility']['active']&&$r['intake']['source_mode']==='shadow');
    $first=(int)$r['allocations'][0]['id'];$payment=(int)$r['payments'][0]['id'];$badAllocation=(int)$db->query('SELECT id FROM portal_allocations WHERE invoice_line_id<>'.(int)$r['invoice']['invoice_line_id'].' LIMIT 1')->fetchColumn();
    check('別請求の充当をこの画面から訂正しない',$post($office,$id,'reverse',['allocation'=>$badAllocation,'operation'=>bin2hex(random_bytes(24)),'reason'=>'架空'])['status']===409);
    $res=$post($finance,$id,'reverse',['allocation'=>$first,'operation'=>bin2hex(random_bytes(24)),'reason'=>'銀行照合の訂正']);$r=json_decode($res['body'],true);check('財務の入金訂正で先行登録済みを取り消す',$res['status']===200&&!$r['eligibility']['ready']&&(int)$r['invoice']['balance_yen']===5000);
    $res=$post($office,$id,'reallocate',['payment'=>$payment,'amount'=>'5000','operation'=>bin2hex(random_bytes(24))]);check('同じ請求への再充当で再判定',$res['status']===200&&json_decode($res['body'],true)['eligibility']['ready']);
    $db->exec("UPDATE portal_club_years SET status='returned' WHERE id=".(int)$a['club_year_id']);
    check('差し戻し状態を入金待ちと区別し承認で変更しない',$ops->read(2,$id)['stage']==='inactive'&&$post($office,$id,'approve',['checked'=>'1'])['status']===409);
    $db->exec("UPDATE portal_club_years SET status='approved' WHERE id=".(int)$a['club_year_id']);
    check('状況別一覧は本人・クラブで絞り込む',count($ops->listing(2,2027,'ready'))>=1&&count(array_filter($ops->listing(8,2027),static fn($x)=>(int)$x['intake']['id']===$id))===0);
    foreach(['chromium'=>'99973','webkit'=>'99974'] as $engine=>$code){$mobile=$make($code,'架空C5Mobile-'.$engine);$ops->execute(2,(int)$mobile['id'],'issue',[]);}
    // 他の実ブラウザー試験へ架空の口座の有効設定を持ち越さない。発行済みの振込案内は固定記録から読む。
    $db->exec('UPDATE portal_bank_accounts SET enabled=0 WHERE id='.(int)$bank['id']);foreach($banks as $b){$s=$db->prepare('UPDATE portal_bank_accounts SET enabled=? WHERE id=?');$s->execute([$b['enabled'],$b['id']]);}
    check('口座設定変更後も発行時の振込先を保持',$ops->read(2,$id)['guide']['ready']);
    $s=$db->prepare('UPDATE portal_years SET source_mode=?,club_open_at=?,club_close_at=? WHERE year=2027');$s->execute(array_values($saved));
    check('再利用・会計操作で旧エントリーとプロフィールを更新しない',$db->query('CHECKSUM TABLE users,entrants,clubs,jmrc_entries,jmrc_entry_fees,jmrc_events,jmrc_annual_memberships')->fetchAll(PDO::FETCH_ASSOC)===$legacy);
})();
