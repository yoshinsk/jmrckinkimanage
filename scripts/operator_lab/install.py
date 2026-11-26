# Path: scripts/operator_lab/install.py
# 機能: 固定した新規の検証サイト・OSユーザー・DBへだけ配置する。本番サイトや実DBは変更しない。
from pathlib import Path
import argparse,base64,hashlib,json,os,pwd,secrets,shutil,subprocess

DOMAIN='portal-test.gymkinki.com';DB='jmrc_portal_operator_lab';USER='jmrc_operator_lab';ROOT=Path('/var/www/vhosts')/DOMAIN
PHP='/opt/plesk/php/8.4/bin/php'

def run(args,env=None,data=None):
    p=subprocess.run(args,input=data,capture_output=True,env=env)
    if p.returncode:raise RuntimeError('Command failed: '+args[0]+' '+args[1]+' '+p.stderr.decode(errors='replace')[-500:])
    return p.stdout

def validate_root(root:Path=ROOT,database:str=DB):
    """書込み先は定数の専用ホスト・専用DBだけ。symlinkや本番パスへのすり替えを拒否する。"""
    if root!=Path('/var/www/vhosts/portal-test.gymkinki.com') or database!='jmrc_portal_operator_lab':raise ValueError('Invalid lab target')
    if root.exists() and (root.is_symlink() or root.resolve()!=root):raise ValueError('Invalid lab path')

def install(bundle:Path):
    validate_root();bundle=bundle.resolve();manifest=json.loads((bundle/'manifest.json').read_text())
    if manifest.get('kind')!='jmrc-operator-lab' or manifest.get('production_data') is not False:raise ValueError('Invalid bundle')
    if ROOT.exists():raise RuntimeError('検証サイトは存在します。再作成や上書きは行いません')
    env=os.environ.copy();env['MYSQL_PWD']=Path('/etc/psa/.psa.shadow').read_text().strip()
    def sql(s):return run(['mariadb','-uadmin','--default-character-set=utf8mb4','-N','-B'],env,s.encode())
    if sql("SELECT schema_name FROM information_schema.schemata WHERE schema_name='"+DB+"'").strip():raise RuntimeError('Dedicated database already exists')
    if sql("SELECT User FROM mysql.user WHERE User='"+USER+"'").strip():raise RuntimeError('Dedicated DB user already exists')
    createenv=os.environ.copy();createenv['PSA_PASSWORD']=secrets.token_urlsafe(36)
    run(['plesk','bin','subscription','--create',DOMAIN,'-owner','admin','-ip','153.127.53.73','-login',USER,'-passwd','','-hosting','true','-www','false','-dns','false','-mail_service','false','-notify','false','-php','true','-php_handler_id','plesk-php84-fpm','-ssl','true','-ssl-redirect','true','-webstat','none'],createenv)
    private=ROOT/'private/operator-lab';private.mkdir(parents=True,exist_ok=False)
    account=pwd.getpwnam(USER);uid,gid=account.pw_uid,account.pw_gid
    # Pleskのpsaclnグループは共有されるため、親privateを専用UIDだけが通れる権限にする。
    os.chown(ROOT/'private',uid,gid);os.chmod(ROOT/'private',0o700)
    dbpass=secrets.token_urlsafe(36);gate=secrets.token_urlsafe(20);data_key=base64.b64encode(secrets.token_bytes(32)).decode()
    passwords={k:{'email':k+'@operator.example.invalid','password':secrets.token_urlsafe(14)} for k in ['club','other','office','finance']}
    (private/'accounts.json').write_text(json.dumps(passwords),encoding='utf8')
    marker={'kind':'jmrc-operator-lab','domain':DOMAIN,'database':DB,'gate_user':'jmrc-test','created_from':manifest['commit']}
    # ハッシュ生成も専用PHP。入口パスワードはプロセス引数・ログに出さない。
    marker['gate_hash']=run([PHP,'-r','echo password_hash(stream_get_contents(STDIN),PASSWORD_DEFAULT);'],data=gate.encode()).decode()
    (private/'operator-lab.json').write_text(json.dumps(marker),encoding='utf8')
    for folder in ['src','schema']:shutil.copytree(bundle/folder,private/folder)
    for name in ['seed.php','guard.php','manifest.json','reset.py']:shutil.copyfile(bundle/name,private/name)
    public=ROOT/'httpdocs'
    cfg={'club_intake_enabled':True,'profile_corrections_enabled':False,'bank_master_enabled':False,'temporary_intake_enabled':False,'staff_role_requests_enabled':False,'license_self_service_enabled':False,'license_export_enabled':False,'insurance_intents_enabled':False,'yucho_import_enabled':False,'data_key':data_key,'db':{'dsn':f'mysql:host=127.0.0.1;dbname={DB};charset=utf8mb4','user':USER,'password':dbpass},'session':{'save_path':str(private/'sessions'),'name':'JMRC_OPERATOR_LAB','lifetime':28800,'secure':True,'legacy_session_until':'2000-01-01T00:00:00Z'}}
    # JSONからPHP配列へ変換し、設定の文字列をシェルへ埋め込まない。
    def phpconfig(data):return b'<?php\nreturn json_decode(base64_decode(\''+base64.b64encode(json.dumps(data).encode())+b'\'),true,512,JSON_THROW_ON_ERROR);\n'
    (private/'auth.config.php').write_bytes(phpconfig(cfg))
    legacy={'db':{'host':'127.0.0.1','port':3306,'name':DB,'charset':'utf8mb4','collation':'utf8mb4_general_ci','user':USER,'pass':dbpass},'features':{'audit_log':True},'base_url':'/entry/','system_name':'クラブ操作練習','storage_path':str(private/'storage'),'club_portal':{'allow_event_create':False},'password_policy':[]}
    for name in ['sessions','tmp','storage']:(private/name).mkdir()
    sql(f"CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER '{USER}'@'localhost' IDENTIFIED BY '{dbpass}'; CREATE USER '{USER}'@'127.0.0.1' IDENTIFIED BY '{dbpass}';")
    schema=private/'schema'
    ordered=[schema/'legacy.sql']+sorted(p for p in schema.glob('*.sql') if p.name[:3].isdigit())
    # 013の料金は年度をseedしてから投入する。017・018も料金のため後段へ分ける。
    later=[p for p in ordered if p.name in ['013_club_fee_profiles_2027.sql','017_regular_organization_fees_2027.sql','018_supporting_organization_fees_2027.sql']]
    for p in ordered:
        if p not in later:sql('USE `'+DB+'`;'+p.read_text(encoding='utf-8-sig'))
    tables=['portal_applications','portal_club_intakes','portal_club_years','portal_club_charges','portal_invoices','portal_invoice_lines','portal_payments','portal_allocations']
    for host in ['localhost','127.0.0.1']:
        sql(f"GRANT SELECT ON `{DB}`.* TO '{USER}'@'{host}';")
        for table in tables:sql(f"GRANT INSERT,UPDATE ON `{DB}`.{table} TO '{USER}'@'{host}';")
        for table in ['portal_clubs','portal_club_intake_revisions','portal_club_intake_closures','portal_audit_events','portal_outbox','audit_logs']:sql(f"GRANT INSERT ON `{DB}`.{table} TO '{USER}'@'{host}';")
    # 初期化だけの専用資格でseed。以後はSELECTと必要なDMLだけに戻す。
    sql(f"GRANT ALL ON `{DB}`.* TO '{USER}'@'127.0.0.1';GRANT ALL ON `{DB}`.* TO '{USER}'@'localhost';")
    run([PHP,str(private/'seed.php')])
    for p in later:sql('USE `'+DB+'`;'+p.read_text(encoding='utf-8-sig'))
    for p in schema.glob('*permissions.sql'):sql('USE `'+DB+'`;'+p.read_text(encoding='utf8'))
    for host in ['localhost','127.0.0.1']:
        sql(f"REVOKE ALL PRIVILEGES ON `{DB}`.* FROM '{USER}'@'{host}';GRANT SELECT ON `{DB}`.* TO '{USER}'@'{host}';")
        for table in tables:sql(f"GRANT INSERT,UPDATE ON `{DB}`.{table} TO '{USER}'@'{host}';")
        for table in ['portal_clubs','portal_club_intake_revisions','portal_club_intake_closures','portal_audit_events','portal_outbox','audit_logs']:sql(f"GRANT INSERT ON `{DB}`.{table} TO '{USER}'@'{host}';")
    # OSレベルでも他サイトへ書き込めない。秘密・ソースは専用ユーザーが読むだけ、セッションだけ書込可。
    for p in private.rglob('*'):
        os.chown(p,0,gid);os.chmod(p,0o750 if p.is_dir() else 0o640)
    os.chown(private,0,gid);os.chmod(private,0o750)
    for name in ['sessions','tmp','storage']:os.chown(private/name,uid,gid);os.chmod(private/name,0o700)
    settings=private/'php-settings.ini'
    settings.write_text('\n'.join([f'auto_prepend_file={private}/guard.php',f'open_basedir={ROOT}',f'session.save_path={private}/sessions',f'upload_tmp_dir={private}/tmp','allow_url_fopen=Off','allow_url_include=Off','display_errors=Off','log_errors=On','expose_php=Off','memory_limit=128M','max_execution_time=30','sendmail_path=/bin/false','disable_functions=mail,exec,system,passthru,shell_exec,proc_open,popen,pcntl_exec,fsockopen,pfsockopen,stream_socket_client,curl_exec,curl_multi_exec,socket_create,socket_connect','[php-fpm-pool-settings]','pm=ondemand','pm.max_children=2','pm.process_idle_timeout=10s'])+'\n')
    run(['plesk','bin','site','--update-php-settings',DOMAIN,'-additional-settings',str(settings)])
    # ガード設定が成功してからWebコードを置く。準備途中の認証なしアプリを作らない。
    shutil.copytree(bundle/'site',public,dirs_exist_ok=True);shutil.copyfile(bundle/'index.php',public/'index.php')
    (public/'entry/common/config.php').write_bytes(phpconfig(legacy))
    if (public/'index.html').is_file() and not (public/'index.html').is_symlink():(public/'index.html').unlink()
    # 証明書の発行対象は新規の検証ホストだけ。既存ドメインの証明書設定は変更しない。
    run(['plesk','bin','extension','--exec','letsencrypt','cli.php','-d',DOMAIN,'-m','yoshihiro@nsksys.com'])
    # 初期状態は非公開領域に保存し、後の技術担当による練習データだけの復元に使う。
    dump=run(['mariadb-dump','-uadmin','--single-transaction','--skip-lock-tables',DB],env=env)
    (private/'baseline.sql').write_bytes(dump);os.chmod(private/'baseline.sql',0o600)
    marker['baseline_sha256']=hashlib.sha256(dump).hexdigest();(private/'operator-lab.json').write_text(json.dumps(marker),encoding='utf8')
    access={'url':'https://'+DOMAIN+'/','gate_user':'jmrc-test','gate_password':gate,'accounts':passwords}
    (private/'access.json').write_text(json.dumps(access),encoding='utf8');os.chmod(private/'access.json',0o600)
    return {'status':'PASS','domain':DOMAIN,'database':DB,'system_user':USER,'source_commit':manifest['commit'],'production_modified':False,'private_access_file':str(private/'access.json')}

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('bundle',type=Path);args=parser.parse_args()
    print(json.dumps(install(args.bundle)))
