// Path: tests/license/information_browser.cjs
// 機能: 旧HP試作の分類・検索・詳細・JST日程を、隔離Chromium/WebKitで操作して検証する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict');
const cases=[],browsers=[];
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const browser=await engine.launch({headless:true});
 try{
  browsers.push({engine:name,version:browser.version(),device});
  const c=await browser.newContext({...devices[device],locale:'ja-JP',ignoreHTTPSErrors:true,javaScriptEnabled:false});
  // 実行時はweb以外を拒否し、既存ログインの固定CSSだけを同梱ファイルで供給する。
  await c.route('**/*',r=>r.request().url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'?r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'}):new URL(r.request().url()).hostname==='web'?r.continue():r.abort());
  const p=await c.newPage();await p.goto('https://web/manage/');
  await p.getByLabel('メールアドレス').fill('u7@example.invalid');await p.getByLabel('パスワード').fill('lab-password-only');
  await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
  await Promise.all([p.waitForURL('**/information-preview.php'),p.getByRole('link',{name:'旧HPの情報ページを確認',exact:false}).tap()]);
  check(name+' 確認用バナー',await p.locator('.info-banner').isVisible());
  const details=await p.locator('.info-grid h3 a').evaluateAll(xs=>xs.map(x=>x.getAttribute('href')));
  check(name+' 四分類の入口',await p.locator('.info-categories a').count()===5);
  const urls=['/manage/information-preview.php',...['documents','calendar','links','migration'].map(x=>'/manage/information-preview.php?section='+x),...details];
  for(const href of urls){
   const r=await p.goto('https://web'+href);check(name+' GET '+href,r.status()===200);
   check(name+' 業務送信フォームなし '+href,await p.locator('main form').evaluateAll(fs=>fs.every(f=>f.method.toLowerCase()==='get'&&new URL(f.action).pathname==='/manage/information-preview.php')));
   for(const width of [320,390,844,1280]){
    await p.setViewportSize({width,height:844});
    check(name+' 幅'+width+' '+href,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&[...document.querySelectorAll('.info-nav a,.info-categories a,.info-grid h3 a,.office-button,.signout,summary,input:not([type=hidden]),select')].filter(e=>e.getBoundingClientRect().height>0).every(e=>e.getBoundingClientRect().height>=44)));
   }
   await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');
   check(name+' 文字200% '+href,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
  }
  await p.setViewportSize(devices[device].viewport);await p.goto('https://web/manage/information-preview.php');
  await Promise.all([p.waitForURL('**/information-preview.php?section=information&category=committee'),p.getByRole('navigation',{name:'お知らせの分類'}).getByRole('link',{name:'運営委員会',exact:true}).tap()]);
  check(name+' 運営委員会分類',await p.locator('.info-grid > li').count()===2&&await p.locator('[data-topic="HP-19"]').count()===1&&await p.locator('[data-topic="HP-20"]').count()===1);
  await Promise.all([p.waitForURL('**/information-preview.php?section=information&item=HP-19'),p.getByRole('link',{name:'運営委員会からのお知らせ',exact:true}).tap()]);
  check(name+' 非公開資料の分離',(await p.locator('.info-detail').innerText()).includes('非公開の委員会資料や資料BOXはこのページに掲載しません'));
  await p.locator('.info-sources summary').tap();
  check(name+' 参照元を開ける',await p.locator('.info-source-link').first().isVisible());
  await p.screenshot({path:`/artifacts/${name}-information-detail.png`,fullPage:true});
  for(const category of ['committee','national']){
   await p.goto('https://web/manage/information-preview.php?item=HP-20&category='+category);
   await p.locator('.info-sources summary').tap();
   check(name+' 文書別分類 '+category,await p.locator('[data-document-category="'+category+'"]').count()===2 && await p.locator('.info-source-link').count()===2);
  }
  await p.goto('https://web/manage/information-preview.php');await p.locator('.info-answer-decisions > summary').tap();
  check(name+' 追加回答18項目を開ける',await p.locator('[data-answer]').count()===18 && await p.locator('[data-answer="Q05"]').isVisible());
  check(name+' 資格の基準時点と70%切上げ', (await p.locator('[data-answer="Q05"]').innerText()).includes('照会時点の最新資格') && (await p.locator('[data-answer="Q09"]').innerText()).includes('全7戦なら5戦'));
  await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');
  check(name+' 回答を開いた状態の320px・200%',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
  await p.goto('https://web/manage/information-preview.php?section=migration');
  await p.getByLabel('元資料の年度候補').fill('2008');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'絞り込む',exact:true}).tap()]);
  check(name+' 年度検索を操作',await p.locator('.info-grid > li').count()===1&&await p.locator('[data-topic="HP-21"]').count()===1);
  await Promise.all([p.waitForURL('**/information-preview.php?section=migration'),p.getByRole('link',{name:'条件を解除',exact:true}).tap()]);await p.getByLabel('キーワード').fill('レンタル');
  await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'絞り込む',exact:true}).tap()]);
  check(name+' キーワード検索を操作',await p.locator('.info-grid > li').count()===1&&await p.locator('[data-topic="HP-14"]').count()===1);
  await p.goto('https://web/manage/information-preview.php?section=calendar');
  await p.getByLabel('予定の種類').selectOption('office');await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:'日程を絞り込む',exact:true}).tap()]);
  check(name+' カレンダーの種別検索',await p.locator('.info-calendar > li').count()===2);
  for(const section of ['information','documents','calendar','links','migration']){
   await p.goto('https://web/manage/information-preview.php?section='+section);
   await p.screenshot({path:`/artifacts/${name}-information-${section}.png`,fullPage:true});
  }
  await p.setViewportSize({width:1280,height:900});await p.goto('https://web/manage/information-preview.php');
  await p.screenshot({path:`/artifacts/${name}-information-desktop.png`,fullPage:true});
  await Promise.all([p.waitForURL('**/entry/login.php'),p.getByRole('button',{name:'サインアウト',exact:true}).click()]);
  check(name+' サインアウト後の再表示拒否',(await c.request.get('https://web/manage/information-preview.php?item=HP-19')).status()===401);
  await c.close();
 }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,javascript_disabled:true}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',error:String(e),stack:e.stack}));process.exit(1);});
