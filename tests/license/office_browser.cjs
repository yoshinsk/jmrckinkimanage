// Path: tests/license/office_browser.cjs
// 機能: 隔離DBの架空会員・口座で、事務局画面のスマートフォン表示、保存、権限、候補確認を検証する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict');
const cases=[],browsers=[];
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device,uid){
 const browser=await engine.launch({headless:true});
 try {
  browsers.push({engine:name,version:browser.version(),device});
  // JavaScriptなしでも開閉・入力・保存・ログアウトが完結することを確認する。
  async function context(){
   const c=await browser.newContext({...devices[device],locale:'ja-JP',ignoreHTTPSErrors:true,javaScriptEnabled:false});
   await c.route('**/*',r=>r.request().url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'?r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'}):new URL(r.request().url()).hostname==='web'?r.continue():r.abort());return c;
  }
  async function login(c,id){const p=await c.newPage();await p.goto('https://web/manage/');await p.getByLabel('メールアドレス').fill(`u${id}@example.invalid`);await p.getByLabel('パスワード').fill('lab-password-only');await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);return p;}
  const c=await context(),p=await login(c,7),prefix=name+' 事務局';
  const list=await (await c.request.get('https://web/manage/bank-settings.php?format=json')).json();
  const account=list.accounts.find(a=>a.display_name===`架空画面口座${uid}`),source=list.sources.find(s=>s.code===`mobile-master-${uid}`);
  assert.ok(account && source,'先行する実ブラウザー試験の架空口座');
  async function submit(label){await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:label,exact:true}).tap()]);}
  async function get(path){const r=await c.request.get('https://web/manage/'+path);assert.equal(r.status(),200);return r.json();}
  await p.goto('https://web/manage/bank-settings.php?source='+source.id);
  check(prefix+' 登録済み実口座コードは読取専用',await p.getByLabel('実口座コード',{exact:true}).getAttribute('readonly')!==null);
  await p.getByLabel('取込元の表示名',{exact:true}).fill('架空実口座'.repeat(20));await p.getByLabel('変更理由',{exact:true}).fill('取込元の名称を訂正 <架空>');await submit('取込元を保存');
  check(prefix+' 保留設定でも事務局の取込元設定を保存',(await get('bank-settings.php?format=json&source='+source.id)).display_name==='架空実口座'.repeat(20));
  await p.goto('https://web/manage/bank-settings.php?account='+account.id);
  await p.getByLabel('ペイメントコアURL',{exact:true}).fill('https://example.invalid/'+('a'.repeat(300)));
  await p.getByLabel('開始日時（日本時間）',{exact:true}).fill('2027-01-01T12:34:56.123');
  await p.getByLabel('終了日時（日本時間・その時刻は対象外）',{exact:true}).fill('2028-01-01T00:00');
  await p.getByLabel('変更理由',{exact:true}).fill('日付とURLを確認 <架空>');await submit('口座設定を保存');
  const saved=await get('bank-settings.php?format=json&account='+account.id);
  check(prefix+' 日付の秒以下とJSTからUTCへの保存',saved.valid_from==='2027-01-01 03:34:56.123000' && saved.valid_until==='2027-12-31 15:00:00.000000');
  check(prefix+' URL・CSV対応・無効状態の維持',saved.payment_url.endsWith('a'.repeat(300)) && saved.source_id===source.id && !saved.enabled);
  await Promise.all([p.waitForNavigation(),p.getByRole('link',{name:'保存した設定を確認',exact:true}).tap()]);
  const history=await p.locator('a[href^="?history="]').first().getAttribute('href');
  await p.goto('https://web/manage/profile-correct.php');await p.getByLabel('会員の共通ユーザーID',{exact:true}).fill(String(uid));await submit('対象を確認する');
  await p.getByLabel('姓',{exact:true}).fill('架空姓'.repeat(20));await p.getByLabel('訂正理由',{exact:true}).fill('スマートフォンの訂正 <架空>');await submit('訂正を保存する');
  check(prefix+' 訂正結果とエスケープした理由を履歴表示',(await p.getByRole('status').innerText())==='訂正を保存しました。' && (await p.locator('main').innerText()).includes('スマートフォンの訂正 <架空>') && await p.locator('架空').count()===0);
  const before=await get('profile-correct.php?format=json&user=60'),lastYear=new Date().getUTCFullYear()-2;
  await p.getByLabel('最終登録年度',{exact:true}).fill(String(lastYear));await submit('削除候補を確認する');
  const candidates=await get('profile-correct.php?format=json&last_year='+lastYear);
  check(prefix+' 候補を抽出しても削除せず再申請中を除外',!candidates.execution_enabled && candidates.candidates.some(r=>Number(r.user_id)===60) && !candidates.candidates.some(r=>Number(r.user_id)===61) && JSON.stringify(await get('profile-correct.php?format=json&user=60'))===JSON.stringify(before));
  check(prefix+' 候補画面に削除実行POSTなし',await p.locator('main form[method="post"]').count()===0);
  const pages=[['account-new','bank-settings.php'],['account-edit','bank-settings.php?account='+account.id],['source-new','bank-settings.php?new=source'],['source-edit','bank-settings.php?source='+source.id],['history','bank-settings.php'+history],['profile-search','profile-correct.php'],['profile-edit','profile-correct.php?user='+uid],['candidates','profile-correct.php?last_year='+lastYear],['not-due','profile-correct.php?last_year=9998']];
  for(const [label,path] of pages){
   check(prefix+' '+label+' 表示', (await p.goto('https://web/manage/'+path)).status()===200);
   for(const width of [320,375,390,412,844,1280]){
    await p.setViewportSize({width,height:width===844?390:844});
    check(prefix+' '+label+' 幅'+width+'pxの表示・タップ寸法',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1 && [...document.querySelectorAll('.office-button,.signout,.portal-brand,summary,.office-check,.office-form input:not([type="hidden"]):not([type="checkbox"]),.office-form select,.office-form textarea')].filter(e=>e.getClientRects().length).every(e=>{const r=e.getBoundingClientRect();return r.height>=44 && r.left>=-1 && r.right<=innerWidth+1;})));
   }
   await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');
   const enlargedFits=await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);
   if(!enlargedFits){
    await p.screenshot({path:`/artifacts/${name}-office-${label}-overflow.png`,fullPage:true});
    const overflow=await p.evaluate(()=>[...document.querySelectorAll('main *')].filter(e=>!['INPUT','TEXTAREA','SELECT'].includes(e.tagName) && e.scrollWidth>e.clientWidth+1).map(e=>({tag:e.tagName,class:e.className,width:e.clientWidth,scroll:e.scrollWidth})).slice(0,12));
    throw new Error(prefix+' '+label+' 200% overflow '+JSON.stringify(overflow));
   }
   check(prefix+' '+label+' 200%文字拡大で横はみ出しなし',enlargedFits);
   await p.evaluate(()=>document.documentElement.style.fontSize='');await p.setViewportSize(devices[device].viewport);
   await p.screenshot({path:`/artifacts/${name}-office-${label}.png`,fullPage:true});
   if(path.startsWith('bank'))check(prefix+' '+label+' CSV取込は保留',await p.locator('a[href="bank.php"]').count()===0 && (await p.locator('main').innerText()).includes('CSV取込・入金照合は準備中'));
  }
  await p.goto('https://web/manage/bank-settings.php');const summary=p.locator('summary').first();await summary.tap();
  check(prefix+' 口座選択一覧をタッチで開く',await p.locator('details').first().getAttribute('open')!==null);
  await summary.focus();await p.keyboard.press('Enter');check(prefix+' 一覧をキーボードで閉じる',await p.locator('details').first().getAttribute('open')===null);
  await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('link',{name:'マイページへ戻る',exact:true}).tap()]);check(prefix+' 共通マイページへ戻る',new URL(p.url()).pathname==='/manage/');
  await p.goto('https://web/manage/profile-correct.php');await Promise.all([p.waitForURL('https://web/entry/login.php'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);await p.goto('https://web/manage/');check(prefix+' 事務局画面から共通サインアウト',new URL(p.url()).pathname==='/entry/login.php');await c.close();
  // メニューの非表示だけでなく、HTML・JSONの直アクセスも既存の権限判定で拒否する。
  for(const [id,role] of [[null,'未認証'],[uid,'エントラント'],[62,'財務専用'],[2,'クラブ代表'],[1,'旧管理者']]){
   const denied=await context();if(id!==null)await login(denied,id);
   for(const path of ['bank-settings.php','profile-correct.php'])for(const suffix of ['', '?format=json'])check(name+' '+role+' '+path+suffix+' 権限拒否',(await denied.request.get('https://web/manage/'+path+suffix)).status()===(id===null?401:403));
   await denied.close();
  }
 }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7',21);await run(webkit,'webkit','iPhone 13',22);process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,javascript_disabled:true}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:String(e)}));process.exit(1);});
