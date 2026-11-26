<?php
// Path: src/Club/ClubRoster.php
// 機能: 共有済み2026年度名簿を既存候補へ読取専用で重ねる。旧名称・コード・ID・所属権限は変更しない。
declare(strict_types=1);
namespace Jmrc\Club;

final class ClubRoster
{
    /** 2026年5月12日現在の参考名簿。現在年度の資格や本人アカウントの証明には使わない。 */
    public static function rows():array{return require __DIR__.'/roster2026.php';}

    public static function find(string $code):?array
    {
        foreach(self::rows() as $row){if($row['code']===$code){return $row;}}return null;
    }

    /** 一意なコードだけで表示を補助する。類似名称による結合・削除・旧履歴の修正はしない。 */
    public static function candidates(array $existing):array
    {
        $roster=self::rows();$used=[];$out=[];
        foreach($existing as $item){
            $reference=str_starts_with($item['ref'],'portal:')?($item['reference_code']??''):$item['code'];
            // 固定5桁が同じでも別クラブの場合がある。旧台帳との明示対応による6桁だけで照合する。
            $matches=array_values(array_filter($roster,static fn($r)=>$r['code']===$reference));
            if(count($matches)===1){
                $r=$matches[0];$used[$r['code']]=true;
                $peers=array_filter($existing,static fn($p)=>$p['code']===$item['code']);
                // 同一コードの複数レコードは、どれが正しいかを勝手に決めない。
                if(count($peers)===1){$item['_search_aliases']=$item['name'].' '.$item['short_name'];$item['name']=$r['name'];$item['short_name']=$r['short_name'];$item['roster_code']=$r['code'];$item['source_year']=2026;}
            }
            $out[]=$item;
        }
        foreach($roster as $r){if(!isset($used[$r['code']])){$out[]=['ref'=>'roster2026:'.$r['code'],'name'=>$r['name'],'short_name'=>$r['short_name'],'code'=>$r['code'],'roster_code'=>$r['code'],'source_year'=>2026];}}
        return $out;
    }
}
