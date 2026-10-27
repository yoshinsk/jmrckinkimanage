-- Path: design/phase2/001_portal_schema.sql
-- 機能: MariaDB 10.5.29向けportal追加テーブル候補。本番未適用。
-- 既存users/roles/entryテーブルとビューは変更しない。実行先DBは呼出側で明示する。
-- 運営未確定値/秘密/実データを投入しない。トランザクションでDDLを戻せるとは限らない。

CREATE TABLE portal_account_controls (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  blocked_at DATETIME(6) NULL,
  blocked_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (blocked_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (status IN ('active','blocked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_code VARCHAR(80) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (role_id,permission_code),
  FOREIGN KEY (role_id) REFERENCES portal_roles (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  last_name VARCHAR(64) NULL,
  first_name VARCHAR(64) NULL,
  last_kana VARCHAR(64) NULL,
  first_kana VARCHAR(64) NULL,
  birthday DATE NULL,
  gender VARCHAR(16) NULL,
  postcode VARCHAR(16) NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(32) NULL,
  mobile VARCHAR(32) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_identifiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(24) NOT NULL,
  value_cipher MEDIUMBLOB NOT NULL,
  lookup_hmac BINARY(32) NOT NULL,
  key_version SMALLINT UNSIGNED NOT NULL,
  license_type VARCHAR(40) NULL,
  expires_on DATE NULL,
  verified_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (user_id,kind,lookup_hmac),
  UNIQUE (id,user_id),
  INDEX (kind,lookup_hmac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_years (
  year SMALLINT UNSIGNED PRIMARY KEY,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NOT NULL,
  club_open_at DATETIME(6) NOT NULL,
  club_close_at DATETIME(6) NOT NULL,
  member_self_open_at DATETIME(6) NULL,
  staff_open_at DATETIME(6) NOT NULL,
  source_mode VARCHAR(16) NOT NULL DEFAULT 'legacy',
  policy_version VARCHAR(32) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  CHECK (source_mode IN ('legacy','shadow','portal')),
  CHECK (valid_from < valid_until),
  CHECK (club_open_at < club_close_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_club_categories (
  category_digit CHAR(1) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  CHECK (category_digit REGEXP '^[0-9]$'),
  CHECK (enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_clubs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fixed_code CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  name_full VARCHAR(255) NOT NULL,
  name_short VARCHAR(128) NOT NULL,
  legacy_affiliated_id BIGINT UNSIGNED NULL UNIQUE,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (legacy_affiliated_id) REFERENCES jmrc_affiliated_clubs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (fixed_code REGEXP '^[0-9]{5}$'),
  CHECK (status IN ('pending','active'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_user_role_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  scope_type VARCHAR(12) NOT NULL,
  scope_club_id BIGINT UNSIGNED NULL,
  scope_key BIGINT UNSIGNED AS (IFNULL(scope_club_id,0)) PERSISTENT,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  granted_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  revoked_by BIGINT UNSIGNED NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (role_id) REFERENCES portal_roles (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (scope_club_id) REFERENCES portal_clubs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (user_id,role_id,scope_type,scope_key),
  CHECK ((scope_type='global' AND scope_club_id IS NULL) OR (scope_type='club' AND scope_club_id IS NOT NULL)),
  CHECK (status IN ('pending','active','revoked')),
  CHECK (valid_until IS NULL OR valid_from < valid_until),
  INDEX (user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_applications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(32) NOT NULL,
  applicant_user_id BIGINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  request_key VARCHAR(64) NOT NULL UNIQUE,
  submitted_at DATETIME(6) NULL,
  decided_at DATETIME(6) NULL,
  decided_by BIGINT UNSIGNED NULL,
  reason TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (applicant_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (year) REFERENCES portal_years (year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (id,year),
  CHECK (status IN ('draft','submitted','approved','rejected','returned','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_club_years (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_id BIGINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  category_digit CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_code CHAR(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  application_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  rep_name VARCHAR(128) NOT NULL,
  contact_name VARCHAR(128) NOT NULL,
  postcode VARCHAR(16) NOT NULL,
  address VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  email VARCHAR(190) NOT NULL,
  bank_details_cipher MEDIUMBLOB NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (club_id) REFERENCES portal_clubs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (year) REFERENCES portal_years (year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (category_digit) REFERENCES portal_club_categories (category_digit) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (application_id,year) REFERENCES portal_applications (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (club_id,year),
  UNIQUE (year,display_code),
  UNIQUE (id,year),
  CHECK (display_code REGEXP '^[0-9]{6}$'),
  CHECK (LEFT(display_code,1)=category_digit),
  CHECK (status IN ('pending','approved','returned','rejected')),
  INDEX (year,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_organizer_links (
  legacy_club_user_id BIGINT UNSIGNED NOT NULL,
  portal_club_id BIGINT UNSIGNED NOT NULL,
  verified_by BIGINT UNSIGNED NOT NULL,
  verified_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (legacy_club_user_id,portal_club_id),
  FOREIGN KEY (legacy_club_user_id) REFERENCES clubs (user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (portal_club_id) REFERENCES portal_clubs (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  legacy_event_id BIGINT UNSIGNED NULL UNIQUE,
  organizer_club_year_id BIGINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (legacy_event_id) REFERENCES jmrc_events (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (organizer_club_year_id,year) REFERENCES portal_club_years (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (id,year),
  CHECK (starts_at < ends_at),
  CHECK (status IN ('draft','published','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_memberships (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  member_type VARCHAR(16) NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  application_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  activated_at DATETIME(6) NULL,
  suspended_at DATETIME(6) NULL,
  general_user_key BIGINT UNSIGNED AS (CASE WHEN member_type='general' THEN user_id ELSE NULL END) PERSISTENT,
  temporary_user_key BIGINT UNSIGNED AS (CASE WHEN member_type='temporary' THEN user_id ELSE NULL END) PERSISTENT,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (year) REFERENCES portal_years (year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (event_id,year) REFERENCES portal_events (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (application_id,year) REFERENCES portal_applications (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (id,year),
  UNIQUE (id,user_id),
  UNIQUE (year,general_user_key),
  UNIQUE (event_id,temporary_user_key),
  CHECK ((member_type='general' AND event_id IS NULL) OR (member_type='temporary' AND event_id IS NOT NULL)),
  CHECK (status IN ('pending','active','suspended')),
  CHECK ((valid_from IS NULL AND valid_until IS NULL) OR (valid_from IS NOT NULL AND valid_until IS NOT NULL AND valid_from < valid_until))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_preregistrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_year_id BIGINT UNSIGNED NOT NULL,
  identifier_kind VARCHAR(24) NOT NULL,
  identifier_hmac BINARY(32) NOT NULL,
  key_version SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  created_by BIGINT UNSIGNED NOT NULL,
  matched_user_id BIGINT UNSIGNED NULL,
  matched_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (club_year_id) REFERENCES portal_club_years (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (matched_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (club_year_id,identifier_kind,key_version,identifier_hmac),
  UNIQUE (id,club_year_id),
  CHECK (status IN ('pending','matched','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_affiliations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  club_year_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  requested_at DATETIME(6) NOT NULL,
  decided_at DATETIME(6) NULL,
  decided_by BIGINT UNSIGNED NULL,
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  match_source_id BIGINT UNSIGNED NULL,
  reason TEXT NULL,
  current_membership_key BIGINT UNSIGNED AS (CASE WHEN status='approved' AND valid_until IS NULL THEN membership_id ELSE NULL END) PERSISTENT,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (membership_id,year) REFERENCES portal_memberships (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (club_year_id,year) REFERENCES portal_club_years (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (match_source_id,club_year_id) REFERENCES portal_preregistrations (id,club_year_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (current_membership_key),
  CHECK (status IN ('pending','approved','rejected','returned')),
  CHECK (status<>'approved' OR valid_from IS NOT NULL),
  CHECK (valid_until IS NULL OR (valid_from IS NOT NULL AND valid_from < valid_until))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_member_numbers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  source_kind VARCHAR(24) NOT NULL,
  source_identifier_id BIGINT UNSIGNED NOT NULL,
  club_year_id BIGINT UNSIGNED NOT NULL,
  number_cipher MEDIUMBLOB NOT NULL,
  number_hmac BINARY(32) NOT NULL,
  key_version SMALLINT UNSIGNED NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  current_member_key BIGINT UNSIGNED AS (CASE WHEN valid_until IS NULL THEN membership_id ELSE NULL END) PERSISTENT,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (membership_id,user_id) REFERENCES portal_memberships (id,user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (membership_id,year) REFERENCES portal_memberships (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (source_identifier_id,user_id) REFERENCES portal_identifiers (id,user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (club_year_id,year) REFERENCES portal_club_years (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (current_member_key),
  UNIQUE (membership_id,number_hmac),
  CHECK (valid_until IS NULL OR valid_from < valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_card_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  token_hash BINARY(32) NOT NULL UNIQUE,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  issued_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (membership_id) REFERENCES portal_memberships (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_qualification_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  purpose_code VARCHAR(32) NOT NULL,
  basis VARCHAR(24) NOT NULL,
  source_table VARCHAR(64) NOT NULL,
  source_id VARCHAR(128) NOT NULL,
  source_hash BINARY(32) NOT NULL,
  confirmed_by BIGINT UNSIGNED NOT NULL,
  confirmed_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  reason TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (membership_id) REFERENCES portal_memberships (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (membership_id,purpose_code,source_table,source_id),
  CHECK (basis IN ('legacy_payment','waiver'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_schemes (
  code VARCHAR(32) PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  owner_scope VARCHAR(32) NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  CHECK (enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_scheme_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  membership_id BIGINT UNSIGNED NOT NULL,
  scheme_code VARCHAR(32) NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  application_id BIGINT UNSIGNED NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'requested',
  coverage_from DATETIME(6) NULL,
  coverage_until DATETIME(6) NULL,
  beneficiary_cipher MEDIUMBLOB NULL,
  external_reference VARCHAR(128) NULL,
  activated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (membership_id,year) REFERENCES portal_memberships (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (scheme_code) REFERENCES portal_schemes (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (application_id,year) REFERENCES portal_applications (id,year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (activated_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (membership_id,scheme_code,year),
  CHECK (status IN ('requested','batched','submitted','active','rejected')),
  CHECK ((coverage_from IS NULL AND coverage_until IS NULL) OR (coverage_from IS NOT NULL AND coverage_until IS NOT NULL AND coverage_from < coverage_until))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_fee_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  year SMALLINT UNSIGNED NOT NULL,
  purpose_code VARCHAR(32) NOT NULL,
  member_type VARCHAR(16) NOT NULL,
  amount_yen BIGINT NOT NULL,
  charge_unit VARCHAR(16) NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (year) REFERENCES portal_years (year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (amount_yen >= 0),
  CHECK (valid_from < valid_until),
  CHECK (enabled IN (0,1)),
  INDEX (year,purpose_code,member_type,valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_bank_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  purpose_code VARCHAR(32) NOT NULL,
  year SMALLINT UNSIGNED NULL,
  display_name VARCHAR(128) NOT NULL,
  details_cipher MEDIUMBLOB NOT NULL,
  payment_url VARCHAR(2048) NULL,
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  enabled BOOLEAN NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (year) REFERENCES portal_years (year) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (enabled IN (0,1)),
  CHECK (valid_until IS NULL OR (valid_from IS NOT NULL AND valid_from < valid_until))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  invoice_no VARCHAR(40) NOT NULL UNIQUE,
  payer_user_id BIGINT UNSIGNED NULL,
  payer_club_year_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  due_at DATETIME(6) NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  account_snapshot_cipher MEDIUMBLOB NOT NULL,
  payment_url_snapshot VARCHAR(2048) NULL,
  idempotency_key VARCHAR(80) NOT NULL UNIQUE,
  issued_at DATETIME(6) NULL,
  finalized_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (application_id) REFERENCES portal_applications (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (payer_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (payer_club_year_id) REFERENCES portal_club_years (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (account_id) REFERENCES portal_bank_accounts (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK ((payer_user_id IS NULL) <> (payer_club_year_id IS NULL)),
  CHECK (status IN ('draft','issued','finalized','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_invoice_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  fee_rule_id BIGINT UNSIGNED NULL,
  purpose_code VARCHAR(32) NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  scheme_enrollment_id BIGINT UNSIGNED NULL,
  club_year_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  line_type VARCHAR(16) NOT NULL DEFAULT 'charge',
  quantity INT NOT NULL,
  unit_amount_yen BIGINT NOT NULL,
  amount_yen BIGINT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (invoice_id) REFERENCES portal_invoices (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (fee_rule_id) REFERENCES portal_fee_rules (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (membership_id) REFERENCES portal_memberships (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (scheme_enrollment_id) REFERENCES portal_scheme_enrollments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (club_year_id) REFERENCES portal_club_years (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (quantity > 0),
  CHECK (amount_yen = quantity * unit_amount_yen),
  CHECK ((line_type='charge' AND amount_yen >= 0) OR line_type='adjustment')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_bank_imports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  file_sha256 BINARY(32) NOT NULL,
  parser_version VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'preview',
  imported_by BIGINT UNSIGNED NOT NULL,
  imported_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (account_id) REFERENCES portal_bank_accounts (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (account_id,file_sha256),
  UNIQUE (id,account_id),
  CHECK (status IN ('preview','confirmed','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  import_id BIGINT UNSIGNED NULL,
  source_type VARCHAR(16) NOT NULL,
  source_key VARCHAR(128) NOT NULL,
  received_at DATETIME(6) NOT NULL,
  amount_yen BIGINT NOT NULL,
  payer_name_cipher MEDIUMBLOB NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (account_id) REFERENCES portal_bank_accounts (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (import_id,account_id) REFERENCES portal_bank_imports (id,account_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (account_id,source_type,source_key),
  CHECK (amount_yen > 0),
  CHECK (status IN ('pending','confirmed','void')),
  INDEX (received_at,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  invoice_line_id BIGINT UNSIGNED NOT NULL,
  amount_yen BIGINT NOT NULL,
  operation_key VARCHAR(80) NOT NULL UNIQUE,
  reverses_id BIGINT UNSIGNED NULL UNIQUE,
  created_by BIGINT UNSIGNED NOT NULL,
  reason TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (payment_id) REFERENCES portal_payments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (invoice_line_id) REFERENCES portal_invoice_lines (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (reverses_id) REFERENCES portal_allocations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK ((reverses_id IS NULL AND amount_yen > 0) OR (reverses_id IS NOT NULL AND amount_yen < 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount_yen BIGINT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'requested',
  requested_by BIGINT UNSIGNED NOT NULL,
  confirmed_by BIGINT UNSIGNED NULL,
  paid_at DATETIME(6) NULL,
  external_reference VARCHAR(128) NULL,
  reason TEXT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (payment_id) REFERENCES portal_payments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (confirmed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (amount_yen > 0),
  CHECK (status IN ('requested','approved','paid','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_insurance_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  club_year_id BIGINT UNSIGNED NOT NULL,
  scheme_code VARCHAR(32) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  template_version VARCHAR(40) NOT NULL,
  submitted_at DATETIME(6) NULL,
  accepted_at DATETIME(6) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (club_year_id) REFERENCES portal_club_years (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (scheme_code) REFERENCES portal_schemes (code) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (kind IN ('initial','additional')),
  CHECK (status IN ('draft','submitted','accepted','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_insurance_batch_members (
  batch_id BIGINT UNSIGNED NOT NULL,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'included',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (batch_id,enrollment_id),
  FOREIGN KEY (batch_id) REFERENCES portal_insurance_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (enrollment_id) REFERENCES portal_scheme_enrollments (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (status IN ('included','submitted','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(32) NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  storage_key VARCHAR(255) NOT NULL UNIQUE,
  sha256 BINARY(32) NOT NULL,
  template_version VARCHAR(40) NOT NULL,
  generated_by BIGINT UNSIGNED NOT NULL,
  generated_at DATETIME(6) NOT NULL,
  visibility VARCHAR(24) NOT NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (batch_id) REFERENCES portal_insurance_batches (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  FOREIGN KEY (generated_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  request_id VARCHAR(64) NOT NULL,
  reason TEXT NULL,
  changes_redacted TEXT NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  INDEX (entity_type,entity_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_key VARCHAR(100) NOT NULL UNIQUE,
  event_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  payload_minimal TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NULL,
  sent_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  CHECK (status IN ('pending','processing','sent','failed')),
  INDEX (status,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_migration_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_table VARCHAR(64) NOT NULL,
  source_id VARCHAR(128) NOT NULL,
  target_table VARCHAR(64) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  source_hash BINARY(32) NOT NULL,
  batch_key VARCHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  UNIQUE (source_table,source_id,target_table)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE portal_retention_policies (
  code VARCHAR(40) PRIMARY KEY,
  duration_days INT UNSIGNED NULL,
  enabled BOOLEAN NOT NULL DEFAULT 0,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CHECK (enabled IN (0,1)),
  CHECK (enabled=0 OR (duration_days IS NOT NULL AND duration_days > 0 AND approved_by IS NOT NULL AND approved_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
