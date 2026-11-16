// Path: public/manage/assets/input-normalization.js
// 機能: 全画面共通の英数字・全角空白整形。サーバー側でも同じ処理を行い、ローマ数字・認証値・原本を保持する。
'use strict';
(() => {
  const exact = new Set(['password','password_confirm','password_confirmation','current_password','new_password','confirm_password','token','_csrf','signature','request_key','instance','csv_text']);
  const normalize = value => value.replace(/[Ａ-Ｚａ-ｚ０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/\u3000/g, ' ');
  const apply = element => {
    if (!(element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement)) return;
    const name = element.name.match(/\[([^\]]+)\]$/)?.[1] || element.name;
    if (exact.has(name) || element instanceof HTMLInputElement && !['text','search','email','tel','url','number'].includes(element.type)) return;
    const next = normalize(element.value);
    if (next !== element.value) {
      const start = element.selectionStart, end = element.selectionEnd;
      element.value = next;
      if (start !== null && end !== null && (element instanceof HTMLTextAreaElement || ['text','search','tel','url'].includes(element.type))) element.setSelectionRange(start,end);
    }
  };
  document.addEventListener('input', event => { if (!event.isComposing) apply(event.target); });
  document.addEventListener('compositionend', event => apply(event.target));
  document.addEventListener('focusout', event => apply(event.target));
  document.addEventListener('submit', event => event.target.querySelectorAll('input,textarea').forEach(apply), true);
})();
