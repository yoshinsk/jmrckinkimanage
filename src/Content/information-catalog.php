<?php
// Path: src/Content/information-catalog.php
// 機能: 2026-11-09の確定回答と154件の旧URL候補。記事原文・個人名簿・正式移行先は含まない。
declare(strict_types=1);
return json_decode(<<<'CATALOG'
{
  "snapshot": "2026-11-09",
  "source_file": "20261031_旧HP移行元URL台帳.csv",
  "source_sha256": "d3322d14ce2e337e9453d9af9dc7944bed21c00766d583b8ccbe6907e9807189",
  "answer_source": "Documents/answers/20261109_追加回答.json",
  "answer_sha256": "ca5c3d95350987026f95bc3541b2341232e317fdd12b2eb819088963c5f72cac",
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
      "pending": "運営委員の権限は事務局と同等。追加アカウントは相互承認で扱う。実運用CSVの列選択仕様は確認待ち。"
    },
    {
      "id": "R08",
      "title": "保存・バックアップ",
      "confirmed": "操作ログ2年。下書きは最終編集から3か月。ログアウトせずブラウザーを閉じてもログインを維持する。",
      "pending": "ログインは既存の有効期間内で維持。新しい非公開下書きは保存ボタンで確定し、未保存の入力は保存されない。自動削除・バックアップ定期運転は未適用。"
    },
    {
      "id": "R11",
      "title": "事務局・運営委員・承認者の登録承認",
      "confirmed": "事務局と運営委員は同等の業務権限。事務局・運営委員の登録は承認者、承認者の登録は運営委員が承認する。自己承認は禁止。",
      "pending": "運営委員長・副委員長・事務局長は承認者に統一。通常の役職申請受付は保留。"
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
      "pending": "事務局がクラブ員名簿から本人へ連絡し、同姓同名は電話で確認する。正式送信先との接続・保険会社への正式請求は保留。"
    },
    {
      "id": "Q04",
      "title": "主催者の権限",
      "confirmed": "P14により2026年度ジムカーナの既存オンラインエントリー運用を踏襲する。",
      "pending": "新しい共同主催・代理権限を自動付与しない。ポータルとの実アカウント対応は個別に検証する。"
    },
    {
      "id": "Q05",
      "title": "主催者の資格照会",
      "confirmed": "担当イベントの締切から終了日の7日後23:59まで、照会時点の最新資格2項目を表示する。",
      "pending": "既存イベントの締切・終了日時との対応と、実データ照会の接続は保留。"
    },
    {
      "id": "Q06",
      "title": "ポイントの管理場所・リンク",
      "confirmed": "P06で変更。各部会長がポータルへ結果を登録する。関連リンクは部会トップを基本とする。",
      "pending": "事務局の非公開準備ではUTF-8 CSVとPDF原本を保存可能。部会担当の実権限・正式得点規則・公開接続は保留。"
    },
    {
      "id": "Q07",
      "title": "シリーズ・クラスの範囲",
      "confirmed": "ジムカーナと将来のダートトライアルに対応する。同じ選手の複数クラス・競技参加を分けて扱う。ラリーのオンラインエントリー移行は現時点の対象外。",
      "pending": "2027年度規則・提出済みクラス一覧は未受領。C15で成立条件と有効戦数を確認済み。順位別配点・失格等の扱いは推定しない。"
    },
    {
      "id": "Q08",
      "title": "ポイント対象資格",
      "confirmed": "Generalのみ。申込時に個人会員かつ正会員クラブ所属が必要。正会員間の移籍で権利を失わない。P15により資格停止はポイント無効、原則救済なし。",
      "pending": "入金訂正だけの場合やクラブ更新猶予は未回答。履歴を現在の状態で書き換えない。"
    },
    {
      "id": "Q09",
      "title": "集計・表示・訂正",
      "confirmed": "クラス別順位・各戦ポイント・有効ポイント・氏名・クラブ略称を表示予定。5戦以下は全戦、超過時は70％四捨五入（全7戦なら5戦、6戦なら4戦）。結果訂正は同一イベントの全件差替え。",
      "pending": "P07の3段階同点比較を適用。最終同順位の表彰枠超過はP18待ち。正式公開は保留。"
    },
    {
      "id": "Q10",
      "title": "インフォメーションの担当",
      "confirmed": "4分類は事務局員が編集し事務局長が承認する。",
      "pending": "資料の非公開下書きだけ保存可能。正式原稿と承認者の接続を確認してから公開する。"
    },
    {
      "id": "Q11",
      "title": "カレンダーの担当",
      "confirmed": "事務局入力・事務局長承認。不・ジ・ダ・ラ・そ・J・他の7分類。",
      "pending": "非公開下書きの編集まで実装。予定別公開範囲・承認・一般公開は保留。Google同期は行わない。"
    },
    {
      "id": "Q12",
      "title": "引継ぎ対象",
      "confirmed": "旧HP情報は1年以内の移管を目標とし、旧HP運用は現事務局へ委任する（P11）。",
      "pending": "以前の「クラブ名簿、旧年度の規則・様式は原則として新ポータルへ移さない」という回答も残るため、すべてのファイルを自動複製・公開せず、対象台帳で個別に確認する。"
    },
    {
      "id": "Q13",
      "title": "会則・規程の分類",
      "confirmed": "会則・規程は運営委員会／全国JMRCに分類。P13により役員名簿は事務局のPDF掲載、保険加入クラブはクラブ一覧に併記する。",
      "pending": "新クラブ一覧は公開可だが住所・電話等の個別公開項目は保留。非公開委員会資料は公開しない。"
    },
    {
      "id": "Q14",
      "title": "旧HPの残置と移行完了",
      "confirmed": "2026年12月31日から1年間の方針に対し、P11で1年以内の移管目標と旧HP運用の事務局委任を補足。",
      "pending": "実際の移管日・旧サーバー停止・保管先は未決定。停止・DNS変更・削除を行わない。"
    },
    {
      "id": "C02",
      "title": "JAF種別・会員番号と履歴",
      "confirmed": "番号はJAFライセンス番号-クラブコード。移籍申請は本人が行い、クラブ承認後に番号を変更。氏名・ライセンス名・JAF会員番号変更は事務局承認。",
      "pending": "所属変更の承認が変更先だけか双方かはP19待ち。受付・再発行・訂正申請の本番実行は保留。"
    },
    {
      "id": "C06",
      "title": "2027年度の料金",
      "confirmed": "2027年は申請日の満年齢で64歳以下2,000円、65歳以上1,200円。途中加入可。保険機関の期間管理は不要。",
      "pending": "料率の2028年への繰越し、正式受付・請求開始は行わない。"
    },
    {
      "id": "C07",
      "title": "賛助会員の年度登録",
      "confirmed": "地区外は原則賛助、JMRC近畿の事業として主催する他地域JAFクラブは正会員。賛助の申請はすべてコード先頭6。",
      "pending": "正会員の登録団体も6。コードから正会員・賛助や会費を逆算しない。実データの正式区分・受付開始は保留。"
    },
    {
      "id": "C08",
      "title": "入会金",
      "confirmed": "新規クラブ・登録団体は賛助を含め10,000円。更新・再加入・賛助の関連団体／企業は入会金なし（11月3日補足）。",
      "pending": "旧会則には未更新後の新規扱いがあるため、2027年度の規程に今回の回答を明記する必要がある。"
    },
    {
      "id": "C09",
      "title": "銀行CSV原本",
      "confirmed": "サンプルを依頼中との回答。",
      "pending": "現物未受領。CSV形式の確定と本番取込は保留。"
    },
    {
      "id": "C10",
      "title": "役職と相互承認",
      "confirmed": "財務委員を運営委員へ改称し、事務局と同等にする。承認者を上位の登録承認役職として設け、自己承認を禁止する。",
      "pending": "初期設定と通常の役職申請を分ける。記事等の承認担当は個別の決定も保持する。"
    },
    {
      "id": "C11",
      "title": "運営委員の業務権限",
      "confirmed": "運営委員は事務局と同等の業務権限を持つ。",
      "pending": "対象クラブに限定されるTemporary主催承認を、全体権限だけで行うことはできない。"
    },
    {
      "id": "C12",
      "title": "主催者照会の終了時刻",
      "confirmed": "イベント終了日の7日後23:59まで（日本時間）。翌日0:00から照会不可。",
      "pending": "イベント日時との対応確認後に実データへ接続する。"
    },
    {
      "id": "C13",
      "title": "年またぎのポイント資格",
      "confirmed": "P05により前年中の翌年イベント申込開始は行わない（各部会長確認済み）。",
      "pending": "会員・クラブの先行年度登録とは別。2月7日前の更新猶予による得点救済は未回答。既存受付をこの変更で開けない。"
    },
    {
      "id": "C14",
      "title": "部会の確認と表彰案内",
      "confirmed": "成立戦の台数合計÷成立戦数の平均を先に四捨五入し、その半数以内かつ6名以内を表彰。事務局が確認し任意時点に手動メール送信。",
      "pending": "最終同順位が枠を超える場合はP18待ち。12月の文面登録を待ち、現在は文案保存までで送信しない。"
    },
    {
      "id": "C15",
      "title": "クラス・シリーズ成立と有効戦",
      "confirmed": "出走3台以上でクラス成立。終了時3戦以上でシリーズ成立。5戦以下は全戦、超過時70％四捨五入。同点は有効得点の高得点回数、全得点の高得点回数、高得点を得た時期が遅い順（P07で更新）。",
      "pending": "正式規程PDF・配点・失格等の扱いは原本待ち。最終同順位は勝手に解消しない。"
    },
    {
      "id": "C16",
      "title": "保険請求連絡後の本人確認",
      "confirmed": "入力は被保険者名とクラブ名の2項目。事務局がクラブ員名簿を確認して本人へ連絡し、同姓同名は電話で確認する。",
      "pending": "旧制度・紙申請も含む受付への実接続は保留。氏名だけでアカウントを自動対応付けしない。"
    },
    {
      "id": "C17",
      "title": "個人情報削除の時期と複製",
      "confirmed": "ポータル会員個人情報だけが削除対象。共通アカウント・履歴・会計・操作ログは保持。保険連絡用名簿は事務局管理でポータル保存不要。",
      "pending": "P10は連絡用名簿の回答であり、共通記録や保険申請・帳票を一括削除する合意ではない。個別退会・複製・バックアップの扱いは保留。"
    },
    {
      "id": "C18",
      "title": "旧HP残置の起算日",
      "confirmed": "P11により旧HP運用は現事務局へ委任し、1年以内に情報移管する目標。",
      "pending": "実際の廃止・保管先・移管完了確認は別途。サーバーやDNSは変更しない。"
    },
    {
      "id": "C24",
      "title": "通知先",
      "confirmed": "本人・クラブ代表・事務局へのメール通知。事務局が文面を確認し、任意の時点で送信する。",
      "pending": "正式文面は12月登録予定。下書き保存までとし、送信・宛先選定は保留。"
    },
    {
      "id": "P01",
      "title": "所属・氏名等の変更承認",
      "confirmed": "所属変更時は本人がポータルのマイページで登録内容の変更を申請しクラブの承認を持って会員番号を変更としてください。氏名、ライセンス名、JAF会員番号の変更時は事務局承認です。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P02",
      "title": "保険の年齢判定と申請日",
      "confirmed": "①誕生日以降を起点にしてください。②保健機関の開始・終了時の取り扱いはポータル上では不要です。③年度途中の申し込みも受け付けます。申請日が起点です。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P03",
      "title": "賛助のコード",
      "confirmed": "賛助での申し込みは６です。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P05",
      "title": "翌年イベントの申込開始",
      "confirmed": "翌年開催分を前年度内に申し込み開始する運用は今後も行いません。（部会長確認済み）",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P06",
      "title": "結果登録と訂正版",
      "confirmed": "正式結果は上記で各部会のＨＰに掲載から引き取りと書きましたが訂正します。各部会長からポータルに結果を登録する運用でお願いします。その際のファイル形式は指定してください。（ＰＤＦでもよいか？）訂正版がある場合は改めて登録として上書をお願いしたいです。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P07",
      "title": "表彰平均と同点比較",
      "confirmed": "成立した試合の参加台数を合計し、成立戦数で割ります。小数点以下は四捨五入です。同点の場合の順位ルールは、「１） 有効得点（選手権として成立した当該クラスの競技会数の７０％（小数点以下四捨五入））の範囲内で高得点を得た回数の多い順に順位を決定する。２） 上記１）の回数も同一の場合、当該競技者が獲得したすべての得点のうち、高得点を得た回数の多い順に順位を認定する。３） 上記２）の方法によっても結果が出ない場合には、高得点を得た時期が遅い順に順位を認定する。」です。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P08",
      "title": "表彰案内の担当・文面",
      "confirmed": "担当者、確認者は事務局です。送信タイミングは送信ボタン等で任意としてください。使用する文面は運用後（12月中）に登録することはできませんでしょうか？まだ事務局員と話しができる状況にありませんので。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P09",
      "title": "正式規程PDF",
      "confirmed": "2027年度のハンドブック・部会別シリーズ規則・クラス一覧、これらは事務局からPDF等アップロードすることはできませんでしょうか？",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P10",
      "title": "保険連絡用名簿",
      "confirmed": "保険連絡用の名簿やデータは事務局で管理します。ポータル内での保存は不要です。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P11",
      "title": "旧HPの移管目標",
      "confirmed": "旧のＨＰ運用は現事務局員にお任せください。ただし、現ＨＰの情報は1年以内に今回のポータルにすべて移管することが目標ということです。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P12",
      "title": "所属通知の文面と送信",
      "confirmed": "通知文面の確認担当者は事務局です。各通知を送るタイミングは随時、連絡方法はメールです。文案は運用後（12月中）の登録を希望します。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P13",
      "title": "名簿の掲載方法",
      "confirmed": "新年度のクラブ一覧は公開可。役員名簿はPDFで事務局から掲載とさせてください。保険加入クラブはクラブ一覧で閲覧可能としてください。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P14",
      "title": "既存主催運用",
      "confirmed": "既存エントリーとはジムカーナのオンラインエントリーのことを言っているつもりです。26年度の運用と同じです。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P15",
      "title": "停止後のポイント",
      "confirmed": "資格停止の場合はポイント無効です。基本的に救済はありません。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P16",
      "title": "下書き期限とログイン",
      "confirmed": "3か月は最終編集タイミングヵら。ログアウトせずに閉じた場合もログインはキープしてください。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
    },
    {
      "id": "P17",
      "title": "予定の分類",
      "confirmed": "カレンダーの枠組みだけ準備して、行事予定は事務局が入力できるようにしてください。その際、「不」事務局の対応不可日「ジ」ジムカーナイベント開催日「ダ」ダートらイベント開催日「ラ」ラリーイベント開催日「そ」その他のイベント開催日、「J」JAFイベント開催日「他」その他、等の見出しを設けてください。",
      "pending": "反映範囲と保留事項は上の関連するC・Q項目を参照。受付・公開・送信は開始しない。"
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
      "categories": [
        "office"
      ]
    },
    "HP-06": {
      "title": "ジムカーナのシリーズポイント",
      "summary": "各部会長がポータルへ結果を登録する方針に変更しました。訂正版は同一イベントの全件差替えとし、PDFは原本資料、UTF-8 CSVは構造化結果として非公開で準備できます。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "gym"
      ],
      "policy": "P06・P07を適用。旧HPからの自動取得は行わない。部会トップへのリンクは継続",
      "page": "P23",
      "pending": "正式な2027年度規則・クラス一覧・配点と部会担当の実権限対応、公式集計・公開は保留。最終同順位の表彰枠超過はP18待ち。",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 5,
      "categories": [
        "kinki"
      ]
    },
    "HP-07": {
      "title": "ダートトライアルのシリーズポイント",
      "summary": "各部会長がポータルへ結果を登録する方針に変更しました。訂正版は同一イベントの全件差替えとし、PDFは原本資料、UTF-8 CSVは構造化結果として非公開で準備できます。",
      "sections": [
        "links"
      ],
      "category": "kinki",
      "divisions": [
        "dirt"
      ],
      "policy": "P06・P07を適用。旧HPからの自動取得は行わない。部会トップへのリンクは継続",
      "page": "P24",
      "pending": "正式な2027年度規則・クラス一覧・配点と部会担当の実権限対応、公式集計・公開は保留。最終同順位の表彰枠超過はP18待ち。",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 5,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "pending": "2027年度は64歳以下2,000円・65歳以上1,200円。申請日の満年齢で判定し、保険期間は管理対象外。正式受付は保留。事務局が名簿から連絡し、同姓同名は電話で確認する。",
      "publisher": "原本確認待ち",
      "classification_note": "",
      "state": "review",
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
      "categories": [
        "committee",
        "national"
      ]
    },
    "HP-21": {
      "title": "クラブ年度登録・クラブ名簿",
      "summary": "クラブ年度登録の案内を掲載する構成です。旧HPのクラブ名簿は原則として新ポータルへ移行せず、別途バックアップを相談します。実際の登録記録から名簿を自動公開しません。 他地域でも、JMRC近畿の事業として競技会を主催するJAF登録クラブは2027年度も正会員とします。",
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
      "revision": 4,
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
  },
  "prior_answer_source": "Documents/answers/20261103_整合性チェック追加回答.json"
}
CATALOG, true, 512, JSON_THROW_ON_ERROR);
