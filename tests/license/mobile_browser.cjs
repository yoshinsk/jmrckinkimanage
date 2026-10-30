// Path: tests/license/mobile_browser.cjs
// 機能: 隔離HTTPS上の実Chromium/WebKitをスマートフォン設定で操作し、登録・期限更新・QR失効を検証。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),jsQR=require('jsqr'),{PNG}=require('pngjs');
const cases=[],browsers=[];let currentCase='setup';
function check(name,value){currentCase=name;assert.ok(value,name);cases.push({case:name,result:'PASS'});}
async function makeContext(browser,device){const c=await browser.newContext({...devices[device],ignoreHTTPSErrors:true,locale:'ja-JP',timezoneId:'Asia/Tokyo',serviceWorkers:'block'});await c.route('**/*',route=>{
    // 既存ヘッダーのCSSだけを固定ファイルから供給し、外部通信は遮断する。
    if(route.request().url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return route.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'});
    return new URL(route.request().url()).hostname==='web'?route.continue():route.abort();
});return c;}
/** 既存CSSで狭幅・横向き・PCを描画し、縦配置と認証エラーからの再入力を確認する。 */
async function checkLoginLayout(context,name,device,uid){
    const page=await context.newPage();await page.goto('https://web/manage/login.php');
    check(name+' manageから共通ログインへ',new URL(page.url()).pathname==='/entry/login.php' && new URL(page.url()).searchParams.get('app')==='manage');
    for(const width of [320,375,390,412,844,1280]){
        await page.setViewportSize({width,height:width===844?390:844});
        const layout=await page.evaluate(()=>{
            const email=document.querySelector('[name=email]'),password=document.querySelector('[name=password]'),button=document.querySelector('button[type=submit]');
            const e=email.getBoundingClientRect(),p=password.getBoundingClientRect(),b=button.getBoundingClientRect(),form=email.form.getBoundingClientRect();
            return document.documentElement.scrollWidth<=innerWidth+1 && e.x>=0 && e.right<=innerWidth && p.y>=e.bottom+16 && b.y>=p.bottom+16 && Math.abs(e.width-form.width)<1 && Math.abs(p.width-e.width)<1 && parseFloat(getComputedStyle(email).fontSize)>=16 && getComputedStyle(button).backgroundColor==='rgb(13, 110, 253)';
        });
        check(name+' サインイン幅'+width+'pxの縦配置・入力幅・余白',layout);
    }
    await page.setViewportSize(devices[device].viewport);await page.screenshot({path:`/artifacts/${name}-login.png`,fullPage:true});
    let submits=0;page.on('request',r=>{if(r.method()==='POST')submits++;});
    await page.getByRole('button',{name:'サインイン',exact:true}).tap();check(name+' 空欄はブラウザーが送信を抑止',submits===0 && !await page.locator('form').evaluate(f=>f.checkValidity()));
    await page.getByLabel('メールアドレス').fill(`u${uid}@example.invalid`);await page.getByLabel('パスワード').fill('incorrect-lab-password');
    const response=page.waitForResponse(r=>new URL(r.url()).pathname==='/entry/login.php' && r.request().method()==='POST');await page.getByRole('button',{name:'サインイン',exact:true}).tap();
    check(name+' 認証失敗は401とエラー案内',(await response).status()===401 && (await page.getByRole('alert').innerText()).includes('サインインに失敗しました'));
    check(name+' 認証失敗後もmanageへの戻り先を保持',await page.locator('[name=app]').inputValue()==='manage' && await page.locator('[name=return_key]').inputValue()==='dashboard' && await page.getByLabel('パスワード').inputValue()==='');
    await page.screenshot({path:`/artifacts/${name}-login-error.png`,fullPage:true});await page.close();
}
async function login(context,id){const p=await context.newPage();await p.goto('https://web/entry/login.php?app=manage&return_key=dashboard');await p.getByLabel('メールアドレス').fill(`u${id}@example.invalid`);await p.getByLabel('パスワード').fill('lab-password-only');await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);return p;}
async function post(context,path,csrf,data){const r=await context.request.post('https://web'+path,{form:{...data,_csrf:csrf}});assert.ok(r.ok(),'API status '+r.status());return r.json();}
async function publicState(page,active){await page.getByRole('button',{name:'最新の資格を照会する'}).tap();await page.waitForFunction(v=>document.getElementById('verification-status').dataset.active===String(v),active);}
async function run(engine,name,device,uid){
    const browser=await engine.launch({headless:true});try{
        browsers.push({engine:name,version:browser.version(),device});
        const owner=await makeContext(browser,device),office=await makeContext(browser,device),anon=await makeContext(browser,device);
        await checkLoginLayout(owner,name,device,uid);
        const page=await login(owner,uid),finance=await login(office,7);const officeCsrf=await finance.locator('input[name=_csrf]').first().inputValue();
        await page.goto('https://web/manage/licenses.php');const ownCsrf=await page.locator('input[name=_csrf]').first().inputValue();
        check(name+' ライセンス入力画面も横はみ出しなし',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        await page.getByLabel('JAFライセンス番号').fill(`MOBILE${uid}`);await page.getByLabel('ライセンス種類',{exact:true}).fill('国内B');await page.getByLabel('有効期限',{exact:true}).fill('2099-12-31');
        await page.screenshot({path:`/artifacts/${name}-license.png`,fullPage:true});
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);
        check(name+' 本人がタッチ操作で登録',(await page.getByRole('status').innerText()).includes('保存しました'));
        const listing=await (await owner.request.get('https://web/manage/licenses.php?format=json')).json();const identifier=listing.licenses.at(-1);
        const year=new Date().getUTCFullYear(),membership=await post(owner,'/manage/memberships.php?format=json',ownCsrf,{member_type:'general',year,club_year_id:900,identifier_id:identifier.id,request_key:crypto.randomBytes(24).toString('hex')});
        const invoice=await post(owner,'/manage/billing.php?format=json',ownCsrf,{action:'issue',id:membership.id});
        await post(office,'/manage/billing.php?format=json',officeCsrf,{action:'approve',id:membership.id});
        await post(office,'/manage/billing.php?format=json',officeCsrf,{action:'confirm',id:invoice.id,amount:invoice.amount_yen,reference:'MOBILE-'+uid,operation:crypto.randomBytes(24).toString('hex')});
        await page.goto('https://web/manage/card.php?id='+membership.id);await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'会員証を表示・発行する',exact:true}).tap()]);await page.locator('#card-qr svg').waitFor();
        const card=await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json();check(name+' 新規ライセンスから会員証へ連携',card.available && card.member_number===`MOBILE${uid}-G-100001`);
        check(name+' 本人会員証に氏名表示',await page.locator('[data-card-field=name]').innerText()===`架空姓 会員${uid}`);
        await page.screenshot({path:`/artifacts/${name}-card.png`,fullPage:true});
        const layout=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,touch:navigator.maxTouchPoints,touchEvents:'ontouchstart' in window}));
        browsers.at(-1).layout=layout;
        currentCase=name+' viewport '+JSON.stringify(layout);assert.ok(layout.scroll<=layout.width+1 && (layout.touch>0 || layout.touchEvents),currentCase);
        check(name+' スマートフォン幅で横はみ出しなし',true);
        const qrPng=PNG.sync.read(await page.locator('#card-qr').screenshot());const decoded=jsQR(new Uint8ClampedArray(qrPng.data),qrPng.width,qrPng.height);check(name+' ブラウザーが描画したQRを復号',decoded?.data===card.qr_url);
        await page.screenshot({path:`/artifacts/${name}-card.png`,fullPage:true});
        await page.setViewportSize({width:844,height:390});check(name+' 横画面でも横はみ出しなし',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.setViewportSize(devices[device].viewport);
        const publicPage=await anon.newPage(),requests=[];publicPage.on('request',r=>requests.push({url:r.url(),method:r.method()}));await publicPage.goto(card.qr_url);await publicPage.waitForFunction(()=>document.getElementById('verification-status').dataset.active==='true');
        check(name+' 匿名照会が有効・Cookieなし',(await anon.cookies()).length===0 && !publicPage.url().includes('#'));
        check(name+' 公開QRは氏名・番号だけ表示',await publicPage.locator('#verification-name').innerText()===card.name && await publicPage.locator('#verification-number').innerText()===card.member_number);
        check(name+' トークンをリクエストURLへ載せない',requests.every(r=>!r.url.includes('#') && !r.url.includes('token=')));
        await publicPage.screenshot({path:`/artifacts/${name}-valid.png`,fullPage:true});
        // 保険は本人の意思受付→対象権限の確認用ダウンロードまで。加入済みにはしない。
        await page.goto('https://web/manage/insurance.php');await page.getByLabel('個人会員登録',{exact:true}).selectOption(String(membership.id));
        await page.getByRole('checkbox',{name:/スポーツ安全保険への加入を希望/}).check();
        await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'加入意思を記録する',exact:true}).tap()]);
        check(name+' 保険意思をタッチ操作で受付',(await page.getByRole('status').innerText()).includes('加入意思を記録しました'));
        check(name+' 保険画面の横はみ出しなし',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
        await page.screenshot({path:`/artifacts/${name}-insurance.png`,fullPage:true});
        const intents=await (await owner.request.get('https://web/manage/insurance.php?format=json')).json();const intent=intents.requests.find(r=>Number(r.membership_id)===membership.id);
        check(name+' 保険料確認待ちを表示',(await page.locator('body').innerText()).includes('加入可否・保険料確認待ち') && !!intent);
        await finance.goto('https://web/manage/insurance.php?club_year_id=900');await finance.locator(`input[name="request_ids[]"][value="${intent.id}"]`).check();
        const downloadEvent=finance.waitForEvent('download');await finance.getByRole('button',{name:'指定Excel形式で確認用出力',exact:true}).tap();const download=await downloadEvent;
        await download.saveAs(`/artifacts/${name}-insurance.xlsx`);
        check(name+' 事務局が実ブラウザーから指定Excelを取得',!await download.failure() && fs.readFileSync(`/artifacts/${name}-insurance.xlsx`).subarray(0,2).toString()==='PK');
        const bankBytes=Buffer.from(`取扱日,受入金額,詳細２\r\n2026/08/25,1234,銀行ブラウザー${uid}\r\n`,'utf8');
        async function uploadBank(){await finance.goto('https://web/manage/bank.php');await finance.getByLabel('実口座の取込元',{exact:true}).selectOption('1');await finance.getByLabel('文字コード',{exact:true}).selectOption('UTF-8');await finance.getByLabel('ゆうちょCSV',{exact:true}).setInputFiles({name:'synthetic-yucho.csv',mimeType:'text/csv',buffer:bankBytes});await Promise.all([finance.waitForNavigation(),finance.getByRole('button',{name:'CSVを取込む',exact:true}).tap()]);}
        await uploadBank();check(name+' 銀行CSVを実ファイルとしてアップロード',(await finance.getByRole('status').innerText()).includes('処理を記録しました'));
        const bankList=await (await office.request.get('https://web/manage/bank.php?format=json&source=1')).json();const receipt=bankList.rows.find(r=>r.payer_name===`銀行ブラウザー${uid}`);
        check(name+' CSVアップロードだけでは未確定',receipt?.status==='review' && receipt.payment_id===null);
        await uploadBank();const repeatedBank=await (await office.request.get('https://web/manage/bank.php?format=json&source=1')).json();check(name+' 同一ファイル再アップロードも同一明細',repeatedBank.rows.filter(r=>r.payer_name===`銀行ブラウザー${uid}`).length===1 && repeatedBank.rows.find(r=>r.id===receipt.id).payment_id===null);
        await finance.goto('https://web/manage/bank.php?row='+receipt.id);check(name+' 銀行照合画面の横はみ出しなし',await finance.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await finance.screenshot({path:`/artifacts/${name}-bank.png`,fullPage:true});
        await finance.goto('https://web/manage/bank-settings.php?new=source');await finance.getByLabel('実口座コード',{exact:true}).fill(`mobile-master-${uid}`);await finance.getByLabel('取込元の表示名',{exact:true}).fill(`架空画面実口座${uid}`);await finance.getByLabel('変更理由',{exact:true}).fill('架空取込元の画面登録');await finance.getByRole('checkbox',{name:/既存取込元と重複しない/}).check();await Promise.all([finance.waitForNavigation(),finance.getByRole('button',{name:'取込元を保存',exact:true}).tap()]);
        check(name+' 実口座取込元を画面登録',(await finance.getByRole('status').innerText()).includes('設定を保存しました'));
        const masterList=await (await office.request.get('https://web/manage/bank-settings.php?format=json')).json();const masterSource=masterList.sources.find(s=>s.code===`mobile-master-${uid}`);
        await finance.goto('https://web/manage/bank-settings.php');await finance.getByLabel('用途',{exact:true}).selectOption('gymkhana_fund');await finance.getByLabel('口座の表示名',{exact:true}).fill(`架空画面口座${uid}`);await finance.getByLabel('口座案内',{exact:true}).fill(`架空銀行 支店${uid} 普通0000000 架空名義`);await finance.getByLabel('ペイメントコアURL',{exact:true}).fill(`https://example.invalid/mobile-core-${uid}`);await finance.getByLabel('CSV取込元',{exact:true}).selectOption(String(masterSource.id));await finance.getByRole('checkbox',{name:/選択した取込元と同じ実口座/}).check();await finance.getByLabel('変更理由',{exact:true}).fill('架空口座の画面登録');await Promise.all([finance.waitForNavigation(),finance.getByRole('button',{name:'口座設定を保存',exact:true}).tap()]);
        check(name+' 口座URLと対応付けを画面登録',(await finance.getByRole('status').innerText()).includes('設定を保存しました'));
        await Promise.all([finance.waitForNavigation(),finance.getByRole('link',{name:'保存した設定を確認',exact:true}).tap()]);await finance.getByLabel('ペイメントコアURL',{exact:true}).fill(`https://example.invalid/mobile-revised-${uid}`);await finance.getByLabel('変更理由',{exact:true}).fill('架空決済URLを画面更新');await Promise.all([finance.waitForNavigation(),finance.getByRole('button',{name:'口座設定を保存',exact:true}).tap()]);
        await Promise.all([finance.waitForNavigation(),finance.locator('a[href^="?history="]').first().tap()]);const historyText=await finance.locator('main').innerText();check(name+' 画面の変更履歴で理由と前後URLを確認',historyText.includes('架空決済URLを画面更新') && historyText.includes(`mobile-core-${uid}`) && historyText.includes(`mobile-revised-${uid}`));
        check(name+' 口座変更履歴がスマートフォン幅に収まる',await finance.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await finance.screenshot({path:`/artifacts/${name}-bank-settings.png`,fullPage:true});
        await finance.goto('https://web/manage/profile-correct.php?user='+uid);await finance.getByLabel('姓',{exact:true}).fill('訂正姓<架空>');await finance.getByLabel('生年月日',{exact:true}).fill('1991-02-03');await finance.getByLabel('訂正理由',{exact:true}).fill('架空の事務局訂正テスト');await Promise.all([finance.waitForNavigation(),finance.getByRole('button',{name:'訂正を保存する',exact:true}).tap()]);check(name+' 事務局が氏名・生年月日を画面訂正',(await finance.getByRole('status').innerText()).includes('訂正を保存しました'));await publicState(publicPage,true);check(name+' 訂正氏名が旧QRへ反映',await publicPage.locator('#verification-name').innerText()===`訂正姓<架空> 会員${uid}`);check(name+' 訂正画面の横はみ出しなし',await finance.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await finance.screenshot({path:`/artifacts/${name}-profile-correct.png`,fullPage:true});
        await page.goto('https://web/manage/licenses.php?id='+identifier.id);await page.getByLabel('有効期限',{exact:true}).fill('2000-01-01');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);await publicState(publicPage,false);
        check(name+' 期限更新を旧QRへ反映',!(await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json()).available);
        await page.goto('https://web/manage/licenses.php?id='+identifier.id);await page.getByLabel('有効期限',{exact:true}).fill('2099-12-31');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);await publicState(publicPage,true);
        const renewed=await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json();check(name+' 同番号の期限更新で番号・QRを維持',renewed.member_number===card.member_number && renewed.qr_url===card.qr_url);
        await anon.setOffline(true);await publicPage.getByRole('button',{name:'最新の資格を照会する'}).tap();await publicPage.waitForFunction(()=>document.getElementById('verification-status').textContent.includes('照会できません'));
        check(name+' 通信障害時は有効表示を消す',await publicPage.locator('#verification-status').getAttribute('data-active')===null && await publicPage.locator('#verification-name').textContent()==='' && await publicPage.locator('#verification-number').textContent()==='');await anon.setOffline(false);await publicState(publicPage,true);
        await page.goto('https://web/manage/card.php?id='+membership.id);await page.locator('#card-qr svg').waitFor();await owner.setOffline(true);
        await page.waitForFunction(()=>document.getElementById('digital-card').hidden,{},{timeout:25000});check(name+' 定期再照会の通信障害で本人証を消去',(await page.locator('[data-card-field=member_number]').innerText())==='' && (await page.locator('[data-card-field=name]').innerText())==='');await owner.setOffline(false);
        await page.goto('https://web/manage/licenses.php?id='+identifier.id);await page.getByLabel('JAFライセンス番号').fill(`CHANGED${uid}`);await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);await publicState(publicPage,false);
        const changed=await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json();check(name+' 番号変更で旧QRを失効・再発行保留',!changed.available && !changed.can_issue);
        await page.goto('https://web/manage/card.php?id='+membership.id);await page.screenshot({path:`/artifacts/${name}-changed.png`,fullPage:true});
        const body=await publicPage.locator('body').innerText();check(name+' 無効な公開画面は氏名・番号を消去',!body.includes(`MOBILE${uid}`) && !body.includes(`CHANGED${uid}`) && !body.includes('架空姓'));
        await owner.close();await office.close();await anon.close();
    }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium-mobile','Pixel 7',21);await run(webkit,'webkit-mobile','iPhone 13',22);const artifacts=fs.readdirSync('/artifacts').filter(n=>n.endsWith('.png'));process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,artifacts}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',case:currentCase,error:String(e).replace(/#[a-f0-9]{64}/g,'#[redacted]')}));process.exit(1);});
