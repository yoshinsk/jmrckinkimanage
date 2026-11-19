<?php
// Path: src/License/LicenseExport.php
// 機能: 明示権限のある事務局へ年度別Generalの照会用CSVを出力。正式JAF書式と送信は扱わない。
declare(strict_types=1);
namespace Jmrc\License;
use PDO;
use Jmrc\Auth\HttpFailure;
use Jmrc\Card\SecretBox;
final class LicenseExport
{
    public const COLUMNS=['年度','利用者ID','会員資格ID','JAFライセンス登録名','会員状態（保存値）','JAFライセンス番号','区分','ライセンスの種類（本人申告）','発行日（未確認は空欄）','旧有効期限（参考）'];
    public function __construct(private readonly PDO $db,private readonly array $config){}
    /** 現行の識別情報取扱権限を再確認する。クラブ代表や旧管理者だけでは全体出力を許可しない。 */
    public function access(int $actor):void{(new LicenseService($this->db,$this->config))->requireMigration($actor);}
    /** 全件の復号・CSV生成に成功してから監査を記録。復号失敗時に一部の個人情報だけを出力しない。 */
    public function csv(int $actor,mixed $year):string
    {
        $this->access($actor);
        if(($this->config['license_export_enabled']??false)!==true){throw new HttpFailure(503,'JAF照会用の出力は確認中です');}
        $year=filter_var($year,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>9999]]);
        if($year===false){throw new HttpFailure(422,'対象年度を確認してください');}
        $s=$this->db->prepare('SELECT year FROM portal_years WHERE year=?');$s->execute([$year]);
        if(!$s->fetchColumn()){throw new HttpFailure(422,'登録済み年度を選択してください');}
        $s=$this->db->prepare("SELECT m.year,m.user_id,m.id,m.status,i.id AS identifier_id,i.value_cipher,i.kind,i.license_type,i.expires_on,d.types_json,d.issued_on,d.registered_name_cipher
          FROM portal_memberships m JOIN portal_identifiers i ON i.id=m.general_license_identifier_id AND i.user_id=m.user_id AND i.kind=m.general_license_kind
          LEFT JOIN portal_license_details d ON d.identifier_id=i.id
          WHERE m.year=? AND m.member_type='general' AND m.status='active' ORDER BY m.user_id,m.id LIMIT 5001");
        $s->execute([$year]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>5000){throw new HttpFailure(422,'対象を分割する運用を事務局で確認してください');}
        $box=new SecretBox($this->config['data_key']??null);$fp=fopen('php://temp','w+');
        try{
            fwrite($fp,"\xEF\xBB\xBF");fputcsv($fp,self::COLUMNS,',','"','');
            foreach($rows as $r){
                if($r['registered_name_cipher']===null){throw new HttpFailure(422,'対象会員のJAFライセンス登録名を本人に確認してください。氏名やエントリー名では代用しません');}
                $name=$box->open($r['registered_name_cipher'],'jmrc:license-name:v1:'.$r['identifier_id']);
                if(LicenseTypes::registeredName($name)===null){throw new HttpFailure(422,'JAFライセンス登録名が未登録です');}
                $number=$box->open($r['value_cipher'],'jmrc:identifier:v1:'.$r['identifier_id']);
                $values=[$r['year'],$r['user_id'],$r['id'],$name,$r['status'],$number,$r['kind']==='jaf_competition'?'競技':'審判員',LicenseTypes::display($r['types_json'],$r['license_type']),$r['issued_on']??'',$r['expires_on']??''];
                fputcsv($fp,array_map(self::safeCell(...),$values),',','"','');
            }
            rewind($fp);$csv=stream_get_contents($fp);
        }finally{fclose($fp);}
        $this->access($actor);
        $s=$this->db->prepare("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,'LICENSE_EXPORTED','year',?,?,?,UTC_TIMESTAMP(6))");
        $s->execute([$actor,$year,bin2hex(random_bytes(16)),json_encode(['year'=>$year,'rows'=>count($rows)],JSON_THROW_ON_ERROR)]);
        return $csv;
    }
    /** 氏名・旧自由記載をExcel等の数式として実行させない。正規の番号や元データ自体は変更しない。 */
    private static function safeCell(mixed $value):string
    {
        $value=(string)$value;
        return preg_match('/^[\\s\\x00-\\x20]*[=+@-]/u',$value)?"'".$value:$value;
    }
}
