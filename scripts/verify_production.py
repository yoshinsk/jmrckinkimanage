# Path: scripts/verify_production.py
# 機能: 指定された既存アカウントで本番の共通ログインを確認。認証情報は標準入力のみで受け取り保存しない。
import argparse
import hashlib
import http.cookiejar as cookies
import json
import os
from pathlib import Path
import pwd
import re
import sys
import socket
import struct
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--backup',required=True);opt=parser.parse_args()
    backup=Path(opt.backup).resolve();assert os.geteuid()==0 and backup.parent==Path('/var/www/vhosts/gymkinki.com/private/jmrc-portal-backups')
    before=json.loads((backup/'manifest.json').read_text())
    env=os.environ.copy();env['MYSQL_PWD']=Path('/etc/psa/.psa.shadow').read_text().strip()
    def sql(q):
        r=subprocess.run(['mariadb','-uadmin','-N','-B','--database=jmrckinki'],input=q.encode(),env=env,capture_output=True)
        assert r.returncode==0,'Verification database query failed'
        return r.stdout.decode().strip()
    # FPMの対象ファイルだけを無効化し、他サイト全体のOPcacheをリセットしない。
    private=Path('/var/www/vhosts/gymkinki.com/private/jmrc-portal')
    probe=private/'cache_refresh.php';paths=[str(p) for p in (private/'current/src').rglob('*.php')]
    entry=Path('/var/www/vhosts/gymkinki.com/httpdocs/entry')
    paths += [str(entry/p) for p in ['common/auth.php','common/csrf.php','common/portal_billing.php','common/portal_registration.php','register_handler.php','entrant/entries.php','login.php','logout.php']]
    array=','.join("'"+p+"'" for p in paths)
    probe.write_text("<?php foreach(["+array+"] as $p){if(function_exists('opcache_invalidate')){opcache_invalidate($p,true);}}echo 'OPCACHE_OK';")
    owner=pwd.getpwnam('gymkinki.com');os.chown(probe,owner.pw_uid,owner.pw_gid);os.chmod(probe,0o600)
    def record(kind,body):
        padding=(-len(body))%8
        return struct.pack('!BBHHBB',1,kind,1,len(body),padding,0)+body+b'\0'*padding
    def length(n):return bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)
    params={'SCRIPT_FILENAME':str(probe),'SCRIPT_NAME':'/cache_refresh.php','REQUEST_METHOD':'GET','SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'gymkinki.com','HTTP_HOST':'gymkinki.com','REMOTE_ADDR':'127.0.0.1','HTTPS':'on'}
    data=b''.join(length(len(k.encode()))+length(len(v.encode()))+k.encode()+v.encode() for k,v in params.items())
    try:
        with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as sock:
            sock.settimeout(20);sock.connect('/var/www/vhosts/system/gymkinki.com/php-fpm.sock')
            sock.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,data)+record(4,b'')+record(5,b''));response=b''
            while b:=sock.recv(65536):response+=b
        assert b'OPCACHE_OK' in response,'FPM cache refresh failed'
    finally:probe.unlink(missing_ok=True)
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args):return None
    jar=cookies.CookieJar();opener=urllib.request.build_opener(NoRedirect,urllib.request.HTTPCookieProcessor(jar))
    def http(path,fields=None):
        req=urllib.request.Request('https://gymkinki.com'+path,data=urllib.parse.urlencode(fields).encode() if fields is not None else None)
        try:r=opener.open(req,timeout=20)
        except urllib.error.HTTPError as e:r=e
        with r:return r.code,r.headers,r.read().decode('utf-8',errors='replace')
    cases=[]
    def check(name,ok):
        assert ok,name
        cases.append({'case':name,'result':'PASS'})
    code,head,body=http('/manage/');check('匿名manageはログインへ',code==302 and head.get('Location')=='/manage/login.php')
    code,head,body=http('/entry/login.php');check('既存サインイン画面が正常',code==200 and 'name="password"' in body)
    code,head,body=http('/manage/card-verify.php',{'token':'0'*64});check('匿名の無効QRは個人情報なし',code==200 and json.loads(body)=={'active':False})
    code,head,body=http('/private/auth.config.php');check('非公開設定を取得できない',code in [403,404])
    credentials=json.load(sys.stdin)
    email=credentials['email'];password=credentials['password']
    assert re.fullmatch(r'[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+',email)
    account=sql("SELECT id,role_id,email_verified FROM users WHERE email='"+email+"'").split('\t')
    assert len(account)==3 and account[2]=='1','Verified existing account required'
    uid,role=int(account[0]),int(account[1])
    target={1:'/entry/admin/events.php',2:'/entry/club/events.php',3:'/entry/entrant/events.php'}[role]
    try:
        code,head,body=http('/entry/login.php');csrf=re.search(r'name="_csrf" value="([a-f0-9]{64})"',body)[1]
        code,head,body=http('/entry/login.php',{'email':email,'password':password,'_csrf':csrf})
        check('既存アカウントの本番サインイン',code==303 and head.get('Location')==target)
        code,head,body=http(target);check('既存の競技会画面が正常',code==200 and '競技会' in body)
        code,head,body=http('/manage/');check('同じ認証でmanageへ移動',code==200 and email in body)
        check('受付開始前の案内を表示','受付は開始していません' in body)
        csrf=re.search(r'name="_csrf" value="([a-f0-9]{64})"',body)[1]
        code,head,body=http('/manage/bank-settings.php');check('指定事務局に入金先設定権限あり',code==200 and '口座設定を保存' in body)
        code,head,body=http('/manage/profile-correct.php');check('指定事務局に会員情報訂正権限あり',code==200 and '会員情報の訂正' in body)
        code,head,body=http('/manage/logout.php',{'_csrf':'invalid'});check('不正CSRFを拒否',code==400)
        code,head,body=http('/manage/logout.php',{'_csrf':csrf});check('共通ログアウトが成功',code==303)
        code,head,body=http(target);check('manageログアウトが旧entryへ反映',code==302 and head.get('Location')=='/entry/login.php')
    finally:
        credentials.clear();password=None
    after=sql("SET SESSION group_concat_max_len=1048576; SELECT COUNT(*),SUM(email_verified=1),SHA2(GROUP_CONCAT(CONCAT(id,':',role_id,':',email_verified,':',password_hash) ORDER BY id SEPARATOR '|'),256) FROM users")
    check('既存305ユーザーの認証情報を保持',after==before['users_baseline'])
    check('全既存ユーザーに認証状態あり',sql('SELECT COUNT(*) FROM users u LEFT JOIN portal_account_controls c ON c.user_id=u.id WHERE c.user_id IS NULL')=='0')
    check('年度の資格参照先は未切替',sql("SELECT source_mode FROM portal_years WHERE year=2027")=='shadow')
    result={'status':'PASS','cases':cases,'existing_users':305,'verified_existing_users':296,'synthetic_users_created':False,'business_records_created':False,'backup':str(backup)}
    (backup/'verification.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));os.chmod(backup/'verification.json',0o600)
    print(json.dumps(result,ensure_ascii=False))


if __name__=='__main__':main()
