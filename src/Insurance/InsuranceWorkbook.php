<?php
// Path: src/Insurance/InsuranceWorkbook.php
// 機能: ハッシュ固定の指定Excel様式に確認データだけを書込む。書式・結合・印刷設定を保持する。
declare(strict_types=1);
namespace Jmrc\Insurance;
use Jmrc\Auth\HttpFailure;
final class InsuranceWorkbook
{
    public const TEMPLATE_HASH='ec52243187269f4cb84a28a7fad445eb3b20eb667e2854ee46950d82d0a59613';
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** 実加入条件を含む元様式の金額・年齢区分・4名条件・記入例は、確認用では空欄/保留表示にする。 */
    public function build(string $template,array $club,array $members): string
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)
            || !is_file($template) || hash_file('sha256',$template)!==self::TEMPLATE_HASH) {
            throw new HttpFailure(503,'保険Excel様式・ZIP/XML拡張の設定を確認してください');
        }
        if(count($members)<1 || count($members)>24){throw new HttpFailure(422,'確認帳票は1回24名まで選択してください');}
        $path=tempnam(sys_get_temp_dir(),'jmrc-insurance-');if($path===false){throw new HttpFailure(503,'帳票を準備できません');}
        $zip=new \ZipArchive();$opened=false;
        try {
            if(!chmod($path,0600) || !copy($template,$path) || $zip->open($path)!==true){throw new HttpFailure(503,'帳票を準備できません');}
            $opened=true;
            $doc=$this->xml($zip->getFromName('xl/worksheets/sheet1.xml'));
            $values=['A1'=>'作成日 '.(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Tokyo')))->format('Y年n月j日'),
                'A4'=>'確認用・提出不可（保険料・期間未確定）','A7'=>'確認用・提出不可。General限定・初回クラブ4名。料率・保険期間は未確定です。',
                'E5'=>$club['name_full'],'T5'=>$club['display_code'],'E6'=>$club['contact_name'],'Q6'=>'',
                'A38'=>'加入区分・保険料：確認待ち','A39'=>''];
            // 旧固定料率と計算結果を残さない。保険期間が不明なため年齢も算出しない。
            foreach(['J38','J39','M38','M39','N38','N39','O38','O39','Q38','Q39','S38','S40','V40','D40'] as $cell){$values[$cell]='';}
            for($row=10;$row<=34;$row++){
                foreach(['B','C','G','M','N','Q','R'] as $col){$values[$col.$row]='';}
                if($row===10){$values['A10']='';}
            }
            foreach($members as $index=>$m){
                $row=11+$index;
                $values['C'.$row]=$m['name'];$values['G'.$row]=$m['kana'];$values['M'.$row]=$m['gender'];
                $values['N'.$row]=$m['birthday']?str_replace('-','.',$m['birthday']):'';$values['R'.$row]=$m['member_number'];
            }
            foreach($values as $cell=>$value){$this->cell($doc,$cell,(string)$value);}
            if(!$zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML())){throw new HttpFailure(503,'帳票を保存できません');}
            // 削除した旧計算式を指すcalcChainも整合させる。他のパーツは変更しない。
            foreach(['xl/_rels/workbook.xml.rels','[Content_Types].xml'] as $part){
                $xml=$this->xml($zip->getFromName($part));$remove=[];
                foreach($xml->documentElement->childNodes as $node){if($node instanceof \DOMElement && (str_contains($node->getAttribute('Type'),'/calcChain') || $node->getAttribute('PartName')==='/xl/calcChain.xml')){$remove[]=$node;}}
                foreach($remove as $node){$node->parentNode->removeChild($node);}
                if(!$zip->addFromString($part,$xml->saveXML())){throw new HttpFailure(503,'帳票を保存できません');}
            }
            $zip->deleteName('xl/calcChain.xml');if(!$zip->close()){throw new HttpFailure(503,'帳票を保存できません');}$opened=false;
            return file_get_contents($path)?:throw new HttpFailure(503,'帳票を読み込めません');
        } finally {if($opened){$zip->close();}if(is_file($path)){unlink($path);}}
    }
    /** 信頼済み固定様式のみを非ネットワークXMLとして解析する。外部エンティティは展開しない。 */
    private function xml(string|false $raw): \DOMDocument
    {
        $d=new \DOMDocument();if($raw===false || !$d->loadXML($raw,LIBXML_NONET)){throw new HttpFailure(503,'Excel様式を確認してください');}return $d;
    }
    /** 既存セルのstyleは保持。すべてinlineStrに固定し、=等で始まる個人情報を式にしない。 */
    private function cell(\DOMDocument $doc,string $ref,string $value): void
    {
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value) || preg_match('//u',$value)!==1){throw new HttpFailure(422,'帳票項目の文字を確認してください');}
        $x=new \DOMXPath($doc);$x->registerNamespace('s',self::NS);$c=$x->query('//s:c[@r="'.$ref.'"]')->item(0);
        if(!$c){throw new HttpFailure(503,'Excelの出力セルを確認してください');}
        while($c->firstChild){$c->removeChild($c->firstChild);}$c->setAttribute('t','inlineStr');
        $is=$doc->createElementNS(self::NS,'is');$t=$doc->createElementNS(self::NS,'t');$t->setAttributeNS('http://www.w3.org/XML/1998/namespace','xml:space','preserve');
        $t->appendChild($doc->createTextNode($value));$is->appendChild($t);$c->appendChild($is);
    }
}
