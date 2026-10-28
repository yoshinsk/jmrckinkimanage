<?php
// Path: scripts/license_migrate.php
// 機能: Web非公開CLIで暗号化計画・確認雛形の作成、無書込check、明示applyを実行する。
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$bootstrap=getenv('JMRC_AUTH_BOOTSTRAP');if(!$bootstrap || !is_file($bootstrap)){fwrite(STDERR,"External bootstrap required\n");exit(1);}require_once $bootstrap;
/** 平文番号を含まないJSONだけを扱い、上書きや既知の公開ディレクトリへの保存を拒否する。 */
function writePlan(string $path,array $data): void
{
    $parent=realpath(dirname($path));
    if(!$parent || preg_match('~(^|[/\\\\])(public|httpdocs|wwwroot|web)([/\\\\]|$)~i',$parent)){throw new \Jmrc\Auth\HttpFailure(422,'Web非公開の保存先を指定してください');}
    $fp=@fopen($path,'x');if(!$fp){throw new \Jmrc\Auth\HttpFailure(409,'出力先は既に存在するか保存できません');}
    chmod($path,0600);try{if(fwrite($fp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR))===false){throw new RuntimeException('Write failed');}}finally{fclose($fp);}
}
try{
    $o=getopt('',['mode:','actor:','expected-database:','users:','manifest:','review:','out:','review-out:','apply']);
    $actor=filter_var($o['actor']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$actor){throw new \Jmrc\Auth\HttpFailure(422,'実行者を明示してください');}
    $db=jmrc_db();$expected=$o['expected-database']??'';
    if(!is_string($expected) || $expected==='' || $db->query('SELECT DATABASE()')->fetchColumn()!==$expected){throw new \Jmrc\Auth\HttpFailure(409,'対象DBを明示して照合してください');}
    $service=new \Jmrc\License\LicenseMigration($db,jmrc_auth_config());$mode=$o['mode']??'check';
    if($mode==='plan'){
        if(!is_string($o['out']??null) || !is_string($o['review-out']??null)){throw new \Jmrc\Auth\HttpFailure(422,'計画・確認雛形の保存先を指定してください');}
        $users=isset($o['users'])?array_map(static function($v){$id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){throw new \Jmrc\Auth\HttpFailure(422,'対象IDを確認してください');}return $id;},explode(',',$o['users'])):[];
        $p=$service->plan($actor,$users);writePlan($o['out'],$p['manifest']);writePlan($o['review-out'],$p['review']);$result=['mode'=>'plan','candidates'=>$p['candidates']];
    }else{
        if(!in_array($mode,['check','apply'],true) || ($mode==='apply' && !array_key_exists('apply',$o))){throw new \Jmrc\Auth\HttpFailure(422,'applyには明示した--applyが必要です');}
        foreach(['manifest','review'] as $name){if(!is_string($o[$name]??null) || !is_file($o[$name])){throw new \Jmrc\Auth\HttpFailure(422,'計画・確認ファイルを指定してください');}}
        $result=$service->execute($actor,json_decode(file_get_contents($o['manifest']),true,512,JSON_THROW_ON_ERROR),json_decode(file_get_contents($o['review']),true,512,JSON_THROW_ON_ERROR),$expected,$mode==='apply');
    }
    echo json_encode(['status'=>'PASS']+$result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){$code=$e instanceof \Jmrc\Auth\HttpFailure?$e->status:503;fwrite(STDERR,json_encode(['status'=>'ERROR','code'=>$code,'message'=>$e instanceof \Jmrc\Auth\HttpFailure?$e->getMessage():'移行処理を完了できません'],JSON_UNESCAPED_UNICODE)."\n");exit(1);}
