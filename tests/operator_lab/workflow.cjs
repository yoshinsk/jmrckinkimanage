// Path: tests/operator_lab/workflow.cjs
// 機能: 専用HTTPSサイトの架空アカウントだけで登録・差し戻し・請求・不足入金・承認を実ブラウザー検証する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
const input=JSON.parse(fs.readFileSync(0,'utf8')),origin='https://portal-test.gymkinki.com',cases=[];let activePage;
assert.equal(input.url,origin+'/');assert.ok(['chromium','webkit'].includes(process.env.LAB_ENGINE));
function check(name,value){assert.ok(value,name);cases.push({case:name,result:'PASS'});}
(async()=>{
 const name=process.env.LAB_ENGINE,b=await ({chromium,webkit}[name]).launch({headless:true});
 let posts=0,blocked=0,productionAttempts=0;
 try{
  const authorization='Basic '+Buffer.from(input.gate_user+':'+input.gate_password).toString('base64');
  const c=await b.newContext({...devices[name==='chromium'?'Pixel 7':'iPhone 13'],locale:'ja-JP',timezoneId:'Asia/Tokyo',httpCredentials:{username:input.gate_user,password:input.gate_password,origin},extraHTTPHeaders:{Authorization:authorization}});
  await c.route('**/*',r=>{const q=r.request(),u=new URL(q.url());
   if(q.url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css')return r.fulfill({path:'/lab/bootstrap.css',contentType:'text/css'});
   if(u.origin!==origin){if(u.hostname==='gymkinki.com'&&q.method()==='POST')productionAttempts++;blocked++;return r.abort();}
   if(q.method()==='POST'&&['/manage/club-register.php','/manage/club-finance.php'].includes(u.pathname))posts++;return r.continue();});
  const p=await c.newPage();activePage=p;
  async function layout(){for(const width of [320,390,844,1280]){await p.setViewportSize({width,height:844});check('幅'+width+' '+new URL(p.url()).pathname,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));}await p.setViewportSize({width:390,height:844});}
  async function shot(n){await layout();await p.screenshot({path:'/artifacts/'+name+'-'+n+'.png',fullPage:true});}
  async function login(role){await p.goto(origin+'/manage/');if(await p.getByRole('button',{name:'サインアウト',exact:true}).count())await Promise.all([p.waitForURL('**/login.php**'),p.getByRole('button',{name:'サインアウト',exact:true}).click()]);await p.goto(origin+'/manage/');await p.getByLabel('メールアドレス').fill(input.accounts[role].email);await p.getByLabel('パスワード').fill(input.accounts[role].password);await Promise.all([p.waitForURL(origin+'/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).click()]);check(role+'でログイン',await p.locator('.account-email').innerText()===input.accounts[role].email);}
  async function click(label){await Promise.all([p.waitForNavigation(),p.getByRole('button',{name:label,exact:true}).click()]);check(label+'後に検証ホストを維持',new URL(p.url()).origin===origin);}
  async function read(id){const r=await c.request.get(origin+'/manage/club-register.php?id='+id+'&format=json');assert.equal(r.status(),200);return (await r.json()).record;}
  await p.goto(origin+'/');check('練習用の注意と手順',await p.locator('h1').innerText()==='クラブ登録の操作練習'&&(await p.locator('.operator-lab-notice').innerText()).includes('架空'));await shot('guide');
  check('対象外の役職画面を閉じる',(await c.request.get(origin+'/manage/staff-roles.php')).status()===404);
  check('非公開設定へアクセスできない',(await c.request.get(origin+'/private/operator-lab/accounts.json')).status()===404);
  await login('club');await p.goto(origin+'/manage/club-register.php?reuse=entry');
  await p.locator('#candidate-query').fill('199981');await click('更新候補を探す');await p.locator('#club-candidate_ref').selectOption('legacy:1');
  for(const e of await p.locator('#club-intake-form input[required]').all()){
   const k=await e.getAttribute('name');await e.fill(k==='jaf_number'?'99981':k.endsWith('email')?input.accounts.club.email:k.includes('postcode')?'0000000':k.includes('phone')||k.includes('mobile')?'0000000000':'練習');}
  await p.locator('#club-club_name').fill('練習更新クラブ');await p.locator('#club-club_short').fill('TEST-UPDATE');
  await p.locator('#club-company').fill('<body>架空会社</body>');
  await p.locator('#club-year').selectOption('2027');await p.locator('#club-registration_kind').selectOption('renewal');await p.locator('#club-region').selectOption('27');await p.locator('#club-jaf_kind').selectOption('official');await p.locator('#club-membership_kind').selectOption('regular');await p.locator('#club-same_contact').selectOption('1');
  await click('申請を提出する');const id=Number(new URL(p.url()).searchParams.get('id'));check('更新申請を保存',id>0&&(await read(id)).review_state==='renewal_review');check('HTML風の入力でもJSON照会を変更しない',(await read(id)).data.company==='<body>架空会社</body>');await shot('submitted');
  await login('other');check('別クラブの申請を拒否',(await c.request.get(origin+'/manage/club-register.php?id='+id)).status()===403);
  await login('office');await p.goto(origin+'/manage/club-register.php?id='+id);await p.locator('#return-reason').fill('練習：クラブ名の末尾に確認済を付けてください');await click('理由を添えて差し戻す');check('差し戻し理由を保存',(await read(id)).review_state==='returned');await shot('returned');
  await login('club');await p.goto(origin+'/manage/club-register.php?id='+id);await p.locator('#club-club_name').fill('練習更新クラブ確認済');await click('訂正して再申請する');check('同じ申請番号で再申請',Number(new URL(p.url()).searchParams.get('id'))===id&&(await read(id)).review_state==='renewal_review');
  await login('office');await p.goto(origin+'/manage/club-register.php?id='+id);await p.locator('#target-ref').selectOption('legacy:1');await p.locator('#fixed-code').fill('99981');await p.locator('input[name=checked]').check();await click('照合結果を年度登録へ接続する');check('事務局照合で年度へ接続',(await read(id)).club_year_id>0);
  await p.goto(origin+'/manage/club-finance.php?intake='+id);await click('請求と振込案内を発行する');
  let rec=(await (await c.request.get(origin+'/manage/club-finance.php?intake='+id+'&format=json')).json()).record;
  check('更新請求50000円と架空口座',Number(rec.invoice.amount_yen)===50000&&Number(rec.invoice.balance_yen)===50000&&rec.guide.details.includes('振込禁止'));await shot('invoice');
  await login('finance');await p.goto(origin+'/manage/club-finance.php?intake='+id);check('財務に年度承認ボタンなし',await p.getByRole('button',{name:'年度登録を承認する',exact:true}).count()===0);
  const csrf=await p.locator('input[name=_csrf]').first().inputValue();
  const denied=await c.request.post(origin+'/manage/club-finance.php?format=json',{form:{_csrf:csrf,action:'approve',intake:String(id),checked:'1'}});posts++;
  check('財務の年度承認をサーバーでも拒否',denied.status()===403);
  for(const [amount,number,balance] of [['10000','1',40000],['40000','2',0]]){
   await p.locator('#received-on').fill(new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Tokyo'}).format(new Date()));await p.locator('#confirm-amount').fill(amount);await p.locator('#reference').fill('TEST-'+id+'-'+number);await click('銀行明細と照合して記録する');
   rec=(await (await c.request.get(origin+'/manage/club-finance.php?intake='+id+'&format=json')).json()).record;check('入金'+number+'の残額と未承認',Number(rec.invoice.balance_yen)===balance&&!rec.eligibility.ready);await shot('payment-'+number);}
  await login('office');await p.goto(origin+'/manage/club-finance.php?intake='+id);await p.locator('input[name=checked]').check();await click('年度登録を承認する');
  rec=(await (await c.request.get(origin+'/manage/club-finance.php?intake='+id+'&format=json')).json()).record;check('承認後に年度登録済み・正本はshadowのまま',rec.eligibility.ready&&!rec.eligibility.active&&rec.intake.source_mode==='shadow');await shot('approved');
  check('本番への業務POST試行なし',productionAttempts===0);await c.close();
  process.stdout.write(JSON.stringify({status:'PASS',engine:name,version:b.version(),cases,lab_business_posts:posts,external_requests_blocked:blocked,production_business_posts:0,intake_id:id}));
 }finally{await b.close();}
})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:e.name,message:e.message}));process.exit(1);});
