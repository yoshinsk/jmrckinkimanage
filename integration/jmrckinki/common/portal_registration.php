<?php
// Path: integration/jmrckinki/common/portal_registration.php → entry/common/portal_registration.php
// 機能: 旧登録処理のトランザクション内で共通認証状態を作り、再登録による既存記録の削除を防ぐ。
declare(strict_types=1);

/** 未確認アカウントだけをロックし、登録済み業務情報がある再登録は事務局確認へ回す。 */
function portal_guard_reregistration(PDO $db,int $user): void
{
    if(!$db->inTransaction()){throw new LogicException('Registration transaction required');}
    $s=$db->prepare('SELECT email_verified FROM users WHERE id=? FOR UPDATE');$s->execute([$user]);$verified=$s->fetchColumn();
    if($verified===false || (int)$verified!==0){throw new RuntimeException('登録済みアカウントは再登録できません');}
    $s=$db->prepare('SELECT status FROM portal_account_controls WHERE user_id=? FOR UPDATE');$s->execute([$user]);$status=$s->fetchColumn();
    if($status!==false && $status!=='active'){throw new RuntimeException('アカウント状態を事務局へ確認してください');}
    foreach(['portal_profiles'=>'user_id','portal_identifiers'=>'user_id','portal_applications'=>'applicant_user_id','portal_user_role_grants'=>'user_id','jmrc_entries'=>'user_id'] as $table=>$column){
        $s=$db->prepare("SELECT 1 FROM $table WHERE $column=? LIMIT 1");$s->execute([$user]);
        if($s->fetchColumn()!==false){throw new RuntimeException('既存記録があるため事務局へ確認してください');}
    }
}

/** users・プロフィールと同じ接続で作成。既存の停止状態をactiveへ戻さない。 */
function portal_registration_control(PDO $db,int $user,bool $reregister): void
{
    if(!$db->inTransaction()){throw new LogicException('Registration transaction required');}
    if($reregister){portal_guard_reregistration($db,$user);}
    $s=$db->prepare('INSERT IGNORE INTO portal_account_controls(user_id) VALUES(?)');$s->execute([$user]);
    if($reregister){$s=$db->prepare('UPDATE portal_account_controls SET auth_version=auth_version+1,row_version=row_version+1 WHERE user_id=?');$s->execute([$user]);}
    $s=$db->prepare("INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES(?,'ACCOUNT_REGISTERED','account',?,?,'{}',UTC_TIMESTAMP(6))");
    $s->execute([$user,$user,bin2hex(random_bytes(16))]);
}
