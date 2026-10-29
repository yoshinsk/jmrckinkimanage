// Path: tests/card/verify_qr.cjs
// 機能: 実SVGを画素化して独立QRデコーダーで復号し、ブラウザー照会/表示停止のJSを検証する。
'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),jsQR=require('jsqr');
const root=path.resolve(__dirname,'../..'),assets=path.join(root,'public/manage/assets');
const library=fs.readFileSync(path.join(assets,'qrcodegen.js'),'utf8'),privateJs=fs.readFileSync(path.join(assets,'cards.js'),'utf8');
const results=[];function check(name,condition){assert.ok(condition,name);results.push({case:name,result:'PASS'});}
class Element {
    constructor(){this.dataset={};this.attributes={};this.children=[];this.textContent='';this.hidden=false;this.handlers={};}
    setAttribute(k,v){this.attributes[k]=v;}removeAttribute(k){delete this.attributes[k];if(k==='data-active'){delete this.dataset.active;}}
    append(n){this.children.push(n);}replaceChildren(...nodes){this.children=nodes;}addEventListener(k,fn){this.handlers[k]=fn;}
}
const doc={getElementById:()=>null,createElementNS:()=>new Element()};
const encoder=vm.createContext({document:doc});vm.runInContext(library+'\n'+privateJs+'\nglobalThis.qrRenderer=JmrcCardQr;',encoder);
const fixtures=JSON.parse(fs.readFileSync(0,'utf8'));
for(const [index,url] of fixtures.entries()) {
    const container=new Element();encoder.qrRenderer.render(container,url);const svg=container.children[0];
    const size=Number(svg.attributes.viewBox.split(' ')[2]),scale=6,width=size*scale,pixels=new Uint8ClampedArray(width*width*4).fill(255);
    const points=[...svg.children[1].attributes.d.matchAll(/M(\d+),(\d+)h1v1h-1z/g)].map(m=>[Number(m[1]),Number(m[2])]);
    check(`QR余白4セル ${index}`,points.every(([x,y])=>x>=4 && y>=4 && x<size-4 && y<size-4));
    for(const [x,y] of points){for(let dy=0;dy<scale;dy++){for(let dx=0;dx<scale;dx++){const i=((y*scale+dy)*width+x*scale+dx)*4;pixels[i]=pixels[i+1]=pixels[i+2]=0;}}}
    const decoded=jsQR(pixels,width,width,{inversionAttempts:'dontInvert'});
    check(`SVG実画素のQR復号一致 ${index}`,decoded?.data===url);
    check(`QRに個人項目を含めない ${index}`,/^https:\/\/web\/manage\/card-check\.php#[a-f0-9]{64}$/.test(decoded.data));
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
async function main(){
    const status=new Element(),button=new Element(),publicName=new Element(),publicNumber=new Element(),details=new Element(),events={},windowEvents={},calls=[];let mode='valid',interval;
    const document={hidden:false,getElementById:id=>({'verification-status':status,'verification-name':publicName,'verification-number':publicNumber,'verification-details':details}[id]??button),addEventListener:(k,fn)=>events[k]=fn};
    const context=vm.createContext({document,window:{addEventListener:(k,fn)=>windowEvents[k]=fn},location:{hash:'#'+'a'.repeat(64),pathname:'/manage/card-check.php'},history:{replaceState:(...args)=>calls.push(['history',...args])},URLSearchParams,setInterval:fn=>{interval=fn;},fetch:async(url,options)=>{calls.push(['fetch',url,options]);if(mode==='offline'){throw Error();}return {ok:true,json:async()=>({active:mode==='valid',name:'架空<氏名>',member_number:'TEST-G-100001'})};}});
    vm.runInContext(fs.readFileSync(path.join(assets,'card-check.js'),'utf8'),context);await flush();
    check('公開照会JSが有効を表示',status.dataset.active==='true');
    check('公開氏名・番号をtextContentで表示',publicName.textContent==='架空<氏名>' && publicNumber.textContent==='TEST-G-100001' && !details.hidden);
    const request=calls.find(c=>c[0]==='fetch');
    check('断片をPOSTだけで送信しCookieを送らない',request[1]==='/manage/card-verify.php' && request[2].method==='POST' && request[2].credentials==='omit' && request[2].body.get('token')==='a'.repeat(64));
    check('アドレスバーから断片を除去',calls[0].at(-1)==='/manage/card-check.php');
    mode='inactive';await interval();check('無効時は氏名・番号も消去',details.hidden && publicName.textContent==='' && publicNumber.textContent==='');check('再照会で無効を表示',status.dataset.active==='false');
    mode='offline';await interval();check('通信障害時に有効を残さない',status.dataset.active===undefined && status.textContent.includes('照会できません'));
    mode='valid';await interval();document.hidden=true;events.visibilitychange();check('非表示時に照会結果を消去',status.dataset.active===undefined);
    document.hidden=false;events.visibilitychange();await flush();check('復帰時に再照会',status.dataset.active==='true');
    let complete;context.fetch=()=>new Promise(resolve=>{complete=resolve;});const pending=interval();document.hidden=true;events.visibilitychange();complete({ok:true,json:async()=>({active:true})});await pending;
    check('遅延レスポンスで非表示の有効結果を復活させない',status.dataset.active===undefined);

    const page=new Element(),card=new Element(),qr=new Element(),privateStatus=new Element(),fields=['year','member_number','club_abbreviation','license_type'].map(key=>{const n=new Element();n.dataset.cardField=key;n.textContent='old';return n;});
    page.dataset.membershipId='42';qr.dataset.qrUrl=fixtures[0];card.querySelectorAll=()=>fields;let privateMode='inactive',refresh;
    const nodes={'card-page':page,'digital-card':card,'card-qr':qr,'card-status':privateStatus};
    const privateContext=vm.createContext({document:{hidden:false,getElementById:id=>nodes[id],createElementNS:()=>new Element(),addEventListener:()=>{}},window:{addEventListener:()=>{}},setInterval:fn=>{refresh=fn;},fetch:async()=>{if(privateMode==='offline'){throw Error();}return{ok:true,json:async()=>({available:privateMode==='valid',year:2026,member_number:'fixture',club_abbreviation:'fixture',license_type:'fixture',qr_url:fixtures[0]})};}});
    vm.runInContext(library+'\n'+privateJs,privateContext);await refresh();
    check('本人画面の無効化で番号とQRを消去',card.hidden && qr.children.length===0 && fields.every(n=>n.textContent===''));
    privateMode='valid';await refresh();check('本人画面の再照会で有効表示',!card.hidden && qr.children.length===1);
    privateMode='offline';await refresh();check('本人画面も通信障害時は表示停止',card.hidden && fields.every(n=>n.textContent===''));
    process.stdout.write(JSON.stringify({status:'PASS',decoder:'jsqr@1.4.0',cases:results}));
}
main().catch(error=>{process.stderr.write(String(error));process.exit(1);});
