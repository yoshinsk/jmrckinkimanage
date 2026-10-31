# Path: scripts/build_information_catalog.py
# 機能: 確定した対応表とURL台帳から、本文未移行の確認専用カタログを再生成する。原文取得・公開承認は行わない。
import csv, hashlib, json, re
from pathlib import Path
root = Path(__file__).resolve().parents[1]
md = (root/'Documents/20261031_追加資料反映・要件更新.md').read_text(encoding='utf-8')
source = root/'Documents/20261031_旧HP移行元URL台帳.csv'
decisions_path = root/'design/phase2/20261031_answer_decisions.json'
decisions = json.loads(decisions_path.read_text(encoding='utf-8'))
rows = list(csv.DictReader(source.open(encoding='utf-8-sig', newline='')))
# 分類はP21～29の案内先。未指定の大会案内等は仮分類であることを各画面に明記する。
configs = {
1: ('information','office','common','事務局からのお知らせ','事務局の案内、新着情報、会員制度の説明をまとめます。旧年度の案内は対象年度を明示します。','Q10・Q12'),
2: ('information','office','common','代表者会議・表彰式','代表者会議や表彰式の開催案内、表彰式の様子を掲載する構成です。開催内容と対象年度は原本確認後に差し替えます。','Q10・Q12'),
3: ('information','office','common','Bライセンス講習会','講習会の案内を掲載する構成です。現在の開催日・申込先は確認待ちです。新たな講習会申込システムは設けません。','Q10・Q12'),
4: ('links','kinki','gym,dirt,rally,race,judge','競技部会のご案内','ジムカーナ、ダートトライアル、ラリー、レース、競技会審査委員会の情報は各部会HPへ案内します。旧HP内の案内ページと部会HPは区別します。','Q12・Q14'),
5: ('calendar','office','common','統一カレンダー','競技会・JMRCの会議・事務局在席／休業日を種別付きで表示します。次の予定はすべて架空の表示例です。','Q11・Q12'),
6: ('links','kinki','gym','ジムカーナのシリーズポイント','各部会HPから結果ページへリンクする方針です。新しい集計の管理場所・公開先・規則が未確定のため、点数や順位は掲載しません。','Q06～Q09・Q12'),
7: ('links','kinki','dirt','ダートトライアルのシリーズポイント','各部会HPから結果ページへリンクする方針です。過年度のPDFを現年度の結果として掲載しません。','Q06～Q09・Q12'),
8: ('links','kinki','rally','ラリーのシリーズポイント','各部会HPから結果ページへリンクする方針です。ドライバー／ナビゲーターの集計単位を含め、正式な結果の掲載条件は確認待ちです。','Q06～Q09・Q12'),
9: ('information','kinki','gym,dirt,rally','シリーズ表彰・全国大会のご案内','シリーズ表彰者、JAF CUP、西日本フェスティバル等の案内を掲載する構成です。表彰者の氏名・所属や対象年度は承認前のため掲載しません。','Q10・Q12・Q13'),
10: ('links','kinki','gym,dirt,rally,race','特別規則・リザルト・練習会','各競技の特別規則、リザルト、イベント、練習会は各部会HPへ案内します。練習会申込やフォーラムはポータルに統合しません。','Q12・Q14'),
11: ('documents,links','kinki','gym,rally','部会の救済基金・互助会','ジムカーナ部会救済基金、ラリー部会互助会の案内は各部会HPで扱います。スポーツ安全保険や協議会見舞金と別の制度です。','Q12・Q14'),
12: ('documents,links','kinki','gym,dirt,rally','競技会の申込書・誓約書','競技の参加申込書、誓約書、ナビゲーター追加申込書は各部会HPへ案内します。オンラインエントリーは既存の仕組みを継続します。','Q12・Q14'),
13: ('information,documents','office','common','個人会員制度のご案内','GeneralはJAFライセンス必須、年会費2,000円です。Temporaryは1イベント500円、主催クラブの承認を受け、開催日のみ有効です。同日複数イベントには利用できません。個人会員の受付開始は保留中です。','Q10・Q12'),
14: ('information,documents','office','common','レンタル機器のご案内','光電管、レスキューツール、KED・バキュームスプリント、重量計、タバード、バックボード、AEDの案内・様式が引継ぎ候補です。貸出条件・様式の年度は確認待ちです。予約・在庫管理は今回の対象に含みません。','Q10・Q12'),
15: ('information,documents','kinki','gym,dirt,rally','シリーズ概要','対象年度と版を付けてシリーズ概要を案内します。正式な規則原本の確認前に旧年度の条件を新年度へ読み替えません。','Q10・Q12'),
16: ('documents,links','national','common','JAF国内競技規則・車両規則','JAFの国内競技規則、車両規則、公認車両一覧は公式ページを参照する構成です。原本を独自に更新・自動複製しません。','Q12'),
17: ('information,documents','office','common','JAF競技保険・オフィシャル名簿様式','JAF公認競技保険・クローズド競技保険の案内と空の様式を対象にします。記入済みのオフィシャル名簿は掲載しません。','Q10・Q12・Q13'),
18: ('documents','office','common','スポーツ安全保険のご案内','加入対象はGeneralです。初回は年度クラブ単位で4名が必要です。2027年の保険料・期間・追加加入の手順は確認待ちです。加入意思の登録と正式な加入は区別します。','Q01～Q03・Q12・Q13'),
19: ('information,documents','committee','common','運営委員会からのお知らせ','運営委員名簿と公開決定事項を案内する構成です。名簿の公開項目は確認待ちです。非公開の委員会資料や資料BOXはこのページに掲載しません。','Q10・Q13'),
20: ('information,documents','national','common','会則・見舞金細則・協議会規程','協議会会則、見舞金細則、全国協議会規程を案内する構成です。分類名と発行団体を分けて確認します。協議会見舞金の請求は今回保留です。','Q12・Q13'),
21: ('information,documents','kinki','common','クラブ年度登録・クラブ名簿','クラブ年度登録の案内と公表用のクラブ／団体名簿を扱う構成です。実際の年度登録記録から名簿を自動公開しません。現年度・過年度の公開範囲は確認待ちです。','Q10・Q12・Q13'),
22: ('links','national','common','JAFのご案内','JAF関西本部、お知らせ、公示、Motor Sports News、ライセンス講習会、全日本選手権の情報はJAFへのリンクで案内します。','Q12・Q14'),
23: ('links','kinki','common','関連リンク・お問い合わせ','各部会、各地域JMRC、JAFへのリンクをまとめます。問い合わせ先の移設先は確認待ちです。この試作から問い合わせは送信しません。','Q12・Q14'),
24: ('migration','kinki','common','移行対象外：2020年のアンケート','2020年の新型コロナウイルスに関するアンケートは新ポータルへの移行対象外です。旧サイトや保存資料の削除は行いません。','対象外はP29で明記'),
}
topics = {}
for line in md.splitlines():
    if not re.match(r'\| HP-\d{2} \|', line): continue
    id, original, policy, page = [c.strip() for c in line.strip('|').split('|')]
    n = int(id[-2:]); sections, category, divisions, title, body, pending = configs[n]
    topics[id] = dict(title=title, summary=body, sections=sections.split(','), category=category,
                      divisions=divisions.split(','), policy=policy, page=page, pending=pending,
                      publisher='原本確認待ち' if n != 16 and n != 22 else 'JAF',
                      classification_note='資料の分類名案。近畿地域の規程も含むためQ13で確認。' if n == 20 else ('配置は確認用の案。正式な分類・担当はQ10・Q12で確認。' if n in [9,15] else ''),
                      state='excluded' if n == 24 else 'review', revision=1)
sources = {id:[] for id in topics}
# 回答を初期案へ上書きする。旧資料・回答原文は残し、表示上の方針だけを更新する。
for id, updates in decisions['topics'].items():
    topics[id].update(updates)
    topics[id]['revision'] = 2
for topic in topics.values():
    topic.setdefault('categories', [topic['category']])
for id in ['HP-09', 'HP-15']:
    topics[id]['classification_note'] = '編集担当は事務局員、公開承認は事務局長。公開する原稿は確認待ち。'
for row in rows:
    item = dict(title=row['旧リンク表示'], url=row['移行元URL'], year=row['元年度候補'], verification=row['リンク先到達性'])
    if item['url'] in decisions['source_categories']:
        item['category'] = decisions['source_categories'][item['url']]
        item['publisher'] = 'JMRC近畿' if item['category']=='committee' else 'JMRC全国協議会'
    sources[row['HP_ID']].append(item)
assert len(topics)==24 and sum(map(len,sources.values()))==154
payload=dict(snapshot='2026-10-31', source_file=source.name, source_sha256=hashlib.sha256(source.read_bytes()).hexdigest(),
             answer_source=decisions['answer_source'], answer_sha256=hashlib.sha256((root/decisions['answer_source']).read_bytes()).hexdigest(),
             decisions=decisions['decisions'], topics=topics, sources=sources)
php="<?php\n// Path: src/Content/information-catalog.php\n// 機能: 2026-10-31の確定対応表と154件の旧URL候補。記事原文・個人名簿・正式移行先は含まない。\ndeclare(strict_types=1);\nreturn json_decode(<<<'CATALOG'\n"+json.dumps(payload, ensure_ascii=False, indent=2)+"\nCATALOG, true, 512, JSON_THROW_ON_ERROR);\n"
(root/'src/Content/information-catalog.php').write_text(php, encoding='utf-8')
print('catalog: 24 topics / 154 source records')
