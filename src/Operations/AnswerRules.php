<?php
// Path: src/Operations/AnswerRules.php
// 機能: 11月4日・6日の回答による保険年齢、3か月の起点、開催年、編集用分類を検証する。公開・削除・請求は行わない。
declare(strict_types=1);
namespace Jmrc\Operations;

final class AnswerRules
{
    public const CALENDAR=['unavailable'=>'不：事務局の対応不可日','gymkhana'=>'ジ：ジムカーナ','dirt'=>'ダ：ダートトライアル','rally'=>'ラ：ラリー','event_other'=>'そ：その他のイベント','jaf'=>'J：JAFイベント','other'=>'他：その他'];
    public const MAIL=['award'=>'表彰案内','affiliation_request'=>'所属申請','affiliation_approved'=>'所属承認','affiliation_rejected'=>'所属却下','affiliation_returned'=>'所属差戻し'];

    /** P02: 申請日現在の満年齢で判定。誕生日当日から新年齢とし、契約の始終期や加入成立日は作らない。 */
    public static function insurance(string $birthday,string $applicationDate): array
    {
        $birth=self::date($birthday);$day=self::date($applicationDate);
        if($birth>$day||$day->format('Y')!=='2027'){throw new \InvalidArgumentException('生年月日と2027年度の申請日を確認してください');}
        $age=$birth->diff($day)->y;
        return ['age'=>$age,'amount_yen'=>$age>=65?1200:2000,'basis'=>'application_date','application_date'=>$applicationDate,'coverage_period_managed'=>false,'can_enroll'=>false];
    }

    /** P16: 最終編集日時から暦で3か月。月末は3か月後の月末に丸め、閲覧や再送で延長しない。 */
    public static function draftExpires(\DateTimeImmutable $edited): \DateTimeImmutable
    {
        $local=$edited->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $target=$local->modify('first day of this month')->modify('+3 months');
        return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),min((int)$local->format('d'),(int)$target->format('t')))
            ->setTime((int)$local->format('H'),(int)$local->format('i'),(int)$local->format('s'),(int)$local->format('u'));
    }

    /** P05: 前年度中の翌年イベント申込を資格判定へ流用しない。会員・クラブの先行年度登録とは別。 */
    public static function entryYearAllowed(string $eventDate,\DateTimeImmutable $submitted): bool
    {
        return self::date($eventDate)->format('Y')===$submitted->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y');
    }

    public static function date(string $value): \DateTimeImmutable
    {
        if(!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)){throw new \InvalidArgumentException('日付をYYYY-MM-DD形式で確認してください');}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Asia/Tokyo'));
        if(!$date||$date->format('Y-m-d')!==$value){throw new \InvalidArgumentException('日付をYYYY-MM-DD形式で確認してください');}return $date;
    }

    /** PDFは表示用原本。自動計算する結果は、明示したUTF-8 CSVの整数得点と共通利用者IDだけを扱う。 */
    public static function results(string $csv): array
    {
        if(strlen($csv)>524288||!preg_match('//u',$csv)){throw new \InvalidArgumentException('結果は512KB以内のUTF-8 CSVを指定してください');}
        $f=fopen('php://temp','w+');fwrite($f,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($f);
        try{
            $head=fgetcsv($f,0,',','"','');
            if($head!==['event_id','class_code','entrant_id','position','points','starters']){throw new \InvalidArgumentException('CSVの見出しは event_id,class_code,entrant_id,position,points,starters です');}
            $rows=[];$seen=[];$positions=[];$event=null;$classes=[];
            while(($r=fgetcsv($f,0,',','"',''))!==false){
                if($r===[null]){continue;}
                if(count($r)!==6||count($rows)>=1000||!preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$r[1])){throw new \InvalidArgumentException('結果の列数・クラス・件数を確認してください');}
                foreach([0,2,3,4,5] as $i){if(!preg_match('/^(0|[1-9][0-9]{0,8})$/D',$r[$i])||(int)$r[$i]>1000000||($i!==4&&(int)$r[$i]<1)){throw new \InvalidArgumentException('結果には範囲内の整数を入力してください');}}
                if((int)$r[3]>(int)$r[5]){throw new \InvalidArgumentException('順位が出走台数を超えています');}
                $event??=(int)$r[0];$key=$r[1].':'.$r[2];
                if($event!==(int)$r[0]||isset($seen[$key])||(isset($classes[$r[1]])&&$classes[$r[1]]!==(int)$r[5])){throw new \InvalidArgumentException('1イベントのCSVにし、クラス内の同一選手を重複させず、出走台数を統一してください');}
                // P18: 同一大会・同一クラスの同順位は正式結果に存在しない前提。重複は入力訂正を求める。
                $position=$r[1].':'.$r[3];
                if(isset($positions[$position])){throw new \InvalidArgumentException('同じクラス内で順位が重複しています。正式結果を確認してください');}
                $positions[$position]=true;$seen[$key]=true;$classes[$r[1]]=(int)$r[5];
                $rows[]=['event_id'=>$event,'class_code'=>$r[1],'entrant_id'=>(int)$r[2],'position'=>(int)$r[3],'points'=>(int)$r[4],'starters'=>(int)$r[5]];
            }
            if(!$rows){throw new \InvalidArgumentException('結果がありません');}
            return ['event_id'=>$event,'rows'=>$rows,'replacement_key'=>'event-'.$event,'publish_enabled'=>false];
        }finally{fclose($f);}
    }
}
