// Path: public/manage/assets/club-intake.js
// 機能: 更新候補の入力補助と代表者兼連絡先の省略。候補選択による認可や事務局確認は行わない。
'use strict';
(() => {
  const candidate=document.querySelector('#club-candidate_ref');
  candidate?.addEventListener('change',()=>{
    const option=candidate.selectedOptions[0];
    if(!option?.dataset.name)return;
    // 不明な候補へ切り替えた際、直前の候補の番号・格式を残さない。認可・料金確定はサーバーで行う。
    for(const [id,value] of [['club-club_name',option.dataset.name],['club-club_short',option.dataset.short],['club-jaf_number',option.dataset.jafNumber||''],['club-jaf_kind',option.dataset.jafKind||'']]){
      const input=document.getElementById(id);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));
    }
    document.getElementById('club-registration_kind').value='renewal';
    document.getElementById('candidate-help').textContent=option.dataset.candidateHelp||'番号と格式は登録資料を確認して入力してください。';
  });
  const same=document.querySelector('#club-same_contact');
  const copyState=()=>document.querySelectorAll('[data-contact]').forEach(input=>{input.disabled=same.value==='1';input.required=same.value!=='1';});
  same?.addEventListener('change',copyState);if(same)copyState();
})();
