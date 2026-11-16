<?php
// Path: src/Club/ClubIntakePolicy.php
// 機能: クラブだけを既存日程で先行受付する条件。個人受付・正本の切替設定は増やさず、準備中は閉じる。
declare(strict_types=1);
namespace Jmrc\Club;
use Jmrc\Auth\HttpFailure;
final class ClubIntakePolicy
{
    /** 未設定は停止。これは受付の準備保留であり、年度正本を切り替える操作ではない。 */
    public static function enabled(?array $config=null):bool
    {
        $config??=function_exists('jmrc_auth_config')?\jmrc_auth_config():[];
        return ($config['club_intake_enabled']??false)===true;
    }
    /** テストでは時計を明示できる。実Web処理はサーバーUTCと年度マスターだけを使用する。 */
    public static function open(array $year,\DateTimeImmutable $at,?array $config=null):bool
    {
        $now=$at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        return self::enabled($config)&&in_array($year['source_mode'],['shadow','portal'],true)
            &&$year['club_open_at']!==null&&$year['club_close_at']!==null
            &&$year['club_open_at']<=$now&&$now<$year['club_close_at']&&$now<$year['valid_until'];
    }
    public static function requireOpen(\PDO $db,int $year,?array $config=null):void
    {
        if(!self::enabled($config)){throw new HttpFailure(503,'クラブ登録の受付は準備中です');}
        $s=$db->prepare('SELECT * FROM portal_years WHERE year=? FOR UPDATE');$s->execute([$year]);$r=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$r||!self::open($r,new \DateTimeImmutable('now',new \DateTimeZone('UTC')),$config)){throw new HttpFailure(422,'クラブ登録の受付期間外です');}
    }
}
