<?php
// Path: public/manage/_review-catalog.php
// 機能: 事務局の操作確認で扱う画面、確定条件、保留理由、入力項目を一か所に定義する。
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

/** 項目は [表示名, 種類または選択肢, 初期例]。例は実在情報・正式な制度条件として使わない。 */
function jmrc_review_catalog(): array
{
    $all = ['office','club','member','finance','public'];
    $visibility = ['private'=>'事務局のみ','members'=>'会員向け','public'=>'公開'];
    $catalog = [
        'clubs'=>['title'=>'正会員クラブの年度登録','group'=>'登録・資格','stage'=>'業務処理あり','roles'=>['office','club'],
            'flow'=>'クラブ申請 → 年会費請求 → 入金確認 → 事務局承認',
            'pending'=>'2027年度は2026年度と同額です。下の料金計算で新規・更新・再加入を比較できます。賛助のコード対応と既存登録の照合は保留で、本番請求は始まりません。',
            'fields'=>['name'=>['クラブ・団体名称','text','操作確認クラブ'],'short'=>['正式略称','text','確認CLUB'],'category'=>['クラブ区分',['1'=>'1 公認','2'=>'2 加盟','3'=>'3 準加盟','6'=>'6 加盟団体'],'1'],'base'=>['不変の5桁コード','text','00001'],'representative'=>['代表者名（架空）','text','確認 代表'],'contact'=>['連絡先名（架空）','text','確認 担当'],'email'=>['メール（架空）','text','club@example.invalid'],'address'=>['連絡先住所（架空）','text','確認用のクラブ住所'],'phone'=>['連絡先電話（架空）','text','000-0000-0000'],'region'=>['JMRC所属支部',\Jmrc\Club\ClubContact::REGIONS,'27'],'region_other'=>['その他の所属支部','text',''],'same_contact'=>['代表者が連絡先を兼ねる',['0'=>'別の担当者','1'=>'代表者と同じ（連絡先の入力を省略）'],'0'],'rep_address'=>['代表者住所（架空）','text','確認用の代表者住所'],'rep_phone'=>['代表者電話（架空）','text','000-0000-0000'],'rep_email'=>['代表者メール（架空）','email','rep@example.invalid'],'fee'=>['説明用のクラブ年会費（円）','number','']],
            'actions'=>['save'=>'年度登録を試す','issue'=>'年会費請求を試す','approve'=>'事務局承認を試す']],
        'licenses'=>['title'=>'ライセンス登録・更新','group'=>'登録・資格','stage'=>'業務処理あり','roles'=>['office','member'],
            'flow'=>'ライセンス登録 → General申請 → 更新時の資格再確認',
            'pending'=>'本番の既存ライセンス移行、採用順と会員番号再発行の運用は保留です。番号を変えた後の旧会員証は失効として確認できます。',
            'fields'=>['number'=>['JAFライセンス番号（架空）','text','DEMO123'],'kind'=>['種類',['competition'=>'競技 国内B','official'=>'審判員 B3'],'competition'],'expiry'=>['有効期限','date','2027-12-31']],
            'actions'=>['save'=>'登録・更新を試す']],
        'memberships'=>['title'=>'個人会員の申請','group'=>'登録・資格','stage'=>'業務処理あり','roles'=>['office','member'],
            'flow'=>'有効なライセンス → General申請 → 所属承認・満額入金 → 資格有効',
            'pending'=>'本番の受付開始と年度資格の参照先切替は保留です。Generalは年2,000円、Temporaryは1イベント500円の確認例です。',
            'fields'=>['name'=>['氏名（架空）','text','確認 太郎'],'kana'=>['フリガナ（架空）','text','カクニン タロウ'],'birthday'=>['生年月日（架空）','date','1990-04-01'],'gender'=>['性別',['male'=>'男性','female'=>'女性','other'=>'その他・回答しない'],'other'],'email'=>['メール（架空）','text','member@example.invalid'],'postcode'=>['郵便番号（架空）','text','000-0000'],'driver'=>['免許証番号（架空）','text','000000000000'],'driver_expiry'=>['免許有効期限（架空）','date','2027-12-31'],'beneficiary'=>['受取人（架空）','text','確認 家族'],'address'=>['住所（架空）','text','確認用の住所'],'phone'=>['電話（架空）','text','000-0000-0000']],
            'actions'=>['save'=>'General申請を試す']],
        'preregistration'=>['title'=>'クラブ員の事前登録・照合','group'=>'登録・資格','stage'=>'画面試作','roles'=>['office','club'],
            'flow'=>'クラブ員事前登録 → 本人のライセンスと照合 → 一意なら所属承認、競合なら手動確認',
            'pending'=>'本番の自動承認・事務局代行はまだ接続していません。照合の一意・不一致・競合を比較できます。',
            'fields'=>['number'=>['照合するJAFライセンス番号（架空）','text','DEMO123'],'match'=>['照合例',['unique'=>'一致する候補が1件','missing'=>'一致なし','conflict'=>'複数クラブで競合'],'unique']],
            'actions'=>['save'=>'事前照合を試す']],
        'affiliations'=>['title'=>'所属承認・変更','group'=>'登録・資格','stage'=>'業務処理あり','roles'=>['office','club','member'],
            'flow'=>'本人が変更申請 → 変更先代表または事務局代行が承認 → 会員番号を変更',
            'pending'=>'変更元クラブの承認は不要です。承認前は元の所属・番号を維持し、承認後に番号を変更して旧QRを失効します。会費を追加せず、ポイント履歴は共通の個人IDで引き継ぎます。本番の変更受付は保留です。',
            'fields'=>['reason'=>['確認・差戻し理由','text','申請内容を確認しました'],'representative_scope'=>['所属変更で確認する代表',['destination'=>'変更先クラブの代表','source'=>'変更元クラブの代表','unrelated'=>'関係のないクラブの代表'],'destination']],
            'actions'=>['approve'=>'所属を承認する','return'=>'所属を差し戻す','change'=>'別クラブへの変更例を見る']],
        'temporary'=>['title'=>'Temporary申請・主催クラブ承認','group'=>'登録・資格','stage'=>'業務処理あり','roles'=>['office','club','member'],
            'flow'=>'イベント選択 → 主催クラブ承認 → 500円入金 → 開催日の資格',
            'pending'=>'当日取消・返金はありません（掛け捨て）。Generalは別に新規登録し、差額切替はありません。事務局の立場だけでは主催クラブ承認はできません。',
            'fields'=>['event'=>['イベント',['A'=>'確認走行会A / 2027-04-10','B'=>'確認走行会B / 2027-04-10','C'=>'確認走行会C / 2027-04-11'],'A']],
            'actions'=>['save'=>'Temporary申請を試す','approve'=>'主催クラブとして承認する']],
        'billing'=>['title'=>'会費請求・入金確認・訂正','group'=>'会計・保険','stage'=>'業務処理あり','roles'=>['office','finance','member'],
            'flow'=>'一意の請求 → 入金確認 → 承認と合わせて有効化 → 訂正時に再判定',
            'pending'=>'本番への入金記録・資格切替は行いません。説明用の請求に対し不足・満額・訂正を確認できます。',
            'fields'=>['target'=>['対象請求',['general'=>'General会費','club'=>'クラブ年会費','temporary'=>'Temporary会費'],'general'],'amount'=>['確認する入金額（円）','number','2000'],'reference'=>['入金識別番号（架空）','text','DEMO-PAY-001'],'reason'=>['訂正理由','text','操作確認のため取消']],
            'actions'=>['issue'=>'請求を表示・発行する','confirm'=>'入金確認を試す','reverse'=>'最後の入金を取り消す']],
        'bank'=>['title'=>'ゆうちょCSV・入金照合','group'=>'会計・保険','stage'=>'業務処理あり','roles'=>['office','finance'],
            'flow'=>'実口座を特定 → CSVの重複確認 → 候補提示 → 担当者が確認',
            'pending'=>'振込名義の末尾にIDを付ける必要はありません。実際の銀行CSVと用途を識別する手順は確認待ちです。ここでは固定の架空CSVを取り込み、曖昧な入金は手動確認に残します。',
            'fields'=>['source'=>['取込元',['demo'=>'確認用ゆうちょ口座'],'demo']],
            'actions'=>['import'=>'架空CSVを取り込む','match'=>'一意候補の照合を試す']],
        'accounts'=>['title'=>'用途別口座・Payment Core URL','group'=>'会計・保険','stage'=>'業務処理あり','roles'=>['office'],
            'flow'=>'用途別の入金先 → 決済URL → CSV実口座との対応',
            'pending'=>'実口座・実決済URLは既存の事務局設定画面で管理します。この画面の値は本番の振込案内へ出ません。',
            'fields'=>['purpose'=>['用途',['club'=>'クラブ年会費','member'=>'個人会費','insurance'=>'スポーツ安全保険','relief'=>'協議会見舞金','fund'=>'ジムカーナ部会救済基金'],'member'],'bank'=>['口座表示（架空）','text','ゆうちょ銀行 確認用口座'],'url'=>['決済URLの例','url','https://example.invalid/payment'],'source'=>['CSV取込元の例','text','確認用ゆうちょ口座']],
            'actions'=>['save'=>'対応付けを試す']],
        'card'=>['title'=>'General会員証・QR資格照会','group'=>'登録・資格','stage'=>'業務処理あり','roles'=>$all,
            'flow'=>'本人用会員証 → 資格の最新状態 → 公開QRの表示範囲',
            'pending'=>'ここにある確認用照会は本番のQRではありません。公開項目は有効時の氏名・会員番号だけです。入会日・印影・証明文は保留です。',
            'fields'=>[],'actions'=>[]],
        'insurance'=>['title'=>'スポーツ安全保険・確認Excel','group'=>'会計・保険','stage'=>'業務処理あり','roles'=>['office','club','member'],
            'flow'=>'Generalの加入意思 → 年度クラブ単位の初回4名確認 → 確認用Excel',
            'pending'=>'2027年度は申請日の満年齢で64歳以下2,000円、65歳以上1,200円です。年度途中の申請も可能です。保険機関の開始・終了期間はポータルで管理しません。正式受付・保険料請求は開始保留です。連絡用名簿は事務局で管理し、ポータルには保存しません。',
            'fields'=>['type'=>['会員区分',['general'=>'General','temporary'=>'Temporary（対象外）'],'general'],'count'=>['同じ年度クラブの有効General人数（架空）','number','4']],
            'actions'=>['save'=>'加入意思・人数を確認する']],
        'events'=>['title'=>'イベントカレンダー・参加者','group'=>'公開情報','stage'=>'画面試作','roles'=>$all,
            'flow'=>'イベント登録 → 公開前の確認 → 日程表・Temporary参加者確認',
            'pending'=>'カレンダーは事務局員が編集し、事務局長が承認します。既存entryの申込導線は継続します。実日程は事務局が入力します。不・ジ・ダ・ラ・そ・J・他の7分類で準備できます。公開範囲と実運用への接続は保留です。',
            'fields'=>['title'=>['イベント名','text','確認用ジムカーナ'],'date'=>['開催日','date','2027-04-10'],'venue'=>['会場','text','確認用会場'],'visibility'=>['公開範囲',$visibility,'private']],
            'actions'=>['save'=>'日程の下書きを保存する']],
        'directory'=>['title'=>'公開クラブ一覧','group'=>'公開情報','stage'=>'画面試作','roles'=>$all,
            'flow'=>'年度登録 → 公開する項目の確認 → クラブ一覧',
            'pending'=>'新年度のクラブ一覧は公開可、保険加入クラブも併記する方針です。連絡先など個別の公開項目は確認待ちです。画面例はクラブ名・略称・コードだけで、連絡先や銀行口座は公開しません。','fields'=>[],'actions'=>[]],
        'notices'=>['title'=>'お知らせ','group'=>'公開情報','stage'=>'画面試作','roles'=>$all,
            'flow'=>'記事作成 → 公開範囲を選択 → 読者側の表示を確認',
            'pending'=>'記事は事務局員が編集し、事務局長が承認します。ここで公開を選んでも確認画面内だけの表示です。正式な原稿・移行先は確認待ちです。',
            'fields'=>['title'=>['見出し','text','登録開始に関するお知らせ（例）'],'body'=>['本文','textarea','これは架空の確認用記事です。実際の受付開始案内ではありません。'],'visibility'=>['公開範囲',$visibility,'private']],
            'actions'=>['save'=>'記事の下書きを保存する','remove'=>'最後の記事を取り下げる']],
        'documents'=>['title'=>'資料BOX・運営委員会資料','group'=>'公開情報','stage'=>'画面試作','roles'=>$all,
            'flow'=>'資料登録 → 閲覧対象の確認 → 資料一覧',
            'pending'=>'運営委員会資料は原則非公開です。「資料・日程・文面の下書き」で事務局限定のPDF保存（2MB以内）を利用できます。一般公開と承認への接続は保留です。',
            'fields'=>['category'=>['資料の種類',['committee'=>'運営委員会資料','form'=>'申請書式','rental'=>'主催者向けレンタル機器案内','jaf'=>'JAF公認競技保険の案内'],'committee'],'title'=>['資料名','text','運営委員会資料（確認例）'],'body'=>['資料の説明','textarea','会議用の説明資料です。'],'visibility'=>['閲覧対象',$visibility,'private']],
            'actions'=>['save'=>'資料情報を保存する','remove'=>'最後の資料を取り下げる']],
        'handbook'=>['title'=>'会則・シリーズ規則','group'=>'公開情報','stage'=>'画面試作','roles'=>$all,
            'flow'=>'全体情報の版を登録 → 閲覧範囲確認 → 部会HPへの案内',
            'pending'=>'ハンドブック冊子は廃止する方針です。正式な2027年の会則・規程原本の提供を待っています。確定した成立条件と有効戦数は下の計算例で確認できます。平均は成立戦数で割り、先に四捨五入します。同点比較は有効得点・全得点・高得点を得た時期の3段階です。P18は3段階で解消する前提です。比較不能な場合は大会結果を確認します。部会情報は部会HP、全体情報は事務局で扱います。',
            'fields'=>['title'=>['文書名・版','text','2027年シリーズ規則（確認例）'],'body'=>['内容の説明','textarea','正式な規程原本の受領後に差し替えます。'],'visibility'=>['閲覧対象',$visibility,'private']],
            'actions'=>['save'=>'文書情報を保存する']],
        'links'=>['title'=>'関連リンク','group'=>'公開情報','stage'=>'画面試作','roles'=>$all,
            'flow'=>'リンク名・URL登録 → 公開範囲の確認',
            'pending'=>'正式な移行対象URLは確認待ちです。確認画面では外部URLへ移動せず、入力値を表示します。',
            'fields'=>['title'=>['リンク名','text','部会ホームページ（例）'],'url'=>['URL','url','https://example.invalid/'],'visibility'=>['閲覧対象',$visibility,'private']],
            'actions'=>['save'=>'リンク案を保存する']],
        'relief'=>['title'=>'協議会見舞金（請求は今回対象外）','group'=>'事務局業務','stage'=>'画面試作','roles'=>['office','member'],
            'flow'=>'制度の違いと、今回のオンライン化の対象外であることを確認',
            'pending'=>'INS-05・追加資料P20により、協議会見舞金の請求は今回は見送ります。スポーツ安全保険の被保険者名・クラブ名の2項目による連絡とは別です。申請入力・送信・審査・給付は行えません。',
            'fields'=>[],'actions'=>[]],
        'fund'=>['title'=>'ジムカーナ部会救済基金','group'=>'会計・保険','stage'=>'運用確認','roles'=>['office','finance','member'],
            'flow'=>'既存entryの管理範囲を確認 → 制度間の違いを確認',
            'pending'=>'救済基金の管理移管は未確定です。個人会費・協議会見舞金・スポーツ安全保険の支払から基金加入済みを推定しません。','fields'=>[],'actions'=>[]],
        'meetings'=>['title'=>'会議・表彰式案内と出欠回答','group'=>'事務局業務','stage'=>'画面試作','roles'=>['office','club'],
            'flow'=>'会議案内 → 出欠回答 → 回答一覧',
            'pending'=>'招集対象、代理出席、通知方法と担当者は確認待ちです。招集メールは送信しません。',
            'fields'=>['title'=>['会議名','text','運営確認会（架空）'],'date'=>['開催日','date','2027-04-10'],'attendance'=>['回答',['pending'=>'未回答','yes'=>'出席','no'=>'欠席','proxy'=>'代理出席・委任（案）'],'pending'],'delegate'=>['代理・委任先（架空）','text','確認用の代理者'],'body'=>['連絡事項','textarea','確認用の回答です。']],
            'actions'=>['save'=>'案内・回答を保存する']],
        'inquiries'=>['title'=>'問い合わせ・対応管理','group'=>'事務局業務','stage'=>'画面試作','roles'=>['office','club','member'],
            'flow'=>'問い合わせ入力 → 受付番号 → 事務局の回答案',
            'pending'=>'本番の通知先と回答文の承認者は確認待ちです。入力は操作確認内に保存し、事務局へ送信しません。',
            'fields'=>['title'=>['件名','text','登録手順の確認（架空）'],'body'=>['問い合わせ内容・回答案','textarea','申請から会員証までの流れを確認したいです。']],
            'actions'=>['save'=>'問い合わせを試す','reply'=>'回答案を保存する']],
        'notifications'=>['title'=>'通知・承認依頼の文面','group'=>'事務局業務','stage'=>'画面試作','roles'=>['office'],
            'flow'=>'宛先区分 → 文面確認 → 運営承認後の送信準備',
            'pending'=>'通知先は個人会員・クラブ代表・事務局です。表彰対象者への案内も追加します。事務局が文面を確認し、任意の時点で手動送信します。正式文面は12月登録予定で、現在は別画面で非公開の文案を保存できます。確認画面からメールは送信されません。',
            'fields'=>['target'=>['宛先区分',['member'=>'申請者','club'=>'クラブ代表','office'=>'事務局'],'member'],'title'=>['件名','text','個人会員申請の状況について（例）'],'body'=>['通知文案','textarea','申請内容をご確認ください。これは確認用の文面です。']],
            'actions'=>['save'=>'通知文案を保存する']],
        'privacy'=>['title'=>'個人情報訂正・削除候補','group'=>'事務局業務','stage'=>'一部業務処理あり','roles'=>['office'],
            'flow'=>'氏名・生年月日の訂正 → 未更新者の候補確認 → 消去範囲の確認',
            'pending'=>'削除対象はポータル会員個人情報のみです。共通アカウント・entry履歴・会計・操作ログは残します。本番の履歴・複製の消去手順は保留です。',
            'fields'=>['name'=>['訂正後の氏名（架空）','text','確認 次郎'],'birthday'=>['訂正後の生年月日（架空）','date','1990-04-02'],'reason'=>['訂正理由','text','入力内容の訂正例']],
            'actions'=>['save'=>'訂正を試す','erase'=>'架空プロフィールの削除例を見る']],
        'operations'=>['title'=>'年度設定・権限・移行・保存方針','group'=>'事務局業務','stage'=>'運用確認','roles'=>['office'],
            'flow'=>'年度受付・費用を確認 → 権限確認 → 移行・復元 → 受付開始判断',
            'pending'=>'2027年度の切替・ライセンス移行・受付開始・本番権限付与は行いません。運営委員は事務局と同等です。事務局・運営委員の登録は承認者が承認し、承認者の登録は運営委員が承認します。自己承認はできません。バックアップは日次30世代・月次12世代・四半期復元試験を設計値としています。下書きの起算点は最終編集で確定しました。バックアップの保存先・担当・自動運転と公開開始は保留です。',
            'fields'=>['date'=>['資格照会の確認日（架空）','date','2027-04-10'],'status'=>['Generalの状態例',['pending'=>'承認・入金待ち','active'=>'必要条件が揃った状態','unpaid'=>'入金訂正で未入金','suspended'=>'資格停止','expired'=>'期間終了'],'pending']],
            'actions'=>['save'=>'状態例を表示する']],
    ];
    // C02/C06～C16/C24: 確定条件の計算と操作例を、既存の24画面内に追加する。
    $catalog['memberships']['fields'] += ['competition_type'=>['競技ライセンス種別（架空）','text','確認用競技種別'], 'official_type'=>['審判員ライセンス種別（架空・非保有は「なし」）','text','確認用審判種別']];
    $catalog['clubs']['fields'] += ['rate_category'=>['料金計算の区分',['official'=>'公認','affiliated'=>'加盟','associate'=>'準加盟','organization'=>'登録団体','business'=>'関連団体・企業'],'official'], 'membership_class'=>['料金計算の会員区分',['regular'=>'正会員','supporting'=>'賛助会員'],'regular'], 'registration_kind'=>['料金計算の登録方法',['new'=>'新規','renewal'=>'年度更新','rejoin'=>'再加入'],'renewal']];
    $catalog['clubs']['actions']['quote']='2027年度の料金を計算';
    $catalog['clubs']['region_fields'] = ['jaf_registered_club'=>['JAF登録クラブですか',['unknown'=>'確認できていない','yes'=>'はい','no'=>'いいえ'],'unknown'], 'hosts_kinki_business'=>['JMRC近畿の事業として競技会を主催しますか',['unknown'=>'確認できていない','yes'=>'はい','no'=>'いいえ'],'unknown']];
    $catalog['clubs']['actions']['classify_region']='他地域クラブの区分を確認';
    $catalog['operations']['fields'] += ['requested_role'=>['登録する役職',['OFFICE'=>'事務局','COMMITTEE'=>'運営委員','APPROVER'=>'承認者'],'OFFICE'], 'role_subject'=>['登録対象',['other'=>'別の架空担当者','self'=>'操作している本人'],'other']];
    $catalog['operations']['actions']['approve_role']='役職の承認可否を試す';
    $catalog['handbook']['fields'] += ['rounds'=>['成立した当該クラスの戦数','number','7'], 'starters'=>['1競技会の出走台数（成立判定用）','number','3'], 'cancelled'=>['その競技会は中止か',['no'=>'開催','yes'=>'中止'],'no'], 'season_ended'=>['シリーズは終了したか',['yes'=>'終了','no'=>'開催中'],'yes'], 'total_starters'=>['表彰人数の計算例：成立戦の延べ出走台数','number','70'], 'award_events'=>['表彰人数の計算例：成立した当該クラスの競技会数','number','7']];
    $catalog['insurance']['fields'] += ['birthday'=>['保険料計算用の生年月日（架空）','date','1962-04-10'],'application_date'=>['申請日（架空・2027年）','date','2027-04-10']];
    $catalog['handbook']['actions']['calculate']='成立条件・有効戦数・表彰人数を試算';
    $catalog['notifications']['actions']['award_draft']='表彰案内の文案を作る';
    $catalog['insurance']['claim_fields']=['insured_name'=>['被保険者名（架空）','text','確認 太郎'], 'club_name'=>['所属クラブ名（架空）','text','操作確認クラブ']];
    $catalog['insurance']['actions']['claim_notice']='請求連絡の2項目を確認する';
    return $catalog;
}
