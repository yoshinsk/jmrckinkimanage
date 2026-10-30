<?php
// Path: public/manage/_review-workspace.php
// 機能: DBや外部送信に接続しない操作確認モデル。架空データの遷移・表示権限・重複・有効条件を扱う。
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/_review-catalog.php';

final class JmrcReviewWorkspace
{
    public const ROLES = ['office'=>'事務局','club'=>'クラブ代表','member'=>'個人会員','finance'=>'財務委員','public'=>'公開閲覧者'];

    /** 実在データを複写せず、独立した例から開始。2時間経過・別ユーザー・版変更でリセットする。 */
    public static function initial(int $owner, int $revision = 0): array
    {
        return ['schema'=>1,'instance'=>bin2hex(random_bytes(16)),'owner'=>$owner,'created'=>time(),'revision'=>$revision,'role'=>'office','date'=>'2027-04-10',
            'license'=>null,'profile'=>['name'=>'確認 太郎','birthday'=>'1990-04-01','address'=>'確認用の住所','phone'=>'000-0000-0000'],
            'club'=>['submitted'=>false,'approved'=>false,'fee'=>0,'name'=>'操作確認クラブ','short'=>'確認CLUB','code'=>'100001'],
            'member'=>['submitted'=>false,'approved'=>false,'suspended'=>false,'card_invalid'=>false],
            'temporary'=>[],'invoices'=>[],'payments'=>[],'bank'=>[],'entries'=>[],'notes'=>[],'history'=>[],'preregistration'=>'未照合',
            'insurance'=>['intent'=>false,'count'=>0]];
    }

    /** モデルには接続先やDBハンドルを持たせない。本番業務の代替ではなく説明用の状態遷移に限定する。 */
    public function __construct(public array $state) {}

    /** 立場の切替は確認画面内だけ。実ユーザーの認証・権限・対象クラブには一切書き戻さない。 */
    public function visible(string $screen): bool
    {
        $c = jmrc_review_catalog();
        return $screen === 'overview' || (isset($c[$screen]) && in_array($this->state['role'], $c[$screen]['roles'], true));
    }

    /** 決定済みの複合条件を表示例に反映。番号の採番元変更は、再発行方針が決まるまで無効を維持する。 */
    public function eligibility(): array
    {
        $s=$this->state;
        $checks=['申請済み'=>$s['member']['submitted'],'所属承認済み'=>$s['member']['approved'],
            '個人会費が満額入金'=>$this->paid('general')>=2000 && isset($s['invoices']['general']),
            '年度クラブが有効'=>$s['club']['approved'] && isset($s['invoices']['club']) && $this->paid('club') >= $s['invoices']['club'],
            'ライセンスが有効'=>$s['license']!==null && $s['license']['expiry'] >= $s['date'],
            '年度内'=>$s['date']>='2027-01-01' && $s['date']<='2027-12-31',
            '資格停止なし'=>!$s['member']['suspended'],'番号の再発行待ちでない'=>!$s['member']['card_invalid'],
            'プロフィールあり'=>$s['profile']!==null];
        return ['active'=>!in_array(false,$checks,true),'checks'=>$checks];
    }

    /** 取消行を残し、未取消分だけを合算。銀行照合と手動入金でも同じ台帳を使う。 */
    public function paid(string $target): int
    {
        return array_sum(array_column(array_filter($this->state['payments'],static fn($p)=>$p['target']===$target && !$p['reversed']),'amount'));
    }

    /** 状態変更を許す立場を定義。画面のボタン非表示とサーバーのPOST検査で同じ判定を使う。 */
    public function allowed(string $screen, string $action): bool
    {
        $role=$this->state['role'];
        if (!$this->visible($screen)) { return false; }
        if ($action==='note' || $action==='role' || $action==='reset') { return true; }
        if (!isset(jmrc_review_catalog()[$screen]['actions'][$action])) { return false; }
        if ($screen==='temporary' && $action==='approve') { return $role==='club'; }
        if ($screen==='temporary' && $action==='save') { return in_array($role,['office','member'],true); }
        if ($screen==='billing' && $action!=='issue') { return in_array($role,['office','finance'],true); }
        if ($screen==='clubs' && $action==='approve') { return $role==='office'; }
        if ($screen==='inquiries' && $action==='reply') { return $role==='office'; }
        if (in_array($screen,['events','notices','documents','handbook','links'],true)) { return $role==='office'; }
        return $role!=='public';
    }

    /** 許可済み入力だけを検査。未知の配列・UTF-8不正・過大な入力・危険なURLは保存しない。 */
    private function fields(string $screen, array $input): array
    {
        $out=[];
        foreach(jmrc_review_catalog()[$screen]['fields'] as $key=>[$label,$type,$default]) {
            $value=$input[$key]??$default;
            if(!is_string($value) || strlen($value)>4000 || preg_match('//u',$value)!==1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value)) { $this->fail($label.'を確認してください'); }
            $value=trim($value);
            if($value==='' && $key!=='fee') { $this->fail($label.'を入力してください'); }
            if(is_array($type) && !array_key_exists($value,$type)) { $this->fail($label.'を選択してください'); }
            if($type==='number' && $value!=='' && (!ctype_digit($value) || (int)$value>10000000)) { $this->fail($label.'は0〜10000000の整数で入力してください'); }
            if($type==='date') { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d || $d->format('Y-m-d')!==$value) { $this->fail($label.'を確認してください'); } }
            if($type==='url' && (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https' || parse_url($value,PHP_URL_USER)!==null || parse_url($value,PHP_URL_PASS)!==null)) { $this->fail('URLは認証情報を含まないhttpsで入力してください'); }
            $out[$key]=$value;
        }
        return $out;
    }

    /** 古いフォームの再送を拒否し、コピー上で処理して、失敗時に途中の状態を残さない。 */
    public function apply(string $screen, array $input): string
    {
        $action=$input['action']??'';
        if(!is_string($action) || !$this->allowed($screen,$action)) { throw new \Jmrc\Auth\HttpFailure(403,'この立場では操作できません'); }
        if(!is_string($input['instance']??null) || !hash_equals($this->state['instance'],$input['instance']) || ($input['revision']??null)!==(string)$this->state['revision']) { throw new \Jmrc\Auth\HttpFailure(409,'別の操作で画面が更新されました。再表示して確認してください。'); }
        $copy=new self($this->state);
        $message=$copy->mutate($screen,$action,$input);
        $copy->state['revision']=$this->state['revision']+1;
        $copy->state['history'][]=['screen'=>$screen,'action'=>$action,'role'=>$this->state['role'],'result'=>$message];
        $copy->state['history']=array_slice($copy->state['history'],-50);
        $this->state=$copy->state;
        return $message;
    }

    /** 各操作は架空データだけを更新。制度上未確定の承認・給付・送信はここにも実装しない。 */
    private function mutate(string $screen,string $action,array $input): string
    {
        if($action==='reset') { $this->state=self::initial($this->state['owner']);return '確認用データを初期状態に戻しました'; }
        if($action==='role') {
            if(!is_string($input['role']??null) || !isset(self::ROLES[$input['role']])) { $this->fail('立場を選択してください'); }
            $this->state['role']=$input['role'];return '確認する立場を切り替えました';
        }
        if($action==='note') {
            $note=$input['note']??'';$decision=$input['decision']??'';
            if(!is_string($note) || strlen($note)>4000 || preg_match('//u',$note)!==1 || !in_array($decision,['pending','ok','change'],true)) { $this->fail('確認メモを確認してください'); }
            $this->state['notes'][$screen]=['decision'=>$decision,'note'=>$note];return '確認メモを保存しました。共有する場合は確認票を書き出してください';
        }
        $f=$this->fields($screen,$input);$s=&$this->state;
        switch($screen) {
            case 'licenses':
                if(!preg_match('/^[A-Za-z0-9-]{1,64}$/D',$f['number'])) { $this->fail('ライセンス番号を確認してください'); }
                if($s['license']!==null && ($s['license']['number']!==$f['number'] || $s['license']['kind']!==$f['kind']) && $s['member']['submitted']) { $s['member']['card_invalid']=true; }
                $s['license']=$f;break;
            case 'clubs':
                if($action==='save') {
                    if(!preg_match('/^[0-9]{5}$/D',$f['base'])) { $this->fail('不変コードは5桁で入力してください'); }
                    if($s['club']['submitted'] && $s['club']['code']!==$f['category'].$f['base']) { $this->fail('登録後の年度コードは変更できません'); }
                    if(isset($s['invoices']['club']) && (int)$f['fee']!==$s['invoices']['club']) { $this->fail('発行済み請求の金額は変更できません'); }
                    $s['club']=array_merge($s['club'],$f,['submitted'=>true,'name'=>$f['name'],'short'=>$f['short'],'code'=>$f['category'].$f['base'],'fee'=>(int)$f['fee']]);
                } elseif($action==='issue') { $this->issue('club'); }
                else { if(!$s['club']['submitted'] || !isset($s['invoices']['club']) || $this->paid('club')<$s['invoices']['club']) { $this->fail('年度申請・年会費請求・満額入金が必要です'); }$s['club']['approved']=true; }
                break;
            case 'memberships':
                if(!$s['license'] || $s['license']['expiry']<$s['date']) { $this->fail('有効なJAFライセンスを先に登録してください'); }
                if(!$s['club']['approved'] || !isset($s['invoices']['club']) || $this->paid('club')<$s['invoices']['club']) { $this->fail('有効な年度クラブ登録が必要です。クラブ登録から確認してください'); }
                if($s['member']['submitted']) { return '同じ年度の申請を表示しました。申請・請求は増やしていません'; }
                $s['profile']=$f;$s['member']['submitted']=true;$this->issue('general');break;
            case 'affiliations':
                if(!$s['member']['submitted']) { $this->fail('先にGeneralを申請してください'); }
                $s['member']['approved']=$action==='approve';
                if($action==='change') { $s['member']['card_invalid']=true; }
                break;
            case 'preregistration':
                $match=$f['match']==='unique' && $s['license']!==null && $s['license']['number']===$f['number'] && $s['member']['submitted'];
                $s['preregistration']=$match?'一意一致・所属承認の例':($f['match']==='conflict'?'複数候補・手動確認':'一致なし・手動確認');
                if($match) { $s['member']['approved']=true; }break;
            case 'temporary':
                $event=$f['event'];$date=$event==='C'?'2027-04-11':'2027-04-10';
                if($action==='save') {
                    if(isset($s['temporary'][$event])) { return '同じイベントの申請を表示しました。請求は増やしていません'; }
                    foreach($s['temporary'] as $v) { if($v['date']===$date) { $this->fail('同じ開催日に複数イベントへTemporary申請はできません'); } }
                    if($date<$s['date'] || !$s['club']['approved'] || !isset($s['invoices']['club']) || $this->paid('club')<$s['invoices']['club']) { $this->fail('開催日と主催クラブの年度資格を確認してください'); }
                    $s['temporary'][$event]=['date'=>$date,'approved'=>false];$this->issue('temporary:'.$event);
                } else { if(!isset($s['temporary'][$event])) { $this->fail('先に対象イベントへ申請してください'); }$s['temporary'][$event]['approved']=true; }
                break;
            case 'billing':
                $target=$f['target'];
                if($target==='temporary') { $event=array_key_last($s['temporary']);if($event===null) { $this->fail('先にTemporaryを申請してください'); }$target.=':'.$event; }
                if($s['role']==='member' && $target==='club') { throw new \Jmrc\Auth\HttpFailure(403,'クラブの請求操作は担当者が行います'); }
                if($action==='issue') { $this->issue($target); }
                elseif($action==='confirm') { $this->payment($target,(int)$f['amount'],$f['reference']); }
                else {
                    $found=false;
                    for($i=count($s['payments'])-1;$i>=0;$i--) { if($s['payments'][$i]['target']===$target && !$s['payments'][$i]['reversed']) { $s['payments'][$i]['reversed']=true;$found=true;break; } }
                    if(!$found) { $this->fail('取消できる入金がありません'); }
                }
                break;
            case 'bank':
                if($action==='import') { $s['bank']=['DEMO-BANK-1'=>['amount'=>2000,'status'=>$s['bank']['DEMO-BANK-1']['status']??'一意候補・確認待ち'],'DEMO-BANK-2'=>['amount'=>500,'status'=>'複数候補・手動確認']]; }
                else { if(!$s['bank']) { $this->fail('先に架空CSVを取り込んでください'); }$this->payment('general',2000,'DEMO-BANK-1');$s['bank']['DEMO-BANK-1']['status']='照合済み'; }
                break;
            case 'insurance':
                if($f['type']!=='general') { $this->fail('スポーツ安全保険はGeneralのみです'); }
                if((int)$f['count']>24) { $this->fail('確認用Excelは1〜24名です'); }
                $s['insurance']=['intent'=>true,'count'=>(int)$f['count']];break;
            case 'privacy':
                if($action==='erase') { $s['profile']=null;$s['license']=null;$s['member']['card_invalid']=true;return '架空プロフィールを削除しました。確認用の請求・入金・操作履歴は残しています'; }
                $s['profile']=array_merge($s['profile']??[],$f);break;
            case 'documents':
                if($action==='remove') { if(!empty($s['entries'][$screen])) { array_pop($s['entries'][$screen]); }break; }
                if($f['category']==='committee' && $f['visibility']!=='private') { $this->fail('運営委員会資料は事務局のみを選択してください'); }
                if(count($s['entries'][$screen]??[])>=20) { $this->fail('確認用資料は20件までです'); }
                $s['entries'][$screen][]=$f;break;
            case 'operations':
                $this->scenario($f['status'],$f['date']);break;
            default:
                // 未確定業務は下書きと一覧表示まで。公開設定もこのセッション内の表示フィルターだけに使う。
                if($action==='remove') { if(!empty($s['entries'][$screen])) { array_pop($s['entries'][$screen]); } }
                else { if(count($s['entries'][$screen]??[])>=20) { $this->fail('確認用の保存件数は20件までです。初期化してください'); }$s['entries'][$screen][]=array_merge($f,['action'=>$action]); }
        }
        return '確認用データを更新しました。本番の受付・記録には反映していません';
    }

    /** 同一資格には同じ請求だけを置き、未確定のクラブ金額を自動補完しない。 */
    private function issue(string $target): void
    {
        $s=&$this->state;
        if(isset($s['invoices'][$target])) { return; }
        if($target==='club') { if(!$s['club']['submitted'] || $s['club']['fee']<1) { $this->fail('説明用のクラブ年会費を入力して年度登録してください'); }$amount=$s['club']['fee']; }
        elseif($target==='general') { if(!$s['member']['submitted']) { $this->fail('先にGeneralを申請してください'); }$amount=2000; }
        else { if(!isset($s['temporary'][substr($target,10)])) { $this->fail('対象イベントの申請がありません'); }$amount=500; }
        $s['invoices'][$target]=$amount;
    }

    /** 識別番号の再利用・過入金は説明画面でも拒否。同じ内容の再照合は一度だけ計上する。 */
    private function payment(string $target,int $amount,string $reference): void
    {
        if(!isset($this->state['invoices'][$target])) { $this->fail('対象請求がありません'); }
        foreach($this->state['payments'] as $p) { if($p['reference']===$reference) {
            if($p['target']===$target && $p['amount']===$amount && !$p['reversed']) { return; }
            $this->fail('この入金識別番号は使用済みです');
        } }
        if($amount<1 || $amount>$this->state['invoices'][$target]-$this->paid($target)) { $this->fail('入金額は未入金額の範囲で入力してください'); }
        $this->state['payments'][]=['target'=>$target,'amount'=>$amount,'reference'=>$reference,'reversed'=>false];
    }

    /** 長い入力をせず運営者が状態比較できる固定例。クラブ1万円はこの例だけの仮額で明示する。 */
    private function scenario(string $status,string $date): void
    {
        $owner=$this->state['owner'];$role=$this->state['role'];$notes=$this->state['notes'];$history=$this->state['history'];
        $this->state=self::initial($owner);$s=&$this->state;$s['role']=$role;$s['notes']=$notes;$s['history']=$history;$s['date']=$date;
        $s['license']=['number'=>'DEMO123','kind'=>'competition','expiry'=>'2027-12-31'];
        $s['club']['submitted']=true;$s['club']['approved']=true;$s['club']['fee']=10000;
        $s['member']['submitted']=true;$s['member']['approved']=$status!=='pending';$s['member']['suspended']=$status==='suspended';
        $s['invoices']=['general'=>2000,'club'=>10000];$this->payment('club',10000,'SCENARIO-CLUB');
        if(!in_array($status,['pending','unpaid'],true)) { $this->payment('general',2000,'SCENARIO-GENERAL'); }
        if($status==='expired') { $s['date']='2028-01-01'; }
    }

    /** 説明用の公開範囲を一覧にも適用。公開閲覧者には非公開資料のタイトルも渡さない。 */
    public function entries(string $screen): array
    {
        $role=$this->state['role'];
        return array_values(array_filter($this->state['entries'][$screen]??[],static fn($r)=>$role==='office' || ($r['visibility']??'members')==='public' || ($role!=='public' && ($r['visibility']??'members')==='members')));
    }

    private function fail(string $message): never { throw new \Jmrc\Auth\HttpFailure(422,$message); }
}
