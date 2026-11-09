<?php
// Path: scripts/EditorDraftDatabase.php
// 機能: 非公開下書きの2表と当該表だけのDML権限を追加。空表・定義一致を確認して失敗時に復元するCLI専用処理。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
use RuntimeException;
final class EditorDraftDatabase
{
    public const TABLES=['portal_editor_drafts','portal_editor_revisions'];
    public const SQL_SHA256='e18c64e0b2a7dd7bc283a27222b8eeb908287b74336359a89139c3912c9bc54c';
    public function __construct(private readonly PDO $db,private readonly string $database)
    {
        $this->need(PHP_SAPI==='cli'&&in_array($database,['jmrckinki','jmrckinki_editor_lab'],true)&&$db->query('SELECT DATABASE()')->fetchColumn()===$database,'Wrong database or SAPI');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }
    private function need(bool $ok,string $why):void{if(!$ok){throw new RuntimeException($why);}}
    private function target(array $account):string
    {
        $this->need(count($account)===2,'Account required');
        foreach($account as $part){$this->need(is_string($part)&&preg_match('/^[A-Za-z0-9_.%:-]{1,80}$/D',$part)===1,'Invalid account');}
        $s=$this->db->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host=?');$s->execute($account);$this->need((int)$s->fetchColumn()===1,'Runtime account missing');
        return $this->db->quote($account[0]).'@'.$this->db->quote($account[1]);
    }
    public function snapshot(array $account):array
    {
        $target=$this->target($account);$tables=[];
        foreach(self::TABLES as $t){$s=$this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');$s->execute([$this->database,$t]);if($s->fetchColumn()){$tables[$t]=['rows'=>(int)$this->db->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn(),'definition_sha256'=>hash('sha256',$this->db->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1])];}}
        $s=$this->db->prepare("SELECT TABLE_NAME,PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA=? AND GRANTEE=? AND TABLE_NAME IN ('portal_editor_drafts','portal_editor_revisions') ORDER BY TABLE_NAME,PRIVILEGE_TYPE");$s->execute([$this->database,$target]);
        return ['tables'=>$tables,'permissions'=>$s->fetchAll(PDO::FETCH_ASSOC)];
    }
    /** 固定DDL以外は受け付けない。既存表があれば再適用せず、人が状態を確認する。 */
    public function apply(array $before,string $sql,array $account,?callable $checkpoint=null):array
    {
        $target=$this->target($account);$this->need(hash('sha256',str_replace("\r\n","\n",$sql))===self::SQL_SHA256,'Unexpected migration');
        $this->need($before===$this->snapshot($account)&&$before===['tables'=>[],'permissions'=>[]],'Existing or changed draft schema');
        $this->need((int)$this->db->query("SELECT GET_LOCK('jmrc_editor_schema',10)")->fetchColumn()===1,'Migration busy');$created=[];$granted=[];
        try{
            $this->need($before===$this->snapshot($account),'State changed after lock');
            $statements=array_values(array_filter(array_map('trim',explode(';',$sql))));$this->need(count($statements)===2,'Expected two statements');
            foreach($statements as $i=>$statement){$this->db->exec($statement);$created[]=self::TABLES[$i];if($checkpoint){$checkpoint($i);}}
            foreach(['portal_editor_drafts'=>'INSERT,UPDATE','portal_editor_revisions'=>'INSERT'] as $table=>$permissions){$this->db->exec('GRANT '.$permissions.' ON `'.$this->database.'`.`'.$table.'` TO '.$target);$granted[$table]=$permissions;}
            return ['database'=>$this->database,'account'=>$account,'before'=>$before,'after'=>$this->snapshot($account),'migration_sha256'=>self::SQL_SHA256];
        }catch(\Throwable $e){
            // この呼出しで作った空表だけを戻す。データが入った場合は停止し、勝手に消さない。
            foreach($created as $t){$this->need((int)$this->db->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn()===0,'Draft data exists; keep tables and restore code only');}
            foreach($granted as $t=>$p){$this->db->exec('REVOKE '.$p.' ON `'.$this->database.'`.`'.$t.'` FROM '.$target);}
            foreach(array_reverse($created) as $t){$this->db->exec('DROP TABLE `'.$t.'`');}
            throw $e;
        }finally{$this->db->query("SELECT RELEASE_LOCK('jmrc_editor_schema')");}
    }
    /** 初回配置を取り消す場合の限定復元。運用後はこの処理を使わず、コードだけを戻して下書きを残す。 */
    public function rollbackEmpty(array $record):void
    {
        $this->need(($record['database']??null)===$this->database&&($record['migration_sha256']??null)===self::SQL_SHA256,'Wrong release record');
        $current=$this->snapshot($record['account']);$this->need($current===$record['after'],'Later schema, data or grant change');
        foreach(self::TABLES as $t){$this->need(($current['tables'][$t]['rows']??-1)===0,'Draft data exists; retain schema');}
        $target=$this->target($record['account']);
        foreach(['portal_editor_drafts'=>'INSERT,UPDATE','portal_editor_revisions'=>'INSERT'] as $t=>$p){$this->db->exec('REVOKE '.$p.' ON `'.$this->database.'`.`'.$t.'` FROM '.$target);}
        foreach(array_reverse(self::TABLES) as $t){$this->db->exec('DROP TABLE `'.$t.'`');}
        $this->need($this->snapshot($record['account'])===$record['before'],'Rollback mismatch');
    }
}
