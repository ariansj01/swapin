/**
 * AI pricing flow — listings/create.php
 */
(function () {
  const form      = document.getElementById('create-form');
  const overlay   = document.getElementById('ai-pricing-overlay');
  if (!form || !overlay) return;

  const loadingEl = document.getElementById('ai-pricing-loading');
  const resultEl  = document.getElementById('ai-pricing-result');
  const stepsEl   = document.getElementById('ai-pricing-steps');
  const titleEl   = document.getElementById('ai-pricing-title');
  const valueEl   = document.getElementById('estimated_value');
  const backBtn   = document.getElementById('ai-pricing-back');
  const confirmBtn= document.getElementById('ai-pricing-confirm');
  let aiConfirmed = false;

  function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  function getAppUrl() {
    return document.querySelector('meta[name="app-url"]')?.content || '';
  }

  function showOverlay() {
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  function hideOverlay() {
    overlay.hidden = true;
    document.body.style.overflow = '';
    loadingEl.hidden = false;
    resultEl.hidden = true;
    titleEl.textContent = 'در حال تحلیل کالای شما…';
    stepsEl?.querySelectorAll('li').forEach((li, i) => li.classList.toggle('is-active', i === 0));
    stepsEl?.querySelectorAll('li').forEach(li => li.classList.remove('is-done'));
  }

  function animateSteps() {
    const steps = stepsEl?.querySelectorAll('li');
    if (!steps) return;
    steps.forEach((li, i) => {
      setTimeout(() => {
        steps.forEach(s => s.classList.remove('is-active'));
        if (i > 0) steps[i - 1].classList.add('is-done');
        li.classList.add('is-active');
      }, i * 900);
    });
  }

  function escHtml(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function showResult(data) {
    loadingEl.hidden = true;
    resultEl.hidden = false;
    titleEl.textContent = 'ارزش‌گذاری هوشمند آماده است';

    document.getElementById('ai-pricing-amount').textContent = data.value_fmt;
    document.getElementById('ai-pricing-range').textContent = 'محدوده پیشنهادی: ' + data.range_fmt;
    document.getElementById('ai-pricing-confidence').textContent =
      (data.uncertain ? '⚠ ' : '') + 'اطمینان AI: ' + data.confidence + '٪';

    const reasonsEl = document.getElementById('ai-pricing-reasons');
    reasonsEl.innerHTML = data.reasons.map(r => `<li><i class="bi bi-check2"></i>${escHtml(r)}</li>`).join('');
    document.getElementById('ai-pricing-note').textContent = data.note;
    valueEl.value = data.value;
  }

  function validateFormBeforeAi() {
    const title = document.getElementById('title');
    const desc = document.getElementById('description');
    const category = document.getElementById('category_id');
    const errors = [];

    if (title.value.trim().length < 5) {
      errors.push('عنوان باید حداقل ۵ کاراکتر باشد');
    }
    if (desc.value.trim().length < 20) {
      errors.push('توضیحات باید حداقل ۲۰ کاراکتر باشد');
    }
    if (!category.value) {
      errors.push('لطفاً دسته‌بندی را انتخاب کنید');
    }

    if (errors.length > 0) {
      if (typeof showToast === 'function') {
        showToast(errors[0], 'error');
      }
      return false;
    }
    return true;
  }

  async function runAiPricing() {
    if (!validateFormBeforeAi()) {
      return;
    }
    showOverlay();
    animateSteps();

    const fd = new FormData();
    fd.append('title', document.getElementById('title').value);
    fd.append('description', document.getElementById('description').value);
    fd.append('condition', document.getElementById('condition').value);
    fd.append('category_id', document.getElementById('category_id').value);
    const csrf = getCsrfToken();
    if (csrf) fd.append('_csrf', csrf);

    const minDelay = new Promise(r => setTimeout(r, 2800));

    try {
      const [_, res] = await Promise.all([
        minDelay,
        fetch(getAppUrl() + '/api/ai_valuate.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: csrf ? { 'X-CSRF-Token': csrf } : {},
        }),
      ]);
      const data = await res.json();
      if (!data.ok) {
        if (data.error === 'rate_limited') {
          throw new Error(data.message || 'حداکثر ۳ بار در ۱۵ دقیقه می‌توانید ارزش‌گذاری AI بگیرید.');
        }
        throw new Error(data.error || 'خطا');
      }
      showResult(data);
    } catch (err) {
      hideOverlay();
      if (typeof showToast === 'function') {
        showToast(err.message || 'خطا در ارزش‌گذاری AI. دوباره تلاش کنید.', 'error');
      }
    }
  }

  form.addEventListener('submit', function (e) {
    if (aiConfirmed) return;
    if (!form.checkValidity()) return;
    e.preventDefault();
    runAiPricing();
  });

  backBtn?.addEventListener('click', hideOverlay);

  confirmBtn?.addEventListener('click', function () {
    aiConfirmed = true;
    hideOverlay();
    document.getElementById('btn-text').style.display = 'none';
    document.getElementById('btn-spinner').style.display = 'inline-block';
    document.getElementById('submit-btn').disabled = true;
    form.submit();
  });
})();
