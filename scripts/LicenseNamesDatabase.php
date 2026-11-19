<?php
// Path: scripts/LicenseNamesDatabase.php
// 機能: 空のライセンス追加表にJAF登録名の暗号欄だけを追加・復元する。既存業務と権限を保持する。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
final class LicenseNamesDatabase
{
    public function __construct(private readonly PDO $db,private readonly string $database,private readonly string $runtime,private readonly string $host)
    {
        $this->need(PHP_SAPI==='cli'&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'CLI database mismatch');
        $this->need(($database==='jmrckinki'&&$runtime==='jmrc_portal'&&$host==='127.0.0.1')||($database==='jmrckinki_license_details_lab'&&$runtime==='details_runtime'&&$host==='%'),'Unexpected principal');
    }
    private function need(bool $ok,string $message):void{if(!$ok){throw new \RuntimeException($message);}}
    /** 原本の値は取り出さず、定義・件数・チェックサム・既存権限を照合する。 */
    public function snapshot():array
    {
        $names=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $this->need(in_array('portal_license_details',$names,true),'License details table missing');
        $protected=array_values(array_diff($names,['portal_license_details','audit_logs','portal_audit_events']));$schemas=[];
        foreach($protected as $t){$this->need((bool)preg_match('/^[a-z0-9_]+$/D',$t),'Unexpected table');$schemas[$t]=preg_replace('/ AUTO_INCREMENT=\\d+/','',$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1]);}
        $s=$this->db->prepare("SELECT privilege_type FROM information_schema.table_privileges WHERE table_schema=DATABASE() AND table_name='portal_license_details' AND grantee=? ORDER BY privilege_type");$s->execute([$this->db->quote($this->runtime).'@'.$this->db->quote($this->host)]);
        return ['exists'=>(bool)$this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='portal_license_details' AND column_name='registered_name_cipher'")->fetchColumn(),'rows'=>(int)$this->db->query('SELECT COUNT(*) FROM portal_license_details')->fetchColumn(),'permissions'=>$s->fetchAll(PDO::FETCH_COLUMN),'schema'=>$this->db->query('SHOW CREATE TABLE portal_license_details')->fetch(PDO::FETCH_NUM)[1],'protected_schemas'=>$schemas,'protected_checksums'=>$this->db->query('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$protected)))->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function apply(array $before,string $ddl):array
    {
        $this->need($before===$this->snapshot()&&!$before['exists']&&$before['rows']===0,'Database changed or details already used');
        try{
            $this->db->exec($ddl);$after=$this->snapshot();
            $this->need($after['exists']&&$after['rows']===0&&$before['permissions']===$after['permissions'],'Migration incomplete');
            $this->need($before['protected_schemas']===$after['protected_schemas']&&$before['protected_checksums']===$after['protected_checksums'],'Protected database changed');
            return ['status'=>'PASS','before'=>$before,'after'=>$after,'migration_sha256'=>hash('sha256',$ddl)];
        }catch(\Throwable $e){$this->restore($before);throw $e;}
    }
    /** コードを先に戻し、追加記録・後続変更がない場合だけ列を戻す。実データを削除して条件を作らない。 */
    public function rollback(array $record):void
    {
        $this->need($this->snapshot()===$record['after']&&$record['after']['rows']===0,'Subsequent data must be preserved');$this->restore($record['before']);
    }
    private function restore(array $before):void
    {
        $now=$this->snapshot();$this->need($now['rows']===0&&$now['permissions']===$before['permissions']&&$now['protected_schemas']===$before['protected_schemas']&&$now['protected_checksums']===$before['protected_checksums'],'Subsequent changes require inspection');
        if($now['exists']){$this->db->exec('ALTER TABLE portal_license_details DROP COLUMN registered_name_cipher');}
        $this->need($this->snapshot()===$before,'Rollback mismatch');
    }
}
