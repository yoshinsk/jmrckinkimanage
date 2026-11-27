<?php
// Path: src/Club/ClubReviewClosure.php
// 機能: 年度単位の再申請終了と提出後の訂正可否を統一。年行の排他で差し戻し・接続・終了の競合を防ぐ。
declare(strict_types=1);
namespace Jmrc\Club;
use PDO;
use Jmrc\Auth\HttpFailure;
final class ClubReviewClosure
{
    public function __construct(private readonly PDO $db,private readonly array $config){}
    /** 閲覧は停止中も可能。申請の個人情報を返さず年度の状態・件数だけを表示する。 */
    public function state(int $year):array
    {
        $s=$this->db->prepare('SELECT closed_at,closed_by FROM portal_club_intake_closures WHERE year=?');$s->execute([$year]);$closed=$s->fetch(PDO::FETCH_ASSOC);
        $s=$this->db->prepare("SELECT COUNT(*) FROM portal_club_intakes WHERE year=? AND review_state<>'linked'");$s->execute([$year]);
        $pending=(int)$s->fetchColumn();
        $s=$this->db->prepare('SELECT action,occurred_at FROM portal_club_review_events WHERE year=? ORDER BY id DESC LIMIT 1');$s->execute([$year]);$last=$s->fetch(PDO::FETCH_ASSOC);
        $isClosed=$last?$last['action']==='closed':(bool)$closed;
        return ['closed'=>$isClosed,'closed_at'=>$isClosed?($last['occurred_at']??$closed['closed_at']):null,'pending_count'=>$pending];
    }
    /** 呼出側の取引内で年度行を先にロックする。新規提出の締切後も、照合完了までは訂正できる。 */
    public function requireMutable(int $year):array
    {
        $r=$this->requireYear($year);
        if($this->state($year)['closed']){throw new HttpFailure(409,'この年度の訂正再申請は終了しています。事務局へ確認してください');}
        return $r;
    }
    /** 終了と再開で同じ年度ロック・有効期間・受付保留を検証する。 */
    private function requireYear(int $year):array
    {
        if(!$this->db->inTransaction()){throw new \LogicException('Club review transaction required');}
        if(!ClubIntakePolicy::enabled($this->config)){throw new HttpFailure(503,'クラブ申請の変更は準備中です');}
        $s=$this->db->prepare('SELECT * FROM portal_years WHERE year=? FOR UPDATE');$s->execute([$year]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r||!$this->withinYear($r)){throw new HttpFailure(422,'クラブ申請の確認期間外です');}
        return $r;
    }
    /** 終了・再開の理由は共通整形し、制御文字や空欄を保存しない。 */
    private function reason(string $reason):string
    {
        $reason=trim(\Jmrc\Input\TextInput::normalize($reason));
        if($reason===''||mb_strlen($reason)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason)){throw new HttpFailure(422,'操作の理由・記録を入力してください');}
        return $reason;
    }
    /** 状態の履歴は監査ログの保存期間と分離し、削除・上書きせず追記する。 */
    private function event(int $actor,int $year,string $action,string $reason):void
    {
        $s=$this->db->prepare('INSERT INTO portal_club_review_events(year,action,actor_user_id,reason) VALUES(?,?,?,?)');$s->execute([$year,$action,$actor,$reason]);
        $s=$this->db->prepare("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,?,'year',?,?,'{}',UTC_TIMESTAMP(6))");$s->execute([$actor,$action==='closed'?'CLUB_REVIEW_CLOSED':'CLUB_REVIEW_REOPENED',$year,bin2hex(random_bytes(16))]);
    }
    /** 訂正受付だけを再開する。新規申請の締切・入金・年度資格・利用者権限は変えない。 */
    public function reopen(int $actor,int $year,string $reason):array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent club review transaction required');}
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            (new ClubService($this->db))->requireAccess($actor,0,true);$this->requireYear($year);
            if(!$this->state($year)['closed']){throw new HttpFailure(409,'訂正再申請が終了している年度を選択してください');}
            $this->event($actor,$year,'reopened',$this->reason($reason));$out=$this->state($year);$this->db->commit();return $out;
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
    /** 新規提出と別に、提出済み内容を訂正できる期間・終了状態を画面とサーバーで共用する。 */
    public function canEdit(array $year):bool
    {
        return ClubIntakePolicy::enabled($this->config)&&$this->withinYear($year)&&!$this->state((int)$year['year'])['closed'];
    }
    private function withinYear(array $year):bool
    {
        $now=$this->now();
        return in_array($year['source_mode'],['shadow','portal'],true)&&$now>=$year['club_open_at']&&$now<$year['valid_until'];
    }
    private function now():string{return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    /** 事務局が新規提出の締切後に明示して終了する。未確認・差し戻しが1件でもあれば全体を変更しない。 */
    public function close(int $actor,int $year,string $reason):array
    {
        if($this->db->inTransaction()){throw new \LogicException('Independent club review transaction required');}
        // 年度ロックを待った後、直前の照合・終了を参照する。承認権限の読取時点のスナップショットを使い続けない。
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try{
            (new ClubService($this->db))->requireAccess($actor,0,true);
            $r=$this->requireMutable($year);
            if($this->now()<$r['club_close_at']){throw new HttpFailure(409,'新規提出の締切後に、すべての申請を確認してから終了してください');}
            $reason=$this->reason($reason);
            $s=$this->db->prepare("SELECT id FROM portal_club_intakes WHERE year=? AND review_state<>'linked' FOR UPDATE");$s->execute([$year]);
            if($s->fetchColumn()){throw new HttpFailure(409,'未確認または差し戻し中の申請があります。すべての申請を照合してください');}
            // 旧終了記録は初回だけ保存し、再開後の再終了は履歴へ追加する。
            $s=$this->db->prepare('SELECT year FROM portal_club_intake_closures WHERE year=?');$s->execute([$year]);
            if(!$s->fetchColumn()){$s=$this->db->prepare('INSERT INTO portal_club_intake_closures(year,closed_by,reason) VALUES(?,?,?)');$s->execute([$year,$actor,$reason]);}
            $this->event($actor,$year,'closed',$reason);
            $out=$this->state($year);$this->db->commit();return $out;
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}
