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
        return ['closed'=>(bool)$closed,'closed_at'=>$closed['closed_at']??null,'pending_count'=>(int)$s->fetchColumn()];
    }
    /** 呼出側の取引内で年度行を先にロックする。新規提出の締切後も、照合完了までは訂正できる。 */
    public function requireMutable(int $year):array
    {
        if(!$this->db->inTransaction()){throw new \LogicException('Club review transaction required');}
        if(!ClubIntakePolicy::enabled($this->config)){throw new HttpFailure(503,'クラブ申請の変更は準備中です');}
        $s=$this->db->prepare('SELECT * FROM portal_years WHERE year=? FOR UPDATE');$s->execute([$year]);$r=$s->fetch(PDO::FETCH_ASSOC);
        if(!$r||!$this->withinYear($r)){throw new HttpFailure(422,'クラブ申請の確認期間外です');}
        if($this->state($year)['closed']){throw new HttpFailure(409,'この年度の訂正再申請は終了しています。事務局へ確認してください');}
        return $r;
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
        $this->db->beginTransaction();
        try{
            (new ClubService($this->db))->requireAccess($actor,0,true);
            $r=$this->requireMutable($year);
            if($this->now()<$r['club_close_at']){throw new HttpFailure(409,'新規提出の締切後に、すべての申請を確認してから終了してください');}
            $reason=trim(\Jmrc\Input\TextInput::normalize($reason));
            if($reason===''||mb_strlen($reason)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason)){throw new HttpFailure(422,'終了を確認した理由・記録を入力してください');}
            $s=$this->db->prepare("SELECT id FROM portal_club_intakes WHERE year=? AND review_state<>'linked' FOR UPDATE");$s->execute([$year]);
            if($s->fetchColumn()){throw new HttpFailure(409,'未確認または差し戻し中の申請があります。すべての申請を照合してください');}
            $s=$this->db->prepare('INSERT INTO portal_club_intake_closures(year,closed_by,reason) VALUES(?,?,?)');$s->execute([$year,$actor,$reason]);
            $s=$this->db->prepare("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,'CLUB_REVIEW_CLOSED','year',?,?,'{}',UTC_TIMESTAMP(6))");$s->execute([$actor,$year,bin2hex(random_bytes(16))]);
            $out=$this->state($year);$this->db->commit();return $out;
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}
