# Path: tests/club/validate_followup_database.py
# 機能: 専用内部ネットワーク・使い捨てDBでクラブ受付DDLの復元試験を実行。本番には接続しない。
from pathlib import Path
import json,subprocess,time,hashlib
root=Path(__file__).resolve().parents[2]
network='jmrc-followup-ddl-20261118';name=network+'-db';label='jmrc.club.ddl.lab=true'
sources=['scripts/ClubFollowupDatabase.php','design/phase2/014_club_review_closure.sql','tests/club/followup_database.php']
hashes={n:hashlib.sha256((root/n).read_bytes()).hexdigest() for n in sources}
def docker(*args,check=True):return subprocess.run(['wsl.exe','-d','Ubuntu','--','docker',*args],capture_output=True,check=check)
docker('network','create','--internal','--label',label,network)
try:
 docker('run','-d','--name',name,'--label',label,'--network',network,'--network-alias','db','--tmpfs','/var/lib/mysql:rw,size=512m','--memory','768m','-e','MARIADB_ROOT_PASSWORD=lab_club_root_only','mariadb:10.5.29@sha256:a530aeeefd82f4fa5150f391b6c75462140904780338766f6b03acecb1cca3ce')
 for i in range(80):
  if docker('exec',name,'mariadb-admin','--protocol=tcp','-h127.0.0.1','-uroot','-plab_club_root_only','ping',check=False).returncode==0:break
  time.sleep(.5)
 else:raise RuntimeError('Synthetic database not ready')
 mount=subprocess.check_output(['wsl.exe','-d','Ubuntu','--','wslpath','-a',root.as_posix()]).decode().strip()
 result=docker('run','--rm','--network',network,'-v',mount+':/srv/auth:ro','jmrc-auth-php:20261027','php','/srv/auth/tests/club/followup_database.php',check=False)
 if result.returncode:raise RuntimeError(result.stderr.decode(errors='replace'))
 report=json.loads(result.stdout);assert report['status']=='PASS'
 assert hashes=={n:hashlib.sha256((root/n).read_bytes()).hexdigest() for n in sources},'DDL sources changed during validation'
 report['source_sha256']=hashes
 (root/'tmp/check_20261118/database_validation.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'status':'PASS','cases':len(report['cases']),'fixture_only':True}))
finally:
 state=docker('inspect',name,check=False)
 if state.returncode==0:
  assert json.loads(state.stdout)[0]['Config']['Labels'].get('jmrc.club.ddl.lab')=='true'
  docker('rm','-f',name)
 docker('network','rm',network)
