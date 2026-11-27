<?php
// Path: scripts/ClubReviewEventsDatabase.php
// 機能: 訂正受付の追記履歴表と2027年度締切だけを限定適用・復元する。旧業務・個人の権限は保護する。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
final class ClubReviewEventsDatabase
{
    private const TABLE='portal_club_review_events';
    public function __construct(private readonly PDO $db,private readonly string $database,private readonly string $runtime,private readonly string $host)
    {
        $this->need(PHP_SAPI==='cli'&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'CLI database mismatch');
        $this->need(($database==='jmrckinki'&&$runtime==='jmrc_portal'&&$host==='127.0.0.1')||($database==='jmrckinki_review_events_lab'&&$runtime==='review_events_runtime'&&$host==='%'),'Unexpected principal');
    }
    private function need(bool $ok,string $why):void{if(!$ok){throw new \RuntimeException($why);}}
    private function rows(string $sql):array{return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
    private function principal():string{return $this->db->quote($this->runtime).'@'.$this->db->quote($this->host);}
    /** 対象年度の原本と、対象外の定義・チェックサムを取り、個人情報本文は出力しない。 */
    public function snapshot():array
    {
        $names=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $exists=in_array(self::TABLE,$names,true);$protected=array_values(array_diff($names,[self::TABLE,'portal_years','audit_logs','portal_audit_events']));$schemas=[];
        foreach(array_diff($names,[self::TABLE]) as $t){$this->need((bool)preg_match('/^[a-z0-9_]+$/D',$t),'Unexpected table');$schemas[$t]=preg_replace('/ AUTO_INCREMENT=\d+/','',$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1]);}
        $s=$this->db->prepare('SELECT privilege_type FROM information_schema.table_privileges WHERE table_schema=DATABASE() AND table_name=? AND grantee=? ORDER BY privilege_type');$s->execute([self::TABLE,$this->principal()]);
        return ['exists'=>$exists,'event_count'=>$exists?(int)$this->db->query('SELECT COUNT(*) FROM '.self::TABLE)->fetchColumn():0,'permissions'=>$s->fetchAll(PDO::FETCH_COLUMN),
            'years'=>$this->rows('SELECT * FROM portal_years ORDER BY year'),'protected_schemas'=>$schemas,
            'protected_checksums'=>$protected?$this->rows('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$protected))):[]];
    }
    /** 既存の年度行を唯一の原本とし、締切以外のセルや既存業務が変わったら拒否する。 */
    private function expectedYears(array $before):array
    {
        $rows=$before['years'];$found=0;
        foreach($rows as &$r){if((int)$r['year']===2027){$this->need($r['source_mode']==='shadow'&&str_starts_with($r['club_close_at'],'2027-02-07 15:00:00'),'Unexpected 2027 calendar');$r['club_close_at']='2027-03-31 15:00:00.000000';$found++;}}
        $this->need($found===1,'Missing year');return $rows;
    }
    public function apply(array $before,string $ddl):array
    {
        $this->need($before===$this->snapshot()&&!$before['exists']&&$before['permissions']===[],'Database baseline changed');$expected=$this->expectedYears($before);
        try{
            $this->db->exec($ddl);$this->db->exec('GRANT INSERT ON `'.$this->database.'`.'.self::TABLE.' TO '.$this->principal());
            $this->db->beginTransaction();$this->db->query('SELECT year FROM portal_years WHERE year=2027 FOR UPDATE')->fetchColumn();
            $this->db->exec("UPDATE portal_years SET club_close_at='2027-03-31 15:00:00.000000',updated_at=updated_at WHERE year=2027");
            $after=$this->snapshot();$this->need($after['exists']&&$after['event_count']===0&&$after['permissions']===['INSERT']&&$after['years']===$expected,'Migration incomplete');
            $this->need($before['protected_schemas']===$after['protected_schemas']&&$before['protected_checksums']===$after['protected_checksums'],'Protected state changed');
            $this->db->commit();return ['status'=>'PASS','before'=>$before,'after'=>$after,'ddl_sha256'=>hash('sha256',$ddl)];
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}$this->restoreEmpty($before);throw $e;}
    }
    /** 後続記録が1件でもあれば復元しない。利用者が作った理由や申請を消さない。 */
    public function rollback(array $record):void
    {
        $this->need($record['after']===$this->snapshot()&&$record['after']['event_count']===0,'Subsequent records must be preserved');
        $this->restoreEmpty($record['before']);$this->need($this->snapshot()===$record['before'],'Rollback mismatch');
    }
    private function restoreEmpty(array $before):void
    {
        $current=$this->snapshot();$this->need($current['event_count']===0,'Review history exists');
        $old=array_values(array_filter($before['years'],static fn($r)=>(int)$r['year']===2027))[0];
        $now=array_values(array_filter($current['years'],static fn($r)=>(int)$r['year']===2027))[0];
        $this->need(in_array($now['club_close_at'],[$old['club_close_at'],'2027-03-31 15:00:00.000000'],true),'Later calendar change');
        if($now['club_close_at']!==$old['club_close_at']){$s=$this->db->prepare('UPDATE portal_years SET club_close_at=?,updated_at=updated_at WHERE year=2027');$s->execute([$old['club_close_at']]);}
        if(in_array('INSERT',$current['permissions'],true)){$this->db->exec('REVOKE INSERT ON `'.$this->database.'`.'.self::TABLE.' FROM '.$this->principal());}
        if($current['exists']){$this->db->exec('DROP TABLE '.self::TABLE);}
    }
}
