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

  function showResult(data) {
    loadingEl.hidden = true;
    resultEl.hidden = false;
    titleEl.textContent = 'ارزش‌گذاری هوشمند آماده است';

    document.getElementById('ai-pricing-amount').textContent = data.value_fmt;
    document.getElementById('ai-pricing-range').textContent = 'محدوده پیشنهادی: ' + data.range_fmt;
    document.getElementById('ai-pricing-confidence').textContent =
      (data.uncertain ? '⚠ ' : '') + 'اطمینان AI: ' + data.confidence + '٪';

    const reasonsEl = document.getElementById('ai-pricing-reasons');
    reasonsEl.innerHTML = data.reasons.map(r => `<li><i class="bi bi-check2"></i>${r}</li>`).join('');
    document.getElementById('ai-pricing-note').textContent = data.note;
    valueEl.value = data.value;
  }

  async function runAiPricing() {
    showOverlay();
    animateSteps();

    const fd = new FormData();
    fd.append('title', document.getElementById('title').value);
    fd.append('description', document.getElementById('description').value);
    fd.append('condition', document.getElementById('condition').value);
    fd.append('category_id', document.getElementById('category_id').value);

    const minDelay = new Promise(r => setTimeout(r, 2800));

    try {
      const [_, res] = await Promise.all([
        minDelay,
        fetch(getAppUrl() + '/api/ai_valuate.php', { method: 'POST', body: fd }),
      ]);
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'خطا');
      showResult(data);
    } catch (err) {
      hideOverlay();
      if (typeof showToast === 'function') showToast('خطا در ارزش‌گذاری AI. دوباره تلاش کنید.', 'error');
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
