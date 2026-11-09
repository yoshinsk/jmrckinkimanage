<?php
// Path: tests/auth/editor_database.php
// 機能: 独立した架空DBで追加表・限定権限・途中失敗・空表復元・保存後の復元拒否を確認する。
declare(strict_types=1);
require '/srv/tests/EditorDraftDatabase.php';
$db=new PDO('mysql:host=db;dbname=jmrckinki_editor_lab;charset=utf8mb4','root','lab_root_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$r=new \Jmrc\Deployment\EditorDraftDatabase($db,'jmrckinki_editor_lab');$account=['editor_runtime','%'];$sql=file_get_contents('/srv/tests/012_editor_drafts.sql');$before=$r->snapshot($account);$cases=[];
$check=static function($label,$ok)use(&$cases){if(!$ok){throw new RuntimeException($label);}$cases[]=$label;};
$deny=static function($label,$f)use($check){$thrown=false;try{$f();}catch(RuntimeException){$thrown=true;}$check($label,$thrown);};
$deny('DBを取り違えない',fn()=>new \Jmrc\Deployment\EditorDraftDatabase($db,'jmrckinki'));
$deny('固定外DDLを拒否',fn()=>$r->apply($before,$sql.' ', $account));
$deny('途中失敗時にこの呼出しの空表だけを戻す',fn()=>$r->apply($before,$sql,$account,static function(){throw new RuntimeException('fixture failure');}));
$check('途中失敗後の元状態',$r->snapshot($account)===$before);
$record=$r->apply($before,$sql,$account);$check('2表とINSERT・UPDATEだけ追加',count($record['after']['tables'])===2&&count($record['after']['permissions'])===3);
$deny('二重適用を拒否',fn()=>$r->apply($before,$sql,$account));
$r->rollbackEmpty($record);$check('空表と限定権限を復元',$r->snapshot($account)===$before);
$record=$r->apply($before,$sql,$account);
$db->exec("INSERT INTO users(id) VALUES(1);INSERT INTO portal_editor_drafts(kind,item_key,row_version,edited_by,edited_at,expires_at) VALUES('calendar','fixture',1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 3 MONTH)");
$deny('保存後のデータを削除しない',fn()=>$r->rollbackEmpty($record));
$check('復元拒否後も保存データを維持',(int)$db->query('SELECT COUNT(*) FROM portal_editor_drafts')->fetchColumn()===1);
echo json_encode(['status'=>'PASS','cases'=>$cases],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
