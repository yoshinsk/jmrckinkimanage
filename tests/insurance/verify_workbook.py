# Path: tests/insurance/verify_workbook.py
# 機能: PHP出力Excelを独立したZIP/XML実装で照合。様式保持・保留欄・人数境界・式注入防止を検証する。
from pathlib import Path
from zipfile import ZipFile
from xml.etree import ElementTree as E
import hashlib
import json
import sys
sys.stdout.reconfigure(encoding='utf-8')

ROOT = Path(__file__).resolve().parents[2]
NS = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
cases = []


def check(name, value):
    assert value, name
    cases.append({'case': name, 'result': 'PASS'})


template = ROOT/'templates/insurance/sports.security.insurance_form_club.xlsx'
check('指定原本のハッシュ固定', hashlib.sha256(template.read_bytes()).hexdigest() == 'ec52243187269f4cb84a28a7fad445eb3b20eb667e2854ee46950d82d0a59613')
with ZipFile(template) as source:
    original = E.fromstring(source.read('xl/worksheets/sheet1.xml'))
    for filename in ['insurance-preview.xlsx', 'insurance-preview-24.xlsx', 'insurance-formula.xlsx']:
        with ZipFile(ROOT/'tmp/insurance_validation'/filename) as output:
            check(filename+' ZIP整合', output.testzip() is None)
            sheet = E.fromstring(output.read('xl/worksheets/sheet1.xml'))
            cells = {c.get('r'): c for c in sheet.findall('.//s:c', NS)}

            def text(ref):
                return ''.join(cells[ref].itertext())

            check(filename+' 書式・印刷設定・結合保持', all(E.tostring(original.find('s:'+tag, NS)) == E.tostring(sheet.find('s:'+tag, NS)) for tag in ['cols', 'mergeCells', 'sheetFormatPr', 'pageMargins', 'pageSetup']))
            check(filename+' 行高を保持', [r.attrib for r in original.findall('s:sheetData/s:row', NS)] == [r.attrib for r in sheet.findall('s:sheetData/s:row', NS)])
            changed = {'xl/worksheets/sheet1.xml', 'xl/_rels/workbook.xml.rels', '[Content_Types].xml', 'xl/calcChain.xml'}
            check(filename+' 他パーツ不変', all(source.read(p) == output.read(p) for p in source.namelist() if p not in changed))
            check(filename+' 確認用と未確定条件を表示', '提出不可' in text('A4') and '未確定' in text('A7'))
            check(filename+' 旧料率・区分・金額・年齢は空欄', all(not text(c) for c in ['B11', 'Q11', 'J38', 'J39', 'M38', 'M39', 'O38', 'O39', 'S40']))
            check(filename+' 記入例を削除', all(not text(c) for c in ['C10', 'G10', 'N10', 'R10']))
            check(filename+' 旧式とcalcChainなし', not sheet.findall('.//s:f', NS) and 'xl/calcChain.xml' not in output.namelist())
            check(filename+' 通常の会員番号を捏造しない', not text('R11'))
            if filename == 'insurance-preview.xlsx':
                check('1名の氏名・カナ・生年月日を正しい欄へ', text('C11') == '架空 保険60' and text('G11') == 'カクウ ホケン' and text('N11') == '1990.04.01')
                check('1名出力で残行を空欄保持', not text('C12') and not text('C34'))
            elif filename == 'insurance-preview-24.xlsx':
                check('24名を最終行まで欠落なく出力', len({text('C'+str(r)) for r in range(11, 35)}) == 24 and text('C34') == '架空 保険60')
            else:
                check('式風氏名とXML特殊文字は文字列のまま', cells['C11'].get('t') == 'inlineStr' and text('C11') == '=1+1 <架空&氏名>')
print(json.dumps({'status': 'PASS', 'cases': cases}, ensure_ascii=False))
