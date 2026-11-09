// Path: tests/license/editor_browser.cjs
// 機能: 下書き4種のスマートフォン表示・保存を架空環境で検証。本番モードはGETと認証だけに限定する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
const live=process.env.EDITOR_LIVE==='1',origin=live?'https://gymkinki.com':'https://web';
const accounts=live?JSON.parse(fs.readFileSync(0,'utf8')):[{role:'office',email:'u7@example.invalid',password:'lab-password-only'},{role:'entrant',email:'u1@example.invalid',password:'lab-password-only'}];
const cases=[],browsers=[];let businessPosts=0;
function check(name,test){assert.ok(test,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const browser=await engine.launch({headless:true});browsers.push({engine:name,version:browser.version(),device});
 try{for(const account of accounts){
  const c=await browser.newContext({...devices[device],locale:'ja-JP',timezoneId:'Asia/Tokyo',javaScriptEnabled:false,ignoreHTTPSErrors:!live});
  await c.route('**/*',r=>{const q=r.request(),u=new URL(q.url());if(live&&!['GET','HEAD'].includes(q.method())&&!['/entry/login.php','/manage/logout.php'].includes(u.pathname)){businessPosts++;return r.abort();}if(q.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'});return u.origin===origin?r.continue():r.abort();});
  const p=await c.newPage(),prefix=name+' '+account.role;
  check(prefix+' 未認証を拒否',(await c.request.get(origin+'/manage/editor.php')).status()===401);
  await p.goto(origin+'/manage/');await p.getByLabel('メールアドレス').fill(account.email);await p.getByLabel('パスワード').fill(account.password);
  await Promise.all([p.waitForURL(origin+'/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
  check(prefix+' 下書き導線の権限',(await p.locator('a[href="/manage/editor.php"]').count()===1)===(account.role==='office'));
  if(account.role!=='office'){check(prefix+' 直接URLの拒否',(await c.request.get(origin+'/manage/editor.php')).status()===403);}
  else{for(const kind of ['calendar','mail_template','result_csv','document_pdf']){
   check(prefix+' '+kind+' 表示',(await p.goto(origin+'/manage/editor.php?kind='+kind)).status()===200);
   check(prefix+' 公開と送信の操作なし',await p.getByRole('button',{name:/^(公開する|送信する)$/}).count()===0);
   for(const width of [320,390,844,1280]){await p.setViewportSize({width,height:844});check(prefix+' '+kind+' 横はみ出し '+width,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));}
   await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');check(prefix+' '+kind+' 文字拡大',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
   if(!live){
    await p.locator('#item-key').fill(kind==='result_csv'?'event-'+(name==='chromium'?99002:99003):name+'-'+kind);await p.locator('#title').fill('架空 '+kind);
    if(kind==='calendar'){await p.locator('#date').fill('2027-04-10');await p.locator('#category').selectOption('unavailable');await p.locator('#description').fill('架空の予定');}
    if(kind==='mail_template')await p.locator('#body').fill('送信しない架空の本文');
    if(kind==='result_csv')await p.locator('#attachment').setInputFiles({name:'result.csv',mimeType:'text/csv',buffer:Buffer.from('event_id,class_code,entrant_id,position,points,starters\n'+(name==='chromium'?99002:99003)+',PN1,3,1,20,4\n')});
    if(kind==='document_pdf')await p.locator('#attachment').setInputFiles({name:'fixture.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n%synthetic\n%%EOF\n')});
    await Promise.all([p.waitForURL(/id=\d+&saved=1/),p.getByRole('button',{name:'下書きを保存する',exact:true}).tap()]);
    check(prefix+' '+kind+' ファイル含む保存',(await p.locator('main').innerText()).includes('下書きを保存しました'));
    if(kind==='calendar'){
     await p.locator('#description').fill('訂正版の予定');await Promise.all([p.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('/editor.php')),p.getByRole('button',{name:'訂正版を保存する',exact:true}).tap()]);await p.waitForLoadState();check(prefix+' 訂正版の再表示',(await p.locator('#description').inputValue())==='訂正版の予定');
    }
   }
   await p.setViewportSize(devices[device].viewport);await p.screenshot({path:`/artifacts/${name}-editor-${kind}.png`,fullPage:true});
  }}
  await p.goto(origin+'/manage/');await Promise.all([p.waitForURL('**/login.php**'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);check(prefix+' ログアウト後は拒否',(await c.request.get(origin+'/manage/editor.php')).status()===401);await c.close();
 }}finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');accounts.forEach(a=>a.password='');process.stdout.write(JSON.stringify({status:'PASS',checked_at_utc:new Date().toISOString(),browsers,cases,business_posts:businessPosts,live}));})().catch(e=>{accounts.forEach(a=>a.password='');process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:e.name,message:live?undefined:e.message}));process.exit(1);});
