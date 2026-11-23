<?php
// Path: tests/license/types_policy.php
// 機能: 指定選択肢・複数種別・日付・共通整形を純粋関数で検証。DB・ファイル更新・外部送信は行わない。
declare(strict_types=1);
$cases=[];$check=static function($name,$ok)use(&$cases){if(!$ok){throw new RuntimeException($name);}$cases[]=$name;};
$bad=static function($name,callable $fn)use($check){$caught=false;try{$fn();}catch(\Jmrc\Auth\HttpFailure $e){$caught=$e->status===422;}$check($name,$caught);};
$competition=['国際スーパー','国際A','国際B','国際C-C','国際C-R','国際ドラッグ','国際ソーラーカー','国内A','国内B'];
$official=['コースA1','コースA2','コースB1','コースB2','コースB3','計時A1','計時A2','計時B1','計時B2','計時B3','技術A1','技術A2','技術B1','技術B2','技術B3'];
$check('競技9種は運営指定と一致',\Jmrc\License\LicenseTypes::options('jaf_competition')===$competition);
$check('審判員15種は運営指定と一致',\Jmrc\License\LicenseTypes::options('jaf_official')===$official);
$check('全審判員種別と並びを保持',\Jmrc\License\LicenseTypes::validate('jaf_official',array_reverse($official))===$official);
// ハイフンは共通整形の対象に含まれないため、全角ハイフンを勝手に同一視しない。
$bad('全角ハイフンを暗黙変換しない',fn()=>\Jmrc\License\LicenseTypes::validate('jaf_competition',['国際Ｃ－Ｃ']));
$check('英数だけを半角整形',\Jmrc\License\LicenseTypes::validate('jaf_competition',['国内Ｂ','国際Ｃ-C'])===['国際C-C','国内B']);
foreach([null,[],['なし'],['コースA1'],['国内B','国内B'],[['国内B']],['<script>'],['国内Ⅲ'],['x'=>'国内B']] as $n=>$value){$bad('未指定・自由記載・重複・構造不正'.$n,fn()=>\Jmrc\License\LicenseTypes::validate('jaf_competition',$value));}
$check('ローマ数字は共通整形で保持',\Jmrc\Input\TextInput::normalize('Ａ　ⅠⅢ')==='A ⅠⅢ');
$check('不明の発行日はnull',\Jmrc\License\LicenseTypes::issuedOn(null)===null&&\Jmrc\License\LicenseTypes::issuedOn('')===null);
foreach([null,'','　'] as $n=>$value){$bad('J01 新しい保存・申請の発行日は必須'.$n,fn()=>\Jmrc\License\LicenseTypes::requiredIssuedOn($value));}
$check('J01 翌年の券面発行日も自己申告どおり',\Jmrc\License\LicenseTypes::requiredIssuedOn('2027-01-01')==='2027-01-01');
$check('閏日と全角数字の日付',\Jmrc\License\LicenseTypes::issuedOn('２０２４-０２-２９')==='2024-02-29');
foreach(['2023-02-29','2026-13-01','2026-11-00','2026-1-1','2026-01-01T00:00:00','0000-01-01',['2026-01-01']] as $n=>$value){$bad('発行日の形式・暦日不正'.$n,fn()=>\Jmrc\License\LicenseTypes::issuedOn($value));}
$check('JAF登録名は半角整形しローマ数字を保持',\Jmrc\License\LicenseTypes::registeredName('ＪＯＨＮ　Ⅲ')==='JOHN Ⅲ');
$check('JAF登録名のカタカナを保持',\Jmrc\License\LicenseTypes::registeredName('カクニン タロウ')==='カクニン タロウ');
$check('旧登録名を推定しない',\Jmrc\License\LicenseTypes::registeredName(null)===null);
foreach([[],['登録名'],'確認 太郎',"NAME\nOTHER",str_repeat('A',129),'=SUM(A)','---'] as $n=>$value){$bad('JAF登録名の構造・文字・長さ'.$n,fn()=>\Jmrc\License\LicenseTypes::registeredName($value));}
echo json_encode(['status'=>'PASS','cases'=>$cases,'database_connections'=>0,'files_written'=>0]);
