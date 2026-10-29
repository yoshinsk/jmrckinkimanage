// Path: tests/license/mobile_browser.cjs
// 機能: 隔離HTTPS上の実Chromium/WebKitをスマートフォン設定で操作し、登録・期限更新・QR失効を検証。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),crypto=require('node:crypto'),fs=require('node:fs'),jsQR=require('jsqr'),{PNG}=require('pngjs');
const cases=[],browsers=[];let currentCase='setup';
function check(name,value){currentCase=name;assert.ok(value,name);cases.push({case:name,result:'PASS'});}
async function makeContext(browser,device){const c=await browser.newContext({...devices[device],ignoreHTTPSErrors:true,locale:'ja-JP',timezoneId:'Asia/Tokyo',serviceWorkers:'block'});await c.route('**/*',route=>new URL(route.request().url()).hostname==='web'?route.continue():route.abort());return c;}
async function login(context,id){const p=await context.newPage();await p.goto('https://web/entry/login.php?app=manage&return_key=dashboard');await p.getByLabel('メールアドレス').fill(`u${id}@example.invalid`);await p.getByLabel('パスワード').fill('lab-password-only');await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);return p;}
async function post(context,path,csrf,data){const r=await context.request.post('https://web'+path,{form:{...data,_csrf:csrf}});assert.ok(r.ok(),'API status '+r.status());return r.json();}
async function publicState(page,active){await page.getByRole('button',{name:'最新の資格を照会する'}).tap();await page.waitForFunction(v=>document.getElementById('verification-status').dataset.active===String(v),active);}
async function run(engine,name,device,uid){
    const browser=await engine.launch({headless:true});try{
        browsers.push({engine:name,version:browser.version(),device});
        const owner=await makeContext(browser,device),office=await makeContext(browser,device),anon=await makeContext(browser,device);
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
        check(name+' トークンをリクエストURLへ載せない',requests.every(r=>!r.url.includes('#') && !r.url.includes('token=')));
        await publicPage.screenshot({path:`/artifacts/${name}-valid.png`,fullPage:true});
        await page.goto('https://web/manage/licenses.php?id='+identifier.id);await page.getByLabel('有効期限',{exact:true}).fill('2000-01-01');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);await publicState(publicPage,false);
        check(name+' 期限更新を旧QRへ反映',!(await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json()).available);
        await page.goto('https://web/manage/licenses.php?id='+identifier.id);await page.getByLabel('有効期限',{exact:true}).fill('2099-12-31');await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);await publicState(publicPage,true);
        const renewed=await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json();check(name+' 同番号の期限更新で番号・QRを維持',renewed.member_number===card.member_number && renewed.qr_url===card.qr_url);
        await anon.setOffline(true);await publicPage.getByRole('button',{name:'最新の資格を照会する'}).tap();await publicPage.waitForFunction(()=>document.getElementById('verification-status').textContent.includes('照会できません'));
        check(name+' 通信障害時は有効表示を消す',await publicPage.locator('#verification-status').getAttribute('data-active')===null);await anon.setOffline(false);await publicState(publicPage,true);
        await page.goto('https://web/manage/card.php?id='+membership.id);await page.locator('#card-qr svg').waitFor();await owner.setOffline(true);
        await page.waitForFunction(()=>document.getElementById('digital-card').hidden,{},{timeout:25000});check(name+' 定期再照会の通信障害で本人証を消去',(await page.locator('[data-card-field=member_number]').innerText())==='' && (await page.locator('[data-card-field=name]').innerText())==='');await owner.setOffline(false);
        await page.goto('https://web/manage/licenses.php?id='+identifier.id);await page.getByLabel('JAFライセンス番号').fill(`CHANGED${uid}`);await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'保存する',exact:true}).tap()]);await publicState(publicPage,false);
        const changed=await (await owner.request.get('https://web/manage/card.php?format=json&id='+membership.id)).json();check(name+' 番号変更で旧QRを失効・再発行保留',!changed.available && !changed.can_issue);
        await page.goto('https://web/manage/card.php?id='+membership.id);await page.screenshot({path:`/artifacts/${name}-changed.png`,fullPage:true});
        const body=await publicPage.locator('body').innerText();check(name+' 公開画面は氏名・番号を表示しない',!body.includes(`MOBILE${uid}`) && !body.includes(`CHANGED${uid}`) && !body.includes('架空姓'));
        await owner.close();await office.close();await anon.close();
    }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium-mobile','Pixel 7',21);await run(webkit,'webkit-mobile','iPhone 13',22);const artifacts=fs.readdirSync('/artifacts').filter(n=>n.endsWith('.png'));process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,artifacts}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',case:currentCase,error:String(e).replace(/#[a-f0-9]{64}/g,'#[redacted]')}));process.exit(1);});
