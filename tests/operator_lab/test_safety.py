# Path: tests/operator_lab/test_safety.py
# 機能: 配布・配置の対象固定と既存出力の保護を、実サーバーへ接続せず検証する。
from pathlib import Path
import importlib.util,sys,tempfile,types,unittest
ROOT=Path(__file__).resolve().parents[2]
if sys.platform=='win32':sys.modules.setdefault('pwd',types.SimpleNamespace())
def module(name):
 spec=importlib.util.spec_from_file_location(name,ROOT/'scripts/operator_lab'/f'{name}.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
install=module('install');build=module('build');reset=module('reset')
class Safety(unittest.TestCase):
 def test_only_dedicated_target(self):install.validate_root()
 def test_production_host_rejected(self):
  with self.assertRaises(ValueError):install.validate_root(Path('/var/www/vhosts/gymkinki.com'))
 def test_production_database_rejected(self):
  with self.assertRaises(ValueError):install.validate_root(database='jmrckinki')
 def test_other_host_rejected(self):
  with self.assertRaises(ValueError):install.validate_root(Path('/tmp/lab'))
 def test_existing_output_preserved(self):
  with tempfile.TemporaryDirectory() as p:
   out=Path(p)/'existing';out.mkdir();(out/'note').write_text('keep')
   with self.assertRaises(ValueError):build.build(out)
   self.assertEqual((out/'note').read_text(),'keep')
 def test_reset_requires_exact_marker(self):
  reset.validate({'kind':'jmrc-operator-lab','domain':reset.DOMAIN,'database':reset.DB},reset.DOMAIN)
 def test_reset_rejects_production_db(self):
  with self.assertRaises(ValueError):reset.validate({'kind':'jmrc-operator-lab','domain':reset.DOMAIN,'database':'jmrckinki'},reset.DOMAIN)
 def test_reset_rejects_missing_confirmation(self):
  with self.assertRaises(ValueError):reset.validate({'kind':'jmrc-operator-lab','domain':reset.DOMAIN,'database':reset.DB},'yes')
if __name__=='__main__':unittest.main()
