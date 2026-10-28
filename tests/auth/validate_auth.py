# Path: tests/auth/validate_auth.py
# 機能: 既存jmrckinkiの必要なソースだけを隔離コピーし、HTTPS/FPM/MariaDBで認証を検証する。
# 本番接続や既存リポジトリの変更は行わない。実設定/実データをコピーしない。
import hashlib
import json
import shutil
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LEGACY = ROOT.parent / 'jmrckinki' / 'httpdocs' / 'entry'
NETWORK = 'jmrc-auth-internal-20261027'
LABEL = 'jmrc.auth.lab=true'
NAMES = ['jmrc-auth-web-20261027', 'jmrc-auth-app-20261027', 'jmrc-auth-db-20261027']
DB_IMAGE = 'mariadb:10.5.29@sha256:a530aeeefd82f4fa5150f391b6c75462140904780338766f6b03acecb1cca3ce'
SOURCE_FILES = ['common/header.php','common/footer.php','common/functions.php','common/db.php',
                'admin/events.php','club/events.php','entrant/events.php','entrant/entries.php','admin/entry_payment_check.php']


def command(args, data=None, timeout=60, check=True):
    result = subprocess.run(args, input=data, capture_output=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(result.stderr.decode(errors='replace')[-5000:])
    return result


def docker(*args, data=None, timeout=60, check=True):
    return command(['wsl.exe','-d','Ubuntu','--','docker',*args],data,timeout,check)


def db_sql(sql):
    return docker('exec','-i',NAMES[2],'mariadb','-uroot','-plab_root_only',
                  '--default-character-set=utf8mb4','jmrckinki_auth_lab',data=sql.encode())


def main():
    (ROOT/'tmp/auth_validation.json').unlink(missing_ok=True)
    context = ROOT/'tmp'/('auth_lab_'+str(time.time_ns()))
    context.mkdir(parents=True)
    for folder in ['site/entry/common','site/manage','src','tests']:
        (context/folder).mkdir(parents=True,exist_ok=True)
    sources = {}
    for relative in SOURCE_FILES:
        source = LEGACY/relative
        sources[relative] = hashlib.sha256(source.read_bytes()).hexdigest()
        destination = context/'site/entry'/relative
        destination.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(source,destination)
    # adapter差替え元もハッシュだけ記録。パスワード入りの既存configは一切読み込まない。
    for relative in ['login.php','logout.php','common/auth.php','common/csrf.php']:
        sources[relative] = hashlib.sha256((LEGACY/relative).read_bytes()).hexdigest()
    for source in (ROOT/'integration/jmrckinki').rglob('*.php'):
        shutil.copyfile(source,context/'site/entry'/source.relative_to(ROOT/'integration/jmrckinki'))
    import runpy
    runpy.run_path(str(ROOT/'integration/prepare_entry_billing.py'))['prepare'](context/'site/entry')
    for source in (ROOT/'public/manage').glob('*.php'):
        shutil.copyfile(source,context/'site/manage'/source.name)
    shutil.copytree(ROOT/'public/manage/assets',context/'site/manage/assets')
    for source in (ROOT/'src').rglob('*.php'):
        target=context/'src'/source.relative_to(ROOT/'src')
        target.parent.mkdir(parents=True,exist_ok=True)
        shutil.copyfile(source,target)
    for name in ['run_http.php','seed.php']:
        shutil.copyfile(ROOT/'tests/auth'/name,context/'tests'/name)
    for source in (ROOT/'tests/membership').glob('*.php'):
        shutil.copyfile(source,context/'tests'/source.name)
    for source in (ROOT/'tests/billing').glob('*.php'):
        shutil.copyfile(source,context/'tests'/source.name)
    for source in (ROOT/'tests/club').glob('*.php'):
        shutil.copyfile(source,context/'tests'/source.name)
    for source in (ROOT/'tests/card').glob('*.php'):
        shutil.copyfile(source,context/'tests'/source.name)
    shutil.copyfile(ROOT/'tests/auth/identity.php',context/'site/entry/identity.php')
    shutil.copyfile(ROOT/'tests/auth/identity.php',context/'site/manage/identity.php')
    shutil.copyfile(ROOT/'tests/auth/permission.php',context/'site/manage/permission.php')
    shutil.copyfile(ROOT/'tests/auth/legacy.config.php',context/'site/entry/common/config.php')
    for name in ['auth.config.php','nginx.conf','Dockerfile.php','Dockerfile.nginx']:
        shutil.copyfile(ROOT/'tests/auth'/name,context/name)
    linux=command(['wsl.exe','-d','Ubuntu','--','wslpath','-a',context.as_posix()]).stdout.decode().strip()
    # 検証専用証明書。実ドメインの秘密鍵・証明書は使わない。
    command(['wsl.exe','-d','Ubuntu','--','openssl','req','-x509','-newkey','rsa:2048','-nodes',
             '-keyout',linux+'/tls.key','-out',linux+'/tls.crt','-days','1',
             '-subj','/CN=web','-addext','subjectAltName=DNS:web'])
    print('Building isolated PHP-FPM and nginx images',flush=True)
    docker('build','-f',linux+'/Dockerfile.php','-t','jmrc-auth-php:20261027',linux,timeout=300)
    docker('build','-f',linux+'/Dockerfile.nginx','-t','jmrc-auth-nginx:20261027',linux,timeout=180)
    created=[]
    network_created=False
    try:
        docker('network','create','--internal','--label',LABEL,NETWORK)
        network_created=True
        docker('run','--detach','--name',NAMES[2],'--label',LABEL,'--network',NETWORK,'--network-alias','db',
               '--memory','768m','--cpus','1','--tmpfs','/var/lib/mysql:rw,size=512m',
               '-e','MARIADB_ROOT_PASSWORD=lab_root_only','-e','MARIADB_DATABASE=jmrckinki_auth_lab',DB_IMAGE)
        created.append(NAMES[2])
        for _ in range(60):
            logs=docker('logs',NAMES[2],check=False)
            ready=b'MariaDB init process done. Ready for start up.' in logs.stdout
            ping=docker('exec',NAMES[2],'mariadb-admin','-uroot','-plab_root_only','ping',check=False)
            if ready and ping.returncode==0:
                break
            time.sleep(0.5)
        else:
            raise RuntimeError('MariaDB startup timed out')
        db_sql((ROOT/'tests/phase2/legacy_schema.sql').read_text(encoding='utf-8'))
        db_sql((ROOT/'design/phase2/001_portal_schema.sql').read_text(encoding='utf-8'))
        db_sql((ROOT/'design/phase2/002_confirmed_membership_rules.sql').read_text(encoding='utf-8'))
        db_sql((ROOT/'design/phase2/003_membership_billing.sql').read_text(encoding='utf-8'))
        db_sql((ROOT/'design/phase2/004_club_registration.sql').read_text(encoding='utf-8'))
        db_sql((ROOT/'design/phase2/005_general_cards.sql').read_text(encoding='utf-8'))
        db_sql("CREATE USER 'lab_fixture'@'%' IDENTIFIED BY 'lab_fixture_only';"
               "GRANT ALL ON jmrckinki_auth_lab.* TO 'lab_fixture'@'%';"
               "CREATE USER 'auth_runtime'@'%' IDENTIFIED BY 'lab_runtime_only';"
               "GRANT SELECT ON jmrckinki_auth_lab.* TO 'auth_runtime'@'%';"
               "GRANT INSERT ON jmrckinki_auth_lab.portal_audit_events TO 'auth_runtime'@'%';"
               "GRANT INSERT ON jmrckinki_auth_lab.audit_logs TO 'auth_runtime'@'%';")
        # 申請/承認サービスに必要なDMLだけを追加。DDL・他人の識別番号更新は許可しない。
        for table in ['portal_applications','portal_memberships','portal_affiliations','portal_invoices','portal_invoice_lines','portal_membership_charges','portal_payments','portal_allocations','portal_club_years','portal_club_charges']:
            db_sql(f"GRANT INSERT,UPDATE ON jmrckinki_auth_lab.{table} TO 'auth_runtime'@'%';")
        db_sql("GRANT INSERT ON jmrckinki_auth_lab.portal_outbox TO 'auth_runtime'@'%';")
        for table in ['portal_member_numbers','portal_card_tokens']:
            db_sql(f"GRANT INSERT,UPDATE ON jmrckinki_auth_lab.{table} TO 'auth_runtime'@'%';")
        for name,alias,image in [(NAMES[1],'app','jmrc-auth-php:20261027'),(NAMES[0],'web','jmrc-auth-nginx:20261027')]:
            docker('run','--detach','--name',name,'--label',LABEL,'--network',NETWORK,'--network-alias',alias,
                   '--memory','256m','--cpus','1',image)
            created.append(name)
        print('Linting PHP and preparing synthetic identities',flush=True)
        lint=docker('exec',NAMES[1],'sh','-c',r"find /srv/auth /srv/web /srv/tests -name '*.php' -exec php -l {} \;")
        if b'Errors parsing' in lint.stdout or b'Fatal error' in lint.stderr:
            raise RuntimeError(lint.stdout.decode()+lint.stderr.decode())
        docker('exec',NAMES[1],'php','/srv/tests/seed.php')
        docker('exec',NAMES[1],'php','/srv/tests/membership_seed.php')
        docker('exec',NAMES[1],'php','/srv/tests/club_seed.php')
        db_sql((ROOT/'design/phase2/billing_permissions.sql').read_text(encoding='utf-8'))
        db_sql((ROOT/'design/phase2/club_permissions.sql').read_text(encoding='utf-8'))
        nginx=docker('exec',NAMES[0],'nginx','-t')
        assert b'successful' in nginx.stderr
        print('Testing login, logout and permission boundaries over HTTPS',flush=True)
        result=docker('exec','--user','www-data',NAMES[1],'php','/srv/tests/run_http.php',timeout=180)
        report=json.loads(result.stdout)
        qr_fixtures=report.pop('card_qr_fixtures')
        qr=command(['node',str(ROOT/'tests/card/verify_qr.cjs')],data=json.dumps(qr_fixtures).encode(),timeout=60)
        report['qr_client_validation']=json.loads(qr.stdout)
        report['checked_at_utc']=datetime.now(timezone.utc).isoformat()
        report['legacy_source_sha256']=sources
        report['legacy_commit']=command(['git','-C',str(LEGACY.parents[1]),'rev-parse','HEAD']).stdout.decode().strip()
        report['adapter_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest()
                                  for folder in ['src','integration/jmrckinki','public/manage']
                                  for p in (ROOT/folder).rglob('*.php')}
        report['membership_rule_migration_sha256']=hashlib.sha256((ROOT/'design/phase2/002_confirmed_membership_rules.sql').read_bytes()).hexdigest()
        report['billing_migration_sha256']=hashlib.sha256((ROOT/'design/phase2/003_membership_billing.sql').read_bytes()).hexdigest()
        report['club_migration_sha256']=hashlib.sha256((ROOT/'design/phase2/004_club_registration.sql').read_bytes()).hexdigest()
        report['card_migration_sha256']=hashlib.sha256((ROOT/'design/phase2/005_general_cards.sql').read_bytes()).hexdigest()
        report['card_test_source_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in (ROOT/'tests/card').iterdir() if p.is_file()}
        report['card_asset_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in (ROOT/'public/manage/assets').iterdir() if p.is_file()}
        report['club_permissions_sha256']=hashlib.sha256((ROOT/'design/phase2/club_permissions.sql').read_bytes()).hexdigest()
        report['club_test_source_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in (ROOT/'tests/club').glob('*.php')}
        report['billing_permissions_sha256']=hashlib.sha256((ROOT/'design/phase2/billing_permissions.sql').read_bytes()).hexdigest()
        report['entry_billing_prepare_sha256']=hashlib.sha256((ROOT/'integration/prepare_entry_billing.py').read_bytes()).hexdigest()
        report['billing_test_source_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in (ROOT/'tests/billing').glob('*.php')}
        report['membership_test_source_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest()
                                                 for p in (ROOT/'tests/membership').glob('*.php')}
        report['test_source_sha256']={str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest()
                                     for p in (ROOT/'tests/auth').iterdir() if p.is_file() and p.suffix!='.json'}
        report['php_lint_files']=lint.stdout.decode().count('No syntax errors detected')
        network=json.loads(docker('network','inspect',NETWORK).stdout)[0]
        assert network['Internal'] is True
        report['network_internal']=True
        for name in created:
            state=json.loads(docker('inspect',name).stdout)[0]
            assert not state['HostConfig']['PortBindings']
        report['published_ports']=[]
        report['fixture_only']=True
        target=ROOT/'tmp/auth_validation.json'
        target.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
        for relative,expected in sources.items():
            assert hashlib.sha256((LEGACY/relative).read_bytes()).hexdigest()==expected
        print(json.dumps({'status':report['status'],'cases':len(report['cases']),
                          'lint_files':report['php_lint_files'],'legacy_sources_unchanged':True},ensure_ascii=False),flush=True)
    except Exception:
        for name in created:
            logs=docker('logs','--tail','25',name,check=False)
            print(name+': '+logs.stderr.decode(errors='replace')[-3500:],flush=True)
        raise
    finally:
        for name in reversed(created):
            state=json.loads(docker('inspect',name).stdout)[0]
            assert state['Config']['Labels'].get('jmrc.auth.lab')=='true'
            docker('stop','--time','5',name)
            docker('rm',name)
        if network_created:
            docker('network','rm',NETWORK)


if __name__=='__main__':
    main()
