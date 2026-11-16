<?php
// Path: tests/club/club_boundary_policy.php
// 機能: 本番時計やDBに触れず、確定したクラブ受付の日本時間・UTC・マイクロ秒境界と保留条件を検証する。
declare(strict_types=1);
if(!class_exists(\Jmrc\Club\ClubIntakePolicy::class)){require dirname(__DIR__,2).'/src/Club/ClubIntakePolicy.php';}
(function():void{
    $cases=[];$standalone=!function_exists('check');
    $assert=static function(string $label,bool $ok)use(&$cases,$standalone):void{
        if(!$ok){throw new RuntimeException($label);}$cases[]=$label;
        if(!$standalone){check($label,$ok);}
    };
    $year=['source_mode'=>'shadow','club_open_at'=>'2026-11-30 15:00:00.000000','club_close_at'=>'2027-02-07 15:00:00.000000','valid_until'=>'2027-12-31 15:00:00.000000'];
    $enabled=['club_intake_enabled'=>true];
    $open=static fn(array $y,string $at,array $config)=>\Jmrc\Club\ClubIntakePolicy::open($y,new DateTimeImmutable($at),$config);
    $times=['2026-11-30T23:59:59.999999+09:00'=>false,'2026-12-01T00:00:00.000000+09:00'=>true,'2026-12-01T00:00:00.000001+09:00'=>true,
        '2027-02-07T23:59:59.999999+09:00'=>true,'2027-02-08T00:00:00.000000+09:00'=>false,'2027-02-08T00:00:00.000001+09:00'=>false];
    foreach(['shadow','portal'] as $mode){foreach($times as $time=>$expected){
        $y=array_replace($year,['source_mode'=>$mode]);$assert('C7 '.$mode.' 境界 '.$time,$open($y,$time,$enabled)===$expected);
        $utc=(new DateTimeImmutable($time))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        $assert('C7 UTC表現でも同じ境界 '.$mode.' '.$utc,$open($y,$utc,$enabled)===$expected);
    }}
    foreach([[],['club_intake_enabled'=>false],['club_intake_enabled'=>'true'],['club_intake_enabled'=>1]] as $i=>$config){
        $assert('C7 明示的な真以外は受付保留 '.$i,!$open($year,'2026-12-01T00:00:00+09:00',$config));
    }
    foreach(['legacy','archived'] as $mode){$assert('C7 対象外の参照元 '.$mode,!$open(array_replace($year,['source_mode'=>$mode]),'2026-12-01T00:00:00+09:00',$enabled));}
    foreach(['club_open_at','club_close_at'] as $key){$assert('C7 日程未設定 '.$key,!$open(array_replace($year,[$key=>null]),'2026-12-01T00:00:00+09:00',$enabled));}
    $early=array_replace($year,['valid_until'=>'2027-01-31 15:00:00.000000']);
    $assert('C7 年度終了の直前は期間内',$open($early,'2027-01-31T23:59:59.999999+09:00',$enabled));
    $assert('C7 年度終了時刻は期間外',!$open($early,'2027-02-01T00:00:00+09:00',$enabled));
    $previous=date_default_timezone_get();
    try{foreach(['UTC','Asia/Tokyo','America/Los_Angeles'] as $zone){date_default_timezone_set($zone);$assert('C7 PHP既定タイムゾーンに依存しない '.$zone,$open($year,'2026-12-01T00:00:00+09:00',$enabled));}}
    finally{date_default_timezone_set($previous);}
    if($standalone){echo json_encode(['status'=>'PASS','cases'=>count($cases),'database_used'=>false,'production_clock_changed'=>false],JSON_THROW_ON_ERROR)."\n";}
})();
