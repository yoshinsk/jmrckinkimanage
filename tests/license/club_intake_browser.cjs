// Path: tests/license/club_intake_browser.cjs
// 機能: 共通整形・ローマ数字保持・更新候補・申請・差し戻しを実ブラウザーで確認。本番は認証以外のPOSTを遮断する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const live=process.env.CLUB_INTAKE_LIVE==='1',origin=live?'https://gymkinki.com':'https://web',base='/manage/club-register.php';
const accounts=live?JSON.parse(fs.readFileSync(0,'utf8')):[{role:'office',email:'u7@example.invalid',password:'lab-password-only'},{role:'entrant',email:'u1@example.invalid',password:'lab-password-only'}];
const cases=[],browsers=[];let businessPosts=0;
function check(name,value){assert.ok(value,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const browser=await engine.launch({headless:true});
 try{
  browsers.push({engine:name,version:browser.version(),device});
  for(const js of [true,false])for(const a of accounts){
   const c=await browser.newContext({...devices[device],locale:'ja-JP',timezoneId:'Asia/Tokyo',javaScriptEnabled:js,ignoreHTTPSErrors:!live});let blocked=0;
   await c.route('**/*',r=>{
    const q=r.request(),url=new URL(q.url());
    if(!['GET','HEAD'].includes(q.method())&&!(q.method()==='POST'&&url.origin===origin&&['/entry/login.php','/manage/logout.php',...(!live?[base]:[])].includes(url.pathname))){blocked++;return r.abort();}
    if(q.method()==='POST'&&url.pathname===base){businessPosts++;if(live){blocked++;return r.abort();}}
    if(q.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'});
    return url.origin===origin?r.continue():r.abort();
   });
   const p=await c.newPage(),prefix=`${name} ${a.role} JS${js?'有':'無'}`;
   check(prefix+' 未認証拒否',(await c.request.get(origin+base)).status()===401);
   await p.goto(origin+'/manage/');await p.getByLabel('メールアドレス').fill(a.email);await p.getByLabel('パスワード').fill(a.password);
   await Promise.all([p.waitForURL(origin+'/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
   await p.locator(`a[href="${base}"]`).tap();await p.waitForURL(origin+base);
   check(prefix+' 事前権限なしで入力画面',(await p.locator('h1').innerText())==='クラブ登録の申請');
   check(prefix+' 横にはみ出さない',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   check(prefix+' 入力欄は16px以上',await p.locator('#club-name').count()===0&&await p.locator('#club-club_name').evaluate(e=>parseFloat(getComputedStyle(e).fontSize)>=16));
   check(prefix+' 代表者と連絡先の順・見出し',await p.locator('#club-intake-form > fieldset > legend').allTextContents().then(xs=>JSON.stringify(xs)===JSON.stringify(['クラブの情報','代表者の情報','連絡先の情報'])));
   check(prefix+' 他地区の所属先ラベル',await p.getByLabel('他地区の場合の所属支部',{exact:true}).count()===1);
   check(prefix+' 既存候補を表示',await p.locator('#club-candidate_ref option').count()>1);
   check(prefix+' 団体区分を表示し2027年の7は選択不可',await p.locator('#club-jaf_kind option').evaluateAll(es=>['official_org','special_org','associate_org'].every(v=>es.some(e=>e.value===v))&&es.find(e=>e.value==='special_org').disabled===(es[0].ownerDocument.querySelector('#club-year').value==='2027')));
   check(prefix+' 年度全体終了は事務局だけ',await p.locator('form[aria-labelledby="review-close-title"]').count()===(a.role==='office'?1:0));
   if(a.role==='entrant')check(prefix+' 事務局一覧は拒否',(await c.request.get(origin+base+'?all=1')).status()===403);
   // GETだけの一覧絞込はJavaScriptなしでも使え、選択しただけでは再読込しない。
   await p.goto(origin+base+(a.role==='office'?'?all=1':''));
   const listUrl=p.url();await p.getByLabel('一覧の年度',{exact:true}).selectOption('2027');await p.getByLabel('一覧の確認状況',{exact:true}).selectOption('returned');
   check(prefix+' 一覧の選択だけでは送信しない',p.url()===listUrl);
   await Promise.all([p.waitForURL(u=>u.searchParams.get('list_state')==='returned'),p.getByRole('button',{name:'一覧を絞り込む',exact:true}).tap()]);
   check(prefix+' 一覧の年度を保持',await p.getByLabel('一覧の年度',{exact:true}).inputValue()==='2027');
   check(prefix+' 一覧の状況を保持',await p.getByLabel('一覧の確認状況',{exact:true}).inputValue()==='returned');
   check(prefix+' 事務局と本人の一覧を維持',p.url().includes('all=1')===(a.role==='office'));
   check(prefix+' 一覧絞込がスマートフォン幅に収まる',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   check(prefix+' 一覧の不正条件を拒否',(await c.request.get(origin+base+'?list_state=unknown')).status()===422);
   await p.goto(origin+base);
   if(live){check(prefix+' 本番受付停止',await p.getByRole('button',{name:'申請を提出する',exact:true}).isDisabled());}
   if(js){
    await p.locator('#club-club_name').fill('Ａｚ０９　ⅠⅢ');check(prefix+' 英数字空白だけ整形',await p.locator('#club-club_name').inputValue()==='Az09 ⅠⅢ');
    await p.locator('#club-bank_details').fill('Ａ１２　Ⅲ');check(prefix+' 複数行入力も整形',await p.locator('#club-bank_details').inputValue()==='A12 Ⅲ');
    const ime=await p.locator('#club-club_name').evaluate(e=>{e.value='Ａ　Ⅲ';e.dispatchEvent(new InputEvent('input',{bubbles:true,isComposing:true}));const during=e.value;e.dispatchEvent(new CompositionEvent('compositionend',{bubbles:true}));return [during,e.value];});
    check(prefix+' 日本語変換中は整形を待つ',ime[0]==='Ａ　Ⅲ'&&ime[1]==='A Ⅲ');
    if(!live){
     await p.locator('#candidate-query').fill('ＡＢＣ　Ⅲ');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'更新候補を探す',exact:true}).tap()]);
     const value=await p.locator('#club-candidate_ref option').evaluateAll(es=>es.find(e=>e.textContent.includes('99991'))?.value);assert.ok(value);
     await p.locator('#club-candidate_ref').selectOption(value);check(prefix+' 候補選択で更新を補助',await p.locator('#club-registration_kind').inputValue()==='renewal'&&(await p.locator('#club-club_name').inputValue()).includes('ABC Ⅲ'));
     check(prefix+' 5桁候補は番号だけ補助',await p.locator('#club-jaf_number').inputValue()==='99991'&&await p.locator('#club-jaf_kind').inputValue()==='');
     const known=await p.locator('#club-candidate_ref option').evaluateAll(es=>es.find(e=>e.textContent.includes('199975'))?.value);
     await p.locator('#club-candidate_ref').selectOption(known);check(prefix+' 先頭区分で公認を補助',await p.locator('#club-jaf_number').inputValue()==='99975'&&await p.locator('#club-jaf_kind').inputValue()==='official');
     const unknown=await p.locator('#club-candidate_ref option').evaluateAll(es=>es.find(e=>e.textContent.includes('699974'))?.value);
     await p.locator('#club-candidate_ref').selectOption(unknown);check(prefix+' 区分6へ切替で前候補の格式を残さない',await p.locator('#club-jaf_number').inputValue()==='99974'&&await p.locator('#club-jaf_kind').inputValue()===''&&(await p.locator('#candidate-help').innerText()).includes('確認'));

    }
   }
   if(!live&&a.role==='entrant'){
    await p.goto(origin+base);await p.locator('#club-year').selectOption('2027');await p.locator('#club-registration_kind').selectOption('new');
    await p.locator('#club-jaf_number').fill(js?'９９９８１':'99982');await p.locator('#club-same_contact').selectOption('1');
    for(const e of await p.locator('#club-intake-form input[required]').all()){
     const key=await e.getAttribute('name');if(key==='jaf_number')continue;
     await e.fill(key.endsWith('email')?'club@example.invalid':key.includes('postcode')?'0000000':key.includes('phone')||key.includes('mobile')?'0000000000':'架空クラブⅢ');
    }
    await p.locator('#club-region').selectOption('27');await p.locator('#club-jaf_kind').selectOption('organization');await p.locator('#club-membership_kind').selectOption('regular');await p.locator('#club-same_contact').selectOption('1');
    // 他試験の未来年度もあるため、選んだ年度の料金確認でサーバーの受付表示を更新する。
    await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'会費だけ確認する',exact:true}).tap()]);
    check(prefix+' 2027年度は特別団体を選択不可',await p.locator('#club-jaf_kind option[value=special_org]').isDisabled());
    assert.deepEqual(await p.locator('#club-intake-form :invalid').evaluateAll(es=>es.map(e=>({name:e.name,message:e.validationMessage}))),[],'正式入力のブラウザー制約');
    await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'申請を提出する',exact:true}).tap()]);
    check(prefix+' 正式入力の保存と再表示',await p.locator('#club-jaf_number').inputValue()===(js?'99981':'99982'));
    const r=await (await c.request.get(p.url()+'&format=json')).json();check(prefix+' 兼任の住所をサーバーで転記',r.record.data.contact_address===r.record.data.rep_address&&r.record.club_year_id===null);
   }
   await p.screenshot({path:`/artifacts/club-${name}-${a.role}-${js?'js':'nojs'}.png`,fullPage:true});
   check(prefix+' 許可外POSTゼロ',blocked===0);await c.close();
  }
 }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');accounts.forEach(a=>a.password='');assert.ok(!live||businessPosts===0);process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),cases,browsers,live,business_posts:businessPosts}));})().catch(e=>{accounts.forEach(a=>a.password='');process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:e.name,message:live?undefined:e.message}));process.exit(1);});
