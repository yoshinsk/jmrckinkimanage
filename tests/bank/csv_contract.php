<?php
// Path: tests/bank/csv_contract.php
// 機能: 架空CSVで既存ゆうちょ解析の返り値・例外を固定し、共通化の前後で比較する。
declare(strict_types=1);
require_once __DIR__.'/../../src/Auth/HttpFailure.php';
require_once __DIR__.'/../../src/Bank/YuchoCsv.php';
$parser=new \Jmrc\Bank\YuchoCsv();$cases=0;
$check=static function(bool $ok)use(&$cases):void{if(!$ok){throw new RuntimeException('CSV contract failed at '.$cases);} $cases++;};
$csv="取扱日,受入金額,詳細２\r\n2026/10/1,2000,架空１２３\r\n2026/10/2,0,\r\n";
$expected=['rows'=>[['record_no'=>2,'date'=>'2026-10-01','amount'=>2000,'name'=>'架空１２３']],'ignored'=>1];
$check($parser->parse($csv,'UTF-8')===$expected);
$check($parser->parse(mb_convert_encoding($csv,'CP932','UTF-8'),'CP932')===$expected);
$check($parser->parse("\xEF\xBB\xBF".$csv,'UTF-8')===$expected);
$check($parser->parse("受入金額,詳細２\n\"2,000\",\"架空\"\"名\"\n",'UTF-8')['rows'][0]===['record_no'=>2,'date'=>null,'amount'=>2000,'name'=>'架空"名']);
$check(\Jmrc\Bank\YuchoCsv::name('架空１２３')==='架空123');
foreach([
 ["受入金額,詳細２\n2000,\"閉じない",'CSV引用符が閉じていません'],
 ["受入金額,詳細２\n2000,a\"b",'CSV引用符の位置が不正です'],
 ["受入金額,詳細２\n2000,\"a\"b",'CSV引用符の後に不正な文字があります'],
 ["受入金額,詳細２\n2000,架空\0",'CSVに制御文字があります'],
 ["取扱日,受入金額,詳細２\n2026/2/30,2000,架空",'取扱日の形式を確認してください（西暦年月日）'],
 ["受入金額,詳細２\n-1,架空",'受入金額を確認してください（レコード2）'],
 ["受入金額,詳細２,詳細2\n1,架空,架空",'CSVヘッダーが重複しています']
] as [$bad,$message]){
 try{$parser->parse($bad,'UTF-8');$check(false);}catch(\Jmrc\Auth\HttpFailure $e){$check($e->status===422&&$e->getMessage()===$message);}
}
echo json_encode(['status'=>'PASS','cases'=>$cases,'synthetic_only'=>true])."\n";
