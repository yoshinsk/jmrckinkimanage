// Path: tests/license/dashboard_browser.cjs
// 機能: マイページの通常設定・保留設定を、架空アカウントと実ブラウザーのタッチ/キーボードで確認する。
'use strict';
const {chromium,webkit,devices}=require('playwright'),assert=require('node:assert/strict');
const cases=[],browsers=[],hold=process.env.DASHBOARD_HOLD==='1';
function check(name,value){assert.ok(value,name);cases.push({case:name,result:'PASS'});}
async function run(engine,name,device){
    const browser=await engine.launch({headless:true});
    try {
        browsers.push({engine:name,version:browser.version(),device});
        for(const [uid,role] of [[21,'entrant'],[7,'office'],[2,'club']]){
            // 保留設定ではJavaScriptを無効化。開閉・画面遷移・ログアウトがHTMLだけで動くことも確認する。
            const context=await browser.newContext({...devices[device],locale:'ja-JP',ignoreHTTPSErrors:true,javaScriptEnabled:!hold});
            await context.route('**/*',r=>r.request().url()==='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'?r.fulfill({path:__dirname+'/vendor/bootstrap-5.3.3.min.css',contentType:'text/css'}):new URL(r.request().url()).hostname==='web'?r.continue():r.abort());
            const page=await context.newPage(),prefix=`${name} ${hold?'保留':'通常'} ${role}`;
            await page.goto('https://web/manage/');await page.getByLabel('メールアドレス').fill(`u${uid}@example.invalid`);await page.getByLabel('パスワード').fill('lab-password-only');
            await Promise.all([page.waitForURL('https://web/manage/'),page.getByRole('button',{name:'サインイン',exact:true}).tap()]);
            check(prefix+' 本人アカウントを表示',(await page.locator('.account-email').innerText())===`u${uid}@example.invalid`);
            for(const width of [320,375,390,412,844,1280]){
                await page.setViewportSize({width,height:width===844?390:844});
                check(prefix+' 幅'+width+'pxで横はみ出しなし',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
                check(prefix+' 幅'+width+'pxで操作対象44px以上',await page.evaluate(()=>[...document.querySelectorAll('.portal-brand,.signout,.entry-link,.menu-link,summary')].every(e=>e.getBoundingClientRect().height>=44)));
            }
            await page.setViewportSize(devices[device].viewport);
            check(prefix+' 事務局メニューの出し分け',(await page.locator('a[href="/manage/bank-settings.php"]').count()===1)===(role==='office'));
            if(hold){
                check(prefix+' 保留中の書込導線がリンクにならない',await page.locator('a[href="/manage/memberships.php"],a[href="/manage/billing.php"],a[href="/manage/bank.php"],a[href="/manage/insurance.php"],a[href="/manage/licenses.php"]').count()===0);
                await page.locator('summary').tap();check(prefix+' 準備中をタッチで展開',await page.locator('details').getAttribute('open')!==null);
                check(prefix+' 展開後も横はみ出しなし',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
                await page.locator('summary').focus();await page.keyboard.press('Enter');check(prefix+' 準備中をキーボードで閉じる',await page.locator('details').getAttribute('open')===null);
            } else {
                check(prefix+' 切替済み年度の会員導線',await page.locator('a[href="/manage/memberships.php"]').count()===1);
                check(prefix+' クラブ権限の導線',(await page.locator('a[href="/manage/clubs.php"]').count()===1)===(role!=='entrant'));
            }
            // 長いメールアドレス・文字拡大はDOM上の表示だけを変更し、DBに書き込まない。
            await page.evaluate(()=>{document.querySelector('.account-email').textContent='a'.repeat(64)+'@'+'b'.repeat(63)+'.example.invalid';document.documentElement.style.fontSize='200%';});
            await page.setViewportSize({width:320,height:844});check(prefix+' 長いメールと200%文字でも横はみ出しなし',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
            await page.reload();await page.setViewportSize(devices[device].viewport);
            await page.screenshot({path:`/artifacts/${name}-dashboard-${hold?'hold':'active'}-${role}.png`,fullPage:true});
            // 本人のentry既定先へ移り、同じセッションで戻れることを確認する。
            await Promise.all([page.waitForURL(role==='club'?'https://web/entry/club/events.php':'https://web/entry/entrant/events.php'),page.getByRole('link',{name:'オンラインエントリーへ',exact:true}).tap()]);
            check(prefix+' 既存entryへタッチ移動',new URL(page.url()).pathname.endsWith('/events.php'));
            await page.goto('https://web/manage/');await Promise.all([page.waitForURL('https://web/entry/login.php'),page.getByRole('button',{name:'サインアウト',exact:true}).tap()]);
            await page.goto('https://web/manage/');check(prefix+' 共通ログアウト後は再認証',new URL(page.url()).pathname==='/entry/login.php');
            await context.close();
        }
    }finally{await browser.close();}
}
(async()=>{await run(chromium,'chromium','Pixel 7');await run(webkit,'webkit','iPhone 13');process.stdout.write(JSON.stringify({status:'PASS',hold,browsers,cases}));})().catch(e=>{process.stderr.write(JSON.stringify({status:'FAIL',error:String(e)}));process.exit(1);});
