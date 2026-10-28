# Path: integration/prepare_entry_billing.py
# 機能: 明示した隔離コピーのentryだけに会費判定アダプターを挿入する。本番へ自動適用しない。
from pathlib import Path

def prepare(entry: Path):
    target = entry / 'entrant/entries.php'
    source = target.read_text(encoding='utf-8')
    marker = '$annualPayStatus=$stView->fetch(PDO::FETCH_ASSOC);'
    # 既存コードの空白差を吸収し、未知の実装には適用を中止する。
    import re
    pattern = r'\$annualPayStatus\s*=\s*\$stView->fetch\(PDO::FETCH_ASSOC\);'
    if len(re.findall(pattern, source)) != 1:
        raise RuntimeError('Annual payment lookup changed; review required')
    source = re.sub(pattern, lambda m: m.group(0)+'\n        $annualPayStatus = portal_annual_payment_status($pdo, (int)$uid, (int)$eventYear, $annualPayStatus);', source)
    checkbox = 'name="jmrc_join_member" value="1"'
    if source.count(checkbox) != 1:
        raise RuntimeError('Membership checkbox changed; review required')
    source = source.replace(checkbox, checkbox+' <?= portal_billing_year($pdo,(int)$eventYear) ? \'disabled\' : \'\' ?>')
    source = source.replace('<label class="form-check-label" for="jmrc_join_member">', '<label class="form-check-label" for="jmrc_join_member"><?php if (portal_billing_year($pdo,(int)$eventYear)): ?><a href="/manage/billing.php">会費のお支払いは会員ポータルへ</a><?php endif; ?>')
    target.write_text(source, encoding='utf-8')
