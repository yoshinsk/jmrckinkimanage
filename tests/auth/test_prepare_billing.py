# Path: tests/auth/test_prepare_billing.py
# 機能: 旧entry・連携済みentry・部分連携を区別し、隔離コピーへの会費処理の二重挿入を防ぐ。
from pathlib import Path
import runpy
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
prepare = runpy.run_path(str(ROOT/'integration/prepare_entry_billing.py'))['prepare']


class PrepareBillingTest(unittest.TestCase):
    def test_old_then_replay(self):
        with tempfile.TemporaryDirectory() as directory:
            entry=Path(directory);(entry/'entrant').mkdir();p=entry/'entrant/entries.php'
            p.write_text('$annualPayStatus=$stView->fetch(PDO::FETCH_ASSOC);\n<input name="jmrc_join_member" value="1">\n<label class="form-check-label" for="jmrc_join_member">',encoding='utf-8')
            prepare(entry);first=p.read_bytes();prepare(entry)
            self.assertEqual(first,p.read_bytes())
            self.assertEqual(p.read_text(encoding='utf-8').count('portal_annual_payment_status('),1)

    def test_latest_source_untouched(self):
        source=(ROOT.parent/'jmrckinki/httpdocs/entry/entrant/entries.php').read_bytes()
        with tempfile.TemporaryDirectory() as directory:
            entry=Path(directory);(entry/'entrant').mkdir();p=entry/'entrant/entries.php';p.write_bytes(source)
            prepare(entry);self.assertEqual(source,p.read_bytes())

    def test_partial_refused_without_write(self):
        with tempfile.TemporaryDirectory() as directory:
            entry=Path(directory);(entry/'entrant').mkdir();p=entry/'entrant/entries.php';p.write_text('portal_annual_payment_status(',encoding='utf-8')
            before=p.read_bytes()
            with self.assertRaisesRegex(RuntimeError,'Partial or duplicate'):prepare(entry)
            self.assertEqual(before,p.read_bytes())


if __name__=='__main__':unittest.main()
