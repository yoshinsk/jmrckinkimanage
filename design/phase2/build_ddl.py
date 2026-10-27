# Path: design/phase2/build_ddl.py
# 機能: 本番互換のportal追加DDLと、隔離検証用の削除DDLを生成する。DBには接続しない。
from pathlib import Path

ROOT = Path(__file__).resolve().parent
TABLES = []
ID = 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
STAMP = 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'
VERSION = 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), row_version BIGINT UNSIGNED NOT NULL DEFAULT 1'


def table(name, columns, constraints=(), mutable=True):
    """依存順に定義を収集する。CHECK/FKはMariaDB 10.5で実検証する。"""
    items = [c.strip() for c in columns.split(';') if c.strip()]
    items += [STAMP]
    if mutable:
        items += VERSION.split(', ')
    items += list(constraints)
    TABLES.append((name, items))


def fk(col, target, ref='id'):
    """暗黙CASCADEを使わず親削除を拒否する。"""
    return f'FOREIGN KEY ({col}) REFERENCES {target} ({ref}) ON DELETE RESTRICT ON UPDATE RESTRICT'


table('portal_account_controls', '''user_id BIGINT UNSIGNED PRIMARY KEY; status VARCHAR(16) NOT NULL DEFAULT 'active'; auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1;
blocked_at DATETIME(6) NULL; blocked_by BIGINT UNSIGNED NULL''', [fk('user_id','users'),fk('blocked_by','users'),"CHECK (status IN ('active','blocked'))"])
table('portal_roles', ID+'; code VARCHAR(40) NOT NULL UNIQUE; name VARCHAR(80) NOT NULL')
table('portal_role_permissions', 'role_id BIGINT UNSIGNED NOT NULL; permission_code VARCHAR(80) NOT NULL', ['PRIMARY KEY (role_id,permission_code)',fk('role_id','portal_roles')], False)
table('portal_profiles', '''user_id BIGINT UNSIGNED PRIMARY KEY; last_name VARCHAR(64) NULL; first_name VARCHAR(64) NULL;
last_kana VARCHAR(64) NULL; first_kana VARCHAR(64) NULL; birthday DATE NULL; gender VARCHAR(16) NULL;
postcode VARCHAR(16) NULL; address VARCHAR(255) NULL; phone VARCHAR(32) NULL; mobile VARCHAR(32) NULL''',[fk('user_id','users')])
table('portal_identifiers', ID+'''; user_id BIGINT UNSIGNED NOT NULL; kind VARCHAR(24) NOT NULL; value_cipher MEDIUMBLOB NOT NULL;
lookup_hmac BINARY(32) NOT NULL; key_version SMALLINT UNSIGNED NOT NULL; license_type VARCHAR(40) NULL; expires_on DATE NULL; verified_at DATETIME(6) NULL''',
[fk('user_id','users'),'UNIQUE (user_id,kind,lookup_hmac)','UNIQUE (id,user_id)','INDEX (kind,lookup_hmac)'])
table('portal_years', '''year SMALLINT UNSIGNED PRIMARY KEY; valid_from DATETIME(6) NOT NULL; valid_until DATETIME(6) NOT NULL;
club_open_at DATETIME(6) NOT NULL; club_close_at DATETIME(6) NOT NULL; member_self_open_at DATETIME(6) NULL;
staff_open_at DATETIME(6) NOT NULL; source_mode VARCHAR(16) NOT NULL DEFAULT 'legacy'; policy_version VARCHAR(32) NOT NULL''',
["CHECK (source_mode IN ('legacy','shadow','portal'))",'CHECK (valid_from < valid_until)','CHECK (club_open_at < club_close_at)'])
table('portal_club_categories', 'category_digit CHAR(1) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY; name VARCHAR(64) NOT NULL; enabled BOOLEAN NOT NULL DEFAULT 0',
["CHECK (category_digit REGEXP '^[0-9]$')",'CHECK (enabled IN (0,1))'])
table('portal_clubs', ID+'''; fixed_code CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE; name_full VARCHAR(255) NOT NULL;
name_short VARCHAR(128) NOT NULL; legacy_affiliated_id BIGINT UNSIGNED NULL UNIQUE; status VARCHAR(16) NOT NULL DEFAULT 'pending' ''',
[fk('legacy_affiliated_id','jmrc_affiliated_clubs'),"CHECK (fixed_code REGEXP '^[0-9]{5}$')","CHECK (status IN ('pending','active'))"])
table('portal_user_role_grants', ID+'''; user_id BIGINT UNSIGNED NOT NULL; role_id BIGINT UNSIGNED NOT NULL;
scope_type VARCHAR(12) NOT NULL; scope_club_id BIGINT UNSIGNED NULL;
scope_key BIGINT UNSIGNED AS (IFNULL(scope_club_id,0)) PERSISTENT;
status VARCHAR(16) NOT NULL DEFAULT 'pending'; valid_from DATETIME(6) NOT NULL; valid_until DATETIME(6) NULL;
granted_by BIGINT UNSIGNED NOT NULL; approved_by BIGINT UNSIGNED NULL; revoked_by BIGINT UNSIGNED NULL; revoked_at DATETIME(6) NULL''',
[fk('user_id','users'),fk('role_id','portal_roles'),fk('scope_club_id','portal_clubs'),fk('granted_by','users'),fk('approved_by','users'),fk('revoked_by','users'),
'UNIQUE (user_id,role_id,scope_type,scope_key)',"CHECK ((scope_type='global' AND scope_club_id IS NULL) OR (scope_type='club' AND scope_club_id IS NOT NULL))",
"CHECK (status IN ('pending','active','revoked'))",'CHECK (valid_until IS NULL OR valid_from < valid_until)','INDEX (user_id,status)'])
table('portal_applications', ID+'''; kind VARCHAR(32) NOT NULL; applicant_user_id BIGINT UNSIGNED NOT NULL; year SMALLINT UNSIGNED NOT NULL;
status VARCHAR(20) NOT NULL DEFAULT 'draft'; request_key VARCHAR(64) NOT NULL UNIQUE;
submitted_at DATETIME(6) NULL; decided_at DATETIME(6) NULL; decided_by BIGINT UNSIGNED NULL; reason TEXT NULL''',
[fk('applicant_user_id','users'),fk('year','portal_years','year'),fk('decided_by','users'),'UNIQUE (id,year)',
"CHECK (status IN ('draft','submitted','approved','rejected','returned','cancelled'))"])
table('portal_club_years', ID+'''; club_id BIGINT UNSIGNED NOT NULL; year SMALLINT UNSIGNED NOT NULL;
category_digit CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
display_code CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL; application_id BIGINT UNSIGNED NOT NULL UNIQUE;
status VARCHAR(16) NOT NULL DEFAULT 'pending'; rep_name VARCHAR(128) NOT NULL; contact_name VARCHAR(128) NOT NULL;
postcode VARCHAR(16) NOT NULL; address VARCHAR(255) NOT NULL; phone VARCHAR(32) NOT NULL; email VARCHAR(190) NOT NULL;
bank_details_cipher MEDIUMBLOB NULL; approved_by BIGINT UNSIGNED NULL; approved_at DATETIME(6) NULL''',
[fk('club_id','portal_clubs'),fk('year','portal_years','year'),fk('category_digit','portal_club_categories','category_digit'),fk('application_id,year','portal_applications','id,year'),fk('approved_by','users'),
'UNIQUE (club_id,year)','UNIQUE (year,display_code)','UNIQUE (id,year)',"CHECK (display_code REGEXP '^[0-9]{6}$')","CHECK (LEFT(display_code,1)=category_digit)","CHECK (status IN ('pending','approved','returned','rejected'))",'INDEX (year,status)'])
table('portal_organizer_links', 'legacy_club_user_id BIGINT UNSIGNED NOT NULL; portal_club_id BIGINT UNSIGNED NOT NULL; verified_by BIGINT UNSIGNED NOT NULL; verified_at DATETIME(6) NOT NULL',
['PRIMARY KEY (legacy_club_user_id,portal_club_id)',fk('legacy_club_user_id','clubs','user_id'),fk('portal_club_id','portal_clubs'),fk('verified_by','users')])
table('portal_events', ID+'''; legacy_event_id BIGINT UNSIGNED NULL UNIQUE; organizer_club_year_id BIGINT UNSIGNED NOT NULL; year SMALLINT UNSIGNED NOT NULL;
title VARCHAR(255) NOT NULL; starts_at DATETIME(6) NOT NULL; ends_at DATETIME(6) NOT NULL; status VARCHAR(16) NOT NULL DEFAULT 'draft' ''',
[fk('legacy_event_id','jmrc_events'),fk('organizer_club_year_id,year','portal_club_years','id,year'),'UNIQUE (id,year)','CHECK (starts_at < ends_at)',"CHECK (status IN ('draft','published','cancelled'))"])
table('portal_memberships', ID+'''; user_id BIGINT UNSIGNED NOT NULL; year SMALLINT UNSIGNED NOT NULL;
member_type VARCHAR(16) NOT NULL; event_id BIGINT UNSIGNED NULL; application_id BIGINT UNSIGNED NOT NULL UNIQUE;
status VARCHAR(20) NOT NULL DEFAULT 'pending'; valid_from DATETIME(6) NULL; valid_until DATETIME(6) NULL;
activated_at DATETIME(6) NULL; suspended_at DATETIME(6) NULL;
general_user_key BIGINT UNSIGNED AS (CASE WHEN member_type='general' THEN user_id ELSE NULL END) PERSISTENT;
temporary_user_key BIGINT UNSIGNED AS (CASE WHEN member_type='temporary' THEN user_id ELSE NULL END) PERSISTENT''',
[fk('user_id','users'),fk('year','portal_years','year'),fk('event_id,year','portal_events','id,year'),fk('application_id,year','portal_applications','id,year'),
'UNIQUE (id,year)','UNIQUE (id,user_id)','UNIQUE (year,general_user_key)','UNIQUE (event_id,temporary_user_key)',
"CHECK ((member_type='general' AND event_id IS NULL) OR (member_type='temporary' AND event_id IS NOT NULL))",
"CHECK (status IN ('pending','active','suspended'))",'CHECK ((valid_from IS NULL AND valid_until IS NULL) OR (valid_from IS NOT NULL AND valid_until IS NOT NULL AND valid_from < valid_until))'])
table('portal_preregistrations', ID+'''; club_year_id BIGINT UNSIGNED NOT NULL; identifier_kind VARCHAR(24) NOT NULL;
identifier_hmac BINARY(32) NOT NULL; key_version SMALLINT UNSIGNED NOT NULL; status VARCHAR(16) NOT NULL DEFAULT 'pending';
created_by BIGINT UNSIGNED NOT NULL; matched_user_id BIGINT UNSIGNED NULL; matched_at DATETIME(6) NULL''',
[fk('club_year_id','portal_club_years'),fk('created_by','users'),fk('matched_user_id','users'),'UNIQUE (club_year_id,identifier_kind,key_version,identifier_hmac)','UNIQUE (id,club_year_id)',"CHECK (status IN ('pending','matched','cancelled'))"])
table('portal_affiliations', ID+'''; membership_id BIGINT UNSIGNED NOT NULL; year SMALLINT UNSIGNED NOT NULL; club_year_id BIGINT UNSIGNED NOT NULL;
status VARCHAR(20) NOT NULL DEFAULT 'pending'; requested_at DATETIME(6) NOT NULL; decided_at DATETIME(6) NULL; decided_by BIGINT UNSIGNED NULL;
valid_from DATETIME(6) NULL; valid_until DATETIME(6) NULL; match_source_id BIGINT UNSIGNED NULL; reason TEXT NULL;
current_membership_key BIGINT UNSIGNED AS (CASE WHEN status='approved' AND valid_until IS NULL THEN membership_id ELSE NULL END) PERSISTENT''',
[fk('membership_id,year','portal_memberships','id,year'),fk('club_year_id,year','portal_club_years','id,year'),fk('decided_by','users'),fk('match_source_id,club_year_id','portal_preregistrations','id,club_year_id'),
'UNIQUE (current_membership_key)',"CHECK (status IN ('pending','approved','rejected','returned'))","CHECK (status<>'approved' OR valid_from IS NOT NULL)",
'CHECK (valid_until IS NULL OR (valid_from IS NOT NULL AND valid_from < valid_until))'])
table('portal_member_numbers', ID+'''; membership_id BIGINT UNSIGNED NOT NULL; user_id BIGINT UNSIGNED NOT NULL; year SMALLINT UNSIGNED NOT NULL;
source_kind VARCHAR(24) NOT NULL; source_identifier_id BIGINT UNSIGNED NOT NULL; club_year_id BIGINT UNSIGNED NOT NULL;
number_cipher MEDIUMBLOB NOT NULL; number_hmac BINARY(32) NOT NULL; key_version SMALLINT UNSIGNED NOT NULL;
valid_from DATETIME(6) NOT NULL; valid_until DATETIME(6) NULL;
current_member_key BIGINT UNSIGNED AS (CASE WHEN valid_until IS NULL THEN membership_id ELSE NULL END) PERSISTENT''',
[fk('membership_id,user_id','portal_memberships','id,user_id'),fk('membership_id,year','portal_memberships','id,year'),fk('source_identifier_id,user_id','portal_identifiers','id,user_id'),fk('club_year_id,year','portal_club_years','id,year'),
'UNIQUE (current_member_key)','UNIQUE (membership_id,number_hmac)','CHECK (valid_until IS NULL OR valid_from < valid_until)'])
table('portal_card_tokens', ID+'''; membership_id BIGINT UNSIGNED NOT NULL; token_hash BINARY(32) NOT NULL UNIQUE;
expires_at DATETIME(6) NOT NULL; revoked_at DATETIME(6) NULL; issued_by BIGINT UNSIGNED NOT NULL''',[fk('membership_id','portal_memberships'),fk('issued_by','users')])
table('portal_qualification_evidence', ID+'''; membership_id BIGINT UNSIGNED NOT NULL; purpose_code VARCHAR(32) NOT NULL; basis VARCHAR(24) NOT NULL;
source_table VARCHAR(64) NOT NULL; source_id VARCHAR(128) NOT NULL; source_hash BINARY(32) NOT NULL;
confirmed_by BIGINT UNSIGNED NOT NULL; confirmed_at DATETIME(6) NOT NULL; revoked_at DATETIME(6) NULL; reason TEXT NOT NULL''',
[fk('membership_id','portal_memberships'),fk('confirmed_by','users'),'UNIQUE (membership_id,purpose_code,source_table,source_id)',"CHECK (basis IN ('legacy_payment','waiver'))"])
table('portal_schemes', 'code VARCHAR(32) PRIMARY KEY; name VARCHAR(128) NOT NULL; owner_scope VARCHAR(32) NOT NULL; enabled BOOLEAN NOT NULL DEFAULT 0',['CHECK (enabled IN (0,1))'])
table('portal_scheme_enrollments', ID+'''; membership_id BIGINT UNSIGNED NOT NULL; scheme_code VARCHAR(32) NOT NULL; year SMALLINT UNSIGNED NOT NULL;
application_id BIGINT UNSIGNED NOT NULL UNIQUE; status VARCHAR(20) NOT NULL DEFAULT 'requested'; coverage_from DATETIME(6) NULL;
coverage_until DATETIME(6) NULL; beneficiary_cipher MEDIUMBLOB NULL; external_reference VARCHAR(128) NULL; activated_by BIGINT UNSIGNED NULL''',
[fk('membership_id,year','portal_memberships','id,year'),fk('scheme_code','portal_schemes','code'),fk('application_id,year','portal_applications','id,year'),fk('activated_by','users'),
'UNIQUE (membership_id,scheme_code,year)',"CHECK (status IN ('requested','batched','submitted','active','rejected'))",'CHECK ((coverage_from IS NULL AND coverage_until IS NULL) OR (coverage_from IS NOT NULL AND coverage_until IS NOT NULL AND coverage_from < coverage_until))'])
table('portal_fee_rules', ID+'''; year SMALLINT UNSIGNED NOT NULL; purpose_code VARCHAR(32) NOT NULL; member_type VARCHAR(16) NOT NULL;
amount_yen BIGINT NOT NULL; charge_unit VARCHAR(16) NOT NULL; valid_from DATETIME(6) NOT NULL; valid_until DATETIME(6) NOT NULL; enabled BOOLEAN NOT NULL DEFAULT 0''',
[fk('year','portal_years','year'),'CHECK (amount_yen >= 0)','CHECK (valid_from < valid_until)','CHECK (enabled IN (0,1))','INDEX (year,purpose_code,member_type,valid_from)'])
table('portal_bank_accounts', ID+'''; purpose_code VARCHAR(32) NOT NULL; year SMALLINT UNSIGNED NULL; display_name VARCHAR(128) NOT NULL;
details_cipher MEDIUMBLOB NOT NULL; payment_url VARCHAR(2048) NULL; valid_from DATETIME(6) NULL; valid_until DATETIME(6) NULL;
enabled BOOLEAN NOT NULL DEFAULT 0; updated_by BIGINT UNSIGNED NOT NULL''',
[fk('year','portal_years','year'),fk('updated_by','users'),'CHECK (enabled IN (0,1))','CHECK (valid_until IS NULL OR (valid_from IS NOT NULL AND valid_from < valid_until))'])
table('portal_invoices', ID+'''; application_id BIGINT UNSIGNED NOT NULL; invoice_no VARCHAR(40) NOT NULL UNIQUE;
payer_user_id BIGINT UNSIGNED NULL; payer_club_year_id BIGINT UNSIGNED NULL; status VARCHAR(16) NOT NULL DEFAULT 'draft';
due_at DATETIME(6) NULL; account_id BIGINT UNSIGNED NOT NULL; account_snapshot_cipher MEDIUMBLOB NOT NULL;
payment_url_snapshot VARCHAR(2048) NULL; idempotency_key VARCHAR(80) NOT NULL UNIQUE; issued_at DATETIME(6) NULL; finalized_at DATETIME(6) NULL''',
[fk('application_id','portal_applications'),fk('payer_user_id','users'),fk('payer_club_year_id','portal_club_years'),fk('account_id','portal_bank_accounts'),
'CHECK ((payer_user_id IS NULL) <> (payer_club_year_id IS NULL))',"CHECK (status IN ('draft','issued','finalized','cancelled'))"])
table('portal_invoice_lines', ID+'''; invoice_id BIGINT UNSIGNED NOT NULL; fee_rule_id BIGINT UNSIGNED NULL;
purpose_code VARCHAR(32) NOT NULL; membership_id BIGINT UNSIGNED NULL; scheme_enrollment_id BIGINT UNSIGNED NULL; club_year_id BIGINT UNSIGNED NULL;
description VARCHAR(255) NOT NULL; line_type VARCHAR(16) NOT NULL DEFAULT 'charge'; quantity INT NOT NULL;
unit_amount_yen BIGINT NOT NULL; amount_yen BIGINT NOT NULL''',
[fk('invoice_id','portal_invoices'),fk('fee_rule_id','portal_fee_rules'),fk('membership_id','portal_memberships'),fk('scheme_enrollment_id','portal_scheme_enrollments'),fk('club_year_id','portal_club_years'),
'CHECK (quantity > 0)','CHECK (amount_yen = quantity * unit_amount_yen)',"CHECK ((line_type='charge' AND amount_yen >= 0) OR line_type='adjustment')"])
table('portal_bank_imports', ID+'''; account_id BIGINT UNSIGNED NOT NULL; file_sha256 BINARY(32) NOT NULL; parser_version VARCHAR(32) NOT NULL;
status VARCHAR(16) NOT NULL DEFAULT 'preview'; imported_by BIGINT UNSIGNED NOT NULL; imported_at DATETIME(6) NOT NULL''',
[fk('account_id','portal_bank_accounts'),fk('imported_by','users'),'UNIQUE (account_id,file_sha256)','UNIQUE (id,account_id)',"CHECK (status IN ('preview','confirmed','rejected'))"])
table('portal_payments', ID+'''; account_id BIGINT UNSIGNED NOT NULL; import_id BIGINT UNSIGNED NULL;
source_type VARCHAR(16) NOT NULL; source_key VARCHAR(128) NOT NULL; received_at DATETIME(6) NOT NULL;
amount_yen BIGINT NOT NULL; payer_name_cipher MEDIUMBLOB NOT NULL; status VARCHAR(16) NOT NULL DEFAULT 'pending';
confirmed_by BIGINT UNSIGNED NULL; confirmed_at DATETIME(6) NULL''',
[fk('account_id','portal_bank_accounts'),fk('import_id,account_id','portal_bank_imports','id,account_id'),fk('confirmed_by','users'),
'UNIQUE (account_id,source_type,source_key)','CHECK (amount_yen > 0)',"CHECK (status IN ('pending','confirmed','void'))",'INDEX (received_at,status)'])
table('portal_allocations', ID+'''; payment_id BIGINT UNSIGNED NOT NULL; invoice_line_id BIGINT UNSIGNED NOT NULL;
amount_yen BIGINT NOT NULL; operation_key VARCHAR(80) NOT NULL UNIQUE; reverses_id BIGINT UNSIGNED NULL UNIQUE;
created_by BIGINT UNSIGNED NOT NULL; reason TEXT NULL''',
[fk('payment_id','portal_payments'),fk('invoice_line_id','portal_invoice_lines'),fk('reverses_id','portal_allocations'),fk('created_by','users'),
'CHECK ((reverses_id IS NULL AND amount_yen > 0) OR (reverses_id IS NOT NULL AND amount_yen < 0))'],False)
table('portal_refunds', ID+'''; payment_id BIGINT UNSIGNED NOT NULL; amount_yen BIGINT NOT NULL; status VARCHAR(16) NOT NULL DEFAULT 'requested';
requested_by BIGINT UNSIGNED NOT NULL; confirmed_by BIGINT UNSIGNED NULL; paid_at DATETIME(6) NULL; external_reference VARCHAR(128) NULL; reason TEXT NOT NULL''',
[fk('payment_id','portal_payments'),fk('requested_by','users'),fk('confirmed_by','users'),'CHECK (amount_yen > 0)',"CHECK (status IN ('requested','approved','paid','rejected'))"])
table('portal_insurance_batches', ID+'''; club_year_id BIGINT UNSIGNED NOT NULL; scheme_code VARCHAR(32) NOT NULL;
kind VARCHAR(16) NOT NULL; status VARCHAR(20) NOT NULL DEFAULT 'draft'; template_version VARCHAR(40) NOT NULL;
submitted_at DATETIME(6) NULL; accepted_at DATETIME(6) NULL; created_by BIGINT UNSIGNED NOT NULL''',
[fk('club_year_id','portal_club_years'),fk('scheme_code','portal_schemes','code'),fk('created_by','users'),"CHECK (kind IN ('initial','additional'))","CHECK (status IN ('draft','submitted','accepted','rejected'))"])
table('portal_insurance_batch_members', 'batch_id BIGINT UNSIGNED NOT NULL; enrollment_id BIGINT UNSIGNED NOT NULL; status VARCHAR(16) NOT NULL DEFAULT \'included\'',
['PRIMARY KEY (batch_id,enrollment_id)',fk('batch_id','portal_insurance_batches'),fk('enrollment_id','portal_scheme_enrollments'),"CHECK (status IN ('included','submitted','rejected'))"])
table('portal_documents', ID+'''; kind VARCHAR(32) NOT NULL; batch_id BIGINT UNSIGNED NULL; storage_key VARCHAR(255) NOT NULL UNIQUE;
sha256 BINARY(32) NOT NULL; template_version VARCHAR(40) NOT NULL; generated_by BIGINT UNSIGNED NOT NULL; generated_at DATETIME(6) NOT NULL;
visibility VARCHAR(24) NOT NULL; expires_at DATETIME(6) NULL''',[fk('batch_id','portal_insurance_batches'),fk('generated_by','users')])
table('portal_audit_events', ID+'''; actor_user_id BIGINT UNSIGNED NULL; action VARCHAR(80) NOT NULL; entity_type VARCHAR(40) NOT NULL;
entity_id BIGINT UNSIGNED NOT NULL; request_id VARCHAR(64) NOT NULL; reason TEXT NULL; changes_redacted TEXT NOT NULL; occurred_at DATETIME(6) NOT NULL''',
[fk('actor_user_id','users'),'INDEX (entity_type,entity_id,occurred_at)'],False)
table('portal_outbox', ID+'''; event_key VARCHAR(100) NOT NULL UNIQUE; event_type VARCHAR(80) NOT NULL; entity_id BIGINT UNSIGNED NOT NULL;
payload_minimal TEXT NOT NULL; status VARCHAR(16) NOT NULL DEFAULT 'pending'; attempts INT UNSIGNED NOT NULL DEFAULT 0;
next_attempt_at DATETIME(6) NULL; sent_at DATETIME(6) NULL''',["CHECK (status IN ('pending','processing','sent','failed'))",'INDEX (status,next_attempt_at)'])
table('portal_migration_links', ID+'''; source_table VARCHAR(64) NOT NULL; source_id VARCHAR(128) NOT NULL;
target_table VARCHAR(64) NOT NULL; target_id BIGINT UNSIGNED NOT NULL; source_hash BINARY(32) NOT NULL;
batch_key VARCHAR(64) NOT NULL; status VARCHAR(20) NOT NULL; reviewed_by BIGINT UNSIGNED NULL''',
[fk('reviewed_by','users'),'UNIQUE (source_table,source_id,target_table)'])
table('portal_retention_policies', 'code VARCHAR(40) PRIMARY KEY; duration_days INT UNSIGNED NULL; enabled BOOLEAN NOT NULL DEFAULT 0; approved_by BIGINT UNSIGNED NULL; approved_at DATETIME(6) NULL',
[fk('approved_by','users'),'CHECK (enabled IN (0,1))','CHECK (enabled=0 OR (duration_days IS NOT NULL AND duration_days > 0 AND approved_by IS NOT NULL AND approved_at IS NOT NULL))'])


def main():
    """CREATEのみの候補DDLを生成。再適用は失敗させ、既存定義の差を隠さない。"""
    header = '-- Path: design/phase2/001_portal_schema.sql\n-- 機能: MariaDB 10.5.29向けportal追加テーブル候補。本番未適用。\n-- 既存users/roles/entryテーブルとビューは変更しない。実行先DBは呼出側で明示する。\n-- 運営未確定値/秘密/実データを投入しない。トランザクションでDDLを戻せるとは限らない。\n\n'
    ddl = '\n\n'.join('CREATE TABLE '+name+' (\n  '+',\n  '.join(items)+'\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;' for name,items in TABLES)
    (ROOT/'001_portal_schema.sql').write_text(header+ddl+'\n', encoding='utf-8', newline='\n')
    down = '-- Path: design/phase2/001_portal_schema.down.lab-only.sql\n-- 機能: 空の隔離DBだけで逆順削除を検証する。本番で実行しない。データ保全の復旧手順ではない。\n'
    (ROOT/'001_portal_schema.down.lab-only.sql').write_text(down+'\n'.join('DROP TABLE '+name+';' for name,_ in reversed(TABLES))+'\n',encoding='utf-8',newline='\n')
    print(f'Generated {len(TABLES)} portal tables')


if __name__ == '__main__':
    main()
