// Path: public/manage/assets/card-check.js
// 機能: QR断片を同一オリジンPOSTだけへ渡し、個人情報を伴わない最新資格の真偽を表示する。
'use strict';
const token=location.hash.slice(1);history.replaceState(null,'',location.pathname);
const statusNode=document.getElementById('verification-status');let checking=false,epoch=0;
async function verifyCard() {
    if(checking || document.hidden) {return;}checking=true;const generation=epoch;
    statusNode.textContent='照会中です。';statusNode.removeAttribute('data-active');
    try {
        const response=await fetch('/manage/card-verify.php',{method:'POST',body:new URLSearchParams({token}),cache:'no-store',credentials:'omit',referrerPolicy:'no-referrer'});
        if(!response.ok) {throw new Error('unavailable');}const result=await response.json();
        if(document.hidden || generation!==epoch) {return;}
        statusNode.textContent=result.active===true?'現在有効です。':'現在有効な資格を確認できません。';statusNode.dataset.active=String(result.active===true);
    }catch {statusNode.textContent='照会できません。通信状態を確認して再照会してください。';}finally {checking=false;}
}
document.getElementById('check-again').addEventListener('click',verifyCard);setInterval(verifyCard,15000);
document.addEventListener('visibilitychange',()=>{epoch++;statusNode.textContent='資格を再確認してください。';statusNode.removeAttribute('data-active');if(!document.hidden) {verifyCard();}});
window.addEventListener('pagehide',()=>{epoch++;statusNode.textContent='資格を再確認してください。';statusNode.removeAttribute('data-active');});
window.addEventListener('pageshow',event=>{if(event.persisted) {verifyCard();}});verifyCard();
