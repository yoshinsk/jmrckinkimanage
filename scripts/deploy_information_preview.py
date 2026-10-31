# Path: scripts/deploy_information_preview.py
# 機能: 旧HP試作の8ファイルを限定配置し、DB・設定を変更せずに検査・バックアップ・復元する。
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import socket
import struct
import subprocess
from datetime import datetime, timezone

SITE = Path('/var/www/vhosts/gymkinki.com')
PHP = '/opt/plesk/php/8.4/bin/php'
SOCKET = '/var/www/vhosts/system/gymkinki.com/php-fpm.sock'
# 依存ファイル→共通bootstrap→新画面→導線の順。公開ディレクトリに内部ソースを置かない。
ORDER = ['src/Content/InformationPreview.php', 'src/Content/information-catalog.php',
         'src/Content/information-template.php', 'public/manage/assets/information.css',
         'src/bootstrap.php', 'public/manage/information-preview.php',
         'public/manage/review.php', 'public/manage/index.php']
EXISTING = ['src/bootstrap.php', 'public/manage/review.php', 'public/manage/index.php']


def require(condition, message):
    """Pythonの最適化オプションでも事前条件の検査を省略しない。"""
    if not condition:
        raise RuntimeError(message)


def sha(path):
    """設定内容や個人情報を出力せず、同一性の確認だけにハッシュを使う。"""
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None


class Release:
    """currentのリンク先を固定し、許可したファイル以外への書込を拒否する。"""
    def __init__(self, site=SITE):
        self.site = site.resolve()
        self.private = self.site/'private/jmrc-portal'
        self.source = (self.private/'current').resolve(strict=True)
        require(self.source.is_relative_to(self.private/'releases'), 'Unexpected release root')
        self.web = self.site/'httpdocs'
        self.backups = self.site/'private/jmrc-portal-backups'

    def target(self, name):
        """currentだけを既知のリンクとして許可し、その先の各親と対象のリンクを拒否する。"""
        require(name in ORDER, 'Path is not in release allowlist')
        require((self.private/'current').resolve(strict=True) == self.source, 'Current release changed')
        root = self.web if name.startswith('public/') else self.source
        path = root/(name[len('public/'):] if name.startswith('public/') else name)
        for part in [path, *path.parents]:
            if part == self.site:
                break
            require(not part.is_symlink(), 'Symlink in target path: '+str(part))
        require(path.resolve().is_relative_to(root), 'Target outside release root')
        require(not path.exists() or path.is_file(), 'Target is not a regular file')
        return path

    def snapshot(self):
        """既存ソース・設定と業務表を読み取りだけで比較。認証操作の監査表だけを除く。"""
        excluded = {self.target(n) for n in ORDER}
        paths = set()
        for root in [self.web/'manage', self.source/'src', self.web/'entry']:
            # セッション・添付・実行時キャッシュを走査対象に含めない。
            for folder, dirs, files in os.walk(root):
                dirs[:] = [d for d in dirs if d not in ['storage', 'uploads', 'logs', 'sessions', '.git']
                           and not (Path(folder)/d).is_symlink()]
                paths.update(Path(folder)/n for n in files if Path(n).suffix in ['.php', '.css', '.js']
                             or n in ['.htaccess', '.user.ini'])
        paths.add(self.private/'auth.config.php')
        paths -= excluded
        require(all(p.is_file() and not p.is_symlink() for p in paths), 'Protected source missing or linked')
        # mariadbへはSELECT/SHOW/CHECKSUMだけを渡す。DB資格情報は子プロセス環境内に限る。
        env = os.environ.copy()
        env['MYSQL_PWD'] = Path('/etc/psa/.psa.shadow').read_text().strip()
        def query(sql):
            result = subprocess.run(['mariadb', '-uadmin', '-N', '-B', '--database=jmrckinki'],
                                    input=sql.encode(), env=env, capture_output=True, check=True)
            return result.stdout.decode().strip()
        tables = query("SELECT table_name FROM information_schema.tables WHERE table_schema='jmrckinki' "
                       "AND table_type='BASE TABLE' AND table_name NOT IN ('audit_logs','portal_audit_events') "
                       'ORDER BY table_name').splitlines()
        require(tables and all(t.replace('_', '').isalnum() for t in tables), 'Unexpected table name')
        checks = query('CHECKSUM TABLE '+','.join('`'+t+'`' for t in tables)+';')
        require('NULL' not in checks and len(checks.splitlines()) == len(tables), 'Incomplete table checksums')
        counts = {t: int(query('SELECT COUNT(*) FROM `'+t+'`;')) for t in
                  ['users', 'jmrc_entries', 'jmrc_entry_fees', 'portal_profiles', 'portal_applications',
                   'portal_memberships', 'portal_invoices', 'portal_payments']}
        flags_code = "<?php $c=require '"+str(self.private/'auth.config.php')+"';$o=[];foreach(" \
            "['profile_corrections_enabled','bank_master_enabled','license_self_service_enabled'," \
            "'insurance_intents_enabled','yucho_import_enabled'] as $k)$o[$k]=$c[$k]??false;echo json_encode($o);"
        flags = json.loads(subprocess.run([PHP], input=flags_code.encode(), capture_output=True, check=True).stdout)
        return {'release_root': str(self.source), 'protected_files': {str(p): sha(p) for p in sorted(paths)},
                'business_checksums': checks, 'counts': counts, 'flags': flags,
                'years': query('SELECT year,source_mode FROM portal_years ORDER BY year;'),
                'permission_roles': query("SELECT r.code FROM portal_roles r JOIN portal_role_permissions p "
                                          "ON p.role_id=r.id WHERE p.permission_code='profiles.correct' ORDER BY r.code;")}

    def preflight(self):
        """必要な保留設定と既存3・新規5ファイルの状態を、変更せずに取得する。"""
        state = self.snapshot()
        require(all(state['flags'][k] is False for k in ['license_self_service_enabled',
                'insurance_intents_enabled', 'yucho_import_enabled']), 'Held feature enabled')
        require(state['years'] == '2027\tshadow', 'Year source mode changed')
        require(state['permission_roles'] == 'OFFICE', 'Preview permission is not office-only')
        return {'before': state, 'files': {n: {'old_sha256': sha(self.target(n))} for n in ORDER}}

    def cache(self):
        """公開URLや全体再起動を使わず、変更したPHPだけを既存FPMソケットで失効させる。"""
        probe = self.private/('information_cache_'+str(os.getpid())+'.php')
        require(not probe.exists() and not probe.is_symlink(), 'Cache probe path occupied')
        targets = [str(self.target(n)) for n in ORDER if n.endswith('.php')]
        code = "<?php $ok=true;foreach(json_decode('"+json.dumps(targets)+"') as $p){$ok=" \
            "(!function_exists('opcache_invalidate') || !opcache_is_script_cached($p) || " \
            "opcache_invalidate($p,true)) && $ok;}if($ok)echo 'INFORMATION_CACHE_OK';"
        st = self.target('src/bootstrap.php').stat()
        # 原子作成・非公開0600。例外時にもプローブを残さない。
        try:
            with probe.open('x') as handle:
                os.chmod(probe, 0o600)
                os.chown(probe, st.st_uid, st.st_gid)
                handle.write(code)
            def record(kind, body):
                padding = (-len(body)) % 8
                return struct.pack('!BBHHBB', 1, kind, 1, len(body), padding, 0)+body+b'\0'*padding
            def length(n):
                return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)
            params = {'SCRIPT_FILENAME': str(probe), 'SCRIPT_NAME': '/information_cache.php',
                      'REQUEST_METHOD': 'GET', 'SERVER_PROTOCOL': 'HTTP/1.1', 'SERVER_NAME': 'gymkinki.com',
                      'REMOTE_ADDR': '127.0.0.1', 'HTTPS': 'on'}
            data = b''.join(length(len(k.encode()))+length(len(v.encode()))+k.encode()+v.encode()
                            for k, v in params.items())
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as channel:
                channel.settimeout(20)
                channel.connect(SOCKET)
                channel.sendall(record(1, struct.pack('!HB5x', 1, 0))+record(4, data)+record(4, b'')+record(5, b''))
                response = b''
                while chunk := channel.recv(65536):
                    response += chunk
            require(b'INFORMATION_CACHE_OK' in response, 'Scoped OPcache invalidation failed')
        finally:
            probe.unlink(missing_ok=True)

    def replace(self, source, name, metadata=None):
        """同一ディレクトリでlint・原子renameし、既存所有者とモードを引き継ぐ。"""
        target = self.target(name)
        reference = 'public/manage/index.php' if name.startswith('public/') else 'src/bootstrap.php'
        st = (target if target.exists() else self.target(reference)).stat()
        mode, uid, gid = metadata or (st.st_mode & 0o777, st.st_uid, st.st_gid)
        candidate = target.with_name('.information-release-'+str(os.getpid())+target.suffix)
        require(not candidate.exists() and not candidate.is_symlink(), 'Candidate path occupied')
        try:
            with candidate.open('xb') as handle:
                os.chmod(candidate, mode)
                os.chown(candidate, uid, gid)
                handle.write(source.read_bytes())
            if name.endswith('.php'):
                subprocess.run([PHP, '-l', str(candidate)], capture_output=True, check=True)
            os.replace(candidate, target)
        finally:
            candidate.unlink(missing_ok=True)

    def restore(self, backup, manifest, strict=True):
        """全対象の後続変更を先に検査し、元の3ファイルを復元・新規5ファイルだけを撤去する。"""
        require(set(manifest['files']) == set(ORDER), 'Manifest file allowlist mismatch')
        require(manifest['before']['release_root'] == str(self.source), 'Release root changed')
        for name in ORDER:
            spec = manifest['files'][name]
            allowed = [spec['new_sha256']] if strict else [spec['old_sha256'], spec['new_sha256']]
            require(sha(self.target(name)) in allowed, 'Later source change: '+name)
            require((spec['old_sha256'] is not None) == (name in EXISTING), 'Unexpected prior file state')
        for name in EXISTING:
            require(sha(backup/'original'/name) == manifest['files'][name]['old_sha256'], 'Backup integrity failure')
        # 導線→新しい入口の撤去→bootstrap→私有依存ファイルの順に戻す。
        for name in reversed(ORDER):
            if name in EXISTING:
                self.replace(backup/'original'/name, name, manifest['files'][name]['metadata'])
            else:
                self.target(name).unlink(missing_ok=True)
            if name == 'src/bootstrap.php':
                self.cache()
        content = self.source/'src/Content'
        if manifest.get('created_content_directory') and content.exists():
            # 後から別のファイルが追加されていた場合は空ディレクトリ以外を削除しない。
            require(not content.is_symlink(), 'Content directory became a symlink')
            if not any(content.iterdir()):
                content.rmdir()
        self.cache()

    def apply(self, stage):
        """全事前条件を満たした時だけバックアップを作り、失敗時は限定したコードを戻す。"""
        expected = json.loads((stage/'payload.json').read_text())
        require(set(expected['files']) == set(ORDER), 'Payload file allowlist mismatch')
        for name in ORDER:
            spec = expected['files'][name]
            require((spec['old_sha256'] is not None) == (name in EXISTING), 'Unexpected prior file state')
            require(sha(self.target(name)) == spec['old_sha256'], 'Live source drift: '+name)
            source = stage/name
            require(source.is_file() and not source.is_symlink(), 'Missing staged file: '+name)
            require(sha(source) == spec['new_sha256'], 'Payload hash mismatch: '+name)
            if name.endswith('.php'):
                subprocess.run([PHP, '-l', str(source)], capture_output=True, check=True)
        require(self.preflight()['before'] == expected['before'], 'Protected state changed before apply')
        require(self.backups.is_dir() and not self.backups.is_symlink(), 'Backup directory invalid')
        backup = self.backups/('information-preview-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
        backup.mkdir(mode=0o700)
        manifest = {**expected, 'backup': str(backup), 'phase': 'backed_up',
                    'created_content_directory': not (self.source/'src/Content').exists()}
        for name in EXISTING:
            source = self.target(name)
            st = source.stat()
            manifest['files'][name]['metadata'] = [st.st_mode & 0o777, st.st_uid, st.st_gid]
            target = backup/'original'/name
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            shutil.copyfile(source, target)
            os.chmod(target, 0o600)
            require(sha(target) == manifest['files'][name]['old_sha256'], 'Backup copy mismatch')
        shutil.copyfile(Path(__file__), backup/'deploy_information_preview.py')
        os.chmod(backup/'deploy_information_preview.py', 0o600)
        def save_manifest():
            (backup/'manifest.json').write_text(json.dumps(manifest, indent=2))
            os.chmod(backup/'manifest.json', 0o600)
        save_manifest()
        try:
            if manifest['created_content_directory']:
                parent = (self.source/'src').stat()
                (self.source/'src/Content').mkdir(mode=parent.st_mode & 0o777)
                os.chown(self.source/'src/Content', parent.st_uid, parent.st_gid)
            for name in ORDER:
                self.replace(stage/name, name)
                if name == 'src/bootstrap.php':
                    self.cache()
            self.cache()
            require(all(sha(self.target(n)) == expected['files'][n]['new_sha256'] for n in ORDER), 'Deployed source mismatch')
            require(self.snapshot() == expected['before'], 'Protected state changed after apply')
        except Exception:
            try:
                self.restore(backup, manifest, strict=False)
            except Exception as error:
                manifest['phase'] = 'rollback_failed'
                save_manifest()
                raise RuntimeError('Rollback incomplete; preserve private dependencies and inspect '+str(backup)) from error
            manifest['phase'] = 'rolled_back'
            save_manifest()
            raise
        manifest['phase'] = 'applied'
        save_manifest()
        return {'status': 'PASS', 'backup': str(backup), 'protected_state_unchanged': True,
                'files': manifest['files'], 'script_sha256': sha(backup/'deploy_information_preview.py')}


def main():
    """rootでの明示モード指定のみ許可。復元対象は私有バックアップ配下の固定manifestに限る。"""
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--preflight', action='store_true')
    group.add_argument('--apply', type=Path, metavar='STAGE')
    group.add_argument('--rollback', type=Path, metavar='BACKUP')
    args = parser.parse_args()
    require(os.geteuid() == 0, 'Root required')
    release = Release()
    if args.preflight:
        result = release.preflight()
    elif args.apply:
        result = release.apply(args.apply.resolve(strict=True))
    else:
        backup = args.rollback.resolve(strict=True)
        require(backup.parent == release.backups and not args.rollback.is_symlink(), 'Unexpected backup path')
        manifest = json.loads((backup/'manifest.json').read_text())
        require(manifest['phase'] in ['applied', 'backed_up', 'rollback_failed'], 'Backup already restored or invalid')
        before = release.snapshot()
        release.restore(backup, manifest, strict=manifest['phase'] == 'applied')
        require(release.snapshot() == before, 'Protected state changed during rollback')
        manifest['phase'] = 'rolled_back'
        (backup/'manifest.json').write_text(json.dumps(manifest, indent=2))
        result = {'status': 'PASS', 'rollback': True, 'protected_state_unchanged': True}
    print(json.dumps(result))


if __name__ == '__main__':
    main()
