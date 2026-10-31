// Path: tests/license/information_offline_browser.cjs
// 機能: 配布用単一HTMLの導線・折り畳み・狭幅を、外部ネットワークのない実ブラウザーで確認する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict');
const cases=[],browsers=[];
function check(name,ok){assert.ok(ok,name);cases.push({case:name,result:'PASS'});}
(async()=>{
 for(const [engine,name,device] of [[chromium,'chromium','Pixel 7'],[webkit,'webkit','iPhone 13']]){
  const browser=await engine.launch({headless:true});
  try{
   browsers.push({engine:name,version:browser.version()});
   const c=await browser.newContext({...devices[device],javaScriptEnabled:false,locale:'ja-JP'});
   const p=await c.newPage(),external=[];
   p.on('request',r=>{if(/^https?:/.test(r.url()))external.push(r.url());});
   await p.goto('file:///project/Documents/previews/20261031_旧HP情報ページ.html');
   check(name+' 配布版は全5区画',await p.locator('.info-section').count()===5);
   check(name+' 24件の詳細がある',await p.locator('details[id^="HP-"]').count()===24);
   check(name+' 送信フォームとスクリプトなし',await p.locator('form,script').count()===0);
   check(name+' 既存アカウント情報なし',!(await p.locator('body').innerText()).includes('@example.invalid'));
   await p.getByRole('navigation',{name:'情報ページの種類'}).getByRole('link',{name:'部会・関連リンク',exact:true}).tap();
   check(name+' 関連リンクへ移動',p.url().endsWith('#links'));
   await p.locator('#HP-20 > summary').tap();
   check(name+' 分類保留の詳細を開ける',await p.locator('#HP-20 .info-body').isVisible());
   check(name+' 発行団体と表示分類を分離',(await p.locator('#HP-20').innerText()).includes('原本確認待ち')&&(await p.locator('#HP-20').innerText()).includes('Q13'));
   await p.locator('#HP-20 .info-sources summary').tap();
   check(name+' 旧資料参照元を開ける',await p.locator('#HP-20 .info-source-link').first().isVisible());
   for(const width of [320,390,844,1280]){
    await p.setViewportSize({width,height:900});
    check(name+' 配布版幅'+width,await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   }
   await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='200%');
   check(name+' 配布版200%文字',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.evaluate(()=>document.documentElement.style.fontSize='');
   await p.locator('#HP-20 > summary').tap();await p.setViewportSize(devices[device].viewport);await p.goto('file:///project/Documents/previews/20261031_旧HP情報ページ.html');
   await p.screenshot({path:`/artifacts/${name}-information-offline-mobile.png`});
   await p.setViewportSize({width:1280,height:900});await p.screenshot({path:`/artifacts/${name}-information-offline-desktop.png`});
   check(name+' 配布版の自動外部通信なし',external.length===0);await c.close();
  }finally{await browser.close();}
 }
 process.stdout.write(JSON.stringify({status:'PASS',browsers,cases,network:'none',javascript_disabled:true}));
})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',error:String(e),stack:e.stack}));process.exit(1);});
