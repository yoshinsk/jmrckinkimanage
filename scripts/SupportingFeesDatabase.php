<?php
// Path: scripts/SupportingFeesDatabase.php
// 機能: 賛助団体の料金1行訂正・2行追加のみを原子的に適用し、後続変更を保護して限定復元する。
declare(strict_types=1);
namespace Jmrc\Deployment;
use PDO;
require_once __DIR__.'/OrganizationFeesDatabase.php';
final class SupportingFeesDatabase
{
    private readonly OrganizationFeesDatabase $guard;
    public function __construct(private readonly PDO $db,string $database,string $runtime,string $host)
    {$this->guard=new OrganizationFeesDatabase($db,$database,$runtime,$host);}
    private function need(bool $ok,string $message):void{if(!$ok){throw new \RuntimeException($message);}}
    /** 定義・業務チェックサム・権限の取得方法を既存の限定配置と共通にする。 */
    public function snapshot():array{return $this->guard->snapshot();}
    private function target(array $r):bool
    {return (int)$r['year']===2027&&$r['membership_kind']==='supporting'&&in_array($r['jaf_kind'],['official_org','organization','associate_org'],true);}
    private function targets(array $s):array{return array_values(array_filter($s['profiles'],$this->target(...)));}
    private function protectedState(array $s):array
    {$s['profiles']=array_values(array_filter($s['profiles'],fn($r)=>!$this->target($r)));return $s;}
    private function expected(array $rows,int $count,int $annual):bool
    {
        if(count($rows)!==$count){return false;}
        foreach($rows as $r){if((int)$r['annual_yen']!==$annual||(int)$r['joining_yen']!==10000||(int)$r['enabled']!==1){return false;}}
        return $count!==1||$rows[0]['jaf_kind']==='organization';
    }
    /** 原本が変わっていないことを検証し、更新後も対象以外が一致しなければ全体を戻す。 */
    public function apply(array $before,string $ddl):array
    {
        $this->need($before===$this->snapshot()&&$before['exists']&&$this->expected($this->targets($before),1,10000),'Unexpected supporting fee baseline');
        $this->db->beginTransaction();
        try{
            $this->db->exec($ddl);$after=$this->snapshot();
            $this->need($this->protectedState($before)===$this->protectedState($after),'Protected state changed');
            $this->need($this->expected($this->targets($after),3,20000),'Supporting fees incomplete');
            $this->db->commit();return ['status'=>'PASS','before'=>$before,'after'=>$after,'migration_sha256'=>hash('sha256',$ddl)];
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
    /** 料金・業務への後続変更があれば拒否する。テーブル全体・既存請求を巻き戻さない。 */
    public function rollback(array $record):void
    {
        $this->db->beginTransaction();
        try{
            $this->need($this->snapshot()===$record['after'],'Subsequent changes must be preserved');
            $this->db->exec("DELETE FROM portal_club_fee_profiles WHERE year=2027 AND membership_kind='supporting' AND jaf_kind IN('official_org','associate_org')");
            $this->db->exec("UPDATE portal_club_fee_profiles SET annual_yen=10000 WHERE year=2027 AND jaf_kind='organization' AND membership_kind='supporting'");
            $this->need($this->snapshot()===$record['before'],'Rollback mismatch');$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}
