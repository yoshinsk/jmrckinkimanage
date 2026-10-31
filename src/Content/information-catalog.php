<?php
// Path: src/Content/information-catalog.php
// 機能: 2026-10-31の確定対応表と154件の旧URL候補。記事原文・個人名簿・正式移行先は含まない。
declare(strict_types=1);
return json_decode(<<<'CATALOG'
{
  "snapshot": "2026-10-31",
  "source_file": "20261031_旧HP移行元URL台帳.csv",
  "source_sha256": "d3322d14ce2e337e9453d9af9dc7944bed21c00766d583b8ccbe6907e9807189",
  "answer_source": "Documents/answers/20261031_追加14項目回答.json",
  "answer_sha256": "d7291edc54ad65ff904bc96a501f7c5756580408bb3ff51db14245d04c1b5376",
  "decisions": [
    {
      "id": "R05",
      "title": "振込の識別",
      "confirmed": "振込名義の末尾IDは不要。クラブ・個人・保険の用途を区別し、申込時に識別IDを発行する。",
      "pending": "識別IDと銀行明細の照合方法は別途確認。名義末尾や曖昧な候補による自動確定はしない。"
    },
    {
      "id": "R06",
      "title": "事務局の複数登録・CSV",
      "confirmed": "事務局は複数アカウントを登録できる。CSVは出力列を選べる設計とし、クラブ代表は担当範囲に限る。",
      "pending": "追加アカウントの指定・承認と、財務用CSVの列・権限は未確定。権限は自動付与しない。"
    },
    {
      "id": "R08",
      "title": "保存・バックアップ",
      "confirmed": "操作ログは2年。バックアップは実装側で保存方針を決める。ドラフトのログアウト時保存は要望として継続する。",
      "pending": "日次30世代・月次12世代、四半期の復元確認を採用方針として文書化。実ジョブ・自動削除は未適用。ドラフト3か月の起算点は確認待ち。"
    },
    {
      "id": "R11",
      "title": "運営担当者の登録承認",
      "confirmed": "運営委員会の担当者登録は事務局長が承認する。主催クラブと代表アカウントは共通で扱う。",
      "pending": "事務局長のアカウント対応、通知先・文面は別途確認。名簿上の役職だけでシステム権限を与えない。"
    },
    {
      "id": "Q01",
      "title": "保険の連絡対象",
      "confirmed": "対象は保険加入済みの被保険者。ポータルのほか、旧システム・紙で申し込んだ被保険者も事務局へ連絡できる。",
      "pending": "システム内の加入記録との一致を必須条件にしない。加入意思の登録を加入済みとは扱わない。"
    },
    {
      "id": "Q02",
      "title": "クラブ代表からの連絡",
      "confirmed": "本人だけでなくクラブ代表からの連絡も受け付ける。事務局が当人へ連絡し、保険手続きはシステム外で進める。",
      "pending": "代表の担当クラブ確認を維持し、被保険者を氏名だけで他アカウントへ結び付けない。正式送信は保留。"
    },
    {
      "id": "Q03",
      "title": "保険連絡の入力項目",
      "confirmed": "入力は被保険者名・クラブ名の2項目。事故日・事故概要・連絡先・添付を追加要求しない。",
      "pending": "実際の連絡先・送信先の接続は保留。給付確定や保険会社への正式請求は行わない。"
    },
    {
      "id": "Q04",
      "title": "主催者の権限",
      "confirmed": "対象はオンラインエントリーで受け付けるイベント。イベント登録・主催権限は既存エントリー側の決定に従う。",
      "pending": "イベント登録承認者、共同主催、代理担当の対応は未確定。新しい主催権限を自動付与しない。"
    },
    {
      "id": "Q05",
      "title": "主催者の資格照会",
      "confirmed": "申込締切以降からイベント終了1週間後まで照会できる。照会時点の最新資格で「個人会員か」「正会員クラブに所属しているか」の2点を表示する。",
      "pending": "確認できない場合は主催者へ注意を表示し、申込受理を自動停止しない。既存イベントの権限・終了日時との接続は保留。"
    },
    {
      "id": "Q06",
      "title": "ポイントの管理場所・リンク",
      "confirmed": "ポイントの登録・確定は既存エントリー側に置く。ポータルからの関連リンクは各部会のトップページを基本とする。",
      "pending": "正式な部会トップURLと、集計の公開開始条件は別途確認する。"
    },
    {
      "id": "Q07",
      "title": "シリーズ・クラスの範囲",
      "confirmed": "ジムカーナと将来のダートトライアルに対応する。同じ選手の複数クラス・競技参加を分けて扱う。ラリーのオンラインエントリー移行は現時点の対象外。",
      "pending": "2027年度規則・提出済みクラス一覧は未受領。順位別点数、不成立・失格・中止等を推定しない。"
    },
    {
      "id": "Q08",
      "title": "ポイント対象資格",
      "confirmed": "Generalだけが対象。申込時に個人会員かつ正会員クラブ所属であることが必要。正会員クラブ間の後日の所属変更で権利を失わない。",
      "pending": "他地区会員は参加費割引のみで会場にて会員証確認。後日の資格停止・入金訂正の得点への影響は確認待ち。"
    },
    {
      "id": "Q09",
      "title": "集計・表示・訂正",
      "confirmed": "クラス別に順位、各イベントのポイント、有効ポイント、氏名、クラブ略称を表示する。有効戦数は開催数の70％を切り上げる（全7戦なら5戦）。",
      "pending": "部会HPからの取込・訂正版の再読込は要望として採用。高得点回数・後開催優先の同点規則の細部、正式取込元・形式・確定担当は確認待ち。自動公開はしない。"
    },
    {
      "id": "Q10",
      "title": "インフォメーションの担当",
      "confirmed": "4分類すべてを事務局員が編集し、事務局長が承認する。部会名等の分類で編集権限を与えない。",
      "pending": "事務局長アカウントの指定と正式原稿の確認後に公開機能を接続する。現在は閲覧専用。"
    },
    {
      "id": "Q11",
      "title": "カレンダーの担当",
      "confirmed": "事務局員が更新し、事務局長が承認する。Googleカレンダーのような表示を目指す。元データの形式は任意。",
      "pending": "予定ごとの公開範囲・実日程・入力元は未確定。Googleとの自動同期が決まったわけではない。"
    },
    {
      "id": "Q12",
      "title": "引継ぎ対象",
      "confirmed": "各部会の旧記事は各部会へ移管する。クラブ名簿、旧年度の規則・様式は原則として新ポータルへ移さない。別途バックアップを相談する。",
      "pending": "稼働中の旧サーバーは廃止希望だが、残置先・移管先・時期は別途協議。既存データの削除は行わない。"
    },
    {
      "id": "Q13",
      "title": "会則・規程の分類",
      "confirmed": "近畿の協議会会則・見舞金規則は「運営委員会」、全国協議会会則・細則と専門委員会規程は「全国JMRC」に分ける。",
      "pending": "役員・保険加入クラブ等の名簿公開項目は未回答。非公開の委員会資料は公開しない。"
    },
    {
      "id": "Q14",
      "title": "旧HPの残置・移行作業",
      "confirmed": "旧ページは約1年残す方針。更新担当は事務局員。ポータル公開後に事務局が移管作業を行い、間に合わない内容は「事務局から」で告知する。",
      "pending": "残置期間の起算日、旧サーバー廃止との調整、移管先・転送設定・公開の最終判断者は未確定。"
    }
  ],
  "topics": {
    "HP-01": {
      "title": "事務局からのお知らせ",
      "summary": "事務局の案内、新着情報、会員制度の説明をまとめます。旧年度の案内は対象年度を明示します。",
      "sections": [
        "information"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "インフォメーション「事務局」。旧年度の案内は年度を明示して選別",
      "page": "P21",
      "pending": "Q10：編集は事務局員、承認は事務局長。現行案内の原稿・年度・公開日は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-02": {
      "title": "代表者会議・表彰式",
      "summary": "代表者会議や表彰式の開催案内、表彰式の様子を掲載する構成です。開催内容と対象年度は原本確認後に差し替えます。",
      "sections": [
        "information"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "インフォメーション「事務局」。出欠受付は既存の会議要件に接続",
      "page": "P22",
      "pending": "Q10・Q12：編集・承認担当は確定。対象案内・対象年度と出欠受付の開始条件は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-03": {
      "title": "Bライセンス講習会",
      "summary": "講習会の案内を掲載する構成です。現在の開催日・申込先は確認待ちです。新たな講習会申込システムは設けません。",
      "sections": [
        "information"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "インフォメーション「事務局」。講習会申込システムの新設は含めない",
      "page": "P22",
      "pending": "現行の開催日・正式な申込先と原稿の確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-04": {
      "title": "競技部会のご案内",
      "summary": "各競技部会の情報は各部会へ移管します。関連リンクは各部会のトップページを基本とし、個別PDFへの恒久リンクを前提にしません。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "gym",
        "dirt",
        "rally",
        "race",
        "judge"
      ],
      "policy": "各部会のHPへ移行またはリンク。現在の内部案内ページと実際の部会HPを区別",
      "page": "P22",
      "pending": "Q06・Q12・Q14：正式なトップURL、移管先と時期は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-05": {
      "title": "統一カレンダー",
      "summary": "競技会・会議・事務局在席／休業日をまとめます。事務局員が更新し、事務局長が承認します。次の予定は架空の表示例です。",
      "sections": [
        "calendar"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "統一カレンダー。部会別詳細日程・規則は部会HPへ",
      "page": "P22",
      "pending": "Q11：予定ごとの公開範囲、実日程、入力元は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-06": {
      "title": "ジムカーナのシリーズポイント",
      "summary": "ポイントの登録・確定は既存エントリー側で管理します。ポータルからはジムカーナ部会トップへ案内し、集計表示は正式規則・原結果の確認後に接続します。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "gym"
      ],
      "policy": "Q06～09により登録・確定は既存entry。部会トップへのリンクを基本とする。自動集計表示は規則・取込元の確認後",
      "page": "P23",
      "pending": "Q07～Q09：2027規則、正式取込元・形式、同点処理の細部と訂正担当は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-07": {
      "title": "ダートトライアルのシリーズポイント",
      "summary": "ダートトライアルのポイント登録・確定も既存エントリー側で扱う方針です。部会トップへの案内と、競技・シリーズ・クラスを分けた集計を準備します。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "dirt"
      ],
      "policy": "HP-06と同じ。競技・シリーズ・クラスを分ける。過年度PDFを現年度結果として転記しない",
      "page": "P24",
      "pending": "Q07～Q09：2027規則、クラス一覧、取込元と公開開始条件は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-08": {
      "title": "ラリーのシリーズポイント",
      "summary": "ラリーの情報はラリー部会へ案内します。現時点ではオンラインエントリー移行と新しいポイント集計の対象に含めません。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "rally"
      ],
      "policy": "ラリー部会へ案内。Q07により現時点ではentry移行・新集計の対象外",
      "page": "P25",
      "pending": "Q06・Q07・Q12：正式な部会トップURLと既存記事の移管先は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-09": {
      "title": "シリーズ表彰・全国大会のご案内",
      "summary": "各部会の旧記事・表彰記事は各部会へ移管します。今後の全国大会等の案内は、事務局員が作成し事務局長が承認する構成です。",
      "sections": [
        "information"
      ],
      "category": "kinki",
      "divisions": [
        "gym",
        "dirt",
        "rally"
      ],
      "policy": "Q12により部会の旧記事は部会へ移管。新規案内の掲載は事務局編集・事務局長承認",
      "page": "P23～25",
      "pending": "Q10・Q12・Q13：新しい原稿・対象年度・氏名等の公開範囲は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "編集担当は事務局員、公開承認は事務局長。公開する原稿は確認待ち。",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-10": {
      "title": "特別規則・リザルト・練習会",
      "summary": "各競技の特別規則、リザルト、イベント、練習会は各部会HPへ案内します。練習会申込やフォーラムはポータルに統合しません。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "gym",
        "dirt",
        "rally",
        "race"
      ],
      "policy": "各部会HP。練習会の申込やフォーラムをポータルへ統合する要件は追加しない",
      "page": "P23～26",
      "pending": "Q12・Q14：部会への具体的な移管先・時期とリンク先の確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-11": {
      "title": "部会の救済基金・互助会",
      "summary": "ジムカーナ部会救済基金、ラリー部会互助会の案内は各部会HPで扱います。スポーツ安全保険や協議会見舞金と別の制度です。",
      "sections": [
        "documents",
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "gym",
        "rally"
      ],
      "policy": "各部会HP。スポーツ安全保険・協議会見舞金の状態に読み替えない",
      "page": "P23・25",
      "pending": "Q12・Q14：部会の正式な案内先と移管手順の確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-12": {
      "title": "競技会の申込書・誓約書",
      "summary": "競技会の案内・資料は各部会へ移管します。旧年度の様式は原則として新ポータルへ移さず、現行の申込導線は既存エントリーを継続します。",
      "sections": [
        "documents",
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "gym",
        "dirt",
        "rally"
      ],
      "policy": "各部会HP。旧年度様式は原則移行せず別途保存相談。既存entryの申込導線は維持",
      "page": "P26",
      "pending": "Q12：現行様式と案内先を確認。過年度の保存は別途バックアップ相談",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-13": {
      "title": "個人会員制度のご案内",
      "summary": "GeneralはJAFライセンス必須、年会費2,000円です。Temporaryは1イベント500円、主催クラブの承認を受け、開催日のみ有効です。同日複数イベントには利用できません。個人会員の受付開始は保留中です。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "ポータルの個人会員申請へ案内。受付保留中は説明と開始予定を表示",
      "page": "P26",
      "pending": "R12：受付開始は保留。現行の案内原稿と正式な開始判断は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-14": {
      "title": "レンタル機器のご案内",
      "summary": "光電管、レスキューツール、KED・バキュームスプリント、重量計、タバード、バックボード、AEDの案内・様式が引継ぎ候補です。貸出条件・様式の年度は確認待ちです。予約・在庫管理は今回の対象に含みません。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "インフォメーション「事務局」。光電管、レスキューツール、KED・バキュームスプリント、重量計、タバード、バックボード、AEDを対象候補とする。予約・在庫管理の新設は含めない",
      "page": "P26",
      "pending": "Q10・Q12：編集・承認担当は確定。現行の貸出条件・様式と年度は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-15": {
      "title": "シリーズ概要",
      "summary": "対象年度と版を付けてシリーズ概要を案内します。2027年度の正式な規則は未受領です。旧年度の規則・様式は原則として移行せず、各部会記事は各部会へ移管します。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "kinki",
      "divisions": [
        "gym",
        "dirt",
        "rally"
      ],
      "policy": "インフォメーション。年度・版を管理",
      "page": "P26",
      "pending": "Q07・Q12：2027年度の正式原稿・クラス一覧の提供待ち",
      "publisher": "原本確認待ち",
      "classification_note": "編集担当は事務局員、公開承認は事務局長。公開する原稿は確認待ち。",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-16": {
      "title": "JAF国内競技規則・車両規則",
      "summary": "JAFの国内競技規則、車両規則、公認車両一覧は公式ページを参照する構成です。原本を独自に更新・自動複製しません。",
      "sections": [
        "documents",
        "links"
      ],
      "category": "national",
      "divisions": [
        "common"
      ],
      "policy": "JAF公式ページへのリンク。原本の独自更新や自動複製は行わない",
      "page": "P26",
      "pending": "JAFの正式な現行リンクと対象版の確認待ち",
      "publisher": "JAF",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "national"
      ]
    },
    "HP-17": {
      "title": "JAF競技保険・オフィシャル名簿様式",
      "summary": "JAF公認競技保険・クローズド競技保険の案内と空の様式を対象にします。記入済みのオフィシャル名簿は掲載しません。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "インフォメーション「事務局」。公表可能な案内・空の様式を対象とし、記入済み名簿を自動公開しない",
      "page": "P26",
      "pending": "Q12・Q13：現行の公表可能な案内・空の様式と、名簿の公開項目は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-18": {
      "title": "スポーツ安全保険のご案内",
      "summary": "加入対象はGeneral、初回は年度クラブ単位で4名です。加入済みの被保険者は、本人またはクラブ代表から氏名・クラブ名だけで事務局へ連絡する方針です。旧システム・紙の加入者も対象とします。",
      "sections": [
        "documents"
      ],
      "category": "office",
      "divisions": [
        "common"
      ],
      "policy": "スポーツ安全保険の申込セクション。公表用案内と本人・クラブの申込記録を分ける",
      "page": "P26",
      "pending": "2027年の料率・保険期間、正式な受付・通知先の接続は保留。事務局から当人へ連絡し、保険手続きはシステム外で進める",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "office"
      ]
    },
    "HP-19": {
      "title": "運営委員会からのお知らせ",
      "summary": "運営委員名簿と公開決定事項を案内する構成です。名簿の公開項目は確認待ちです。非公開の委員会資料や資料BOXはこのページに掲載しません。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "committee",
      "divisions": [
        "common"
      ],
      "policy": "インフォメーション「運営委員会」。公開済み情報の引継ぎであり、非公開会議資料を公開する指示ではない",
      "page": "P27",
      "pending": "Q10・Q13：編集は事務局員、承認は事務局長。名簿の公開項目と原稿は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "committee"
      ]
    },
    "HP-20": {
      "title": "会則・見舞金細則・協議会規程",
      "summary": "近畿の協議会会則・見舞金規則を「運営委員会」、全国協議会の会則・細則と専門委員会規程を「全国JMRC」に分けます。協議会見舞金の請求は保留です。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "national",
      "divisions": [
        "common"
      ],
      "policy": "Q13で訂正。近畿の会則・見舞金規則は運営委員会、全国の会則・細則・専門委員会規程は全国JMRC。文書ごとに発行団体を保持",
      "page": "P27",
      "pending": "Q13の分類訂正は確定。移行する現行原本・版と公開承認は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "Q13の回答でP27の分類を訂正済み。文書ごとに分類と発行団体を表示します。",
      "state": "review",
      "revision": 2,
      "categories": [
        "committee",
        "national"
      ]
    },
    "HP-21": {
      "title": "クラブ年度登録・クラブ名簿",
      "summary": "クラブ年度登録の案内を掲載する構成です。旧HPのクラブ名簿は原則として新ポータルへ移行せず、別途バックアップを相談します。実際の登録記録から名簿を自動公開しません。",
      "sections": [
        "information",
        "documents"
      ],
      "category": "kinki",
      "divisions": [
        "common"
      ],
      "policy": "登録案内はJMRC近畿。Q12により旧HPのクラブ名簿は原則移行せず別途保存相談。業務登録データは保持",
      "page": "P27",
      "pending": "Q12・Q13：新しい公表用名簿の公開項目は未確定。年度登録の受付開始も保留",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-22": {
      "title": "JAFのご案内",
      "summary": "JAF関西本部、お知らせ、公示、Motor Sports News、ライセンス講習会、全日本選手権の情報はJAFへのリンクで案内します。",
      "sections": [
        "links"
      ],
      "category": "national",
      "divisions": [
        "common"
      ],
      "policy": "JAFへのリンクで案内",
      "page": "P28",
      "pending": "現行の公式リンクとリンク切れの確認待ち",
      "publisher": "JAF",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "national"
      ]
    },
    "HP-23": {
      "title": "関連リンク・お問い合わせ",
      "summary": "各部会・各地域JMRC・JAFへのリンクをまとめます。部会リンクはトップページを基本とします。旧ページは約1年残す方針ですが、具体的な移管・問い合わせ先は別途調整します。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "common"
      ],
      "policy": "関連リンク。お問い合わせは既存の問い合わせ要件を継続し、具体的な移設先を確認",
      "page": "P29",
      "pending": "Q06・Q12・Q14：正式なトップURL、移管先、問い合わせ先は確認待ち",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 2,
      "categories": [
        "kinki"
      ]
    },
    "HP-24": {
      "title": "移行対象外：2020年のアンケート",
      "summary": "2020年の新型コロナウイルスに関するアンケートは新ポータルへの移行対象外です。旧サイトや保存資料の削除は行いません。",
      "sections": [
        "migration"
      ],
      "category": "kinki",
      "divisions": [
        "common"
      ],
      "policy": "新ポータルへの移行対象外。元サイト・保存済み資料の削除を意味しない",
      "page": "P29",
      "pending": "対象外はP29で明記",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "excluded",
      "revision": 1,
      "categories": [
        "kinki"
      ]
    }
  },
  "sources": {
    "HP-01": [
      {
        "title": "トップの新着・固定案内",
        "url": "http://www.jmrc-kinki.net/index.html",
        "year": "原本確認",
        "verification": "トップHTMLをHTTP GETで200確認・該当区画の内容精査は移行時"
      },
      {
        "title": "夏季休業のお知らせ。",
        "url": "http://www.jmrc-kinki.net/jimukyoku/jimu_2026.html",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "事務局からのお知らせ",
        "url": "http://www.jmrc-kinki.net/jimukyoku_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-02": [
      {
        "title": "ＪＡＦ近畿地方選手権・ＪＭＲＣ近畿シリーズ表彰式 開催案内",
        "url": "http://www.jmrc-kinki.net/awardceremony_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "表彰式の全表彰順について",
        "url": "http://www.jmrc-kinki.net/awardceremony_info2.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "クラブ・団体代表者会議（総会）開催案内",
        "url": "http://www.jmrc-kinki.net/generalmeeting_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-03": [
      {
        "title": "Ｂライセンス講習会申し込みはこちら",
        "url": "http://www.jmrc-kinki.net/blicense.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-04": [
      {
        "title": "ダートトライアル専門部会",
        "url": "http://www.jmrc-kinki.net/dirt_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ジムカーナ専門部会",
        "url": "http://www.jmrc-kinki.net/gym_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "競技会審査委員会（旧：審査委員グループ）",
        "url": "http://www.jmrc-kinki.net/judge/judge_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "レース専門部会",
        "url": "http://www.jmrc-kinki.net/race_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ラリー専門部会",
        "url": "http://www.jmrc-kinki.net/rally_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-05": [
      {
        "title": "一覧",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_calendar_all.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "トップの事務局カレンダー",
        "url": "http://www.jmrc-kinki.net/index.html",
        "year": "原本確認",
        "verification": "トップHTMLをHTTP GETで200確認・該当区画の内容精査は移行時"
      },
      {
        "title": "ＪＭＲＣカレンダー",
        "url": "http://www.jmrc-kinki.net/jmrc_calendar.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-06": [
      {
        "title": "ミドルシリーズ",
        "url": "https://b10b0f54-3008-4957-9111-76f46439a2b8.filesusr.com/ugd/eefd37_a5bf073a839942708b232a02f1fa5b52.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "チャンピオンシリーズ",
        "url": "https://b10b0f54-3008-4957-9111-76f46439a2b8.filesusr.com/ugd/eefd37_c9179bc2d5504b25a2c7ff6ae571ebe8.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-07": [
      {
        "title": "チャンピオンシリーズ",
        "url": "http://www.jmrc-kinki.net/_userdata/2025_dirt_point_champion.pdf",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ジュニアシリーズ",
        "url": "http://www.jmrc-kinki.net/_userdata/2025_dirt_point_junior.pdf",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-08": [
      {
        "title": "アベレージラリーシリーズ",
        "url": "https://jmrc-kinki-rally.com/documents/2025/2025_average_point.pdf",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＳＳラリーシリーズ",
        "url": "https://jmrc-kinki-rally.com/documents/2025/2025_ss_point.pdf",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-09": [
      {
        "title": "公式通知 No.1（審査委員の変更）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_bulletin.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_entryform.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_entryform.xlsx",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "暫定エントラントリスト",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_entrylist_provisional.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "金曜日練習会案内",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_fridayinfo.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "大会ポスター",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_officialposter.jpg",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "公開練習案内",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_practice_info.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "特別規則書",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_allstar_gym_regulation.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "公式通知 No.1（施設見取図）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_bulletin1.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "PDF版",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_entryform.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "Excel版",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_entryform.xlsx",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "公開練習エントラントリスト",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_entrylist_practice",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "暫定エントラントリスト（10/29 修正版）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_entrylist_provisional.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "JAF：Eラーニング受講のお願い",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_jaf_subcommittee.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "大会ポスター",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_officialposter.jpg",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "information No.3（パドックリスト）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_paddocklist.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "特別規則書",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_jafcup_dirt_regulation.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "PDF版",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_entryform.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "Excel版",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_entryform.xlsx",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "暫定エントラントリスト",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_entrylist_provisional.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "information No.1（年少者のサービス登録）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_info.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "大会ポスター",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_officialposter.jpg",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "大会ポスター",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_officialposter.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "パドック配置図",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_paddocklayout.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "パドックリスト",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_paddocklist.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "公開練習リザルト",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_practice_result.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "特別規則書（草案）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_regulation.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "リザルト",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_result.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "大型車の進入ルート図",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_westjapanfestival_dirt_routemap.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＡＦ近畿地方選手権",
        "url": "http://www.jmrc-kinki.net/award/dirt_award_JAF2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ近畿シリーズ",
        "url": "http://www.jmrc-kinki.net/award/dirt_award_JMRC2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＡＦ近畿地方選手権",
        "url": "http://www.jmrc-kinki.net/award/gym_award_JAF2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ近畿シリーズ",
        "url": "http://www.jmrc-kinki.net/award/gym_award_JMRC2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＡＦ近畿地方選手権",
        "url": "http://www.jmrc-kinki.net/award/rally_award_JAF2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ近畿シリーズ",
        "url": "http://www.jmrc-kinki.net/award/rally_award_JMRC2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-10": [
      {
        "title": "ダートトライアルカレンダー",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_calendar_dirt.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ジムカーナカレンダー",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_calendar_gym.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ラリーカレンダー",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_calendar_rally.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ダートトライアル",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_dirttrial_regulation.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ジムカーナ",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_gymkhana_regulation.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ラリー",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_rally_regulation.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ダートトライアル",
        "url": "http://www.jmrc-kinki.net/judge/judgingcommittee_list_dirt.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ジムカーナ",
        "url": "http://www.jmrc-kinki.net/judge/judgingcommittee_list_gym.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ラリー",
        "url": "http://www.jmrc-kinki.net/judge/judgingcommittee_list_rally.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-11": [
      {
        "title": "ＪＭＲＣ近畿ジムカーナ救済基金規定",
        "url": "http://www.jmrc-kinki.net/_userdata/JMRCkinki_gym_relieffund_rule.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ近畿ラリー部会互助会制度（2022年4月1日 改正）",
        "url": "http://www.jmrc-kinki.net/_userdata/JMRCkinki_rally_gojyokai_20220401.pdf",
        "year": "2022",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-12": [
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/2025_entryform_dirt.pdf",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/2025_entryform_dirt.xlsx",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "誓約書（アベレージラリーシリーズ用）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_entry_averally_pledge.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ラリー参加申込書（アベレージラリーシリーズ用）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_entryform_averally.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ナビゲーター追加申込書（アベレージラリーシリーズ用）",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_entryform_averally_navi.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "(WEBエントリーリンク)",
        "url": "https://gymkinki.com/entry/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-13": [
      {
        "title": "2026年度 個人会員入会申込書",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_kojinkaiin_form.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "個人会員入会申込書",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_kojinkaiin_form2.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "個人会員入会申込書 記入例・書き方",
        "url": "http://www.jmrc-kinki.net/_userdata/kojinkaiin_form_example.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ近畿個人会員のご案内",
        "url": "http://www.jmrc-kinki.net/_userdata/kojinkaiin_info.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "個人会員入会申込みのご案内",
        "url": "http://www.jmrc-kinki.net/kojinkaiin.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-14": [
      {
        "title": "（書き込み可）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_AED2.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "（書き込み可）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_KED2.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "（書き込み可）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_RESCUETOOL2.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "（書き込み可）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_TABARD2.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_aed.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "バックボードレンタル申込書",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_backboard.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_ked.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_rescuetool.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_tabard.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "光電管レンタル申込書",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_timing.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "車両重量計レンタル申込書",
        "url": "http://www.jmrc-kinki.net/_userdata/rental_weightgauge.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "レンタル機器申込みのご案内",
        "url": "http://www.jmrc-kinki.net/rental.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-15": [
      {
        "title": "各シリーズ概要等",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_outline.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-16": [
      {
        "title": "ＪＡＦ国内競技規則・ＪＡＦ国内競技車両規則 等",
        "url": "https://motorsports.jaf.or.jp/regulations/information",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "公認車両一覧・登録車両一覧",
        "url": "https://motorsports.jaf.or.jp/regulations/vehicles",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-17": [
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/JAF_speed.closed.competition.insurance_form.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/JAF_speed.closed.competition.insurance_form.xlsx",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2026年度ＪＡＦ届出スピード競技クローズド保険案内",
        "url": "http://www.jmrc-kinki.net/_userdata/JAF_speed.closed.competition.insurance_info_2026.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/JAF_speed.competition.insurance_form.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/JAF_speed.competition.insurance_form.xlsx",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2026年度ＪＡＦ公認スピード競技保険案内",
        "url": "http://www.jmrc-kinki.net/_userdata/JAF_speed.competition.insurance_info_2026.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "オフィシャル名簿・PDF",
        "url": "http://www.jmrc-kinki.net/_userdata/JMRCkinki_officiallist.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "オフィシャル名簿・Excel",
        "url": "http://www.jmrc-kinki.net/_userdata/JMRCkinki_officiallist.xlsx",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-18": [
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_form_addition.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_form_addition.xlsx",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_form_club.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_form_club.xlsx",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_form_organizer.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "画像リンク（添付原本で要確認）",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_form_organizer.xlsx",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "スポーツ安全保険案内",
        "url": "http://www.jmrc-kinki.net/_userdata/sports.security.insurance_info_2026.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ近畿個人会員様へスポーツ安全保険のご案内",
        "url": "http://www.jmrc-kinki.net/sports_insurance.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2026年度（令和８年度）スポーツ安全保険加入登録クラブ名簿",
        "url": "http://www.jmrc-kinki.net/sports_insurance_club.html",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2026年度 スポーツ安全保険加入登録クラブ名簿",
        "url": "http://www.jmrc-kinki.net/sports_insurance_club.html#compe",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "(外部リンク)",
        "url": "https://www.sportsanzen.org/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-19": [
      {
        "title": "2026年度 役員・委員会名簿",
        "url": "http://www.jmrc-kinki.net/meibo.html",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "運営委員会決定事項（2027年度 代表者会議の開催日及び会場が正式に決定）",
        "url": "http://www.jmrc-kinki.net/steeringcommittee_decisions.html",
        "year": "2027",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-20": [
      {
        "title": "ＪＭＲＣ全国協議会専門委員会規程",
        "url": "http://www.jmrc-kinki.net/_userdata/zenkoku_expertcommittee.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）",
        "category": "national",
        "publisher": "JMRC全国協議会"
      },
      {
        "title": "ＪＭＲＣ全国協議会会則・細則",
        "url": "http://www.jmrc-kinki.net/_userdata/zenkoku_kaisoku.saisoku.pdf",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）",
        "category": "national",
        "publisher": "JMRC全国協議会"
      },
      {
        "title": "ＪＡＦ近畿地域クラブ協議会会則",
        "url": "http://www.jmrc-kinki.net/kaisoku.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）",
        "category": "committee",
        "publisher": "JMRC近畿"
      },
      {
        "title": "JAF近畿地域協議会見舞金給付細則",
        "url": "http://www.jmrc-kinki.net/mimaikin20250401.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）",
        "category": "committee",
        "publisher": "JMRC近畿"
      }
    ],
    "HP-21": [
      {
        "title": "2026年度 登録クラブ",
        "url": "http://www.jmrc-kinki.net/_userdata/2026_clubs.pdf",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "クラブ・団体年度更新及び加入のご案内",
        "url": "http://www.jmrc-kinki.net/club_koushin_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2008年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2008clubs.html",
        "year": "2008",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2009年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2009clubs.html",
        "year": "2009",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2010年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2010clubs.html",
        "year": "2010",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2011年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2011clubs.html",
        "year": "2011",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2012年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2012clubs.html",
        "year": "2012",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2013年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2013clubs.html",
        "year": "2013",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2014年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2014clubs.html",
        "year": "2014",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2015年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2015clubs.html",
        "year": "2015",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2016年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2016clubs.html",
        "year": "2016",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2017年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2017clubs.html",
        "year": "2017",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2018年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2018clubs.html",
        "year": "2018",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2019年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2019clubs.html",
        "year": "2019",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2020年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2020clubs.html",
        "year": "2020",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2021年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2021clubs.html",
        "year": "2021",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2022年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2022clubs.html",
        "year": "2022",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2023年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2023clubs.html",
        "year": "2023",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2024年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2024clubs.html",
        "year": "2024",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2025年度 登録クラブ・団体名簿",
        "url": "http://www.jmrc-kinki.net/clubs/2025clubs.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-22": [
      {
        "title": "2025年度カレンダー",
        "url": "http://www.jmrc-kinki.net/aj_cal_2025.html",
        "year": "2025",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "2026年度カレンダー",
        "url": "http://www.jmrc-kinki.net/aj_cal_2026.html",
        "year": "2026",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＡＦからのお知らせ",
        "url": "http://www.jmrc-kinki.net/jaf_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＡＦ関西本部からのお知らせ",
        "url": "http://www.jmrc-kinki.net/jaf_kansai_info.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "Ａライセンス講習会スケジュール",
        "url": "https://jaf.or.jp/common/safety-drive/practical-training/seminars?genre=3&pref=00&subGenre=301",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "Ｂライセンス講習会スケジュール",
        "url": "https://jaf.or.jp/common/safety-drive/practical-training/seminars?genre=3&pref=00&subGenre=302",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "公認審判員講習会スケジュール",
        "url": "https://jaf.or.jp/common/safety-drive/practical-training/seminars?genre=3&pref=00&subGenre=303",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-23": [
      {
        "title": "ＪＡＦモータースポーツ",
        "url": "http://jaf-sports.jp/index.htm",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ北海道",
        "url": "http://jmrc-hokkaido.org/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ダートトライアル専門部会",
        "url": "http://jmrc.kinkidirt.com/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "一般社団法人 日本自動車連盟",
        "url": "http://www.jaf.or.jp/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "お問い合わせ",
        "url": "http://www.jmrc-kinki.net/inquiry.html",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "特別規則書・リザルト",
        "url": "https://gymkinki.wixsite.com/mysite",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "ＪＭＲＣ全国ラリー振興事業委員会",
        "url": "https://info.jmrc.jp/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      },
      {
        "title": "(部会HP)",
        "url": "https://jmrc-kinki-rally.com/",
        "year": "原本確認",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ],
    "HP-24": [
      {
        "title": "ＪＭＲＣ近畿シリーズ・新型コロナウイルスに関するアンケート（2020.6.12〜7.8）",
        "url": "http://www.jmrc-kinki.net/questionnaire202006.html",
        "year": "2020",
        "verification": "未確認（トップHTMLのhrefから抽出）"
      }
    ]
  }
}
CATALOG, true, 512, JSON_THROW_ON_ERROR);
