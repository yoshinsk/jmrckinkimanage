<?php
// Path: scripts/LicenseDetailsDatabase.php
// 機能: 空のライセンス追加表と最小DML権限をCLIで適用・復元。既存表と後続データを保護する。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
final class LicenseDetailsDatabase
{
    private const TABLE='portal_license_details';
    public function __construct(private readonly PDO $db,private readonly string $database,private readonly string $runtime,private readonly string $host)
    {
        $this->need(PHP_SAPI==='cli'&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'CLI database mismatch');
        $this->need(($database==='jmrckinki'&&$runtime==='jmrc_portal'&&$host==='127.0.0.1')||($database==='jmrckinki_license_details_lab'&&$runtime==='details_runtime'&&$host==='%'),'Unexpected principal');
    }
    private function need(bool $ok,string $message):void{if(!$ok){throw new \RuntimeException($message);}}
    private function principal():string{return $this->db->quote($this->runtime).'@'.$this->db->quote($this->host);}
    /** 個人情報は値を保存せず、既存表の定義とチェックサムで原本を照合する。 */
    public function snapshot():array
    {
        $names=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $exists=in_array(self::TABLE,$names,true);$protected=array_values(array_diff($names,[self::TABLE,'audit_logs','portal_audit_events']));$schemas=[];
        foreach($protected as $t){$this->need((bool)preg_match('/^[a-z0-9_]+$/D',$t),'Unexpected table');$schemas[$t]=preg_replace('/ AUTO_INCREMENT=\\d+/','',$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1]);}
        $s=$this->db->prepare('SELECT privilege_type FROM information_schema.table_privileges WHERE table_schema=DATABASE() AND table_name=? AND grantee=? ORDER BY privilege_type');$s->execute([self::TABLE,$this->principal()]);
        return ['exists'=>$exists,'rows'=>$exists?(int)$this->db->query('SELECT COUNT(*) FROM '.self::TABLE)->fetchColumn():0,'permissions'=>$s->fetchAll(PDO::FETCH_COLUMN),'schema'=>$exists?$this->db->query('SHOW CREATE TABLE '.self::TABLE)->fetch(PDO::FETCH_NUM)[1]:null,'protected_schemas'=>$schemas,'protected_checksums'=>$this->db->query('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$protected)))->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function apply(array $before,string $ddl):array
    {
        $this->need($before===$this->snapshot()&&!$before['exists']&&$before['permissions']===[],'Database changed or migration already applied');
        try{
            $this->db->exec($ddl);$this->db->exec('GRANT INSERT,UPDATE ON `'.$this->database.'`.'.self::TABLE.' TO '.$this->principal());
            $after=$this->snapshot();$this->need($after['exists']&&$after['rows']===0&&$after['permissions']===['INSERT','UPDATE'],'Migration incomplete');
            $this->need($before['protected_schemas']===$after['protected_schemas']&&$before['protected_checksums']===$after['protected_checksums'],'Protected database changed');
            return ['status'=>'PASS','before'=>$before,'after'=>$after,'migration_sha256'=>hash('sha256',$ddl)];
        }catch(\Throwable $e){$this->restore($before);$this->need($this->snapshot()===$before,'Partial migration recovery requires inspection');throw $e;}
    }
    /** 適用直後と一致し、追加記録がない場合だけを復元対象にする。既存業務を巻き戻さない。 */
    public function rollback(array $record):void
    {
        $this->need($this->snapshot()===$record['after']&&$record['after']['rows']===0,'Subsequent data must be preserved');$this->restore($record['before']);$this->need($this->snapshot()===$record['before'],'Rollback mismatch');
    }
    private function restore(array $before):void
    {
        $now=$this->snapshot();$this->need($now['rows']===0,'License details exist');
        foreach(array_diff($now['permissions'],$before['permissions']) as $permission){$this->need(in_array($permission,['INSERT','UPDATE'],true),'Unexpected grant');$this->db->exec('REVOKE '.$permission.' ON `'.$this->database.'`.'.self::TABLE.' FROM '.$this->principal());}
        if($now['exists']){$this->db->exec('DROP TABLE '.self::TABLE);}
    }
}
