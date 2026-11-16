<?php
// Path: src/Operations/MaterialsReview.php
// 機能: 11月13日受領資料の入力項目・架空例を提供し、会計試作の状態をログインごとに隔離する。
declare(strict_types=1);
namespace Jmrc\Operations;
use Jmrc\Auth\HttpFailure;
require_once __DIR__.'/../Club/ClubContact.php';
require_once __DIR__.'/AccountingPolicy.php';
require_once __DIR__.'/AccountingWorkflow.php';
final class MaterialsReview
{
    public const CLUB_FIELDS=[
        'club_name'=>'クラブ・団体名称','club_short'=>'正式略称',
        'club_postcode'=>'クラブ所在地の郵便番号','club_address'=>'クラブ所在地','company'=>'会社・ショップ名','club_phone'=>'クラブ電話番号',
        'rep_name'=>'代表者氏名','rep_kana'=>'代表者ふりがな','rep_postcode'=>'代表者の郵便番号','rep_address'=>'代表者住所','rep_phone'=>'代表者電話','rep_mobile'=>'代表者携帯電話','rep_email'=>'代表者メール',
        'contact_name'=>'連絡担当者氏名','contact_kana'=>'連絡担当者ふりがな','contact_postcode'=>'連絡先の郵便番号','contact_address'=>'連絡先住所','contact_phone'=>'連絡先電話','contact_mobile'=>'連絡先携帯電話','contact_email'=>'連絡先メール',
        'region'=>'所属支部','jaf_kind'=>'JAF登録クラブ・団体の型式','membership_kind'=>'JMRC会員区分'
    ];
    /** ユーザー間でデータを共有しない。架空の会費は確認年のデータとして用意し、実資格や請求を参照しない。 */
    public static function initial(int $owner):array
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo'));$month=$now->modify('first day of last month')->format('Y-m');$year=(int)substr($month,0,4);
        $fees=[['id'=>'DEMO-MEMBER-1','status'=>'confirmed','item'=>'member_fee','date'=>$month.'-12','amount'=>2000,'detail'=>'架空会員の会費','memo'=>'自動連携の確認例','registered_at'=>$month.'-13 09:00:00']];
        $fees=array_map(static fn($r)=>AccountingPolicy::receipt(['persons'=>1,'receipt_id'=>$r['id'],'item'=>$r['item'],'amount'=>$r['amount'],'paid_on'=>$r['date'],'confirmed_at'=>$r['registered_at']]),$fees);
        $club=AccountingReview::entry(['item'=>'club_fee','date'=>$month.'-10','amount'=>30000,'detail'=>'架空クラブの年会費','memo'=>'手入力の確認例'],$month.'-11 09:00:00 JST','manual:DEMO-CLUB-1');
        return ['schema'=>2,'owner'=>$owner,'created'=>time(),'instance'=>bin2hex(random_bytes(16)),'revision'=>1,'year'=>$year,'month'=>$month,'budgets'=>[$year=>AccountingReview::budget(['club_fee'=>60000,'member_fee'=>20000,'meeting'=>10000,'supplies'=>5000])],'ledger'=>AccountingReview::synchronize([$club],$fees),'fees'=>$fees,'balances'=>[],'closed'=>[],'club'=>self::sampleClub(),'departments'=>[],'budget_status'=>[],'month_approved'=>[],'refunds'=>[],'terms'=>[]];
    }
    /** 旧画面で入力した予算と手入力明細を失わず、クラブ会費の架空自動行を手入力例へ移す。 */
    public static function upgrade(array $state):array
    {
        if(($state['schema']??null)!==1){return $state;}
        $state['fees']=array_values(array_filter($state['fees'],static fn($r)=>$r['item']==='member_fee'));
        foreach($state['ledger'] as &$r){if($r['source']==='portal_fee_example'&&$r['item']!=='member_fee'){$r['source']='manual';}}unset($r);
        $state['club']=array_intersect_key($state['club'],self::CLUB_FIELDS+['same_contact'=>true,'region_other'=>true]);
        $state+=['departments'=>[],'budget_status'=>[],'month_approved'=>[],'refunds'=>[],'terms'=>[]];$state['schema']=2;return $state;
    }
    /** 実在情報を含まない入力例。会社名は任意、連絡先の省略は兼任選択時だけ。 */
    public static function sampleClub():array
    {
        $out=[];foreach(self::CLUB_FIELDS as $key=>$label){$out[$key]='確認用 '.$label;}
        return array_replace($out,['region'=>'27','company'=>'','same_contact'=>'1','region_other'=>'']);
    }
    /** 未確定の通知文はひな型と識別し、送信先・加入番号の実データは含めない。 */
    public static function notice(string $kind):string
    {
        if($kind==='insurance_completed'){
            return "【文面確認用・送信不可】スポーツ安全保険 加入手続き完了のお知らせ\n{クラブ名} 御中\n\n以下は差し込み項目の候補です。正式文面は提供待ちです。\n申込日：{申込日}\n加入人数・区分：{人数・区分}\n払込日・金額：{払込日・金額}\n加入手続日：{手続日}\n保険有効日：{有効日}\n加入依頼番号：{加入依頼番号}\n\n入金確認のみで手続き完了を判定しません。";
        }
        if($kind==='club_renewal_2028'){
            return "【文面確認用・送信不可】2028年度以降のクラブ更新のご案内\n{クラブ名} 御中\n\n{対象年度}のクラブ更新についてご案内します。\n受付期間：{受付開始日}～{受付締切日}\n会費：{年度・会員区分の確定金額}\n手続き先：{正式な案内URL}\n\n2027年度分の案内は運営委員会が発出します。送信日・宛先・承認方法は別途確認します。";
        }
        throw new HttpFailure(422,'通知の種類を選択してください');
    }
    /** 原本の値をコピーせず、列・桁・前置きだけ同じ架空CSVを生成する。 */
    public static function sampleCsv(string $kind):string
    {
        if($kind==='yucho'){
            return "お客さま口座情報\n架空口座\n\n明細情報\n確認専用\n\n\n取引日,入出金明細ＩＤ,受入金額（円）,払出金額（円）,詳細１,詳細２,現在（貸付）高,\n20261010,900000000000001,2000,,送金,カクウ タロウ123,2000,\n20261011,900000000000002,,100,料金,,1900,\n";
        }
        if($kind==='payment_core'){
            return AccountingReview::csv([\Jmrc\Bank\PaymentCoreCsv::HEADERS,['架空 太郎','000-0000-0000','sample@example.invalid','カード',2000,100,'10/10','カクウ タロウ123']]);
        }
        throw new HttpFailure(422,'CSVの種類を選択してください');
    }
    /** 入力例を同じセッションにだけ保持し、実在クラブの登録や名簿公開に接続しない。 */
    public static function club(array $input):array
    {
        $out=[];foreach(self::CLUB_FIELDS as $key=>$label){$v=$input[$key]??'';if(!is_string($v)||mb_strlen($v)>255||!preg_match('//u',$v)||preg_match('/[\x00-\x1f]/',$v)){throw new HttpFailure(422,$label.'を確認してください');}$out[$key]=trim($v);}
        $out=array_replace($out,\Jmrc\Club\ClubContact::normalize($input));
        foreach(self::CLUB_FIELDS as $key=>$label){if($key!=='company'&&$out[$key]===''){throw new HttpFailure(422,$label.'を入力してください');}}
        return $out;
    }
    /** すべての変更を版と操作インスタンスで保護する。失敗した入力は呼出元の状態へ部分反映しない。 */
    public static function apply(array $state,array $in,?string $upload=null):array
    {
        if(($in['instance']??null)!==$state['instance']||!is_string($in['revision']??null)||$in['revision']!==(string)$state['revision']){throw new HttpFailure(409,'別の操作で更新されました。画面を開き直してください');}
        $action=$in['action']??'';
        if($action==='reset'){return self::initial($state['owner']);}
        if($action==='budget'){
            $year=AccountingReview::year($in['year']??null);if(!is_array($in['amounts']??null)){throw new HttpFailure(422,'予算を入力してください');}
            AccountingWorkflow::editable($state,$year);
            if($state['departments'][$year]??[]){$total=AccountingWorkflow::departmentTotal($state['departments'][$year]);if(AccountingReview::money($in['amounts']['contribution']??null)!==$total){throw new HttpFailure(422,'分担金は部会別の合計を使用してください');}}
            $state['budgets'][$year]=AccountingReview::budget($in['amounts']);$state['year']=$year;
        }elseif($action==='budget_import'){
            $result=AccountingReview::importBudget($upload??'');AccountingWorkflow::editable($state,$result['year']);$state['year']=$result['year'];$state['budgets'][$result['year']]=$result['amounts'];$state['departments'][$result['year']]=$result['departments'];
        }elseif($action==='department'){
            $year=AccountingReview::year($in['year']??null);AccountingWorkflow::editable($state,$year);$d=AccountingWorkflow::department($in['department_name']??null,$in['department_amount']??null);
            $state['departments'][$year][$d['name']]=$d['amount'];$total=AccountingWorkflow::departmentTotal($state['departments'][$year]);$state['budgets'][$year]??=AccountingReview::budget([]);$state['budgets'][$year]['contribution']=$total;$state['year']=$year;
        }elseif(in_array($action,['budget_submit','budget_approve','budget_return'],true)){
            $year=AccountingReview::year($in['year']??null);$status=$state['budget_status'][$year]??'draft';AccountingWorkflow::requireRole($in,$action==='budget_submit'?'office':'chief');
            if(($action==='budget_submit'&&$status!=='draft')||($action!=='budget_submit'&&$status!=='submitted')){throw new HttpFailure(409,'予算の状態を確認してください');}
            $state['budget_status'][$year]=match($action){'budget_submit'=>'submitted','budget_approve'=>'approved',default=>'draft'};$state['year']=$year;
        }elseif($action==='manual'){
            if(count($state['ledger'])>=2000){throw new HttpFailure(422,'明細は2000件以内です');}
            $row=AccountingReview::entry($in,gmdate('Y-m-d H:i:s').' UTC','manual:'.$state['revision']);
            if(isset($state['closed'][substr($row['date'],0,7)])){throw new HttpFailure(409,'確認用に確定した月です。事務局長の解除例を先に試してください');}
            if($row['item']==='contribution'){$d=AccountingWorkflow::department($in['department_name']??null,0);$row['department']=$d['name'];}
            $state['ledger'][]=$row;unset($state['month_approved'][substr($row['date'],0,7)]);
        }elseif($action==='sync'){
            foreach($state['fees'] as $fee){if(isset($state['closed'][substr($fee['date'],0,7)])){throw new HttpFailure(409,'確定済み月への連携例は保留します');}}
            $state['ledger']=AccountingReview::synchronize($state['ledger'],$state['fees']);
            $state['month_approved']=[];
        }elseif($action==='refund'){
            $r=AccountingWorkflow::refund($state['ledger'],$state['refunds'],$in,'refund:'.$state['revision']);
            if(isset($state['closed'][substr($r['date'],0,7)])){throw new HttpFailure(409,'確定済み月の返金例は先に解除してください');}
            $state['refunds'][]=$r;unset($state['month_approved'][substr($r['date'],0,7)]);
        }elseif($action==='term'){
            if(count($state['terms'])>=100){throw new HttpFailure(422,'任期の確認例は100件以内です');}$state['terms'][]=AccountingWorkflow::term($in);
        }elseif(in_array($action,['month_approve','reopen'],true)){
            AccountingWorkflow::requireRole($in,'chief');$month=$in['month']??null;if(!is_string($month)){throw new HttpFailure(422,'対象月を指定してください');}AccountingReview::date($month.'-01');
            if($action==='reopen'){if(!isset($state['closed'][$month])){throw new HttpFailure(409,'確定済みの月を指定してください');}unset($state['closed'][$month],$state['month_approved'][$month]);}
            else{
                $b=$state['balances'][$month]??['current'=>[],'previous'=>[]];
                if(isset($state['closed'][$month])||!AccountingReview::balance($b['current'],$b['previous'])['balanced']){throw new HttpFailure(409,'未確定の月と貸借一致を確認してください');}
                foreach($state['refunds'] as $r){if(substr($r['date'],0,7)===$month){throw new HttpFailure(409,'返金の集計方法が未確定のため承認を保留します');}}
                $state['month_approved'][$month]=true;
            }$state['month']=$month;
        }elseif(in_array($action,['balance','close'],true)){
            $month=$in['month']??null;if(!is_string($month)){throw new HttpFailure(422,'対象月を指定してください');}AccountingReview::date($month.'-01');
            if(isset($state['closed'][$month])){throw new HttpFailure(409,'この月は確認用に確定済みです');}
            if($action==='balance'){
                if(!is_array($in['current']??null)||!is_array($in['previous']??null)){throw new HttpFailure(422,'残高を入力してください');}
                $b=AccountingReview::balance($in['current'],$in['previous']);
                $state['balances'][$month]=['current'=>array_map(static fn($r)=>$r['current'],$b['items']),'previous'=>array_map(static fn($r)=>$r['previous'],$b['items'])];
                unset($state['month_approved'][$month]);
            }else{
                if(!AccountingReview::mayClose($month,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))){throw new HttpFailure(422,'月末確定は前月以前を指定してください');}
                $input=$state['balances'][$month]??['current'=>[],'previous'=>[]];$b=AccountingReview::balance($input['current'],$input['previous']);
                if(!$b['balanced']){throw new HttpFailure(422,'全科目を入力し、資産と負債・剰余金の合計を確認してください');}
                if(empty($state['month_approved'][$month])){throw new HttpFailure(409,'事務局長の確認・承認待ちです');}
                $state['closed'][$month]=['at'=>gmdate('c'),'balance'=>$b,'ledger_sha256'=>hash('sha256',json_encode($state['ledger']))];
            }$state['month']=$month;
        }elseif($action==='club'){if(!is_array($in['club']??null)){throw new HttpFailure(422,'クラブの入力項目を確認してください');}$state['club']=self::club($in['club']);}
        else{throw new HttpFailure(422,'確認する操作を選んでください');}
        $state['revision']++;return $state;
    }
}
