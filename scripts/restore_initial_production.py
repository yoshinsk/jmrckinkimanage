# Path: scripts/restore_initial_production.py
# 機能: 初回導入時の旧entryコードへ切戻す。DB・新規業務記録・暗号鍵は削除しない。
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import subprocess
import tarfile


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--backup',required=True)
    opt=parser.parse_args()
    assert os.geteuid()==0
    backup=Path(opt.backup).resolve()
    assert backup.parent==Path('/var/www/vhosts/gymkinki.com/private/jmrc-portal-backups')
    manifest=json.loads((backup/'manifest.json').read_text())
    assert hashlib.sha256((backup/'entry.tar.gz').read_bytes()).hexdigest()==manifest['backup_hashes']['entry.tar.gz']
    env=os.environ.copy();env['MYSQL_PWD']=Path('/etc/psa/.psa.shadow').read_text().strip()
    # 申請・入金開始後の機械的な切戻しを防ぎ、照合後の復旧へ切り替える。
    for table in ['portal_applications','portal_invoices','portal_payments']:
        q=f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='jmrckinki' AND table_name='{table}'"
        exists=subprocess.run(['mariadb','-uadmin','-N','-B','-e',q],env=env,capture_output=True,check=True).stdout.strip()==b'1'
        if exists:
            count=subprocess.run(['mariadb','-uadmin','-N','-B','-e',f'SELECT COUNT(*) FROM jmrckinki.{table}'],env=env,capture_output=True,check=True).stdout.strip()
            assert count==b'0','New business records exist; reconciliation required'
    entry=Path('/var/www/vhosts/gymkinki.com/httpdocs/entry')
    owner=pwd.getpwnam('gymkinki.com')
    with tarfile.open(backup/'entry.tar.gz','r:gz') as archive:
        for name in ['common/csrf.php','login.php','logout.php','register_handler.php','entrant/entries.php','common/auth.php']:
            body=archive.extractfile('entry/'+name).read()
            assert hashlib.sha256(body).hexdigest()==manifest['files'][name]
            target=entry/name;temp=target.with_name(target.name+'.restore-new')
            temp.write_bytes(body);os.chown(temp,owner.pw_uid,owner.pw_gid);os.chmod(temp,0o644)
            subprocess.run(['/opt/plesk/php/8.4/bin/php','-l',str(temp)],check=True,stdout=subprocess.DEVNULL)
            os.replace(temp,target)
    manage=entry.parent/'manage'
    if manage.exists():os.rename(manage,backup/'manage-disabled')
    # セッションを上書き・削除せず、旧認証でも同じuid/ridを利用する。
    print(json.dumps({'status':'CODE_RESTORED','backup':str(backup),'database_restored':False}))


if __name__=='__main__':main()
