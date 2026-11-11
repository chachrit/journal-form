/**
 * Journal Form — ฝั่งเบราว์เซอร์
 * 1. show_if: ซ่อน/แสดงช่องตามเงื่อนไข (ต้องให้ผลตรงกับ lib/ConditionEvaluator.php)
 * 2. ลายเซ็น: วาดบน canvas แล้วเก็บเป็น PNG base64 ใน input hidden
 * 3. เตือนไฟล์ใหญ่เกินก่อนส่ง
 *
 * เรียก JF.init(container) ซ้ำได้ (หน้า editor เรียกทุกครั้งที่ preview เปลี่ยน)
 */
(function () {
  'use strict';

  // ---------------------------------------------------------------- conditions

  function str(v) {
    return v === null || v === undefined || typeof v === 'object' ? '' : String(v);
  }

  function isEmpty(v) {
    return v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
  }

  function equals(actual, expected) {
    if (Array.isArray(actual)) return actual.map(str).indexOf(str(expected)) !== -1;
    return str(actual) === str(expected);
  }

  function inList(actual, list) {
    list = (Array.isArray(list) ? list : [list]).map(str);
    if (Array.isArray(actual)) return actual.some(function (a) { return list.indexOf(str(a)) !== -1; });
    return list.indexOf(str(actual)) !== -1;
  }

  function isNumeric(s) {
    return s.trim() !== '' && !isNaN(Number(s));
  }

  function compare(actual, expected) {
    var a = str(actual), b = str(expected);
    if (a === '' || b === '') return null;
    if (isNumeric(a) && isNumeric(b)) {
      var x = Number(a), y = Number(b);
      return x < y ? -1 : (x > y ? 1 : 0);
    }
    return a < b ? -1 : (a > b ? 1 : 0);
  }

  function evaluate(cond, values) {
    if (!cond) return true;
    if (cond.all) return cond.all.every(function (c) { return evaluate(c, values); });
    if (cond.any) return cond.any.some(function (c) { return evaluate(c, values); });

    var actual = values[cond.field];
    var expected = cond.value;
    switch (cond.op || 'eq') {
      case 'eq': return equals(actual, expected);
      case 'neq': return !equals(actual, expected);
      case 'in': return inList(actual, expected);
      case 'not_in': return !inList(actual, expected);
      case 'empty': return isEmpty(actual);
      case 'not_empty': return !isEmpty(actual);
      case 'gt': return compare(actual, expected) === 1;
      case 'lt': return compare(actual, expected) === -1;
      case 'contains':
        if (Array.isArray(actual)) return equals(actual, expected);
        return str(expected) !== '' && str(actual).indexOf(str(expected)) !== -1;
      default: return false;
    }
  }

  /** ค่าปัจจุบันของช่อง (อ่านจาก element ภายใน wrapper) */
  function readValue(wrap) {
    var type = (wrap.className.match(/field-(\w+)/) || [])[1];
    var inputs = wrap.querySelectorAll('input, select, textarea');
    if (type === 'checkbox') {
      return Array.prototype.filter.call(inputs, function (i) { return i.checked; }).map(function (i) { return i.value; });
    }
    if (type === 'radio') {
      var checked = Array.prototype.find.call(inputs, function (i) { return i.checked; });
      return checked ? checked.value : '';
    }
    if (type === 'file') {
      return inputs[0] && inputs[0].files && inputs[0].files.length ? 'file' : '';
    }
    if (type === 'signature') {
      var hidden = wrap.querySelector('input[type=hidden]');
      return hidden && hidden.value ? 'signed' : '';
    }
    return inputs[0] ? inputs[0].value.trim() : '';
  }

  function applyConditions(form) {
    var effective = {};
    form.querySelectorAll('.field').forEach(function (wrap) {
      var cond = wrap.dataset.showIf ? JSON.parse(wrap.dataset.showIf) : null;
      var show = evaluate(cond, effective);
      if (wrap.hidden === show) {
        wrap.hidden = !show;
        // ช่องที่ซ่อน: disable เพื่อไม่ให้ส่งค่าและไม่ติด required ของเบราว์เซอร์
        wrap.querySelectorAll('input, select, textarea, button').forEach(function (el) {
          if (el.name === '_csrf') return;
          el.disabled = !show;
        });
      }
      if (wrap.dataset.name) {
        effective[wrap.dataset.name] = show ? readValue(wrap) : null;
      }
    });
  }

  // ---------------------------------------------------------------- signature

  function initSignature(box) {
    if (box.dataset.ready) return;
    box.dataset.ready = '1';

    var canvas = box.querySelector('canvas');
    var hidden = box.querySelector('input[type=hidden]');
    var ctx = canvas.getContext('2d');
    var drawing = false, last = null, dirty = false;

    function resize() {
      var ratio = Math.max(window.devicePixelRatio || 1, 1);
      var w = canvas.offsetWidth, h = canvas.offsetHeight;
      if (!w) return;
      canvas.width = w * ratio;
      canvas.height = h * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth = 2.2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#111';
      // วาดลายเซ็นเดิมกลับ (เช่น หลังส่งแล้วมี error)
      if (hidden.value) {
        var img = new Image();
        img.onload = function () { ctx.drawImage(img, 0, 0, w, h); };
        img.src = hidden.value;
      }
    }

    function pos(e) {
      var r = canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    canvas.addEventListener('pointerdown', function (e) {
      if (hidden.disabled) return;
      drawing = true;
      last = pos(e);
      canvas.setPointerCapture(e.pointerId);
      ctx.beginPath();
      ctx.arc(last.x, last.y, 1, 0, Math.PI * 2);
      ctx.fillStyle = '#111';
      ctx.fill();
      dirty = true;
      e.preventDefault();
    });
    canvas.addEventListener('pointermove', function (e) {
      if (!drawing) return;
      var p = pos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last = p;
      e.preventDefault();
    });
    function end() {
      if (!drawing) return;
      drawing = false;
      if (dirty) {
        hidden.value = canvas.toDataURL('image/png');
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
    canvas.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);

    box.querySelector('[data-signature-clear]').addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hidden.value = '';
      dirty = false;
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    });

    resize();
    window.addEventListener('resize', function () {
      // ขนาดเปลี่ยนแล้ว canvas ถูกล้าง → วาดจากค่าที่เก็บไว้ใหม่
      if (canvas.offsetWidth * Math.max(window.devicePixelRatio || 1, 1) !== canvas.width) resize();
    });
  }

  // ---------------------------------------------------------------- submit checks

  function checkBeforeSubmit(form, e) {
    var problems = [];
    form.querySelectorAll('.field:not([hidden])').forEach(function (wrap) {
      var label = (wrap.querySelector('.label') || {}).textContent || '';
      label = label.replace('*', '').trim();

      var sig = wrap.querySelector('[data-signature] input[type=hidden]');
      if (sig && wrap.querySelector('.req') && !sig.value) problems.push('กรุณาเซ็นชื่อ: ' + label);

      if (wrap.classList.contains('field-checkbox') && wrap.querySelector('.req')
        && !wrap.querySelector('input:checked')) problems.push('กรุณาเลือกอย่างน้อย 1 ข้อ: ' + label);

      var file = wrap.querySelector('input[type=file]');
      if (file && file.files) {
        var maxBytes = parseFloat(file.dataset.maxMb || '10') * 1048576;
        Array.prototype.forEach.call(file.files, function (f) {
          if (f.size > maxBytes) problems.push('ไฟล์ "' + f.name + '" ใหญ่เกิน ' + file.dataset.maxMb + ' MB');
        });
      }
    });
    var box = form.querySelector('[data-jf-problems]');
    if (problems.length) {
      e.preventDefault();
      if (!box) {
        box = document.createElement('div');
        box.className = 'alert alert-error';
        box.setAttribute('data-jf-problems', '');
        box.setAttribute('role', 'alert');
        form.insertBefore(box, form.firstChild);
      }
      box.innerHTML = '<ul>' + problems.map(function (p) {
        var li = document.createElement('li');
        li.textContent = p;
        return li.outerHTML;
      }).join('') + '</ul>';
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    if (box) box.remove();
    var btn = form.querySelector('button[type=submit]');
    if (btn) {
      // กันกดส่งซ้ำ
      setTimeout(function () { btn.disabled = true; btn.textContent = 'กำลังส่ง…'; }, 0);
    }
  }

  // ---------------------------------------------------------------- init

  function init(root) {
    var form = root.matches && root.matches('[data-jf-form]') ? root : root.querySelector('[data-jf-form]') || root;
    form.querySelectorAll('[data-signature]').forEach(initSignature);
    applyConditions(form);
    if (!form.dataset.jfBound) {
      form.dataset.jfBound = '1';
      form.addEventListener('input', function () { applyConditions(form); });
      form.addEventListener('change', function () { applyConditions(form); });
      if (form.tagName === 'FORM' && form.hasAttribute('data-jf-form')) {
        form.addEventListener('submit', function (e) { checkBeforeSubmit(form, e); });
      }
    }
  }

  window.JF = { init: init, evaluate: evaluate };

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-jf-form]').forEach(init);
  });
})();
