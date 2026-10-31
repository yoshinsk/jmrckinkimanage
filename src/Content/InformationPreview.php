<?php
// Path: src/Content/InformationPreview.php
// 機能: 旧HP引継ぎの確認専用カタログを絞り込み、認証画面と配布用HTMLに同じ内容を描画する。
declare(strict_types=1);
namespace Jmrc\Content;

use Jmrc\Auth\HttpFailure;

final class InformationPreview
{
    public const SECTIONS = ['information'=>'お知らせ', 'documents'=>'資料・制度の案内', 'calendar'=>'統一カレンダー', 'links'=>'部会・関連リンク', 'migration'=>'引継ぎ対象の確認'];
    public const CATEGORIES = ['office'=>'事務局', 'committee'=>'運営委員会', 'national'=>'全国JMRC', 'kinki'=>'JMRC近畿'];
    public const DIVISIONS = ['common'=>'共通', 'gym'=>'ジムカーナ', 'dirt'=>'ダートトライアル', 'rally'=>'ラリー', 'race'=>'レース', 'judge'=>'競技会審査委員会'];

    /** 公開原本のURLだけを含む固定の台帳。業務DB・保険帳票・アップロードを参照しない。 */
    public static function catalog(): array
    {
        return require __DIR__.'/information-catalog.php';
    }

    /** HTML属性・本文・URL表示へ同じエスケープを適用し、旧HTMLを実行しない。 */
    public static function escape(mixed $text): string
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 台帳に紛れたスクリプトURL・制御文字・認証情報をリンクとして配信しない。取得や転送は行わない。 */
    public static function sourceUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        return $url;
    }

    /** 配列・長過ぎる値・不正な選択肢を拒否する。表示フィルターを公開承認や権限として扱わない。 */
    public static function selection(array $query): array
    {
        $selected = [];
        foreach (['section'=>'information','category'=>'','division'=>'','year'=>'','q'=>'','item'=>'','kind'=>''] as $key=>$default) {
            $value = $query[$key] ?? $default;
            if (!is_string($value) || mb_strlen($value) > 100 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new HttpFailure(422, '検索条件を確認してください');
            }
            $selected[$key] = trim($value);
        }
        foreach (['section'=>self::SECTIONS,'category'=>self::CATEGORIES,'division'=>self::DIVISIONS,
                  'kind'=>['competition'=>'競技会','meeting'=>'会議','office'=>'事務局在席・休業']] as $key=>$options) {
            if (($selected[$key] !== '' || $key === 'section') && !isset($options[$selected[$key]])) {
                throw new HttpFailure(422, '検索条件を確認してください');
            }
        }
        if ($selected['year'] !== '' && !preg_match('/^(19|20)[0-9]{2}$/D', $selected['year'])) {
            throw new HttpFailure(422, '年度を確認してください');
        }
        $catalog = self::catalog();
        if ($selected['item'] !== '' && !isset($catalog['topics'][$selected['item']])) {
            throw new HttpFailure(404, '確認対象が見つかりません');
        }
        return $selected;
    }

    /** 元年度は台帳の候補であり、現年度の有効な規程・結果に読み替えない。 */
    public static function matching(array $selected, array $catalog): array
    {
        return array_filter($catalog['topics'], static function (array $topic, string $id) use ($selected, $catalog): bool {
            if ($selected['section'] !== 'migration' && !in_array($selected['section'], $topic['sections'], true)) { return false; }
            if ($selected['category'] !== '' && $selected['category'] !== $topic['category']) { return false; }
            if ($selected['division'] !== '' && !in_array($selected['division'], $topic['divisions'], true)) { return false; }
            $sources = $catalog['sources'][$id] ?? [];
            if ($selected['year'] !== '' && !in_array($selected['year'], array_column($sources, 'year'), true)) { return false; }
            $haystack = $id.' '.$topic['title'].' '.$topic['summary'].' '.implode(' ', array_column($sources, 'title'));
            return $selected['q'] === '' || mb_stripos($haystack, $selected['q']) !== false;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** カレンダーは固定の架空例のみ。更新元キーで一意にし、中止を消さずに表示する。 */
    public static function calendar(string $kind = ''): array
    {
        $rows = [
            ['key'=>'demo-competition-1','kind'=>'competition','title'=>'競技会の表示例（架空）','date'=>'2027-04-10','time'=>'終日','status'=>'予定の表示例','detail'=>'競技会の詳細は各部会HPへ案内します。実際の競技日程ではありません。'],
            ['key'=>'demo-meeting-1','kind'=>'meeting','title'=>'代表者会議の表示例（架空）','date'=>'2027-04-11','time'=>'13:00～15:00','status'=>'予定の表示例','detail'=>'案内・出欠受付の担当と公開範囲は確認待ちです。'],
            ['key'=>'demo-office-1','kind'=>'office','title'=>'事務局在席の表示例（架空）','date'=>'2027-04-12','time'=>'10:00～16:00','status'=>'予定の表示例','detail'=>'実際の在席時間ではありません。公開範囲はQ11で確認します。'],
            ['key'=>'demo-office-2','kind'=>'office','title'=>'事務局休業の表示例（架空）','date'=>'2027-04-13','time'=>'終日','status'=>'休業の表示例','detail'=>'実際の休業日ではありません。'],
            ['key'=>'demo-competition-2','kind'=>'competition','title'=>'中止した競技会の表示例（架空）','date'=>'2027-04-18','time'=>'終日','status'=>'中止の表示例','detail'=>'中止情報を残す場合の見え方です。申込や取消の処理は行いません。'],
        ];
        $unique = [];
        foreach ($rows as $row) { if ($kind === '' || $row['kind'] === $kind) { $unique[$row['key']] = $row; } }
        return array_values($unique);
    }

    /** サーバーではGETの確認画面、配布版では単一HTML内の区画へ移動する。外部の移行先は生成しない。 */
    public static function url(array $values, bool $offline): string
    {
        if ($offline) { return '#'.($values['item'] ?? ($values['section'] ?? 'information')); }
        return '/manage/information-preview.php'.($values ? '?'.http_build_query($values, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /** 画面と配布版の本文を共用する。配布版に認証情報・業務データ・送信フォームを含めない。 */
    public static function render(array $query = [], ?string $csrf = null, bool $offline = false): string
    {
        $selected = self::selection($query);
        $catalog = self::catalog();
        $e = self::escape(...);
        $url = static fn(array $values): string => self::url($values, $offline);
        ob_start();
        require __DIR__.'/information-template.php';
        return (string)ob_get_clean();
    }
}
