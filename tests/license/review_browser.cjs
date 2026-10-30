// Path: tests/license/review_browser.cjs
// 機能: 24画面のタッチ操作・文字拡大・資格遷移・下書き・確認票を実ブラウザーと架空データで検証する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict');
const cases=[],browsers=[];
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
 const browser=await engine.launch({headless:true});
 try{
  browsers.push({engine:name,version:browser.version(),device});
  const c=await browser.newContext({...devices[device],locale:'ja-JP',ignoreHTTPSErrors:true,javaScriptEnabled:false});
  await c.route('**/*',r=>r.request().url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'?r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'}):new URL(r.request().url()).hostname==='web'?r.continue():r.abort());
  const p=await c.newPage();await p.goto('https://web/manage/');await p.getByLabel('メールアドレス').fill('u7@example.invalid');await p.getByLabel('パスワード').fill('lab-password-only');
  await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);
  await p.getByRole('link',{name:'機能の操作確認',exact:false}).tap();await p.waitForURL('**/review.php');
  const links=await p.locator('.menu-list a').evaluateAll(xs=>xs.map(x=>x.getAttribute('href')));check(name+' 24画面の入口',links.length===24);
  await p.screenshot({path:`/artifacts/${name}-review-overview.png`,fullPage:true});
  for(const href of links){
   const r=await p.goto('https://web'+href);check(name+' GET '+href,r.status()===200);
   check(name+' 架空と保留を明示 '+href,await p.locator('.review-banner').count()===1 && await p.getByText('確認が残る事項',{exact:true}).count()===1);
   check(name+' 全POSTの送信先を確認領域に限定 '+href,await p.locator('main form').evaluateAll(fs=>fs.every(f=>new URL(f.getAttribute('action'),location.href).pathname==='/manage/review.php')));
   for(const width of [320,390,844,1280]){
    await p.setViewportSize({width,height:844});
    check(name+' 幅'+width+' '+href,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1 && [...document.querySelectorAll('.office-button,.signout,summary,.office-form input:not([type=hidden]),.office-form select,.office-form textarea')].filter(e=>e.getBoundingClientRect().height>0).every(e=>e.getBoundingClientRect().height>=44)));
   }
   await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');check(name+' 文字200% '+href,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
  }
  await p.setViewportSize(devices[device].viewport);
  async function go(screen){const r=await p.goto('https://web/manage/review.php?screen='+screen);assert.equal(r.status(),200,screen);}
  async function submit(label){await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:label,exact:true}).tap()]);}
  async function role(value){const details=p.locator('details').first();if(!await details.getAttribute('open'))await details.locator('summary').tap();await p.getByLabel('立場を切り替える').selectOption(value);await submit('立場を変更');}
  await go('operations');await p.getByLabel('Generalの状態例').selectOption('active');await submit('状態例を表示する');await go('card');
  check(name+' 有効会員証を表示',await p.locator('[data-eligibility=active]').count()===1 && (await p.locator('.review-card').innerText()).includes('確認 太郎'));
  await p.screenshot({path:`/artifacts/${name}-review-card.png`,fullPage:true});
  await go('billing');await submit('最後の入金を取り消す');check(name+' 入金取消で資格無効',await p.locator('[data-eligibility=inactive]').count()===1);
  await p.getByLabel('入金識別番号（架空）').fill('BROWSER-REVIEW-1');await submit('入金確認を試す');check(name+' 再入金で資格を再判定',await p.locator('[data-eligibility=active]').count()===1);
  await p.screenshot({path:`/artifacts/${name}-review-billing.png`,fullPage:true});
  await go('temporary');await submit('Temporary申請を試す');await p.getByLabel('イベント',{exact:true}).selectOption('B');await submit('Temporary申請を試す');check(name+' 同日別イベントを拒否',(await p.getByRole('alert').innerText()).includes('同じ開催日'));
  await role('club');await go('temporary');await submit('主催クラブとして承認する');check(name+' クラブ代表で主催承認',(await p.locator('.office-panel .office-records').innerText()).includes('主催承認 済'));
  await role('office');
  // 下書きを作成し、保存結果と閲覧対象を確認。外部サービスへ送信する操作は存在しない。
  for(const [screen,button] of [['events','日程の下書きを保存する'],['notices','記事の下書きを保存する'],['documents','資料情報を保存する'],['handbook','文書情報を保存する'],['links','リンク案を保存する'],['relief','申請内容の下書きを保存する'],['meetings','案内・回答を保存する'],['inquiries','問い合わせを試す'],['notifications','通知文案を保存する'],['accounts','対応付けを試す']]){
   await go(screen);await submit(button);check(name+' 下書き保存 '+screen,await p.getByRole('status').count()===1 && (await p.locator('.office-records dl').count())>=1);
  }
  await go('notices');await p.getByLabel('見出し').fill('PUBLIC-REVIEW');await p.getByLabel('公開範囲').selectOption('public');await submit('記事の下書きを保存する');
  await role('public');await go('notices');check(name+' 公開例は公開記事だけ',(await p.locator('.office-records dl').count())===1 && (await p.locator('.office-panel .office-records').innerText()).includes('PUBLIC-REVIEW'));
  check(name+' 公開例に投稿フォームなし',await p.getByRole('button',{name:'記事の下書きを保存する',exact:true}).count()===0);
  await go('card');check(name+' QR公開項目の例',(await p.locator('main').innerText()).includes('DEMO123-G-100001') && !(await p.locator('main').innerText()).includes('確認用の住所'));
  await role('office');await go('insurance');await p.getByLabel('会員区分').selectOption('temporary');await submit('加入意思・人数を確認する');check(name+' Temporary保険は対象外',await p.getByRole('alert').count()===1);
  await p.getByLabel('会員区分').selectOption('general');await p.getByLabel('同じ年度クラブの有効General人数（架空）').fill('4');await submit('加入意思・人数を確認する');
  const download=c.request.get('https://web/manage/review.php?screen=insurance&download=insurance');const file=await download;check(name+' 確認Excel生成',file.status()===200 && (await file.body()).subarray(0,2).toString()==='PK');
  await p.screenshot({path:`/artifacts/${name}-review-insurance.png`,fullPage:true});
  await p.getByLabel('確認結果').selectOption('change');await p.getByLabel('メモ（個人情報を含めない）').fill('保険料と期間の確認が必要');await submit('確認メモを保存');
  const notes=await c.request.get('https://web/manage/review.php?download=notes');check(name+' メモ24画面を書出し',notes.status()===200 && (await notes.json())['画面別確認'].length===24 && (await notes.text()).includes('保険料と期間の確認が必要'));
  const main=await p.goto('https://web/manage/');check(name+' 本番相当の受付保留を維持',main.status()===200 && await p.locator('a[href="/manage/memberships.php"]').count()===0);
  await Promise.all([p.waitForURL('**/entry/login.php'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);
  check(name+' ログアウト後は確認画面401',(await c.request.get('https://web/manage/review.php')).status()===401);await c.close();
 }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,javascript_disabled:true}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',error:String(e),stack:e.stack}));process.exit(1);});
