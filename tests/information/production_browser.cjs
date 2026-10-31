// Path: tests/information/production_browser.cjs
// 機能: 本番試作を既存2アカウントで読み取り検証。業務POSTを遮断し、資格情報は標準入力だけで受け取る。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const accounts=JSON.parse(fs.readFileSync(0,'utf8')),cases=[],browsers=[];
const origin='https://gymkinki.com',base='/manage/information-preview.php';
assert.deepEqual(accounts.map(a=>a.role).sort(),['entrant','office']);
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}

async function run(engine,name,device){
  // 検証用に作るブラウザーだけを操作する。外部リンクを開かず、通常のログイン・ログアウト以外はGETだけ。
  const browser=await engine.launch({headless:true});
  try{
    browsers.push({engine:name,version:browser.version(),device});
    for(const account of accounts){
      const context=await browser.newContext({...devices[device],locale:'ja-JP',timezoneId:'Asia/Tokyo',javaScriptEnabled:false});
      let blocked=0;
      await context.route('**/*',route=>{
        const request=route.request(),url=new URL(request.url());
        if(!['GET','HEAD'].includes(request.method()) && !(request.method()==='POST' && url.origin===origin && ['/entry/login.php','/manage/logout.php'].includes(url.pathname))){blocked++;return route.abort();}
        return url.origin===origin || request.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'?route.continue():route.abort();
      });
      const page=await context.newPage(),prefix=name+' '+account.role;
      try{
        check(prefix+' 未認証は401',(await context.request.get(origin+base)).status()===401);
        await page.goto(origin+'/manage/');
        await page.getByLabel('メールアドレス').fill(account.email);
        await page.getByLabel('パスワード').fill(account.password);
        await Promise.all([page.waitForURL(origin+'/manage/'),page.getByRole('button',{name:'サインイン',exact:true}).tap()]);
        check(prefix+' 既存アカウントでログイン',await page.locator('.account-email').innerText()===account.email);
        check(prefix+' 登録受付・請求入口は保留',await page.locator('a[href="/manage/memberships.php"],a[href="/manage/billing.php"]').count()===0);
        check(prefix+' マイページ入口は事務局だけ',(await page.locator(`a[href="${base}"]`).count()===1)===(account.role==='office'));
        if(account.role==='office'){
          await Promise.all([page.waitForURL('**/information-preview.php'),page.getByRole('link',{name:'旧HPの情報ページを確認',exact:false}).tap()]);
          const paths=[base,...['documents','calendar','links','migration'].map(s=>base+'?section='+s),
                       ...Array.from({length:24},(_,i)=>base+'?item=HP-'+String(i+1).padStart(2,'0'))];
          for(const path of paths){
            const response=await page.goto(origin+path);
            check(prefix+' 閲覧 '+path,response.status()===200);
            check(prefix+' 確認用の表示 '+path,await page.locator('.info-banner').isVisible());
            check(prefix+' 保存・送信フォームなし '+path,await page.locator('main form').evaluateAll(forms=>forms.every(f=>f.method==='get')));
            await page.setViewportSize({width:320,height:844});
            check(prefix+' 幅320px '+path,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
            await page.evaluate(()=>document.documentElement.style.fontSize='200%');
            check(prefix+' 文字200% '+path,await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
            await page.evaluate(()=>document.documentElement.style.fontSize='');
          }
          await page.goto(origin+base+'?section=migration');
          check(prefix+' 引継ぎ対象24件',await page.locator('[data-topic]').count()===24);
          const response=await context.request.get(origin+base),headers=response.headers();
          check(prefix+' キャッシュ・検索掲載・埋込を制限',headers['cache-control'].includes('no-store') && headers['x-robots-tag'].includes('noindex') && headers['content-security-policy'].includes("frame-ancestors 'none'") && headers['referrer-policy']==='no-referrer');
          for(const section of ['information','calendar']){
            await page.goto(origin+base+'?section='+section);
            await page.setViewportSize(devices[device].viewport);
            await page.screenshot({path:`/artifacts/${name}-information-live-${section}.png`,fullPage:true});
          }
          await page.goto(origin+base+'?section=information&category=committee');
          check(prefix+' 分類による絞り込み',await page.locator('[data-topic="HP-19"]').count()===1 && await page.locator('[data-topic="HP-13"]').count()===0);
          for(const category of ['committee','national']){
            await page.goto(origin+base+'?item=HP-20&category='+category);
            check(prefix+' 回答済みの文書分類 '+category,await page.locator('[data-document-category="'+category+'"]').count()===2);
            check(prefix+' 分類内の文書のみ '+category,await page.locator('.info-source-link').count()===2);
          }
          await page.goto(origin+base);await page.locator('.info-answer-decisions > summary').tap();
          check(prefix+' 回答18項目が閲覧可能',await page.locator('[data-answer]').count()===18 && await page.locator('[data-answer="Q05"]').isVisible());
          check(prefix+' 最新資格・切上げ回答を反映',(await page.locator('[data-answer="Q05"]').innerText()).includes('照会時点の最新資格') && (await page.locator('[data-answer="Q09"]').innerText()).includes('全7戦なら5戦'));
          await page.setViewportSize({width:320,height:844});await page.evaluate(()=>document.documentElement.style.fontSize='200%');
          check(prefix+' 回答展開320px・文字200%',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
          await page.evaluate(()=>document.documentElement.style.fontSize='');
          await page.screenshot({path:`/artifacts/${name}-information-live-answers.png`,fullPage:false});
          await page.goto(origin+base+'?q='+encodeURIComponent('存在しないXYZ'));
          check(prefix+' 検索該当なし',await page.getByText('該当する確認対象はありません。',{exact:false}).count()===1);
          for(const path of ['/src/Content/information-catalog.php','/manage/information-catalog.php','/private/jmrc-portal/auth.config.php']){
            check(prefix+' 内部ファイル非公開 '+path,[403,404].includes((await context.request.get(origin+path)).status()));
          }
          await page.goto(origin+'/manage/review.php');
          check(prefix+' 既存操作確認からの導線',await page.locator(`a[href="${base}"]`).count()===1);
          const links=await page.locator('.menu-list a').evaluateAll(xs=>xs.map(x=>x.getAttribute('href')));
          check(prefix+' 既存24画面を維持',links.length===24);
          for(const link of links)check(prefix+' 既存確認画面GET '+link,(await context.request.get(origin+link)).status()===200);
          for(const path of ['bank-settings.php','profile-correct.php','profile-correct.php?last_year=9998'])
            check(prefix+' 既存事務局画面GET '+path,(await context.request.get(origin+'/manage/'+path)).status()===200);
        }else{
          for(const path of [base,base+'?item=HP-19',base+'?section=migration','/manage/review.php'])
            check(prefix+' 権限外の直URLは403 '+path,(await context.request.get(origin+path)).status()===403);
        }
        // 元のエントリーへ既存セッションで遷移でき、ポータルからのログアウトも連動するか確認する。
        await page.goto(origin+'/manage/');
        await Promise.all([page.waitForURL(/\/entry\/(entrant|admin|club)\/events\.php$/),page.getByRole('link',{name:'オンラインエントリーへ',exact:true}).tap()]);
        check(prefix+' 既存entryと共通認証',new URL(page.url()).pathname.endsWith('/events.php'));
        const entryPath=new URL(page.url()).pathname;
        await page.goto(origin+'/manage/');
        await Promise.all([page.waitForURL(origin+'/entry/login.php'),page.getByRole('button',{name:'サインアウト',exact:true}).tap()]);
        check(prefix+' ログアウトで試作も401',(await context.request.get(origin+base)).status()===401);
        await page.goto(origin+entryPath);
        check(prefix+' entryもログアウト済み',new URL(page.url()).pathname==='/entry/login.php');
        check(prefix+' 業務POSTを試行していない',blocked===0);
      }finally{
        // 途中失敗でも検証に使ったセッションだけを通常のログアウト経路で終了する。
        try{
          const response=await context.request.get(origin+'/manage/'),html=await response.text();
          const match=html.match(/name="_csrf" value="([a-f0-9]{64})"/);
          if(match && html.includes('account-email'))await context.request.post(origin+'/manage/logout.php',{form:{_csrf:match[1]},maxRedirects:0});
        }catch{}
        await context.close();
      }
    }
  }finally{await browser.close();}
}

(async()=>{
  await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');
  accounts.forEach(a=>a.password='');
  process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),browsers,cases,business_posts:0}));
})().catch(error=>{
  accounts.forEach(a=>a.password='');
  process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:error.name}));process.exit(1);
});
