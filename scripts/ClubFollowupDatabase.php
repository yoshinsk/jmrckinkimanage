<?php
// Path: scripts/ClubFollowupDatabase.php
// 機能: 確定区分と再申請終了表だけをCLIで追加。既存データ・定義・権限を照合して限定復元する。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
final class ClubFollowupDatabase
{
    private const TABLE='portal_club_intake_closures';
    public function __construct(private readonly PDO $db,private readonly string $database,private readonly string $runtime,private readonly string $host)
    {
        $this->need(PHP_SAPI==='cli'&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'CLI database mismatch');
        $this->need(($database==='jmrckinki'&&$runtime==='jmrc_portal'&&$host==='127.0.0.1')||($database==='jmrckinki_club_followup_lab'&&$runtime==='followup_runtime'&&$host==='%'),'Unexpected principal');
    }
    private function need(bool $ok,string $message):void{if(!$ok){throw new \RuntimeException($message);}}
    private function rows(string $sql):array{return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
    private function exists():bool{return (bool)$this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='".self::TABLE."'")->fetchColumn();}
    private function principal():string{return $this->db->quote($this->runtime).'@'.$this->db->quote($this->host);}
    /** 原本は値を出さずチェックサム。変更するマスターと制約だけ別枠にして比較する。 */
    public function snapshot():array
    {
        $names=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $protected=array_values(array_diff($names,[self::TABLE,'portal_club_categories','audit_logs','portal_audit_events']));$schemas=[];
        foreach($protected as $t){$this->need((bool)preg_match('/^[a-z0-9_]+$/D',$t),'Unexpected table');$r=$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM);$schemas[$t]=preg_replace('/ AUTO_INCREMENT=\d+/','',$r[1]);}
        $s=$this->db->prepare('SELECT privilege_type FROM information_schema.table_privileges WHERE table_schema=DATABASE() AND table_name=? AND grantee=? ORDER BY privilege_type');$s->execute([self::TABLE,$this->principal()]);
        return ['exists'=>$this->exists(),'closures'=>$this->exists()?$this->rows('SELECT * FROM '.self::TABLE):[],
            'categories'=>$this->rows('SELECT * FROM portal_club_categories ORDER BY category_digit'),
            'category_schema'=>$this->db->query('SHOW CREATE TABLE portal_club_categories')->fetch(PDO::FETCH_NUM)[1],
            'permissions'=>$s->fetchAll(PDO::FETCH_COLUMN),
            'protected_checksums'=>$protected?$this->rows('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$protected))):[], 'protected_schemas'=>$schemas];
    }
    public function apply(array $before,string $ddl):array
    {
        $this->need($before===$this->snapshot()&&!$before['exists'],'Database changed or migration already applied');
        $this->need(array_column($before['categories'],'category_digit')===['1','2','3','6'],'Unexpected existing categories');
        try{
            $this->db->exec($ddl);$this->db->exec('GRANT INSERT ON `'.$this->database.'`.'.self::TABLE.' TO '.$this->principal());
            $after=$this->snapshot();$this->need(count($after['categories'])===7&&$after['exists']&&$after['closures']===[],'Migration incomplete');
            $this->need($before['protected_checksums']===$after['protected_checksums']&&$before['protected_schemas']===$after['protected_schemas'],'Protected database changed');
            return ['status'=>'PASS','before'=>$before,'after'=>$after,'migration_sha256'=>hash('sha256',$ddl)];
        }catch(\Throwable $e){$this->restore($before);$this->need($before===$this->snapshot(),'Partial migration recovery failed');throw $e;}
    }
    /** 追加記録または後続変更があれば自動復元を拒否する。後続の業務を消すためにチェックを緩めない。 */
    public function rollback(array $record):void
    {
        $this->need($this->snapshot()===$record['after']&&$record['after']['closures']===[],'Subsequent data must be preserved');
        $this->restore($record['before']);$this->need($this->snapshot()===$record['before'],'Rollback mismatch');
    }
    private function restore(array $before):void
    {
        if($this->exists()){$this->need((int)$this->db->query('SELECT COUNT(*) FROM '.self::TABLE)->fetchColumn()===0,'Closure records exist');}
        $current=$this->snapshot();
        if(in_array('INSERT',$current['permissions'],true)&&!in_array('INSERT',$before['permissions'],true)){$this->db->exec('REVOKE INSERT ON `'.$this->database.'`.'.self::TABLE.' FROM '.$this->principal());}
        if($this->exists()){$this->db->exec('DROP TABLE '.self::TABLE);}
        $this->db->exec("DELETE FROM portal_club_categories WHERE category_digit IN('5','7','8')");
        $exists=(int)$this->db->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='portal_club_categories' AND constraint_name='chk_portal_category_confirmed'")->fetchColumn();
        if($exists){$this->db->exec('ALTER TABLE portal_club_categories DROP CONSTRAINT chk_portal_category_confirmed');}
        $this->db->exec("ALTER TABLE portal_club_categories ADD CONSTRAINT chk_portal_category_confirmed CHECK (category_digit IN ('1','2','3','6'))");
    }
}
