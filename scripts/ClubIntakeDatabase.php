<?php
// Path: scripts/ClubIntakeDatabase.php
// 機能: クラブ受付用の追加3表・確定料金・必要DML権限だけを適用し、空の追加表を検証付きで復元する。CLI専用。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
use RuntimeException;
final class ClubIntakeDatabase
{
    private const TABLES=['portal_club_fee_profiles','portal_club_intakes','portal_club_intake_revisions'];
    private const PRIVILEGES=['portal_clubs'=>['INSERT'],'portal_club_intakes'=>['INSERT','UPDATE'],'portal_club_intake_revisions'=>['INSERT']];
    public function __construct(private readonly PDO $db,private readonly string $database,private readonly string $runtime,private readonly string $host)
    {
        $this->need(PHP_SAPI==='cli','CLI required');
        $this->need(in_array($database,['jmrckinki','jmrckinki_club_release_lab'],true)&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'Wrong database');
        $this->need(($database==='jmrckinki'&&$runtime==='jmrc_portal'&&$host==='127.0.0.1')||($database==='jmrckinki_club_release_lab'&&$runtime==='intake_runtime'&&$host==='%'),'Unexpected runtime principal');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private function need(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
    private function rows(string $sql):array{return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
    private function exists(string $table):bool{$s=$this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);return (int)$s->fetchColumn()===1;}
    /** 既存データはチェックサムだけ。認証ログは通常ログインで増えるため保護業務表から除く。 */
    public function snapshot():array
    {
        $names=$this->db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $protected=array_values(array_diff($names,[...self::TABLES,'audit_logs','portal_audit_events']));$schemas=[];
        foreach($protected as $t){$this->need((bool)preg_match('/^[a-z0-9_]+$/D',$t),'Unexpected table');$row=$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM);$schemas[$t]=preg_replace('/ AUTO_INCREMENT=\d+/','',$row[1]);}
        $own=[];foreach(self::TABLES as $t){if($this->exists($t)){$own[$t]=['count'=>(int)$this->db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(),'checksum'=>$this->rows('CHECKSUM TABLE '.$t)];}}
        $principal="'".$this->runtime."'@'".$this->host."'";
        $s=$this->db->prepare("SELECT table_name,privilege_type FROM information_schema.table_privileges WHERE table_schema=DATABASE() AND grantee=? AND table_name IN ('portal_clubs','portal_club_intakes','portal_club_intake_revisions') ORDER BY table_name,privilege_type");$s->execute([$principal]);
        return ['database'=>$this->database,'own'=>$own,'profiles'=>$this->exists('portal_club_fee_profiles')?$this->rows('SELECT * FROM portal_club_fee_profiles ORDER BY year,jaf_kind,membership_kind'):[],
            'permissions'=>$s->fetchAll(PDO::FETCH_ASSOC),'protected_checksums'=>$protected?$this->rows('CHECKSUM TABLE '.implode(',',array_map(static fn($t)=>'`'.$t.'`',$protected))):[],'protected_schemas'=>$schemas];
    }
    public function apply(array $before,string $migration,string $fees):array
    {
        $this->need($this->snapshot()===$before,'Protected database changed');$this->need($before['own']===[],'Club intake schema already exists');
        try{
            $this->db->exec($migration);$this->db->exec($fees);
            foreach(self::PRIVILEGES as $table=>$privileges){foreach($privileges as $privilege){$this->db->exec('GRANT '.$privilege.' ON `'.$this->database.'`.`'.$table.'` TO '.$this->principal());}}
            $after=$this->snapshot();$this->need(count($after['profiles'])===9&&$after['own']['portal_club_intakes']['count']===0&&$after['own']['portal_club_intake_revisions']['count']===0,'Unexpected initial rows');
            $this->need($before['protected_checksums']===$after['protected_checksums']&&$before['protected_schemas']===$after['protected_schemas'],'Existing database modified');
            return ['status'=>'PASS','before'=>$before,'after'=>$after,'migration_sha256'=>hash('sha256',$migration),'fees_sha256'=>hash('sha256',$fees)];
        }catch(\Throwable $e){$this->remove($before);$this->need($this->snapshot()===$before,'Failed apply needs manual recovery');throw $e;}
    }
    /** 一件でも申請や訂正履歴が入れば自動復元しない。後続の既存業務・権限変更も上書きしない。 */
    public function rollback(array $record):void
    {
        $current=$this->snapshot();$this->need($current===$record['after'],'Database changed after deployment');
        $this->need($current['own']['portal_club_intakes']['count']===0&&$current['own']['portal_club_intake_revisions']['count']===0,'Business records exist');
        $this->remove($record['before']);$this->need($this->snapshot()===$record['before'],'Rollback mismatch');
    }
    private function principal():string{return $this->db->quote($this->runtime).'@'.$this->db->quote($this->host);}
    private function remove(array $before):void
    {
        foreach(['portal_club_intakes','portal_club_intake_revisions'] as $t){$this->need(!$this->exists($t)||(int)$this->db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn()===0,'Business records must be preserved');}
        $current=$this->snapshot();
        foreach(self::PRIVILEGES as $table=>$privileges){foreach($privileges as $privilege){$row=['table_name'=>$table,'privilege_type'=>$privilege];if(in_array($row,$current['permissions'],true)&&!in_array($row,$before['permissions'],true)){$this->db->exec('REVOKE '.$privilege.' ON `'.$this->database.'`.`'.$table.'` FROM '.$this->principal());}}}
        foreach(array_reverse(self::TABLES) as $t){if($this->exists($t)){$this->db->exec('DROP TABLE `'.$t.'`');}}
    }
}
