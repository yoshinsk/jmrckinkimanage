// Path: tests/license/member_browser.cjs
// 機能: 架空会員で申請・一意請求・承認・入金・訂正をタッチ操作し、受付保留と権限・小画面表示を確認する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict'),crypto=require('node:crypto');
const hold=process.env.MEMBER_HOLD==='1',cases=[],browsers=[];
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device,uid){
 const b=await engine.launch({headless:true}),contexts=[];let current='';
 const prefix=name+' '+(hold?'保留':'通常');
 try{
  browsers.push({engine:name,version:b.version(),device});
  async function login(id){
   const c=await b.newContext({...devices[device],locale:'ja-JP',ignoreHTTPSErrors:true,javaScriptEnabled:false});contexts.push(c);
   await c.route('**/*',r=>r.request().url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'?r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'}):new URL(r.request().url()).hostname==='web'?r.continue():r.abort());
   const p=await c.newPage();await p.goto('https://web/manage/');await p.getByLabel('メールアドレス').fill(`u${id}@example.invalid`);await p.getByLabel('パスワード').fill('lab-password-only');await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('button',{name:'サインイン',exact:true}).tap()]);return {c,p};
  }
  async function navigate(p,path){const r=await p.goto('https://web/manage/'+path);assert.equal(r.status(),200,path);}
  async function submit(p,button){await Promise.all([p.waitForNavigation(),button.tap()]);}
  async function json(c,path){const r=await c.request.get('https://web/manage/'+path);assert.equal(r.status(),200,path);return r.json();}
  async function layout(p,label,fullPage=true){
   current=prefix+' '+label;
   for(const width of [320,375,390,412,844,1280]){
    await p.setViewportSize({width,height:width===844?390:844});
    check(current+' 幅'+width+'pxの表示と操作寸法',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1 && [...document.querySelectorAll('.office-button,.signout,.portal-brand,summary,.office-form input:not([type="hidden"]),.office-form select')].filter(e=>e.getClientRects().length).every(e=>{const r=e.getBoundingClientRect();return r.height>=44 && r.left>=-1 && r.right<=innerWidth+1;})));
   }
   await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');check(current+' 文字200%で横はみ出しなし',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');await p.setViewportSize(devices[device].viewport);
   await p.screenshot({path:`/artifacts/${name}-member-${hold?'hold':'active'}-${label}.png`,fullPage});
  }
  const owner=await login(uid),p=owner.p;
  if(hold){
   for(const [id,role] of [[uid,'entrant'],[7,'office'],[62,'finance'],[2,'club']]){
    const session=id===uid?owner:await login(id);
    for(const path of ['memberships.php','billing.php']){
     await navigate(session.p,path);check(prefix+' '+role+' '+path+' 書込フォームなし',await session.p.locator('main form[method="post"]').count()===0);
     check(prefix+' '+role+' '+path+' 受付前の案内',await session.p.getByRole('status').count()===1);
     await layout(session.p,role+'-'+path.replace('.php',''),false);
    }
   }
   const mine=await json(owner.c,'memberships.php?format=json'),membership=mine.memberships.find(m=>m.member_type==='general');
   await navigate(p,'billing.php');const invoiceLink=await p.locator('#invoices a').filter({hasText:'General'}).first().getAttribute('href'),before=await json(owner.c,'billing.php'+invoiceLink+'&format=json');
   const staff=await login(62),csrf=await staff.p.locator('[name=_csrf]').first().inputValue();
   for(const action of ['issue','confirm','reverse','reallocate','approve']){const r=await staff.c.request.post('https://web/manage/billing.php?format=json',{form:{_csrf:csrf,action,id:before.id,amount:1,payment:before.allocations[0].payment_id,reference:'MEMBER-HOLD-'+uid,reason:'架空保留',operation:crypto.randomBytes(24).toString('hex')}});check(prefix+' '+action+' 直接送信も409',r.status()===409);}
   const after=await json(owner.c,'billing.php'+invoiceLink+'&format=json');check(prefix+' 保留中の確認で入金明細不変',JSON.stringify(before)===JSON.stringify(after));
   await navigate(p,'billing.php'+invoiceLink);check(prefix+' 保留中は決済ページへのリンクなし',await p.getByRole('link',{name:'決済ページへ',exact:true}).count()===0);await layout(p,'invoice');
   const token=await p.locator('[name=_csrf]').first().inputValue();const denied=await owner.c.request.post('https://web/manage/memberships.php?format=json',{form:{_csrf:token,member_type:'temporary',event_id:103,request_key:crypto.randomBytes(24).toString('hex')}});check(prefix+' 保留中の新規申請を拒否',denied.status()===422);
   assert.ok(membership);
  }else{
   await navigate(p,'memberships.php');check(prefix+' ライセンスなしではGeneralを送信できない',await p.getByRole('button',{name:'Generalを申請',exact:true}).isDisabled());await layout(p,'no-license');
   await navigate(p,'licenses.php');await p.getByLabel('JAFライセンス番号').fill('MEMBER'+uid);await p.getByLabel('ライセンス種類',{exact:true}).fill('国内B');await p.getByLabel('有効期限',{exact:true}).fill('2099-12-31');await submit(p,p.getByRole('button',{name:'保存する',exact:true}));
   const license=(await json(owner.c,'licenses.php?format=json')).licenses.at(-1);
   await navigate(p,'memberships.php');const form=p.getByRole('form',{name:'General申請',exact:true});await form.getByLabel('年度',{exact:true}).selectOption(String(new Date().getUTCFullYear()));await form.getByLabel('所属クラブ',{exact:true}).selectOption('900');await form.getByLabel('登録済みライセンス',{exact:true}).selectOption(String(license.id));
   const initial=await form.evaluate(f=>Object.fromEntries(new FormData(f)));await layout(p,'general-form');await submit(p,form.getByRole('button',{name:'Generalを申請',exact:true}));
   check(prefix+' General申請の受理表示',(await p.getByRole('status').innerText()).includes('申請を受け付けました'));
   let mine=await json(owner.c,'memberships.php?format=json');const membership=mine.memberships.find(m=>m.member_type==='general');assert.ok(membership);
   const repeated=await owner.c.request.post('https://web/manage/memberships.php?format=json',{form:initial});check(prefix+' 同じ申請の再送で重複しない',repeated.status()===200 && (await repeated.json()).id===membership.id);
   await navigate(p,'billing.php');await submit(p,p.getByRole('form',{name:`申請番号 ${membership.id} の請求`,exact:true}).getByRole('button'));let invoicePath=await p.getByRole('link',{name:'最新の資格・入金明細を表示',exact:true}).getAttribute('href');
   // 先行HTTP試験が当年会費を2,500円に変更済み。表示金額を固定の2,000円にしないことも確認する。
   let invoice=await json(owner.c,'billing.php'+invoicePath+'&format=json');check(prefix+' 一度だけ請求し入金前は無効',Number(invoice.amount_yen)===2500 && invoice.balance_yen===2500 && !invoice.eligibility.active);
   await navigate(p,'billing.php');await submit(p,p.getByRole('form',{name:`申請番号 ${membership.id} の請求`,exact:true}).getByRole('button'));check(prefix+' 請求の再表示でも同じ請求ID',(await p.getByRole('link',{name:'最新の資格・入金明細を表示',exact:true}).getAttribute('href'))===invoicePath);
   const ownCsrf=await p.locator('[name=_csrf]').first().inputValue();const denied=await owner.c.request.post('https://web/manage/billing.php?format=json',{form:{_csrf:ownCsrf,action:'confirm',id:invoice.id,amount:2000,reference:'FORBIDDEN',operation:crypto.randomBytes(24).toString('hex')}});check(prefix+' エントラントの入金確認POSTは403',denied.status()===403);
   const club=await login(2),finance=await login(62),office=await login(7);
   for(const [session,role,canPay] of [[owner,'entrant',false],[club,'club',false],[finance,'finance',true],[office,'office',true]]){
    await navigate(session.p,'billing.php');check(prefix+' '+role+' 入金・訂正権限の表示',(await session.p.getByRole('form',{name:'入金確認',exact:true}).count()===1)===canPay && (await session.p.getByRole('form',{name:'充当の訂正',exact:true}).count()===1)===canPay);
   }
   const foreign=await office.p.locator('#invoices a').evaluateAll((links,own)=>links.map(a=>a.getAttribute('href')).find(v=>v!==own),invoicePath);
   check(prefix+' 他人の請求HTMLは403',(await owner.c.request.get('https://web/manage/billing.php'+foreign)).status()===403);
   check(prefix+' クラブ代表は会員本人の請求を読めない',(await club.c.request.get('https://web/manage/billing.php'+invoicePath+'&format=json')).status()===403);
   await layout(club.p,'club-approval',false);await submit(club.p,club.p.getByRole('form',{name:`申請番号 ${membership.id} の所属承認`,exact:true}).getByRole('button'));
   invoice=await json(owner.c,'billing.php'+invoicePath+'&format=json');check(prefix+' クラブの所属承認だけでは資格無効',invoice.eligibility.approved && !invoice.eligibility.active);
   async function confirm(amount,suffix){
    await navigate(finance.p,'billing.php');const f=finance.p.getByRole('form',{name:'入金確認',exact:true});await f.getByLabel('請求ID',{exact:true}).fill(String(invoice.id));await f.getByLabel('入金額',{exact:true}).fill(String(amount));await f.getByLabel('銀行取引番号',{exact:true}).fill('MEMBER-'+uid+'-'+suffix);const data=await f.evaluate(el=>Object.fromEntries(new FormData(el)));await submit(finance.p,f.getByRole('button'));return data;
   }
   const first=await confirm(1000,'A');invoice=await json(owner.c,'billing.php'+invoicePath+'&format=json');check(prefix+' 一部入金では資格無効',invoice.balance_yen===1500 && !invoice.eligibility.active);
   const replay=await finance.c.request.post('https://web/manage/billing.php?format=json',{form:first});check(prefix+' 入金確認の再送で二重計上しない',replay.status()===200 && (await json(owner.c,'billing.php'+invoicePath+'&format=json')).allocations.length===1);
   await confirm(1500,'B');invoice=await json(owner.c,'billing.php'+invoicePath+'&format=json');check(prefix+' 承認と満額入金で資格有効',invoice.balance_yen===0 && invoice.eligibility.active);
   await navigate(finance.p,'billing.php');const reverse=finance.p.getByRole('form',{name:'充当の訂正',exact:true});await reverse.getByLabel('元の充当ID',{exact:true}).fill(String(invoice.allocations[0].id));await reverse.getByLabel('訂正理由',{exact:true}).fill('スマートフォンでの架空入金訂正');await submit(finance.p,reverse.getByRole('button'));
   const reversed=await json(owner.c,'billing.php'+invoicePath+'&format=json');check(prefix+' 入金訂正で資格が無効に戻る',reversed.balance_yen===1000 && !reversed.eligibility.active && reversed.allocations.some(a=>a.reverses_id!==null));
   await navigate(p,'billing.php'+invoicePath);await layout(p,'reversed-invoice');
   await navigate(finance.p,'billing.php');const reallocate=finance.p.getByRole('form',{name:'訂正後の再充当',exact:true});await reallocate.getByLabel('請求ID',{exact:true}).fill(String(invoice.id));await reallocate.getByLabel('入金ID',{exact:true}).fill(String(invoice.allocations[0].payment_id));await reallocate.getByLabel('充当額',{exact:true}).fill('1000');await submit(finance.p,reallocate.getByRole('button'));
   invoice=await json(owner.c,'billing.php'+invoicePath+'&format=json');check(prefix+' 同じ請求への再充当で資格を再判定',invoice.balance_yen===0 && invoice.eligibility.active);
   await navigate(p,'billing.php'+invoicePath);check(prefix+' 請求額と資格を画面で確認',await p.locator('[data-amount=total]').innerText()==='2,500 円' && await p.locator('[data-state=active]').innerText()==='有効');await layout(p,'paid-invoice');
   await navigate(finance.p,'billing.php'+invoicePath);await layout(finance.p,'finance-invoice',false);await layout(office.p,'office-invoices',false);
   await navigate(p,'memberships.php');const temp=p.getByRole('form',{name:'Temporary申請',exact:true});await temp.getByLabel('イベント',{exact:true}).selectOption('101');await submit(p,temp.getByRole('button'));mine=await json(owner.c,'memberships.php?format=json');check(prefix+' Temporaryは主催承認待ち',mine.memberships.some(m=>m.member_type==='temporary' && m.application_status==='submitted' && !m.eligibility.active));
   const csrf=await p.locator('[name=_csrf]').first().inputValue();const duplicate=await owner.c.request.post('https://web/manage/memberships.php?format=json',{form:{_csrf:csrf,member_type:'temporary',event_id:102,request_key:crypto.randomBytes(24).toString('hex')}});check(prefix+' Temporaryの同日別イベント申請を拒否',duplicate.status()===409);await layout(p,'applications');
   // 別の請求を閲覧中に発行しても、旧GETパラメーターに引きずられず操作対象を表示する。
   const temporary=mine.memberships.find(m=>m.member_type==='temporary');await navigate(p,'billing.php'+invoicePath);await submit(p,p.getByRole('form',{name:`申請番号 ${temporary.id} の請求`,exact:true}).getByRole('button'));const issued=await p.getByRole('link',{name:'最新の資格・入金明細を表示',exact:true}).getAttribute('href');const tempInvoice=await json(owner.c,'billing.php'+issued+'&format=json');check(prefix+' 別の請求詳細から発行した対象を表示',Number(tempInvoice.membership_id)===Number(temporary.id) && Number(tempInvoice.amount_yen)===500 && tempInvoice.id!==invoice.id);
  }
  await navigate(p,'memberships.php');await Promise.all([p.waitForURL('https://web/manage/'),p.getByRole('link',{name:'マイページへ戻る',exact:true}).tap()]);check(prefix+' マイページへ戻る',new URL(p.url()).pathname==='/manage/');
  await navigate(p,'billing.php');await Promise.all([p.waitForURL('https://web/entry/login.php'),p.getByRole('button',{name:'サインアウト',exact:true}).tap()]);for(const path of ['memberships.php','billing.php'])check(prefix+' 未認証の'+path+'は401',(await owner.c.request.get('https://web/manage/'+path)).status()===401);
 }catch(e){throw new Error(current+' / '+String(e));}finally{for(const c of contexts)await c.close();await b.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7',181);await run(webkit,'webkit','iPhone 13',182);process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,hold,javascript_disabled:true}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',lastPassed:cases.at(-1)?.case,error:String(e)}));process.exit(1);});
