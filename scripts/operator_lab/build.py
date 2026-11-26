# Path: scripts/operator_lab/build.py
# 機能: 実設定・個人情報・Git履歴を含めず、クラブ操作練習用の限定配布物を生成する。
from pathlib import Path
import argparse,hashlib,json,shutil,subprocess,tarfile

ROOT=Path(__file__).resolve().parents[2]
ENTRY=['login.php','logout.php','common/header.php','common/footer.php','common/functions.php','common/db.php','assets/app.css','assets/validation.js']

def build(output:Path):
    """新しい出力先だけにコピーする。既存の環境・本番の設定を読み取らない。"""
    output=output.resolve()
    if output.exists():raise ValueError('出力先は未作成のディレクトリを指定してください')
    output.mkdir(parents=True)
    legacy=ROOT.parent/'jmrckinki/httpdocs/entry';sources={}
    def copy(source,target):
        target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,target)
        sources[str(source.relative_to(ROOT)) if source.is_relative_to(ROOT) else 'jmrckinki/'+str(source.relative_to(legacy))]=hashlib.sha256(source.read_bytes()).hexdigest()
    for name in ENTRY:copy(legacy/name,output/'site/entry'/name)
    for p in (ROOT/'integration/jmrckinki').rglob('*.php'):copy(p,output/'site/entry'/p.relative_to(ROOT/'integration/jmrckinki'))
    for folder,target in [('public/manage','site/manage'),('src','src')]:
        for p in (ROOT/folder).rglob('*'):
            if p.is_file() and p.suffix in ['.php','.js','.css']:copy(p,output/target/p.relative_to(ROOT/folder))
    # 認証は同じ実装を使い、メール・実設定・登録/パスワード再発行の公開経路は持ち込まない。
    (output/'site/entry/common/mailer.php').write_text('<?php\nfunction send_mail(...$args):bool{return false;}\n',encoding='utf8')
    copy(ROOT/'tests/phase2/legacy_schema.sql',output/'schema/legacy.sql')
    for p in sorted((ROOT/'design/phase2').glob('*.sql')):
        if p.name[:3] in {f'{n:03}' for n in range(1,19)} and '.down.' not in p.name:copy(p,output/'schema'/p.name)
    for name in ['billing_permissions.sql','club_permissions.sql','profile_permissions.sql']:copy(ROOT/'design/phase2'/name,output/'schema'/name)
    for name in ['seed.php','guard.php','index.php','install.py','reset.py']:copy(ROOT/'scripts/operator_lab'/name,output/name)
    copy(ROOT/'scripts/operator_lab/lab.css',output/'site/lab.css')
    copy(ROOT/'scripts/operator_lab/lab.htaccess',output/'site/.htaccess')
    # 共有名簿の実在クラブも候補へ出さず、専用DBの架空2クラブだけで練習する。
    (output/'src/Club/roster2026.php').write_text('<?php\n// Operator lab: synthetic candidates only.\nreturn [];\n',encoding='utf8')
    manifest={'kind':'jmrc-operator-lab','production_data':False,'commit':subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip(),'sources':sources,'overrides':{'src/Club/roster2026.php':'実在する共有名簿を空にし、専用DBの架空候補だけを使用'}}
    (output/'manifest.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf8')
    with tarfile.open(str(output)+'.tar.gz','w:gz') as tar:
        for p in sorted(output.rglob('*')):
            if p.is_file():tar.add(p,arcname=str(p.relative_to(output)),recursive=False)
    return manifest

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('output',type=Path);a=parser.parse_args();m=build(a.output)
    print(json.dumps({'files':len(m['sources']),'production_data':False,'archive':str(a.output)+'.tar.gz'}))
