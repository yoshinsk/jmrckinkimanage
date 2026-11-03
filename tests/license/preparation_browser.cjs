// Path: tests/license/preparation_browser.cjs
// 機能: 隔離/本番の運用準備と受付保留をChromium/WebKitで確認。本番の業務POSTを遮断し、資格情報は保存しない。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const live=process.env.PREPARATION_LIVE==='1',origin=live?'https://gymkinki.com':'https://web';
const accounts=live?JSON.parse(fs.readFileSync(0,'utf8')):[{role:'office',email:'u7@example.invalid',password:'lab-password-only'},{role:'entrant',email:'u1@example.invalid',password:'lab-password-only'}];
assert.deepEqual(accounts.map(a=>a.role).sort(),['entrant','office']);
const cases=[],browsers=[];
function check(name,value){assert.ok(value,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const browser=await engine.launch({headless:true});
 try{
  browsers.push({engine:name,version:browser.version(),device});
  for(const account of accounts){
   const c=await browser.newContext({...devices[device],locale:'ja-JP',timezoneId:'Asia/Tokyo',javaScriptEnabled:false,ignoreHTTPSErrors:!live});
   let businessPosts=0;
   await c.route('**/*',r=>{
    const q=r.request(),url=new URL(q.url());
    if(!['GET','HEAD'].includes(q.method()) && !(q.method()==='POST' && url.origin===origin && ['/entry/login.php','/manage/logout.php'].includes(url.pathname))){businessPosts++;return r.abort();}
    if(q.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'});
    return url.origin===origin?r.continue():r.abort();
   });
   const p=await c.newPage(),prefix=name+' '+account.role;
   for(const path of ['/manage/preparation.php','/manage/staff-roles.php'])check(prefix+' 未認証 '+path,(await c.request.get(origin+path)).status()===401);
   await p.goto(origin+'/manage/');await p.getByLabel('メールアドレス').fill(account.email);await p.getByLabel('パスワード').fill(account.password);
   await Promise.all([p.waitForURL(origin+'/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
   check(prefix+' 既存ログイン',await p.locator('.account-email').innerText()===account.email);
   check(prefix+' 新導線は事務局だけ',(await p.locator('a[href="/manage/preparation.php"]').count()===1)===(account.role==='office'));
   check(prefix+' 会員受付と請求は保留',await p.locator('a[href="/manage/memberships.php"],a[href="/manage/billing.php"]').count()===0);
   if(account.role==='office'){
    const paths=['/manage/preparation.php',...['club','event','privacy','membership'].map(x=>'/manage/preparation.php?section='+x),'/manage/staff-roles.php'];
    if(!live)paths.push('/manage/preparation.php?section=privacy&id=3','/manage/preparation.php?section=club&id=1&category=organization&membership=supporting&registration=new');
    for(const path of paths){
     check(prefix+' 閲覧 '+path,(await p.goto(origin+path)).status()===200);
     check(prefix+' 業務POSTフォームなし '+path,await p.locator('main form').evaluateAll(fs=>fs.every(f=>f.method.toLowerCase()==='get')));
     for(const width of [320,390,844,1280]){
      await p.setViewportSize({width,height:844});
      check(prefix+' 幅'+width+' '+path,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
     }
     await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');
     check(prefix+' 文字200% '+path,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
    }
    await p.goto(origin+'/manage/staff-roles.php');check(prefix+' 役職受付を停止表示',(await p.locator('main').innerText()).includes('役職の申請受付は準備中'));
    const result=await c.request.get(origin+'/manage/preparation.php?format=json'),data=(await result.json()).data;
    check(prefix+' 開始判断と書込を無効化',data.opening_authorized===false && data.writes_enabled===false && !data.flags.staff_role_requests_enabled);
    const h=result.headers();check(prefix+' キャッシュ・検索・埋込の制限',h['cache-control'].includes('no-store')&&h['x-robots-tag'].includes('noindex')&&h['content-security-policy'].includes("frame-ancestors 'none'"));
    for(const section of ['overview','club','privacy']){await p.goto(origin+'/manage/preparation.php?section='+section);await p.setViewportSize(devices[device].viewport);await p.screenshot({path:`/artifacts/${name}-preparation-${section}.png`,fullPage:true});}
   }else{
    for(const path of ['/manage/preparation.php','/manage/preparation.php?section=privacy&id=7','/manage/staff-roles.php'])check(prefix+' 権限なし '+path,(await c.request.get(origin+path)).status()===403);
   }
   check(prefix+' 業務POSTゼロ',businessPosts===0);
   await p.goto(origin+'/manage/');await Promise.all([p.waitForURL('**/login.php**'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);
   check(prefix+' 共通ログアウト',(await c.request.get(origin+'/manage/preparation.php')).status()===401);await c.close();
  }
 }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');accounts.forEach(a=>a.password='');process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),browsers,cases,business_posts:0,live}));})().catch(e=>{accounts.forEach(a=>a.password='');process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:e.name,message:live?undefined:e.message}));process.exit(1);});
