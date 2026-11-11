/**
 * หน้าแก้ฟอร์ม: ส่ง JSON ไปตรวจ/สร้าง preview ที่ admin/form_preview.php ทุกครั้งที่พิมพ์ (หน่วง 400ms)
 */
(function () {
  'use strict';

  var editor = document.querySelector('[data-editor]');
  if (!editor) return;

  var textarea = editor.querySelector('[data-schema]');
  var preview = editor.querySelector('[data-preview]');
  var status = editor.querySelector('[data-editor-status]');
  var csrf = editor.querySelector('input[name=_csrf]').value;
  var url = editor.dataset.previewUrl;
  var timer = null, seq = 0;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function render(data) {
    if (!data.ok) {
      status.className = 'editor-status is-error';
      status.innerHTML = '<strong>มีข้อผิดพลาด ' + data.errors.length + ' รายการ</strong><ul>'
        + data.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
      return;
    }
    status.className = 'editor-status is-ok';
    status.textContent = '✓ JSON ถูกต้อง';
    preview.innerHTML = '<h1>' + esc(data.title) + '</h1>'
      + (data.description ? '<p class="description">' + esc(data.description) + '</p>' : '')
      + '<div class="jf-form" data-jf-preview>' + data.html + '</div>';
    if (window.JF) window.JF.init(preview.querySelector('[data-jf-preview]'));
  }

  function refresh() {
    var mine = ++seq;
    var body = new FormData();
    body.append('_csrf', csrf);
    body.append('schema', textarea.value);
    fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) { if (mine === seq) render(data); })
      .catch(function (err) {
        if (mine !== seq) return;
        status.className = 'editor-status is-error';
        status.textContent = 'โหลด preview ไม่ได้ (' + err.message + ') — session อาจหมดอายุ ลองบันทึกหรือโหลดหน้าใหม่';
      });
  }

  textarea.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(refresh, 400);
  });

  // Tab ใส่ช่องว่าง 2 ตัวแทนการย้ายโฟกัส
  textarea.addEventListener('keydown', function (e) {
    if (e.key === 'Tab' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
      e.preventDefault();
      var s = textarea.selectionStart, end = textarea.selectionEnd;
      textarea.setRangeText('  ', s, end, 'end');
    }
  });

  // ป้องกันปิดหน้าโดยไม่ได้บันทึก
  var original = textarea.value;
  var submitting = false;
  editor.addEventListener('submit', function () { submitting = true; });
  window.addEventListener('beforeunload', function (e) {
    if (!submitting && textarea.value !== original) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  refresh();
})();
