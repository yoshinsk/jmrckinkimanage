// Path: tests/license/accounting_browser.cjs
// 機能: 会計確認画面のスマートフォン表示と予算・CSV操作を検証。本番はログイン以外の書込を遮断する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const live=process.env.ACCOUNTING_LIVE==='1',origin=live?'https://gymkinki.com':'https://web',base='/manage/accounting-review.php';
const accounts=live?JSON.parse(fs.readFileSync(0,'utf8')):[{role:'office',email:'u7@example.invalid',password:'lab-password-only'},{role:'entrant',email:'u1@example.invalid',password:'lab-password-only'}];
assert.deepEqual(accounts.map(a=>a.role).sort(),['entrant','office']);
const cases=[],browsers=[];
function check(name,value){assert.ok(value,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const browser=await engine.launch({headless:true});
 try{
  browsers.push({engine:name,version:browser.version(),device});
  for(const a of accounts){
   const c=await browser.newContext({...devices[device],locale:'ja-JP',timezoneId:'Asia/Tokyo',javaScriptEnabled:false,ignoreHTTPSErrors:!live});let blocked=0;
   await c.route('**/*',r=>{
    const q=r.request(),u=new URL(q.url()),allowed=['/entry/login.php','/manage/logout.php',...(!live?[base]:[])];
    if(!['GET','HEAD'].includes(q.method())&&!(q.method()==='POST'&&u.origin===origin&&allowed.includes(u.pathname))){blocked++;return r.abort();}
    if(q.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'});
    return u.origin===origin?r.continue():r.abort();
   });
   const p=await c.newPage(),prefix=name+' '+a.role;
   check(prefix+' 未認証拒否',(await c.request.get(origin+base)).status()===401);
   await p.goto(origin+'/manage/');await p.getByLabel('メールアドレス').fill(a.email);await p.getByLabel('パスワード').fill(a.password);
   await Promise.all([p.waitForURL(origin+'/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
   check(prefix+' 共通ログイン',await p.locator('.account-email').innerText()===a.email);
   check(prefix+' 導線は事務局権限だけ',(await p.locator(`a[href="${base}"]`).count()===1)===(a.role==='office'));
   if(a.role==='office'){
    for(const section of ['budget','income','balance','csv','club','notice']){
     check(prefix+' 表示 '+section,(await p.goto(origin+base+'?section='+section)).status()===200);
     check(prefix+' 確認専用の説明 '+section,(await p.locator('main').innerText()).includes('事務局専用の検証画面'));
     for(const width of [320,390,844,1280]){await p.setViewportSize({width,height:844});check(prefix+' 幅'+width+' '+section,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));}
     await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');
     check(prefix+' 文字200% '+section,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
     if(['budget','csv','club'].includes(section)){await p.setViewportSize(devices[device].viewport);await p.screenshot({path:`/artifacts/${name}-accounting-${section}.png`,fullPage:true});}
    }
    const response=await c.request.get(origin+base+'?format=json'),s=await response.json(),headers=response.headers();
    check(prefix+' 実業務書込・送信なし',!s.business_writes&&!s.mail_sent);
    check(prefix+' キャッシュ・検索・埋込制限',headers['cache-control'].includes('no-store')&&headers['x-robots-tag'].includes('noindex')&&headers['content-security-policy'].includes("frame-ancestors 'none'"));
    for(const type of ['budget','income','audit','balance','yucho','payment_core']){const r=await c.request.get(origin+base+'?download='+type);check(prefix+' CSV取得 '+type,r.status()===200&&r.headers()['content-disposition'].includes('attachment'));}
    if(!live){
     await p.goto(origin+base);await p.locator('#budget-club_fee').fill('75000');await Promise.all([p.waitForURL('**/accounting-review.php?section=budget&saved=1'),p.getByRole('button',{name:'確認用予算に反映する',exact:true}).tap()]);
     check(prefix+' 予算反映',(await (await c.request.get(origin+base+'?format=json')).json()).budget.club_fee===75000);
     const budget=await (await c.request.get(origin+base+'?download=budget')).body();await p.locator('#budget-file').setInputFiles({name:'budget.csv',mimeType:'text/csv',buffer:budget});
     await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'予算CSVを読み込む',exact:true}).tap()]);check(prefix+' ファイル予算再読込',await p.locator('[role=status]').count()===1);
     await p.goto(origin+base+'?section=csv');const sample=await (await c.request.get(origin+base+'?download=payment_core')).body();await p.locator('#csv-kind').selectOption('payment_core');await p.locator('#csv-file').setInputFiles({name:'fictional.csv',mimeType:'text/csv',buffer:sample});
     await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'CSVの内容を確認する',exact:true}).tap()]);check(prefix+' ファイル解析と年保留',(await p.locator('main').innerText()).includes('年または日付を確認してください'));
    }
   }else{for(const q of ['','?format=json','?download=budget','?section=club'])check(prefix+' 権限拒否 '+q,(await c.request.get(origin+base+q)).status()===403);}
   check(prefix+' 許可外POSTゼロ',blocked===0);
   await p.goto(origin+'/manage/');await Promise.all([p.waitForURL('**/login.php**'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);check(prefix+' 共通ログアウト',(await c.request.get(origin+base)).status()===401);await c.close();
  }
 }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');accounts.forEach(a=>a.password='');process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),browsers,cases,live,business_posts:0}));})().catch(e=>{accounts.forEach(a=>a.password='');process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:e.name,message:live?undefined:e.message}));process.exit(1);});
