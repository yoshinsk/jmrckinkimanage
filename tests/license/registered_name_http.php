<?php
// Path: tests/license/registered_name_http.php
// 機能: 本人のJAF登録名を暗号保存し、旧情報の省略更新・競合・他人の参照を隔離検証する。
declare(strict_types=1);
$nameOwner=new Browser();$nameOwner->login(15);$nameOther=new Browser();$nameOther->login(16);
$names=new \Jmrc\License\LicenseService($db,jmrc_auth_config());$nameRow=array_values(array_filter($names->listing(15),static fn($r)=>$r['license_types']!==[]))[0];
$input=['id'=>$nameRow['id'],'row_version'=>$nameRow['row_version'],'kind'=>$nameRow['kind'],'number'=>'','license_types'=>$nameRow['license_types'],'issued_on'=>$nameRow['issued_on'],'registered_name'=>'ＴＥＳＴ　Ⅲ'];
$saved=$names->save(15,$input);
check('L15 本人の登録名を半角整形しローマ数字保持',$saved['registered_name']==='TEST Ⅲ');
$nameCipher=$db->query('SELECT registered_name_cipher FROM portal_license_details WHERE identifier_id='.(int)$nameRow['id'])->fetchColumn();
check('L15 登録名を平文保管しない',str_starts_with($nameCipher,'gcm1:')&&!str_contains($nameCipher,'TEST'));
$input['row_version']=$saved['row_version'];$again=$names->save(15,$input);
check('L15 同じ登録名の再送は更新しない',$again['row_version']===$saved['row_version']);
unset($input['registered_name']);$oldClient=$names->save(15,$input);
check('L15 名前のない旧入力で登録済み名を消さない',$oldClient['registered_name']==='TEST Ⅲ'&&$oldClient['row_version']===$saved['row_version']);
licenseReject('L15 配列の登録名はサーバーで拒否',fn()=>$names->save(15,$input+['registered_name'=>['偽装']]),422);
check('L15 他人の登録名を読めない',$nameOther->request('/manage/licenses.php?format=json&id='.$nameRow['id'])['status']===404);
$own=$nameOwner->request('/manage/licenses.php?id='.$nameRow['id']);
check('L15 本人の編集欄だけに登録名を表示',str_contains($own['body'],'TEST Ⅲ')&&str_contains($own['body'],'jaf-name-help'));
$nameAudit=json_encode($db->query("SELECT changes_redacted FROM portal_audit_events WHERE action='LICENSE_UPDATED'")->fetchAll());
check('L15 操作ログへ登録名を含めない',!str_contains($nameAudit,'TEST'));
