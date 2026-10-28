<?php
// Path: src/Card/SecretBox.php
// 機能: 会員証の識別情報を用途別AES-256-GCMとHMACで保護する。未知形式を平文扱いしない。
declare(strict_types=1);
namespace Jmrc\Card;
use Jmrc\Auth\HttpFailure;
final class SecretBox
{
    private readonly string $key;
    public function __construct(?string $encodedKey)
    {
        $key=base64_decode($encodedKey??'',true);
        if ($key===false || strlen($key)!==32) { throw new HttpFailure(503,'会員証の暗号設定を確認してください'); }$this->key=$key;
    }
    /** 用途と対象を認証データに含め、別の用途/会員へ暗号文を付け替えられないようにする。 */
    public function seal(string $plain,string $context): string
    {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$context);
        if ($cipher===false) { throw new HttpFailure(503,'会員証の暗号処理を完了できません'); }return 'gcm1:'.base64_encode($iv.$tag.$cipher);
    }
    /** 形式・認証タグの不一致時は停止。既存識別番号の移行方式を推測しない。 */
    public function open(string $cipher,string $context): string
    {
        $raw=str_starts_with($cipher,'gcm1:')?base64_decode(substr($cipher,5),true):false;
        if ($raw===false || strlen($raw)<28) { throw new HttpFailure(503,'識別情報の保管形式を確認してください'); }
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$context);
        if ($plain===false) { throw new HttpFailure(503,'識別情報を確認できません'); }return $plain;
    }
    public function digest(string $value,string $context): string { return hash_hmac('sha256',$context.':'.$value,$this->key,true); }
}
