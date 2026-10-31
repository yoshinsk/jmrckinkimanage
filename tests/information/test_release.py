# Path: tests/information/test_release.py
# 機能: 一時ディレクトリで限定配置・復元・改変検知を検証。本番接続とDB操作は行わない。
import copy
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'scripts'))
import deploy_information_preview as deploy


class LabRelease(deploy.Release):
    """ファイル操作は実行し、DB集計とFPM呼出しだけを架空状態へ置換する。"""
    def snapshot(self):
        return {'release_root': str(self.source), 'protected_files': {'config': deploy.sha(self.private/'auth.config.php')},
                'business_checksums': 'fixture', 'counts': {'users': 1},
                'flags': {'license_self_service_enabled': False, 'insurance_intents_enabled': False,
                          'yucho_import_enabled': False}, 'years': '2027\tshadow', 'permission_roles': 'OFFICE'}

    def cache(self):
        if getattr(self, 'fail_cache_once', False):
            self.fail_cache_once = False
            raise RuntimeError('Synthetic cache failure')


class ReleaseTest(unittest.TestCase):
    """LINUXの実ファイル権限・symlink・renameを使い、破壊範囲が8ファイル以内か調べる。"""
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='jmrc-information-rollback-')
        self.root = Path(self.temp.name)
        private = self.root/'private/jmrc-portal'
        source = private/'releases/fixture'
        (source/'src').mkdir(parents=True)
        (private/'current').symlink_to(source, target_is_directory=True)
        (private/'auth.config.php').write_text('protected configuration')
        (self.root/'httpdocs/manage/assets').mkdir(parents=True)
        (self.root/'private/jmrc-portal-backups').mkdir()
        self.release = LabRelease(self.root)
        for name in deploy.EXISTING:
            target = self.release.target(name)
            target.write_text('<?php // original '+name+'\n')
            target.chmod(0o640)
        self.stage = self.root/'stage'
        for name in deploy.ORDER:
            target = self.stage/name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text('<?php // replacement '+name+'\n' if name.endswith('.php') else 'body{}')
        self.payload = self.release.preflight()
        for name in deploy.ORDER:
            self.payload['files'][name]['new_sha256'] = deploy.sha(self.stage/name)
        self.write_payload()
        # PHP構文は隔離PHP8.4・本番配置前lintで別途検査。ここは復元境界の試験に限定する。
        self.saved_php = deploy.PHP
        deploy.PHP = '/usr/bin/true'

    def tearDown(self):
        deploy.PHP = self.saved_php
        self.temp.cleanup()

    def write_payload(self):
        (self.stage/'payload.json').write_text(json.dumps(self.payload))

    def deployed(self):
        result = self.release.apply(self.stage)
        backup = Path(result['backup'])
        return backup, json.loads((backup/'manifest.json').read_text())

    def hashes(self):
        return {n: deploy.sha(self.release.target(n)) for n in deploy.ORDER}

    def assert_original(self):
        self.assertEqual(self.hashes(), {n: s['old_sha256'] for n, s in self.payload['files'].items()})
        self.assertEqual(self.release.snapshot(), self.payload['before'])

    def test_apply_and_restore(self):
        backup, manifest = self.deployed()
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup/'manifest.json').stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.hashes(), {n: s['new_sha256'] for n, s in self.payload['files'].items()})
        self.release.restore(backup, manifest)
        self.assert_original()
        self.assertFalse((self.release.source/'src/Content').exists())
        for name in deploy.EXISTING:
            self.assertEqual(self.release.target(name).stat().st_mode & 0o777, 0o640)

    def test_bad_payload_refused_before_backup(self):
        (self.stage/deploy.ORDER[0]).write_text('changed')
        with self.assertRaisesRegex(RuntimeError, 'Payload hash mismatch'):
            self.release.apply(self.stage)
        self.assert_original()
        self.assertEqual(list(self.release.backups.iterdir()), [])

    def test_live_drift_refused(self):
        self.release.target('public/manage/index.php').write_text('later change')
        before = self.hashes()
        with self.assertRaisesRegex(RuntimeError, 'Live source drift'):
            self.release.apply(self.stage)
        self.assertEqual(self.hashes(), before)

    def test_protected_drift_refused(self):
        (self.release.private/'auth.config.php').write_text('later configuration')
        with self.assertRaisesRegex(RuntimeError, 'Protected state changed'):
            self.release.apply(self.stage)
        self.assertEqual(list(self.release.backups.iterdir()), [])

    def test_rollback_later_edit_refused_before_changes(self):
        backup, manifest = self.deployed()
        self.release.target('src/Content/information-template.php').write_text('later change')
        before = self.hashes()
        with self.assertRaisesRegex(RuntimeError, 'Later source change'):
            self.release.restore(backup, manifest)
        self.assertEqual(self.hashes(), before)

    def test_corrupt_backup_refused_before_changes(self):
        backup, manifest = self.deployed()
        (backup/'original/src/bootstrap.php').write_text('bad backup')
        before = self.hashes()
        with self.assertRaisesRegex(RuntimeError, 'Backup integrity failure'):
            self.release.restore(backup, manifest)
        self.assertEqual(self.hashes(), before)

    def test_symlink_parent_refused(self):
        outside = self.root/'outside'
        outside.mkdir()
        (self.release.source/'src/Content').symlink_to(outside, target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, 'Symlink in target'):
            self.release.apply(self.stage)
        self.assertEqual(list(outside.iterdir()), [])

    def test_changed_current_refused(self):
        alternate = self.release.private/'releases/other'
        alternate.mkdir()
        (self.release.private/'current').unlink()
        (self.release.private/'current').symlink_to(alternate)
        with self.assertRaisesRegex(RuntimeError, 'Current release changed'):
            self.release.target('src/bootstrap.php')

    def test_partial_apply_restores_automatically(self):
        self.release.fail_cache_once = True
        with self.assertRaisesRegex(RuntimeError, 'Synthetic cache failure'):
            self.release.apply(self.stage)
        self.assert_original()
        backup = next(self.release.backups.iterdir())
        self.assertEqual(json.loads((backup/'manifest.json').read_text())['phase'], 'rolled_back')

    def test_extra_file_payload_refused(self):
        self.payload['files']['public/manage/../../entry/login.php'] = {}
        self.write_payload()
        with self.assertRaisesRegex(RuntimeError, 'allowlist mismatch'):
            self.release.apply(self.stage)
        self.assertEqual(list(self.release.backups.iterdir()), [])

    def test_unrelated_added_file_retained(self):
        backup, manifest = self.deployed()
        extra = self.release.source/'src/Content/later.txt'
        extra.write_text('unrelated')
        self.release.restore(backup, manifest)
        self.assert_original()
        self.assertEqual(extra.read_text(), 'unrelated')

    def test_non_office_permission_refused(self):
        state = self.release.snapshot()
        state['permission_roles'] = 'FINANCE\nOFFICE'
        self.release.snapshot = lambda: copy.deepcopy(state)
        with self.assertRaisesRegex(RuntimeError, 'not office-only'):
            self.release.preflight()

    def test_answers_update_and_restore_keeps_original_preview(self):
        # 初回配置の後に3ファイルだけを更新し、回答版の復元では初回試作の5新規ファイルも残す。
        self.deployed()
        baseline = self.hashes()
        update = LabRelease(self.root, profile='answers')
        payload = update.preflight()
        payload['profile'] = 'answers'
        for name in update.order:
            (self.stage/name).write_text('<?php // answers update '+name+'\n')
            payload['files'][name]['new_sha256'] = deploy.sha(self.stage/name)
        (self.stage/'payload.json').write_text(json.dumps(payload))
        backup = Path(update.apply(self.stage)['backup'])
        manifest = json.loads((backup/'manifest.json').read_text())
        self.assertEqual(manifest['profile'], 'answers')
        self.assertEqual(len(manifest['files']), 3)
        update.restore(backup, manifest)
        self.assertEqual(self.hashes(), baseline)
        self.assertTrue(self.release.target('public/manage/information-preview.php').is_file())

    def test_answers_profile_refuses_bootstrap(self):
        with self.assertRaisesRegex(RuntimeError, 'allowlist'):
            LabRelease(self.root, profile='answers').target('src/bootstrap.php')

    def test_profile_mismatch_refused(self):
        self.payload['profile'] = 'answers'
        self.write_payload()
        with self.assertRaisesRegex(RuntimeError, 'profile mismatch'):
            self.release.apply(self.stage)
        self.assert_original()


if __name__ == '__main__':
    unittest.main()
