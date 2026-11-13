<?php
// Path: src/Operations/MaterialsReview.php
// 機能: 11月13日受領資料の入力項目・架空例を提供し、会計試作の状態をログインごとに隔離する。
declare(strict_types=1);
namespace Jmrc\Operations;
use Jmrc\Auth\HttpFailure;
final class MaterialsReview
{
    public const CLUB_FIELDS=[
        'club_name'=>'クラブ・団体名','club_short'=>'正式略称','jaf_registration'=>'JAF登録証の確認メモ',
        'club_postcode'=>'クラブ所在地の郵便番号','club_address'=>'クラブ所在地','company'=>'会社・ショップ名','club_phone'=>'クラブ電話番号',
        'rep_name'=>'代表者氏名','rep_kana'=>'代表者ふりがな','rep_postcode'=>'代表者の郵便番号','rep_address'=>'代表者住所','rep_mobile'=>'代表者携帯電話','rep_email'=>'代表者メール',
        'contact_name'=>'連絡担当者氏名','contact_kana'=>'連絡担当者ふりがな','contact_postcode'=>'連絡先の郵便番号','contact_address'=>'連絡先住所','contact_phone'=>'連絡先電話','contact_mobile'=>'連絡先携帯電話','contact_email'=>'連絡先メール',
        'directory_phone'=>'ハンドブックへ掲載する電話の希望','delivery_name'=>'名簿送付先の担当者','delivery_postcode'=>'名簿送付先の郵便番号','delivery_address'=>'名簿送付先住所','delivery_phone'=>'名簿送付先電話','region'=>'所属支部','jaf_kind'=>'JAF登録クラブ・団体の型式','membership_kind'=>'JMRC会員区分'
    ];
    /** ユーザー間でデータを共有しない。架空の会費は確認年のデータとして用意し、実資格や請求を参照しない。 */
    public static function initial(int $owner):array
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo'));$month=$now->modify('first day of last month')->format('Y-m');$year=(int)substr($month,0,4);
        $fees=[['id'=>'DEMO-CLUB-1','status'=>'confirmed','item'=>'club_fee','date'=>$month.'-10','amount'=>30000,'detail'=>'架空クラブの年会費','memo'=>'自動連携の確認例','registered_at'=>$month.'-11 09:00:00'],['id'=>'DEMO-MEMBER-1','status'=>'confirmed','item'=>'member_fee','date'=>$month.'-12','amount'=>2000,'detail'=>'架空会員の会費','memo'=>'自動連携の確認例','registered_at'=>$month.'-13 09:00:00']];
        return ['schema'=>1,'owner'=>$owner,'created'=>time(),'instance'=>bin2hex(random_bytes(16)),'revision'=>1,'year'=>$year,'month'=>$month,'budgets'=>[$year=>AccountingReview::budget(['club_fee'=>60000,'member_fee'=>20000,'meeting'=>10000,'supplies'=>5000])],'ledger'=>AccountingReview::synchronize([],$fees),'fees'=>$fees,'balances'=>[],'closed'=>[],'club'=>[]];
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
        $out=[];foreach(self::CLUB_FIELDS as $key=>$label){$v=$input[$key]??'';if(!is_string($v)||mb_strlen($v)>255||!preg_match('//u',$v)||preg_match('/[\x00-\x1f]/',$v)){throw new HttpFailure(422,$label.'を確認してください');}$out[$key]=trim($v);}return $out;
    }
    /** すべての変更を版と操作インスタンスで保護する。失敗した入力は呼出元の状態へ部分反映しない。 */
    public static function apply(array $state,array $in,?string $upload=null):array
    {
        if(($in['instance']??null)!==$state['instance']||!is_string($in['revision']??null)||$in['revision']!==(string)$state['revision']){throw new HttpFailure(409,'別の操作で更新されました。画面を開き直してください');}
        $action=$in['action']??'';
        if($action==='reset'){return self::initial($state['owner']);}
        if($action==='budget'){
            $year=AccountingReview::year($in['year']??null);if(!is_array($in['amounts']??null)){throw new HttpFailure(422,'予算を入力してください');}
            $state['budgets'][$year]=AccountingReview::budget($in['amounts']);$state['year']=$year;
        }elseif($action==='budget_import'){
            $result=AccountingReview::importBudget($upload??'');$state['year']=$result['year'];$state['budgets'][$result['year']]=$result['amounts'];
        }elseif($action==='manual'){
            if(count($state['ledger'])>=2000){throw new HttpFailure(422,'明細は2000件以内です');}
            $row=AccountingReview::entry($in,gmdate('Y-m-d H:i:s').' UTC','manual:'.$state['revision']);
            if(isset($state['closed'][substr($row['date'],0,7)])){throw new HttpFailure(409,'確認用の月末確定をした月です。初期化後に試してください');}$state['ledger'][]=$row;
        }elseif($action==='sync'){
            foreach($state['fees'] as $fee){if(isset($state['closed'][substr($fee['date'],0,7)])){throw new HttpFailure(409,'確定済み月への連携例は保留します');}}
            $state['ledger']=AccountingReview::synchronize($state['ledger'],$state['fees']);
        }elseif(in_array($action,['balance','close'],true)){
            $month=$in['month']??null;if(!is_string($month)){throw new HttpFailure(422,'対象月を指定してください');}AccountingReview::date($month.'-01');
            if(isset($state['closed'][$month])){throw new HttpFailure(409,'この月は確認用に確定済みです');}
            if($action==='balance'){
                if(!is_array($in['current']??null)||!is_array($in['previous']??null)){throw new HttpFailure(422,'残高を入力してください');}
                $b=AccountingReview::balance($in['current'],$in['previous']);
                $state['balances'][$month]=['current'=>array_map(static fn($r)=>$r['current'],$b['items']),'previous'=>array_map(static fn($r)=>$r['previous'],$b['items'])];
            }else{
                if(!AccountingReview::mayClose($month,new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))){throw new HttpFailure(422,'月末確定は前月以前を指定してください');}
                $input=$state['balances'][$month]??['current'=>[],'previous'=>[]];$b=AccountingReview::balance($input['current'],$input['previous']);
                if(!$b['balanced']){throw new HttpFailure(422,'全科目を入力し、資産と負債・剰余金の合計を確認してください');}
                $state['closed'][$month]=['at'=>gmdate('c'),'balance'=>$b,'ledger_sha256'=>hash('sha256',json_encode($state['ledger']))];
            }$state['month']=$month;
        }elseif($action==='club'){if(!is_array($in['club']??null)){throw new HttpFailure(422,'クラブの入力項目を確認してください');}$state['club']=self::club($in['club']);}
        else{throw new HttpFailure(422,'確認する操作を選んでください');}
        $state['revision']++;return $state;
    }
}
