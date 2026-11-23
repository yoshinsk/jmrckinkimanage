// Path: tests/license/club_operations_browser.cjs
// 機能: クラブ会費・承認と既存情報再利用を実ブラウザーで検証。本番は認証以外の業務POSTを遮断する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const live=process.env.CLUB_OPERATIONS_LIVE==='1',origin=live?'https://gymkinki.com':'https://web',base='/manage/club-finance.php';
const hold=live||process.env.CLUB_OPERATIONS_HOLD==='1';
const accounts=live?JSON.parse(fs.readFileSync(0,'utf8')):[{role:'office',id:7},{role:'club',id:2},{role:'entrant',id:90071},{role:'finance',id:13}].map(a=>({...a,email:`u${a.id}@example.invalid`,password:'lab-password-only'}));
const cases=[],browsers=[];let businessPosts=0;
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const b=await engine.launch({headless:true});
 try{
  browsers.push({engine:name,version:b.version(),device});
  for(const a of accounts){
   const c=await b.newContext({...devices[device],locale:'ja-JP',timezoneId:'Asia/Tokyo',javaScriptEnabled:false,ignoreHTTPSErrors:!live});let blocked=0;
   await c.route('**/*',r=>{
    const q=r.request(),u=new URL(q.url());
    if(!['GET','HEAD'].includes(q.method())&&!(u.origin===origin&&q.method()==='POST'&&['/entry/login.php','/manage/logout.php',...(!live?[base]:[])].includes(u.pathname))){blocked++;return r.abort();}
    if(q.method()==='POST'&&u.pathname===base)businessPosts++;
    if(q.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'});
    return u.origin===origin?r.continue():r.abort();
   });
   const p=await c.newPage(),prefix=name+' '+a.role;
   check(prefix+' 未認証拒否',(await c.request.get(origin+base)).status()===401);
   await p.goto(origin+'/manage/');await p.getByLabel('メールアドレス').fill(a.email);await p.getByLabel('パスワード').fill(a.password);
   await Promise.all([p.waitForURL(origin+'/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
   await p.locator(`a[href="${base}"]`).tap();await p.waitForURL(origin+base);
   check(prefix+' マイページからクラブ会計',await p.locator('h1').innerText()==='クラブ会費・承認');
   check(prefix+' 横にはみ出さない',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   check(prefix+' 絞込みの全年度が利用可能',(await c.request.get(origin+base+'?year=&state=all&format=json')).status()===200);
   if(hold)check(prefix+' 受付停止',(await p.locator('main').innerText()).includes('受付と書込みは停止'));
   if(!hold&&a.role==='office'){
    const list=await (await c.request.get(origin+base+'?format=json')).json();const item=list.registrations.find(r=>r.intake.name_short==='架空C5Mobile-'+name);assert.ok(item);
    const id=item.intake.id;await p.goto(origin+base+'?intake='+id);
    check(prefix+' 請求時の振込先を表示',(await p.locator('[data-guide=details]').innerText()).includes('架空ゆうちょ銀行'));
    check(prefix+' スマートフォン入力欄16px以上',await p.locator('#received-on').evaluate(e=>parseFloat(getComputedStyle(e).fontSize)>=16));
    const date=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Tokyo'}).format(new Date());
    await p.locator('#received-on').fill(date);await p.locator('#confirm-amount').fill('30000');await p.locator('#reference').fill('MOBILE-C5-'+name);
    await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'銀行明細と照合して記録する',exact:true}).tap()]);
    check(prefix+' 入金後も承認前は未完了',(await p.locator('[data-state=ready]').innerText())==='条件未完了');
    await p.locator('input[name=checked]').check();await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'年度登録を承認する',exact:true}).tap()]);
    check(prefix+' 年度開始前の登録済み表示',(await p.locator('[data-state=ready]').innerText())==='年度登録済み'&&(await p.locator('[data-state=active]').innerText())==='現在は無効');
    let r=(await (await c.request.get(origin+base+'?intake='+id+'&format=json')).json()).record;const al=r.allocations[0];
    await p.locator('#allocation').selectOption(String(al.id));await p.locator('#reason').fill('架空の銀行照合訂正');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'元の充当を取り消す',exact:true}).tap()]);
    check(prefix+' 訂正直後に登録条件を再判定',(await p.locator('[data-state=ready]').innerText())==='条件未完了');
    await p.locator('#payment').selectOption(String(al.payment_id));await p.locator('#reallocate-amount').fill('30000');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'同じ請求へ再充当する',exact:true}).tap()]);
    check(prefix+' 再充当で先行登録済みを回復',(await p.locator('[data-state=ready]').innerText())==='年度登録済み');
    await p.screenshot({path:`/artifacts/club-operations-${name}-detail.png`,fullPage:true});
   }
   if(!live&&a.role==='club'){
    await p.goto(origin+'/manage/club-register.php?reuse=entry');check(prefix+' 既存クラブの入力再利用',await p.locator('#club-club_name').inputValue()==='架空主催');
    check(prefix+' 旧銀行情報も再利用候補',(await p.locator('#club-bank_details').inputValue()).includes('架空銀行'));
   }
   await p.goto(origin+'/manage/memberships.php?reuse=entry');
   check(prefix+' 個人情報の再利用画面',await p.locator('h1').innerText()==='個人会員の申請');
   if(!live&&a.role==='entrant')check(prefix+' 訂正済み氏名を参照',(await p.locator('main').innerText()).includes('訂正後姓'));
   await p.goto(origin+'/manage/licenses.php?reuse=entry');
   check(prefix+' 運転免許期限を発行日へ流用しない',await p.locator('input[name=issued_on]').inputValue()===''&&await p.locator('input[name=expires_on]').count()===0);
   check(prefix+' 競技の指定9種を複数選択',await p.locator('input[name="license_types[]"]').count()===9);
   check(prefix+' JAF登録名は自動補完せず単独入力欄',await p.locator('#jaf-name').inputValue()===''&&await p.getByLabel('JAFライセンス登録名（照会用・任意）',{exact:true}).count()===1);
   await Promise.all([p.waitForURL(u=>u.searchParams.get('kind')==='jaf_official'),p.getByRole('link',{name:'審判員の登録',exact:true}).tap()]);
   check(prefix+' JSなしで審判員の指定15種を表示',await p.locator('input[name="license_types[]"]').count()===15);
   check(prefix+' 発行日は必須で期限から補完しない',await p.locator('input[name=issued_on]').inputValue()===''&&await p.locator('input[name=issued_on]').getAttribute('required')!==null);
   if(live)check(prefix+' ライセンス保存は保留',await p.getByRole('button',{name:'保存する',exact:true}).isEnabled()===false);
   if(a.role==='office'){
    await p.goto(origin+'/manage/license-export.php');
    check(prefix+' 照会用CSVの確認画面',await p.locator('h1').innerText()==='JAF照会用出力の確認');
    if(hold)check(prefix+' 照会CSVも保留',!await p.getByRole('button',{name:'照会用CSVをダウンロード',exact:true}).isEnabled());
   }else if(a.role==='entrant')check(prefix+' 本人に全体CSVの権限を与えない',(await c.request.get(origin+'/manage/license-export.php')).status()===403);
   // 本人のJAF番号・住所が出る画面はスクリーンショットに残さない。
   await p.goto(origin+base);await p.screenshot({path:`/artifacts/club-operations-${name}-${a.role}.png`,fullPage:true});
   check(prefix+' 許可外POSTゼロ',blocked===0);
   await Promise.all([p.waitForURL('**/login.php**'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);
   check(prefix+' 共通ログアウト',(await c.request.get(origin+base)).status()===401);await c.close();
  }
 }finally{await b.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');accounts.forEach(a=>a.password='');assert.ok(!live||businessPosts===0);process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),cases,browsers,live,business_posts:businessPosts}));})().catch(e=>{accounts.forEach(a=>a.password='');process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:e.name,message:live?undefined:e.message}));process.exit(1);});
