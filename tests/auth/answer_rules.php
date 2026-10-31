<?php
// Path: tests/auth/answer_rules.php
// 機能: 追加回答の端数・時刻境界・最新照会と申込時の権利の分離を、架空の資格で検証する。
declare(strict_types=1);
require_once '/srv/auth/src/Series/SeriesRules.php';
require_once '/srv/auth/src/Events/OrganizerEligibilityPolicy.php';
use Jmrc\Series\SeriesRules;
use Jmrc\Events\OrganizerEligibilityPolicy as OrganizerPolicy;

foreach ([0=>0,1=>1,2=>2,3=>3,4=>3,7=>5,10=>7,11=>8,20=>14] as $held=>$expected) {
    check('70パーセント切上げ '.$held.'戦', SeriesRules::effectiveRounds($held) === $expected);
}
foreach ([-1, PHP_INT_MAX] as $invalid) {
    try { SeriesRules::effectiveRounds($invalid); check('不正な開催数の拒否',false); }
    catch (InvalidArgumentException) { check('負数・桁あふれ開催数を拒否 '.$invalid,true); }
}
check('申込時にGeneralと有効クラブで権利あり',SeriesRules::pointRight(true,'general',true,true)==='eligible');
check('申込時未加入は後から権利を推定しない',SeriesRules::pointRight(true,'general',false,true)==='ineligible');
check('申込時クラブ未更新は対象外',SeriesRules::pointRight(true,'general',true,false)==='ineligible');
check('Temporaryはポイント対象外',SeriesRules::pointRight(true,'temporary',true,true)==='ineligible');
check('履歴不足の所属を未加入と誤判定しない',SeriesRules::pointRight(true,'general',true,null)==='unconfirmed');
check('他地区等の未確定区分をGeneralと推定しない',SeriesRules::pointRight(true,'other_region',true,true)==='unconfirmed');
check('shadow等の非正本の状態は正式判定にしない',SeriesRules::pointRight(false,'general',true,true)==='unconfirmed');

$deadline = new DateTimeImmutable('2027-04-01T18:00:00+09:00');
$end = new DateTimeImmutable('2027-04-10T17:00:00+09:00');
foreach ([['2027-04-01T17:59:59+09:00','not_open'],['2027-04-01T18:00:00+09:00','open'],
          ['2027-04-01T09:00:00Z','open'],['2027-04-17T16:59:59+09:00','open'],['2027-04-17T17:00:00+09:00','expired']] as [$now,$expected]) {
    check('主催照会の期間境界 '.$now,OrganizerPolicy::window(true,new DateTimeImmutable($now),$deadline,$end)===$expected);
    check('期間内でも担当外は拒否 '.$now,OrganizerPolicy::window(false,new DateTimeImmutable($now),$deadline,$end)==='forbidden');
}
check('終了日時なしは照会期間を補完しない',OrganizerPolicy::window(true,$deadline,$deadline,null)==='unconfirmed');
check('逆転したイベント期間を拒否',OrganizerPolicy::window(true,$deadline,$end,$deadline)==='unconfirmed');
check('期間外には資格2項目も返さない',!array_key_exists('member',OrganizerPolicy::display('expired',true,true)));
$latest = OrganizerPolicy::display('open',false,null);
check('照会では最新の無効と確認不能を区別',$latest['member']==='いいえ' && $latest['club']==='確認できません');
check('主催者へ注意し自動受付停止しない',$latest['caution'] && !$latest['auto_reject'] && $latest['basis']==='query_time');
check('現在の訂正でも申込時の権利を自動変更しない',SeriesRules::pointRight(true,'general',true,true)==='eligible');
check('現在の正会員クラブへの移籍後も申込時の権利継続',OrganizerPolicy::display('open',true,true)['club']==='はい' && SeriesRules::pointRight(true,'general',true,true)==='eligible');
check('最新両資格が有効なら注意なし',!OrganizerPolicy::display('open',true,true)['caution']);
