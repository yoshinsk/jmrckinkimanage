// Path: public/manage/assets/club-intake.js
// 機能: 更新候補の入力補助と代表者兼連絡先の省略。候補選択による認可や事務局確認は行わない。
'use strict';
(() => {
  const candidate=document.querySelector('#club-candidate_ref');
  candidate?.addEventListener('change',()=>{
    const option=candidate.selectedOptions[0];
    if(!option?.dataset.name)return;
    for(const [id,value] of [['club-club_name',option.dataset.name],['club-club_short',option.dataset.short]]){
      const input=document.getElementById(id);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));
    }
    document.getElementById('club-registration_kind').value='renewal';
  });
  const same=document.querySelector('#club-same_contact');
  const copyState=()=>document.querySelectorAll('[data-contact]').forEach(input=>{input.disabled=same.value==='1';input.required=same.value!=='1';});
  same?.addEventListener('change',copyState);if(same)copyState();
})();
