<?php
// Path: scripts/OrganizationFeesDatabase.php
// 機能: 正会員団体2区分の料金とCHECK制約だけを追加・限定復元し、既存料金・業務・権限を保護する。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
final class OrganizationFeesDatabase
{
    private const OLD="CONSTRAINT `CONSTRAINT_3` CHECK (`jaf_kind` in ('official','affiliated','associate','organization','business'))";
    private const NEW="CONSTRAINT `CONSTRAINT_3` CHECK (`jaf_kind` in ('official','affiliated','associate','organization','business','official_org','associate_org'))";
    public function __construct(private readonly PDO $db,private readonly string $database,private readonly string $runtime,private readonly string $host)
    {
        $this->need(PHP_SAPI==='cli'&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'CLI database mismatch');
        $this->need(($database==='jmrckinki'&&$runtime==='jmrc_portal'&&$host==='127.0.0.1')||($database==='jmrckinki_org_fees_lab'&&$runtime==='orgfees_runtime'&&$host==='%'),'Unexpected principal');
    }
    private function need(bool $ok,string $message):void{if(!$ok){throw new \RuntimeException($message);}}
    private function added(array $row):bool{return (int)$row['year']===2027&&$row['membership_kind']==='regular'&&in_array($row['jaf_kind'],['official_org','associate_org'],true);}
    /** CHECKの並び順だけを正規化する。列順・型・他制約・外部キーの変更は一致と扱わない。 */
    private function schema():array
    {
        $ddl=$this->db->query('SHOW CREATE TABLE portal_club_fee_profiles')->fetch(PDO::FETCH_NUM)[1];$base=[];$checks=[];
        foreach(explode("\n",$ddl) as $line){if(preg_match('/^\s*CONSTRAINT `[^`]+` CHECK /',$line)){$checks[]=rtrim(trim($line),',');}else{$base[]=$line;}}
        sort($checks);return ['base'=>implode("\n",$base),'checks'=>$checks];
    }
    /** 対象以外の値は取り出さず、定義・チェックサムと権限を保存して照合する。 */
    public function snapshot():array
    {
        $names=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $protected=array_values(array_diff($names,['portal_club_fee_profiles','audit_logs','portal_audit_events']));$schemas=[];
        foreach($protected as $t){$this->need((bool)preg_match('/^[a-z0-9_]+$/D',$t),'Unexpected table');$schemas[$t]=preg_replace('/ AUTO_INCREMENT=\\d+/','',$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1]);}
        $s=$this->db->prepare('SELECT table_name,privilege_type FROM information_schema.table_privileges WHERE table_schema=DATABASE() AND grantee=? ORDER BY table_name,privilege_type');$s->execute([$this->db->quote($this->runtime).'@'.$this->db->quote($this->host)]);
        $profiles=$this->db->query('SELECT * FROM portal_club_fee_profiles ORDER BY year,jaf_kind,membership_kind')->fetchAll(PDO::FETCH_ASSOC);$schema=$this->schema();
        return ['exists'=>in_array(self::NEW,$schema['checks'],true),'rows'=>count(array_filter($profiles,$this->added(...))),'profiles'=>$profiles,'schema'=>$schema,'permissions'=>$s->fetchAll(PDO::FETCH_ASSOC),'protected_schemas'=>$schemas,'protected_checksums'=>$this->db->query('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$protected)))->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function apply(array $before,string $ddl):array
    {
        $this->need($before===$this->snapshot()&&!$before['exists']&&$before['rows']===0&&in_array(self::OLD,$before['schema']['checks'],true),'Database changed or unexpected fee constraint');
        try{
            $this->db->exec($ddl);$after=$this->snapshot();$this->unchanged($before,$after);
            $this->need($after['exists']&&$after['rows']===2,'Migration incomplete');
            $checks=$before['schema']['checks'];$checks[array_search(self::OLD,$checks,true)]=self::NEW;sort($checks);
            $this->need($after['schema']===['base'=>$before['schema']['base'],'checks'=>$checks],'Unexpected schema change');
            return ['status'=>'PASS','before'=>$before,'after'=>$after,'migration_sha256'=>hash('sha256',$ddl)];
        }catch(\Throwable $e){$this->restore($before);throw $e;}
    }
    /** 既存料金・他業務・権限を保持し、追加行は確定値の2区分だけと照合する。 */
    private function unchanged(array $before,array $now):void
    {
        foreach(['permissions','protected_schemas','protected_checksums'] as $key){$this->need($before[$key]===$now[$key],'Protected state changed');}
        $this->need(array_values(array_filter($now['profiles'],fn($r)=>!$this->added($r)))===$before['profiles'],'Existing fee changed');
        foreach(array_filter($now['profiles'],$this->added(...)) as $r){$this->need((int)$r['annual_yen']===20000&&(int)$r['joining_yen']===10000&&(int)$r['enabled']===1,'Added fee changed');}
    }
    public function rollback(array $record):void
    {
        $this->need($this->snapshot()===$record['after'],'Subsequent changes must be preserved');$this->restore($record['before']);
    }
    private function restore(array $before):void
    {
        $now=$this->snapshot();$this->unchanged($before,$now);
        if($now===$before){return;}
        $this->need($now['schema']['base']===$before['schema']['base'],'Fee table changed');
        $without=static fn($checks)=>array_values(array_filter($checks,static fn($c)=>!str_starts_with($c,'CONSTRAINT `CONSTRAINT_3` ')));
        $this->need($without($now['schema']['checks'])===$without($before['schema']['checks']),'Other fee constraints changed');
        foreach(array_diff($now['schema']['checks'],$without($now['schema']['checks'])) as $check){$this->need(in_array($check,[self::OLD,self::NEW],true),'Fee constraint changed after deployment');}
        $this->db->exec("DELETE FROM portal_club_fee_profiles WHERE year=2027 AND membership_kind='regular' AND jaf_kind IN('official_org','associate_org')");
        if(in_array(self::NEW,$now['schema']['checks'],true)||in_array(self::OLD,$now['schema']['checks'],true)){$this->db->exec('ALTER TABLE portal_club_fee_profiles DROP CONSTRAINT CONSTRAINT_3');}
        $this->db->exec('ALTER TABLE portal_club_fee_profiles ADD '.self::OLD);
        $this->need($this->snapshot()===$before,'Rollback mismatch');
    }
}
