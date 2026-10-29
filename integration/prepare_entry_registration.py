# Path: integration/prepare_entry_registration.py
# 機能: 既存登録コードの確認済み箇所だけに共通認証状態と再登録保護を追加。元リポジトリには書き込まない。
from pathlib import Path


def prepare(entry: Path):
    target = entry / 'register_handler.php'
    text = target.read_text(encoding='utf-8-sig')
    markers = ["require_once __DIR__ . '/common/auth.php';",
               '    $pdo->beginTransaction();',
               '    // 2. プロフィールテーブル (INSERT)']
    if any(text.count(m) != 1 for m in markers):
        raise RuntimeError('Registration code changed; review required')
    text = text.replace(markers[0], markers[0]+"\nrequire_once __DIR__ . '/common/portal_registration.php';")
    text = text.replace(markers[1], markers[1]+"\n    if ($isReRegistration) { portal_guard_reregistration($pdo,$userId); }")
    text = text.replace(markers[2], '    portal_registration_control($pdo,$userId,$isReRegistration);\n\n'+markers[2])
    target.write_text(text, encoding='utf-8')
