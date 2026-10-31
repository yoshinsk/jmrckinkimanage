# Path: tests/information/validate_offline.py
# 機能: 固定カタログと配布版を照合し、ネットワークなしでHTML生成・実ブラウザー確認を行う。
import csv
import hashlib
import json
import subprocess
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def command(args):
    """エラーは成功証跡にしない。コンテナの通信・マウント範囲は各呼出しで固定する。"""
    result = subprocess.run(['wsl.exe', '-d', 'Ubuntu', '--', *args], capture_output=True, timeout=180)
    if result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace'))
    return result.stdout


def main():
    """本文を再生成し、元台帳の行・URL・年度を照合してから配布版を操作確認する。"""
    linux = command(['wslpath', '-a', ROOT.as_posix()]).decode().strip()
    target = ROOT/'Documents/previews/20261031_旧HP情報ページ.html'
    report_path = ROOT/'tmp/information_offline_validation.json'
    report_path.unlink(missing_ok=True)
    source_file = ROOT/'src/Content/information-catalog.php'
    catalog = json.loads(source_file.read_text(encoding='utf-8').split("<<<'CATALOG'\n",1)[1].split('\nCATALOG,',1)[0])
    csv_path = ROOT/'Documents'/catalog['source_file']
    assert hashlib.sha256(csv_path.read_bytes()).hexdigest() == catalog['source_sha256']
    rows = list(csv.DictReader(csv_path.open(encoding='utf-8-sig', newline='')))
    expected = [(r['HP_ID'],r['旧リンク表示'],r['移行元URL'],r['元年度候補']) for r in rows]
    actual = [(hp,r['title'],r['url'],r['year']) for hp,items in catalog['sources'].items() for r in items]
    assert expected == actual and len(actual) == 154
    content = command(['docker','run','--rm','--network','none','--read-only','--cap-drop','ALL',
                       '--security-opt','no-new-privileges','--mount',f'type=bind,source={linux},target=/project,readonly',
                       'jmrc-auth-php:20261027','php','/project/scripts/export_information_preview.php'])
    assert b'<!doctype html>' in content and b'<form' not in content and b'<script' not in content
    target.parent.mkdir(exist_ok=True)
    target.write_bytes(content)
    artifacts = ROOT/'tmp/information_offline'
    artifacts.mkdir(exist_ok=True)
    artifact_linux = command(['wslpath','-a',artifacts.as_posix()]).decode().strip()
    output = command(['docker','run','--rm','--init','--network','none','--memory','1536m','--shm-size','512m',
                      '--mount',f'type=bind,source={linux},target=/project,readonly',
                      '--mount',f'type=bind,source={linux}/tests/license/information_offline_browser.cjs,target=/lab/information_offline_browser.cjs,readonly',
                      '--mount',f'type=bind,source={artifact_linux},target=/artifacts',
                      'jmrc-license-browser:20261028','node','information_offline_browser.cjs'])
    report = json.loads(output)
    report.update(checked_at_utc=datetime.now(timezone.utc).isoformat(),source_records=154,source_ledger_sha256=catalog['source_sha256'],
                  artifact_sha256=hashlib.sha256(content).hexdigest(),artifact_bytes=len(content),production_changed=False)
    report_path.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'status':report['status'],'browser_cases':len(report['cases']),'source_records':154}))


if __name__=='__main__':
    main()
