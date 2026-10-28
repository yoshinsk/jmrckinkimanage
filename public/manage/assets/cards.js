// Path: public/manage/assets/cards.js
// 機能: 会員証QRを端末内で生成し、表示中も資格を確認。停止/通信障害時は表示内容を消去する。
'use strict';
const JmrcCardQr = {
    /** QRには不透明トークンのURLだけを符号化。4セルの余白を含めSVGへ描画する。 */
    matrix(url) {
        const qr=qrcodegen.QrCode.encodeText(url,qrcodegen.QrCode.Ecc.MEDIUM);
        return Array.from({length:qr.size},(_,y)=>Array.from({length:qr.size},(_,x)=>qr.getModule(x,y)));
    },
    render(container,url) {
        const cells=this.matrix(url),size=cells.length+8,ns='http://www.w3.org/2000/svg';
        const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox',`0 0 ${size} ${size}`);svg.setAttribute('role','img');svg.setAttribute('aria-label','資格照会QR');
        const background=document.createElementNS(ns,'rect');background.setAttribute('width',String(size));background.setAttribute('height',String(size));background.setAttribute('fill','#fff');svg.append(background);
        const path=document.createElementNS(ns,'path');path.setAttribute('fill','#000');
        path.setAttribute('d',cells.flatMap((row,y)=>row.flatMap((dark,x)=>dark?[`M${x+4},${y+4}h1v1h-1z`]:[])).join(''));
        svg.append(path);container.replaceChildren(svg);
    }
};
if(typeof document!=='undefined') {
    const page=document.getElementById('card-page'),card=document.getElementById('digital-card'),qr=document.getElementById('card-qr'),status=document.getElementById('card-status');
    if(page?.dataset.membershipId && card && qr && status) {
        let busy=false,epoch=0;
        function clear(message) {
            card.hidden=true;qr.replaceChildren();delete qr.dataset.qrUrl;
            card.querySelectorAll('[data-card-field]').forEach(node=>node.textContent='');status.textContent=message;
        }
        async function refresh() {
            if(busy || document.hidden) {return;}busy=true;const generation=epoch;
            clear('最新の資格を確認中です。');
            try {
                const r=await fetch(`/manage/card.php?format=json&id=${encodeURIComponent(page.dataset.membershipId)}`,{cache:'no-store',credentials:'same-origin',referrerPolicy:'no-referrer'});
                if(!r.ok) {throw new Error('unavailable');}const data=await r.json();
                if(document.hidden || generation!==epoch) {return;}
                if(!data.available) {clear('会員証は現在表示できません。');return;}
                card.querySelectorAll('[data-card-field]').forEach(node=>node.textContent=String(data[node.dataset.cardField]??''));
                JmrcCardQr.render(qr,data.qr_url);card.hidden=false;status.textContent='現在有効な会員証です。';
            }catch {clear('資格を確認できません。再度読み込んでください。');}finally {busy=false;}
        }
        if(qr.dataset.qrUrl) {JmrcCardQr.render(qr,qr.dataset.qrUrl);}setInterval(refresh,15000);
        document.addEventListener('visibilitychange',()=>{epoch++;if(document.hidden) {clear('資格を再確認してください。');}else {refresh();}});
        window.addEventListener('pagehide',()=>{epoch++;clear('資格を再確認してください。');});
        window.addEventListener('pageshow',event=>{if(event.persisted) {refresh();}});
    }
}
