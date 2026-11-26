# Path: scripts/operator_lab/reset.py
# 機能: 固定した検証DBだけを既知の初期状態へ戻す。技術担当のCLI専用で、本番DB名は受け付けない。
from pathlib import Path
from datetime import datetime,timezone
import argparse,hashlib,json,os,subprocess
DOMAIN='portal-test.gymkinki.com';DB='jmrc_portal_operator_lab'
PRIVATE=Path('/var/www/vhosts/portal-test.gymkinki.com/private/operator-lab')

def validate(marker,confirm):
    if confirm!=DOMAIN or marker.get('domain')!=DOMAIN or marker.get('database')!=DB or marker.get('kind')!='jmrc-operator-lab':raise ValueError('Dedicated lab marker and confirmation required')

def reset(confirm):
    if os.geteuid()!=0:raise PermissionError('root CLI only')
    if PRIVATE.resolve()!=PRIVATE or PRIVATE.is_symlink():raise ValueError('Invalid private path')
    marker=json.loads((PRIVATE/'operator-lab.json').read_text());validate(marker,confirm)
    original=(PRIVATE/'baseline.sql').read_bytes()
    if hashlib.sha256(original).hexdigest()!=marker.get('baseline_sha256'):raise ValueError('Baseline changed')
    env=os.environ.copy();env['MYSQL_PWD']=Path('/etc/psa/.psa.shadow').read_text().strip()
    saved=subprocess.run(['mariadb-dump','-uadmin','--single-transaction','--skip-lock-tables',DB],env=env,capture_output=True,check=True).stdout
    backup=PRIVATE/'practice-backups';backup.mkdir(mode=0o700,exist_ok=True)
    target=backup/(datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')+'.sql');target.write_bytes(saved);target.chmod(0o600)
    # DB名を引数で受け取らず固定する。初期化前の練習履歴も非公開で保存する。
    subprocess.run(['mariadb','-uadmin'],input=(f'DROP DATABASE `{DB}`;CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;USE `{DB}`;\n').encode()+original,env=env,capture_output=True,check=True)
    # 古いセッションだけを失効させ、他サイトや任意パスのファイルは列挙しない。
    sessions=PRIVATE/'sessions'
    if sessions.resolve()!=sessions or sessions.is_symlink():raise ValueError('Invalid session path')
    for p in sessions.iterdir():
        if p.is_file() and not p.is_symlink() and p.name.startswith('sess_'):p.unlink()
    print(json.dumps({'status':'PASS','database':DB,'prior_practice_backup':str(target),'production_changed':False}))

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--confirm',required=True);a=parser.parse_args();reset(a.confirm)
