// Discount code UI — inline box (wallet) + modal (promotion / subscription)
(function () {
  'use strict';

  var API = window.SWAAPIN_DISCOUNT_API || '/api/discount.php';

  function csrfToken() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }

  function toPersianDigits(s) {
    return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
  }

  function fmtMoney(n) {
    var formatted = new Intl.NumberFormat('en-US').format(Math.max(0, Math.round(n || 0)));
    return toPersianDigits(formatted) + ' تومان';
  }

  function validate(code, amount) {
    var body = new FormData();
    body.append('code', code);
    body.append('amount', String(amount));
    body.append('_csrf', csrfToken());
    return fetch(API, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken(), 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: body
    }).then(function (r) { return r.json(); });
  }

  function setFeedback(el, type, msg) {
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      el.className = 'discount-feedback';
      return;
    }
    el.hidden = false;
    el.className = 'discount-feedback discount-feedback--' + (type === 'ok' ? 'ok' : 'error');
    el.innerHTML = (type === 'ok'
      ? '<i class="bi bi-check-circle"></i> '
      : '<i class="bi bi-exclamation-circle"></i> ') + msg;
  }

  // ── Inline boxes (wallet) ──────────────────────────────────────
  document.querySelectorAll('[data-discount-box]').forEach(function (box) {
    var form = box.closest('form');
    var input = box.querySelector('[data-discount-input]');
    var applyBtn = box.querySelector('[data-discount-apply]');
    var feedback = box.querySelector('[data-discount-feedback]');
    var amountSel = box.getAttribute('data-amount-input') || '';
    var amountInput = (form && amountSel) ? form.querySelector(amountSel) : null;

    function currentAmount() {
      if (amountInput) {
        return parseInt(amountInput.value || '0', 10) || 0;
      }
      return parseInt(box.getAttribute('data-amount') || '0', 10) || 0;
    }

    function run() {
      var code = (input.value || '').trim();
      if (!code) {
        setFeedback(feedback, '', '');
        return;
      }
      if (currentAmount() <= 0) {
        setFeedback(feedback, 'error', 'ابتدا مبلغ را وارد کنید.');
        return;
      }
      applyBtn.disabled = true;
      validate(code, currentAmount()).then(function (res) {
        if (res && res.ok) {
          setFeedback(feedback, 'ok',
            'کد اعمال شد — تخفیف ' + fmtMoney(res.discount) + ' — مبلغ پرداختی: ' + fmtMoney(res.final));
        } else {
          setFeedback(feedback, 'error', (res && res.message) || 'کد تخفیف نامعتبر است.');
        }
      }).catch(function () {
        setFeedback(feedback, 'error', 'خطا در بررسی کد تخفیف.');
      }).finally(function () {
        applyBtn.disabled = false;
      });
    }

    if (applyBtn) applyBtn.addEventListener('click', run);
    if (input) input.addEventListener('blur', function () { if (input.value.trim()) run(); });
  });

  // ── Modal (promotion / subscription) ───────────────────────────
  var modal = document.getElementById('discountModal');
  if (!modal) return;

  var mInput = modal.querySelector('[data-discount-input]');
  var mFeedback = modal.querySelector('[data-discount-feedback]');
  var mAmountInfo = modal.querySelector('[data-discount-amount-info]');
  var confirmBtn = modal.querySelector('[data-discount-confirm]');
  var skipBtn = modal.querySelector('[data-discount-skip]');
  var pendingForm = null;
  var pendingAmount = 0;

  function findHidden(form) {
    return form.querySelector('input[type="hidden"][name="discount_code"]');
  }

  function resolveAmount(form) {
    var plan = form.closest('.promote-plan');
    if (plan) {
      var durSel = plan.querySelector('.promote-duration-select');
      if (durSel && durSel.selectedIndex >= 0) {
        var opt = durSel.options[durSel.selectedIndex];
        var p = opt ? parseInt(opt.dataset.price || '0', 10) : 0;
        if (p > 0) return p;
      }
    }
    var explicit = parseInt(form.getAttribute('data-discount-amount') || '0', 10);
    if (explicit > 0) return explicit;
    var amountInput = form.querySelector('[data-discount-amount-input]');
    if (amountInput) return parseInt(amountInput.value || '0', 10) || 0;
    return 0;
  }

  function openModal(form) {
    pendingForm = form;
    pendingAmount = resolveAmount(form);
    setFeedback(mFeedback, '', '');
    var hidden = findHidden(form);
    mInput.value = hidden ? (hidden.value || '').trim() : '';
    mAmountInfo.textContent = 'مبلغ فعلی: ' + fmtMoney(pendingAmount);
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(function () { mInput.focus(); }, 30);
  }

  function closeModal() {
    modal.hidden = true;
    document.body.style.overflow = '';
    pendingForm = null;
  }

  function submitPending(code) {
    var form = pendingForm;
    closeModal();
    if (!form) return;
    form.dataset.discountBypass = '1';
    var hidden = findHidden(form);
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'discount_code';
      form.appendChild(hidden);
    }
    hidden.value = code || '';
    form.submit();
  }

  // Capture phase so this runs before other submit handlers (e.g. promote confirm)
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-discount-form')) return;
    if (form.dataset.discountBypass === '1') return;
    e.preventDefault();
    e.stopPropagation();
    openModal(form);
  }, true);

  confirmBtn.addEventListener('click', function () {
    if (!pendingForm) return;
    var code = (mInput.value || '').trim();
    if (!code) {
      submitPending('');
      return;
    }
    confirmBtn.disabled = true;
    validate(code, pendingAmount).then(function (res) {
      if (res && res.ok) {
        setFeedback(mFeedback, 'ok',
          'تخفیف ' + fmtMoney(res.discount) + ' اعمال شد — مبلغ پرداختی: ' + fmtMoney(res.final));
        setTimeout(function () { submitPending(code); }, 350);
      } else {
        setFeedback(mFeedback, 'error', (res && res.message) || 'کد تخفیف نامعتبر است.');
      }
    }).catch(function () {
      setFeedback(mFeedback, 'error', 'خطا در بررسی کد تخفیف.');
    }).finally(function () {
      confirmBtn.disabled = false;
    });
  });

  if (skipBtn) skipBtn.addEventListener('click', function () { submitPending(''); });

  modal.querySelectorAll('[data-discount-dismiss]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });
})();
