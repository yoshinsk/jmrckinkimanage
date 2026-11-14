<?php
// Path: src/Operations/AccountingWorkbook.php
// 機能: 確認用会計表をExcel形式へ出力する。文字列を数式に変えず、正式帳票と区別する。
declare(strict_types=1);
namespace Jmrc\Operations;
use Jmrc\Auth\HttpFailure;
final class AccountingWorkbook
{
    /** 固定のOOXML部品と型付きセルだけを出力。外部参照、マクロ、数式を含めない。 */
    public static function build(array $rows):string
    {
        if(!class_exists(\ZipArchive::class)){throw new HttpFailure(503,'Excel出力のZIP拡張を確認してください');}
        if(count($rows)>2010){throw new HttpFailure(422,'出力件数が上限を超えています');}
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach($rows as $i=>$row){
            if(count($row)>26){throw new HttpFailure(422,'出力列数が上限を超えています');}
            $xml.='<row r="'.($i+1).'">';
            foreach(array_values($row) as $col=>$v){
                $ref=chr(65+$col).($i+1);
                if(is_int($v)||is_float($v)){$xml.='<c r="'.$ref.'"><v>'.$v.'</v></c>';}
                else{
                    $s=(string)($v??'');if(preg_match('//u',$s)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$s)){throw new HttpFailure(422,'出力する文字を確認してください');}
                    $xml.='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
                }
            }$xml.='</row>';
        }$xml.='</sheetData></worksheet>';
        $parts=[
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="確認用・正式帳票ではありません" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml'=>$xml,
        ];
        $file=tempnam(sys_get_temp_dir(),'jmrc-accounting-');if($file===false){throw new HttpFailure(503,'Excel出力を準備できません');}
        $zip=new \ZipArchive();$open=false;
        try{
            if(!chmod($file,0600)||$zip->open($file,\ZipArchive::OVERWRITE)!==true){throw new HttpFailure(503,'Excel出力を準備できません');}$open=true;
            foreach($parts as $name=>$part){if(!$zip->addFromString($name,$part)){throw new HttpFailure(503,'Excel出力を保存できません');}}
            if(!$zip->close()){throw new HttpFailure(503,'Excel出力を保存できません');}$open=false;
            return file_get_contents($file)?:throw new HttpFailure(503,'Excel出力を読み込めません');
        }finally{if($open){$zip->close();}if(is_file($file)){unlink($file);}}
    }
}
