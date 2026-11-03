// Path: tests/insurance/production_notice_browser.cjs
// 機能: 保険の表示修正後も本番の受付が閉じていることを既存2アカウントで確認する。資格情報は保存しない。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const accounts=JSON.parse(fs.readFileSync(0,'utf8')),cases=[],browsers=[];
const origin='https://gymkinki.com';let businessPosts=0;
assert.deepEqual(accounts.map(a=>a.role).sort(),['entrant','office']);
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
(async()=>{
  for(const [engine,name,device] of [[chromium,'chromium','Pixel 7'],[webkit,'webkit','iPhone 13']]){
    const browser=await engine.launch({headless:true});browsers.push({engine:name,version:browser.version(),device});
    try{
      for(const account of accounts){
        const context=await browser.newContext({...devices[device],locale:'ja-JP',javaScriptEnabled:false});
        await context.route('**/*',route=>{
          const request=route.request(),url=new URL(request.url());
          // ログインとログアウト以外の更新リクエストは送信前に止める。
          if(!['GET','HEAD'].includes(request.method()) && !(request.method()==='POST' && url.origin===origin && ['/entry/login.php','/manage/logout.php'].includes(url.pathname))){businessPosts++;return route.abort();}
          return url.origin===origin?route.continue():route.abort();
        });
        const prefix=name+' '+account.role,page=await context.newPage();
        try{
          check(prefix+' 未認証は401',(await context.request.get(origin+'/manage/insurance.php')).status()===401);
          await page.goto(origin+'/manage/');
          await page.getByLabel('メールアドレス').fill(account.email);await page.getByLabel('パスワード').fill(account.password);
          await Promise.all([page.waitForURL(origin+'/manage/'),page.getByRole('button',{name:'サインイン',exact:true}).tap()]);
          check(prefix+' 既存ログイン',await page.locator('.account-email').innerText()===account.email);
          const response=await context.request.get(origin+'/manage/insurance.php'),body=await response.text();
          check(prefix+' 保険受付は準備中のまま',response.status()===503 && body.includes('準備中'));
        }finally{
          // 失敗時もログイン済みの検証セッションを閉じる。業務の送信は行わない。
          const html=await (await context.request.get(origin+'/manage/')).text(),csrf=html.match(/name="_csrf" value="([a-f0-9]{64})"/);
          if(csrf && html.includes('account-email'))await context.request.post(origin+'/manage/logout.php',{form:{_csrf:csrf[1]},maxRedirects:0});
          check(prefix+' ログアウト',(await context.request.get(origin+'/manage/insurance.php')).status()===401);
          await context.close();
        }
      }
    }finally{await browser.close();}
  }
  assert.equal(businessPosts,0);accounts.forEach(a=>a.password='');
  process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),browsers,cases,business_posts:businessPosts}));
})().catch(error=>{accounts.forEach(a=>a.password='');process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:error.name}));process.exit(1);});
