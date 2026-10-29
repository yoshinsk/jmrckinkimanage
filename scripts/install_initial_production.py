# Path: scripts/install_initial_production.py
# 機能: 復元試験済みバックアップと照合して初回本番配置。秘密は本番内で生成し、既存認証情報を保持する。
import argparse
import base64
import datetime
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import sys


def main():
    args = argparse.ArgumentParser()
    args.add_argument('--stage', required=True)
    args.add_argument('--backup', required=True)
    args.add_argument('--release', required=True)
    args.add_argument('--office-email', required=True)
    opt = args.parse_args()
    assert os.geteuid() == 0
    assert re.fullmatch(r'[a-f0-9]{40}', opt.release)
    stage = Path(opt.stage).resolve()
    backup = Path(opt.backup).resolve()
    assert stage.parent == Path('/home/alma') and stage.name.startswith('jmrc_portal_release_')
    assert backup.parent == Path('/var/www/vhosts/gymkinki.com/private/jmrc-portal-backups')
    manifest = json.loads((backup/'manifest.json').read_text())
    assert manifest['restore_test']['status'] == 'PASS'
    entry = Path('/var/www/vhosts/gymkinki.com/httpdocs/entry')
    public = entry.parent/'manage'
    private = Path('/var/www/vhosts/gymkinki.com/private/jmrc-portal')
    assert not public.exists() and not public.is_symlink() and not private.exists()
    for name, expected in manifest['files'].items():
        assert hashlib.sha256((entry/name).read_bytes()).hexdigest() == expected, 'Production source drift: '+name
    payload = json.loads((stage/'payload.json').read_text())
    for name, expected in payload.items():
        path = (stage/name).resolve()
        assert path.is_relative_to(stage) and path.is_file()
        assert hashlib.sha256(path.read_bytes()).hexdigest() == expected, 'Staged payload mismatch'
    php = '/opt/plesk/php/8.4/bin/php'
    for folder in ['app', 'entry']:
        for path in (stage/folder).rglob('*.php'):
            subprocess.run([php, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
    env = os.environ.copy()
    env['MYSQL_PWD'] = Path('/etc/psa/.psa.shadow').read_text().strip()

    def sql(text, database='jmrckinki'):
        r = subprocess.run(['mariadb','-uadmin','-N','-B','--database='+database], input=text.encode(), env=env, capture_output=True)
        if r.returncode:
            (backup/'install-database-error.txt').write_bytes(r.stderr)
            raise RuntimeError('Database operation failed; private diagnostic saved')
        return r.stdout.decode().strip()

    assert sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='jmrckinki' AND table_name LIKE 'portal_%'") == '0'
    assert re.fullmatch(r'[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+',opt.office_email)
    office=sql("SELECT id,email_verified FROM users WHERE email='"+opt.office_email+"'").split('\t')
    assert len(office)==2 and office[1]=='1','Verified office account required'
    office_id=int(office[0])
    private.mkdir(mode=0o700)
    release = private/'releases'/opt.release
    release.parent.mkdir(mode=0o750)
    shutil.copytree(stage/'app', release)
    for path in (release/'src').rglob('*.php'):
        assert not path.is_symlink()
    state = {'release':opt.release, 'backup':str(backup), 'phase':'prepared', 'entry_files':[]}

    def record(phase):
        state['phase'] = phase
        (backup/'deployment.json').write_text(json.dumps(state, indent=2))
        os.chmod(backup/'deployment.json', 0o600)
        print(phase, flush=True)

    record('prepared')
    for path in sorted((stage/'ddl').glob('[0-9][0-9][0-9]_*.sql')):
        sql('SET SESSION lock_wait_timeout=5;\n'+path.read_text())
        record('schema:'+path.name)
    sql("INSERT INTO portal_roles(code,name) VALUES('OFFICE','事務局'),('FINANCE','財務委員'),('CLUB_REP','クラブ代表');")
    for path in sorted((stage/'ddl').glob('*_permissions.sql')):
        sql(path.read_text())
    sql("INSERT IGNORE INTO portal_account_controls(user_id) SELECT id FROM users;")
    sql(f"INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,status,valid_from,granted_by,approved_by) SELECT {office_id},id,'global','active',UTC_TIMESTAMP(6),{office_id},{office_id} FROM portal_roles WHERE code='OFFICE';")
    sql(f"INSERT INTO portal_audit_events(actor_user_id,action,entity_type,entity_id,request_id,changes_redacted,occurred_at) VALUES({office_id},'INITIAL_OFFICE_AUTHORIZED','account',{office_id},UUID(),'{{}}',UTC_TIMESTAMP(6));")
    state['office_user_id']=office_id
    sql("""INSERT IGNORE INTO portal_profiles(user_id,last_name,first_name,last_kana,first_kana,birthday,gender,postcode,address,phone,mobile)
        SELECT user_id,last_name,first_name,last_kana,first_kana,birthday,gender,postcode,address,phone,contact_mobile FROM entrants;""")
    sql((stage/'calendar.sql').read_text())
    import secrets
    password = secrets.token_hex(32)
    sql("CREATE USER 'jmrc_portal'@'127.0.0.1' IDENTIFIED BY '"+password+"'; GRANT SELECT ON jmrckinki.* TO 'jmrc_portal'@'127.0.0.1';")
    immutable = {'portal_audit_events','portal_profile_changes','portal_bank_master_changes','portal_identifier_revisions','portal_license_migrations','portal_insurance_exports','portal_insurance_export_items','portal_outbox','portal_yucho_import_rows'}
    mutable = {'portal_applications','portal_memberships','portal_affiliations','portal_invoices','portal_invoice_lines','portal_membership_charges','portal_payments','portal_allocations','portal_club_years','portal_club_charges','portal_member_numbers','portal_card_tokens','portal_identifiers','portal_license_claims','portal_insurance_requests','portal_bank_imports','portal_yucho_rows','portal_bank_accounts','portal_bank_source_accounts'}
    for table in immutable | mutable:
        rights = 'INSERT' if table in immutable else 'INSERT,UPDATE'
        sql(f"GRANT {rights} ON jmrckinki.{table} TO 'jmrc_portal'@'127.0.0.1';")
    sql("GRANT DELETE ON jmrckinki.portal_bank_source_accounts TO 'jmrc_portal'@'127.0.0.1';")
    sql("GRANT UPDATE(last_name,first_name,birthday,row_version) ON jmrckinki.portal_profiles TO 'jmrc_portal'@'127.0.0.1';")
    sql("GRANT INSERT,UPDATE(display_name,enabled,row_version,updated_by,updated_at,dedup_key_check) ON jmrckinki.portal_bank_sources TO 'jmrc_portal'@'127.0.0.1';")
    until = (datetime.datetime.now(datetime.timezone.utc)+datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M:%SZ')
    config = {'deployment_notice':'初回導入の確認中です。会員登録・保険・入金の受付は開始していません。','db':{'dsn':'mysql:host=127.0.0.1;port=3306;dbname=jmrckinki;charset=utf8mb4','user':'jmrc_portal','password':password},
              'session':{'name':'PHPSESSID','save_path':str(entry/'storage/sessions'),'lifetime':2592000,'secure':True,'legacy_session_until':until},
              'data_key':base64.b64encode(secrets.token_bytes(32)).decode(),
              'card_public_url':'https://gymkinki.com/manage/card-check.php',
              'profile_corrections_enabled':True,'bank_master_enabled':True,'yucho_import_enabled':False,
              'insurance_intents_enabled':False,'license_self_service_enabled':False,
              'insurance_template':str(private/'current/templates/insurance/sports.security.insurance_form_club.xlsx')}
    encoded = base64.b64encode(json.dumps(config).encode()).decode()
    (private/'auth.config.php').write_text("<?php\n// 本番非公開設定。リポジトリ外で生成。\nreturn json_decode(base64_decode('"+encoded+"'),true,512,JSON_THROW_ON_ERROR);\n")
    shutil.copyfile(private/'auth.config.php', backup/'portal-auth.config.php')
    os.chmod(backup/'portal-auth.config.php', 0o600)
    owner = pwd.getpwnam('gymkinki.com')
    for path in [private, *private.rglob('*')]:
        os.chown(path, owner.pw_uid, owner.pw_gid)
        os.chmod(path, 0o750 if path.is_dir() else 0o640)
    os.chmod(private/'auth.config.php', 0o600)
    os.symlink(release, private/'current')
    os.lchown(private/'current',owner.pw_uid,owner.pw_gid)
    record('private-ready')
    # 復旧対象を先に記録。追加helperは旧認証に影響しない。
    names=['common/portal_billing.php','common/portal_registration.php','register_handler.php','entrant/entries.php','common/auth.php','common/csrf.php','login.php','logout.php']
    for name in names:
        src = stage/'entry'/name
        dest = entry/name
        if dest.exists():
            assert hashlib.sha256(dest.read_bytes()).hexdigest() == manifest['files'][name]
        state['entry_files'].append(name)
        record('installing:'+name)
        # 新規登録のhookを配置した後にもう一度追随し、移行途中の登録を取りこぼさない。
        if name == 'common/auth.php':
            sql('INSERT IGNORE INTO portal_account_controls(user_id) SELECT id FROM users;')
            os.chmod(entry/'storage/sessions', 0o700)
        temp = dest.with_name(dest.name+'.portal-new')
        shutil.copyfile(src, temp)
        os.chown(temp, owner.pw_uid, owner.pw_gid)
        os.chmod(temp, 0o644)
        os.replace(temp, dest)
    webstage=entry.parent/('.manage-new-'+opt.release)
    shutil.copytree(release/'public/manage',webstage)
    for path in [webstage,*webstage.rglob('*')]:
        os.chown(path,owner.pw_uid,owner.pw_gid)
        os.chmod(path,0o755 if path.is_dir() else 0o644)
    os.replace(webstage,public)
    sql('INSERT IGNORE INTO portal_account_controls(user_id) SELECT id FROM users;')
    for name in ['common/config.php','common/db.php','verify.php','reset_handler.php']:
        assert hashlib.sha256((entry/name).read_bytes()).hexdigest() == manifest['files'][name]
    state['unchanged_user_snapshot'] = sql("SET SESSION group_concat_max_len=1048576; SELECT COUNT(*),SUM(email_verified=1),SHA2(GROUP_CONCAT(CONCAT(id,':',role_id,':',email_verified,':',password_hash) ORDER BY id SEPARATOR '|'),256) FROM users")
    state['user_snapshot_matches_backup'] = state['unchanged_user_snapshot'] == manifest['users_baseline']
    state['legacy_session_until'] = until
    record('installed-awaiting-http-verification')
    print(json.dumps(state), flush=True)


if __name__ == '__main__':
    main()
