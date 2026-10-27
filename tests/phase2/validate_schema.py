# Path: tests/phase2/validate_schema.py
# 機能: ローカルWSL上のネットワーク隔離MariaDBだけでDDL・制約・移行の保持を検証する。
# 本番接続機能は持たない。個人データを使わず、コンテナは検証後に停止削除する。
import hashlib
import json
import re
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor

ROOT = Path(__file__).resolve().parents[2]
NAME = 'jmrckinki-phase2-validation-20261027'
DB = 'jmrc_phase2_lab'
IMAGE = 'mariadb:10.5.29@sha256:a530aeeefd82f4fa5150f391b6c75462140904780338766f6b03acecb1cca3ce'
REPORT = ROOT / 'tmp' / 'phase2_validation.json'
RESULTS = []


def docker(*args, data=None, check=True):
    """ローカルWSLのDockerへ固定接続。ホスト/本番DBの指定引数は受け付けない。"""
    result = subprocess.run(['wsl.exe', '-d', 'Ubuntu', '--', 'docker', *args],
                            input=data.encode() if isinstance(data, str) else data,
                            capture_output=True, timeout=60)
    if check and result.returncode:
        raise RuntimeError(result.stderr.decode('utf-8', errors='replace'))
    return result


def sql(text, expected_error=None, database=DB):
    """SQLはネットワークのない専用コンテナ内UNIX socketでのみ実行する。"""
    result = docker('exec', '-i', NAME, 'mariadb', '-uroot', '--default-character-set=utf8mb4',
                    '--batch', '--raw', '--skip-column-names', database, data=text, check=False)
    error = result.stderr.decode('utf-8', errors='replace')
    if expected_error:
        assert result.returncode and f'ERROR {expected_error} ' in error, error
    elif result.returncode:
        raise RuntimeError(error)
    return result.stdout.decode('utf-8', errors='replace').strip()


def case(name, statement, error=None, value=None):
    """エラー番号と結果を明示し、失敗した検証を成功として数えない。"""
    result = sql(statement, error)
    if value is not None:
        assert result == str(value), (name, result, value)
    RESULTS.append({'case': name, 'result': 'PASS', 'expected_error': error})


def snapshot():
    """既存テーブルとビューの定義・架空行を比較。AUTO_INCREMENT現在値は除く。"""
    names = ['roles','users','entrants','clubs','jmrc_affiliated_clubs','jmrc_annual_memberships',
             'jmrc_events','jmrc_entries','jmrc_entry_fees','audit_logs','view_annual_jmrc_payments']
    return {n: [re.sub(r' AUTO_INCREMENT=\d+', '', sql('SHOW CREATE TABLE '+n)),
                sql('SELECT * FROM '+n+' ORDER BY 1')] for n in names}


def synthetic_legacy_row(table, overrides):
    """旧表のNOT NULL列を架空値で満たし、非空の過去申込を用意する。実データは使わない。"""
    columns = sql("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COALESCE(COLUMN_DEFAULT,'__NONE__'),EXTRA "
                  "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='"+table+"' ORDER BY ORDINAL_POSITION")
    values = {}
    for line in columns.splitlines():
        fields = line.split('\t')
        name, typ, detail, nullable, default, extra = fields + [''] * (6-len(fields))
        if name in overrides:
            values[name] = overrides[name]
        elif nullable == 'NO' and default == '__NONE__' and 'auto_increment' not in extra:
            if typ == 'enum':
                values[name] = detail[5:].split(',')[0].strip("')")
            elif typ in ('int','bigint','tinyint','smallint','decimal'):
                values[name] = 1
            elif typ == 'date':
                values[name] = '2026-06-01'
            elif typ in ('datetime','timestamp'):
                values[name] = '2026-06-01 00:00:00'
            else:
                values[name] = 'fixture'
    quoted = [str(v) if isinstance(v,int) else "'"+v.replace("'","''")+"'" for v in values.values()]
    sql('INSERT INTO '+table+' ('+','.join('`'+n+'`' for n in values)+') VALUES('+','.join(quoted)+')')


def main():
    """起動→既存定義→DDL→異常系→同時処理→逆順削除を検証し証跡を残す。"""
    started = False
    REPORT.unlink(missing_ok=True)
    try:
        docker('run', '--detach', '--rm', '--name', NAME, '--network', 'none',
               '--label', 'jmrc.phase2.lab=true', '--memory', '768m', '--cpus', '1',
               '--tmpfs', '/var/lib/mysql:rw,size=512m', '--tmpfs', '/run/mysqld:rw,size=32m',
               '-e', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1', IMAGE,
               '--skip-networking', '--performance-schema=OFF')
        started = True
        for _ in range(60):
            state = docker('exec', NAME, 'mariadb-admin', '-uroot', 'ping', check=False)
            if state.returncode == 0:
                # 初期化用サーバーと本起動を区別する。
                logs = docker('logs', NAME, check=False)
                if 'MariaDB init process done. Ready for start up.' in logs.stdout.decode(errors='replace'):
                    break
            time.sleep(0.5)
        else:
            raise RuntimeError('隔離MariaDBの本起動を確認できませんでした')
        sql('CREATE DATABASE '+DB+' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', database='mysql')
        sql((ROOT/'tests/phase2/legacy_schema.sql').read_text(encoding='utf-8'))
        case('本番同一MariaDB版', "SELECT SUBSTRING_INDEX(VERSION(),'-',1)", value='10.5.29')
        sql("INSERT INTO roles VALUES(1,'JMRC_ADMIN'),(2,'CLUB'),(3,'ENTRANT');"
            "INSERT INTO users(id,role_id,email,email_verified,password_hash) VALUES"
            "(1,1,'office@example.invalid',1,'fixture-no-auth'),"
            "(2,3,'member@example.invalid',1,'fixture-no-auth'),"
            "(3,3,'other@example.invalid',1,'fixture-no-auth');")
        synthetic_legacy_row('entrants', {'user_id':2})
        synthetic_legacy_row('jmrc_events', {'id':1,'event_date':'2026-06-01'})
        synthetic_legacy_row('jmrc_entries', {'id':1,'user_id':2,'event_id':1,'status':'accepted','personal_member_choice':'登録済'})
        case('既存非空ビューの基準値', 'SELECT is_member_paid FROM view_annual_jmrc_payments WHERE user_id=2 AND target_year=2026',value=1)
        baseline = snapshot()
        ddl = (ROOT/'design/phase2/001_portal_schema.sql').read_text(encoding='utf-8')
        sql(ddl)
        case('portal36表作成', "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,7)='portal_'", value=36)
        case('既存定義を黙って再利用しない', ddl, error=1050)
        sql("INSERT INTO portal_account_controls(user_id) VALUES(1),(2),(3);"
            "INSERT INTO portal_roles(id,code,name) VALUES(1,'OFFICE','事務局'),(2,'CLUB_REP','代表');"
            "INSERT INTO portal_years(year,valid_from,valid_until,club_open_at,club_close_at,staff_open_at,policy_version) VALUES"
            "(2027,'2026-12-31 15:00:00','2027-12-31 15:00:00','2026-12-01','2027-02-08','2026-12-01','lab'),"
            "(2028,'2027-12-31 15:00:00','2028-12-31 15:00:00','2027-12-01','2028-02-01','2027-12-01','lab');"
            "INSERT INTO portal_club_categories VALUES('1','検証用区分',1,NOW(),NOW(),1);"
            "INSERT INTO portal_clubs(id,fixed_code,name_full,name_short) VALUES(1,'00001','架空クラブ','LAB'),(2,'00002','架空クラブ2','LAB2');")
        for i in range(1,21):
            sql(f"INSERT INTO portal_applications(id,kind,applicant_user_id,year,request_key) VALUES({i},'lab',2,{2028 if i==3 else 2027},'request-{i}');")
        for i, year, club, code in [(1,2027,1,'100001'),(2,2027,2,'100002'),(3,2028,1,'100001')]:
            sql(f"INSERT INTO portal_club_years(id,club_id,year,category_digit,display_code,application_id,status,rep_name,contact_name,postcode,address,phone,email) VALUES({i},{club},{year},'1','{code}',{i},'approved','架空','架空','0000000','架空住所','000','club@example.invalid');")
        sql("INSERT INTO portal_events(id,organizer_club_year_id,year,title,starts_at,ends_at) VALUES"
            "(1,1,2027,'検証会1','2027-04-01','2027-04-02'),(2,1,2027,'検証会2','2027-05-01','2027-05-02');"
            "INSERT INTO portal_memberships(id,user_id,year,member_type,application_id) VALUES(1,2,2027,'general',4);"
            "INSERT INTO portal_memberships(id,user_id,year,member_type,event_id,application_id) VALUES(2,2,2027,'temporary',1,5),(3,2,2027,'temporary',2,6);")
        case('同年度General重複拒否', "INSERT INTO portal_memberships(user_id,year,member_type,application_id) VALUES(2,2027,'general',7)",1062)
        case('同イベントTemporary重複拒否', "INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id) VALUES(2,2027,'temporary',1,7)",1062)
        case('別イベントTemporaryを保持', "SELECT COUNT(*) FROM portal_memberships WHERE user_id=2 AND member_type='temporary'",value=2)
        case('Generalへイベント指定拒否', "INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id) VALUES(3,2027,'general',1,7)",4025)
        case('Temporaryイベント欠落拒否', "INSERT INTO portal_memberships(user_id,year,member_type,application_id) VALUES(3,2027,'temporary',7)",4025)
        case('所属年度不一致拒否', "INSERT INTO portal_affiliations(membership_id,year,club_year_id,requested_at) VALUES(1,2028,3,NOW())",1452)
        case('申請年度不一致拒否', "INSERT INTO portal_memberships(user_id,year,member_type,application_id) VALUES(3,2028,'general',7)",1452)
        case('イベント年度不一致拒否', "INSERT INTO portal_memberships(user_id,year,member_type,event_id,application_id) VALUES(3,2028,'temporary',1,3)",1452)
        sql("INSERT INTO portal_affiliations(id,membership_id,year,club_year_id,status,requested_at,valid_from) VALUES(1,1,2027,1,'approved',NOW(),NOW());")
        case('有効所属の二重承認拒否', "INSERT INTO portal_affiliations(membership_id,year,club_year_id,status,requested_at,valid_from) VALUES(1,2027,2,'approved',NOW(),NOW())",1062)
        case('所属変更履歴', "START TRANSACTION; UPDATE portal_affiliations SET valid_until='2027-06-01' WHERE id=1; INSERT INTO portal_affiliations(membership_id,year,club_year_id,status,requested_at,valid_from) VALUES(1,2027,2,'approved',NOW(),'2027-06-01'); COMMIT; SELECT COUNT(*) FROM portal_affiliations WHERE membership_id=1",value=2)
        case('クラブscope実在FK', "INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,valid_from,granted_by) VALUES(2,2,'club',999,NOW(),1)",1452)
        case('globalにクラブ指定拒否', "INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,scope_club_id,valid_from,granted_by) VALUES(2,1,'global',1,NOW(),1)",4025)
        sql("INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,valid_from,granted_by) VALUES(2,1,'global',NOW(),1);")
        case('NULLを使う全体scope重複拒否', "INSERT INTO portal_user_role_grants(user_id,role_id,scope_type,valid_from,granted_by) VALUES(2,1,'global',NOW(),1)",1062)
        case('固定コード先頭ゼロ保持', 'SELECT fixed_code FROM portal_clubs WHERE id=1',value='00001')
        case('不正固定番号拒否', "INSERT INTO portal_clubs(fixed_code,name_full,name_short) VALUES('ABCDE','bad','bad')",4025)
        case('年度期間逆転拒否', "INSERT INTO portal_years(year,valid_from,valid_until,club_open_at,club_close_at,staff_open_at,policy_version) VALUES(2029,'2029-12-31','2029-01-01','2028-12-01','2029-02-01','2028-12-01','lab')",4025)
        sql("INSERT INTO portal_identifiers(id,user_id,kind,value_cipher,lookup_hmac,key_version) VALUES(1,2,'jaf','synthetic',UNHEX(SHA2('id1',256)),1),(2,3,'jaf','synthetic',UNHEX(SHA2('id2',256)),1);")
        case('別人の識別番号で採番不可', "INSERT INTO portal_member_numbers(membership_id,user_id,year,source_kind,source_identifier_id,club_year_id,number_cipher,number_hmac,key_version,valid_from) VALUES(1,2,2027,'jaf',2,1,'synthetic',UNHEX(SHA2('n1',256)),1,NOW())",1452)
        sql("INSERT INTO portal_member_numbers(membership_id,user_id,year,source_kind,source_identifier_id,club_year_id,number_cipher,number_hmac,key_version,valid_from) VALUES(1,2,2027,'jaf',1,1,'synthetic',UNHEX(SHA2('n1',256)),1,NOW());")
        case('現行番号二重発行拒否', "INSERT INTO portal_member_numbers(membership_id,user_id,year,source_kind,source_identifier_id,club_year_id,number_cipher,number_hmac,key_version,valid_from) VALUES(1,2,2027,'jaf',1,1,'synthetic',UNHEX(SHA2('n2',256)),1,NOW())",1062)
        case('未承認保存方針の有効化拒否', "INSERT INTO portal_retention_policies(code,duration_days,enabled) VALUES('audit',730,1)",4025)
        case('親users削除をRESTRICT', 'DELETE FROM users WHERE id=2',1451)
        sql("INSERT INTO portal_bank_accounts(id,purpose_code,display_name,details_cipher,updated_by) VALUES(1,'member','架空口座','synthetic',1),(2,'insurance','架空口座2','synthetic',1);"
            "INSERT INTO portal_invoices(id,application_id,invoice_no,payer_user_id,account_id,account_snapshot_cipher,idempotency_key) VALUES(1,4,'LAB-1',2,1,'synthetic','inv1');"
            "INSERT INTO portal_invoice_lines(id,invoice_id,purpose_code,membership_id,description,quantity,unit_amount_yen,amount_yen) VALUES(1,1,'member',1,'General',1,2000,2000);"
            "INSERT INTO portal_bank_imports(id,account_id,file_sha256,parser_version,imported_by,imported_at) VALUES(1,1,UNHEX(SHA2('file1',256)),'lab',1,NOW());"
            "INSERT INTO portal_payments(id,account_id,import_id,source_type,source_key,received_at,amount_yen,payer_name_cipher,status) VALUES(1,1,1,'bank','tx1',NOW(),2000,'synthetic','confirmed');")
        case('同ファイル再取込拒否', "INSERT INTO portal_bank_imports(account_id,file_sha256,parser_version,imported_by,imported_at) VALUES(1,UNHEX(SHA2('file1',256)),'lab',1,NOW())",1062)
        case('銀行取引二重計上拒否', "INSERT INTO portal_payments(account_id,source_type,source_key,received_at,amount_yen,payer_name_cipher) VALUES(1,'bank','tx1',NOW(),2000,'synthetic')",1062)
        case('別口座import紐付け拒否', "INSERT INTO portal_payments(account_id,import_id,source_type,source_key,received_at,amount_yen,payer_name_cipher) VALUES(2,1,'bank','tx2',NOW(),2000,'synthetic')",1452)
        case('請求冪等キー重複拒否', "INSERT INTO portal_invoices(application_id,invoice_no,payer_user_id,account_id,account_snapshot_cipher,idempotency_key) VALUES(4,'LAB-2',2,1,'synthetic','inv1')",1062)
        case('払主の二重指定拒否', "INSERT INTO portal_invoices(application_id,invoice_no,payer_user_id,payer_club_year_id,account_id,account_snapshot_cipher,idempotency_key) VALUES(4,'LAB-2',2,1,1,'synthetic','inv2')",4025)
        case('明細の計算不一致拒否', "INSERT INTO portal_invoice_lines(invoice_id,purpose_code,description,quantity,unit_amount_yen,amount_yen) VALUES(1,'member','bad',2,2000,2000)",4025)
        case('新会員費を旧ビューへ混入しない', 'SELECT COUNT(*) FROM view_annual_jmrc_payments WHERE target_year=2027',value=0)
        sql("INSERT INTO portal_schemes(code,name,owner_scope) VALUES('sports_safety','架空保険','office');"
            "INSERT INTO portal_scheme_enrollments(membership_id,scheme_code,year,application_id) VALUES(1,'sports_safety',2027,8);")
        case('同年度保険二重登録拒否', "INSERT INTO portal_scheme_enrollments(membership_id,scheme_code,year,application_id) VALUES(1,'sports_safety',2027,9)",1062)
        case('保険資格の年度不一致拒否', "INSERT INTO portal_scheme_enrollments(membership_id,scheme_code,year,application_id) VALUES(1,'sports_safety',2028,3)",1452)
        # 次のprocedureはロック順と残額検証の実験用であり、本番アプリ実装ではない。
        sql('''DELIMITER //
CREATE PROCEDURE lab_allocate(IN op VARCHAR(80), IN amount BIGINT, IN pause_seconds INT)
BEGIN
 DECLARE capacity BIGINT; DECLARE used_amount BIGINT; DECLARE demand BIGINT; DECLARE line_used BIGINT;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT amount_yen INTO capacity FROM portal_payments WHERE id=1 FOR UPDATE;
 SELECT amount_yen INTO demand FROM portal_invoice_lines WHERE id=1 FOR UPDATE;
 SELECT COALESCE(SUM(amount_yen),0) INTO used_amount FROM portal_allocations WHERE payment_id=1;
 SELECT COALESCE(SUM(amount_yen),0) INTO line_used FROM portal_allocations WHERE invoice_line_id=1;
 IF amount<=0 OR used_amount+amount>capacity OR line_used+amount>demand THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='allocation capacity exceeded';
 END IF;
 INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,created_by) VALUES(1,1,amount,op,1);
 DO SLEEP(pause_seconds);
 COMMIT;
END//
DELIMITER ;''')
        with ThreadPoolExecutor(2) as pool:
            first = pool.submit(sql, "CALL lab_allocate('concurrent-1',1500,2)")
            time.sleep(0.5)
            second = pool.submit(sql, "CALL lab_allocate('concurrent-2',1000,0)",1644)
            first.result(); second.result()
        case('同時消込の超過拒否・ロールバック', 'SELECT SUM(amount_yen) FROM portal_allocations',value=1500)
        case('消込operation再送拒否', "CALL lab_allocate('concurrent-1',100,0)",1062)
        case('取消の符号不正拒否', "INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,reverses_id,created_by) VALUES(1,1,100,'bad-reverse',1,1)",4025)
        sql("INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,reverses_id,created_by,reason) VALUES(1,1,-1500,'reverse-1',1,1,'検証取消');")
        case('取消二重実行拒否', "INSERT INTO portal_allocations(payment_id,invoice_line_id,amount_yen,operation_key,reverses_id,created_by) VALUES(1,1,-1500,'reverse-2',1,1)",1062)
        case('逆仕訳後残額', 'SELECT SUM(amount_yen) FROM portal_allocations',value=0)
        case('通知event再送防止', "INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES('event1','lab',1,'{}'); INSERT INTO portal_outbox(event_key,event_type,entity_id,payload_minimal) VALUES('event1','lab',1,'{}')",1062)
        assert baseline == snapshot(), '既存の定義/行/ビューが変更された'
        RESULTS.append({'case':'既存10表+1ビューの定義/架空行が不変','result':'PASS'})
        # 空の別DBでdownを検証し、ユーザーデータを戻せると誤認させない。
        sql('CREATE DATABASE jmrc_phase2_down CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',database='mysql')
        sql((ROOT/'tests/phase2/legacy_schema.sql').read_text(encoding='utf-8'),database='jmrc_phase2_down')
        sql(ddl,database='jmrc_phase2_down')
        sql((ROOT/'design/phase2/001_portal_schema.down.lab-only.sql').read_text(encoding='utf-8'),database='jmrc_phase2_down')
        count=sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()",database='jmrc_phase2_down')
        assert count=='11',count
        sql(ddl,database='jmrc_phase2_down')
        RESULTS.append({'case':'空の隔離DBで逆順削除→再作成、既存11オブジェクト保持','result':'PASS'})
        report={'status':'PASS','checked_at_utc':datetime.now(timezone.utc).isoformat(),
                'image':IMAGE,'database_version':sql('SELECT VERSION()'),
                'network_mode':'none','published_ports':[], 'synthetic_data_only':True,
                'ddl_sha256':hashlib.sha256(ddl.encode()).hexdigest(),'cases':RESULTS,
                'not_tested':['PHP/FPM共通ログイン','本番適用','運営未確定ルール','アプリ実装の金額/認可/取消同額検証']}
        REPORT.parent.mkdir(parents=True,exist_ok=True)
        REPORT.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
        print(json.dumps({'status':'PASS','cases':len(RESULTS),'ddl_sha256':report['ddl_sha256']},ensure_ascii=False))
    finally:
        if started:
            # 名前だけに依存せずラベルを照合して、この検証が作った隔離コンテナのみ削除する。
            label=docker('inspect','--format','{{index .Config.Labels "jmrc.phase2.lab"}}',NAME,check=False)
            if label.stdout.decode().strip()=='true':
                docker('stop','--time','5',NAME)


if __name__ == '__main__':
    main()
