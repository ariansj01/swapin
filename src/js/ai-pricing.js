/**
 * AI pricing flow — listings/create.php
 * Posts to /api/ai_valuate.php so the server calls ai_price_listing with mode:pricing.
 * Do not route valuation through /api/ai_chat.php (that always wraps mode:chat).
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

  function getCreditUnit() {
    return document.querySelector('meta[name="credit-unit"]')?.content || 'تومان';
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

  function formatCreditLocal(amount) {
    const n = Math.round(Number(amount) || 0);
    const formatted = new Intl.NumberFormat('fa-IR').format(n);
    return formatted + ' ' + getCreditUnit();
  }

  function extractJsonFromText(text) {
    if (!text) return null;
    const clean = String(text).trim();
    try {
      const parsed = JSON.parse(clean);
      if (parsed && typeof parsed === 'object') return parsed;
    } catch {}
    const fenced = clean.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/i);
    if (fenced && fenced[1]) {
      try { return JSON.parse(fenced[1]); } catch {}
    }
    const braceStart = clean.indexOf('{');
    const braceEnd   = clean.lastIndexOf('}');
    if (braceStart !== -1 && braceEnd !== -1 && braceEnd > braceStart) {
      const slice = clean.substring(braceStart, braceEnd + 1);
      try { return JSON.parse(slice); } catch {}
    }
    const matches = clean.match(/\{[\s\S]*\}/);
    if (matches && matches[0]) {
      try { return JSON.parse(matches[0]); } catch {}
    }
    return null;
  }

  function normaliseNumber(v) {
    if (v == null) return 0;
    let s = String(v).trim();
    s = s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
    s = s.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    s = s.replace(/[^\d.-]/g, '');
    const n = Number(s);
    return isFinite(n) ? Math.round(n) : 0;
  }

  function buildValuationFallback(title, desc, cond) {
    const condMul = { new: 1.0, like_new: 0.88, good: 0.72, fair: 0.58, poor: 0.42 };
    const mul = condMul[cond] ?? 0.72;
    let hash = 0;
    const src = title + '|' + desc + '|' + cond;
    for (let i = 0; i < src.length; i++) {
      hash = ((hash << 5) - hash + src.charCodeAt(i)) | 0;
    }
    const seedBase = 3500000 + (Math.abs(hash) % 42000000);
    const base = Math.round(seedBase);
    const raw = Math.round(base * mul / 100000) * 100000;
    const value = Math.max(500000, Math.min(raw, 500000000000));
    const rangeLow  = Math.round(value * 0.88 / 100000) * 100000;
    const rangeHigh = Math.round(value * 1.12 / 100000) * 100000;
    return {
      ok: true,
      value: value,
      value_fmt: formatCreditLocal(value),
      range_low: rangeLow,
      range_high: rangeHigh,
      range_fmt: formatCreditLocal(rangeLow) + ' — ' + formatCreditLocal(rangeHigh),
      confidence: 55,
      uncertain: true,
      reasons: [
        'اتصال دستیار هوشمند برقرار نشد — از تخمین داخلی مرورگر استفاده شد.',
        'پیشنهاد را فقط راهنما در نظر بگیرید و در صورت نیاز مقدار را دستی تنظیم کنید.',
      ],
      note: 'ارزش‌گذاری پشتیبان — دستیار در دسترس نبود.',
      ai_source: 'fallback_local',
    };
  }

  function normalisePricingFromAi(parsed) {
    if (!parsed || typeof parsed !== 'object') return null;

    let min = 0, max = 0;
    if (parsed.value_range && typeof parsed.value_range === 'object') {
      min = normaliseNumber(parsed.value_range.min);
      max = normaliseNumber(parsed.value_range.max);
    }
    if (min <= 0 && max <= 0) {
      min = normaliseNumber(parsed.min);
      max = normaliseNumber(parsed.max);
    }
    if (min <= 0 && max <= 0) {
      const val = normaliseNumber(parsed.estimated_value ?? parsed.valuation ?? parsed.value ?? parsed.price ?? 0);
      if (val > 0) {
        min = Math.round(val * 0.88);
        max = Math.round(val * 1.12);
      }
    }
    if (min <= 0 && max <= 0) return null;
    if (min > max) [min, max] = [max, min];
    if (min <= 0) min = Math.max(500000, Math.round(max * 0.85));
    if (max <= 0) max = Math.min(500000000000, Math.round(min * 1.15));

    min = Math.round(min / 100000) * 100000;
    max = Math.round(max / 100000) * 100000;
    min = Math.max(500000, min);
    max = Math.min(500000000000, max);
    if (min > max) min = Math.round(max * 0.88 / 100000) * 100000;

    const value = Math.round((min + max) / 2 / 100000) * 100000;
    let conf = parsed.confidence ?? parsed.certainty ?? 0.55;
    if (typeof conf === 'string') conf = normaliseNumber(conf) / 100;
    if (conf > 1) conf = conf / 100;
    const confPct = Math.round(Math.max(0, Math.min(1, Number(conf) || 0.55)) * 100);
    const uncertain = confPct < 60;

    const reasons = [];
    if (Array.isArray(parsed.reasons) && parsed.reasons.length) {
      parsed.reasons.forEach(r => {
        const s = String(r).trim();
        if (s) reasons.push(s);
      });
    }
    const singleReason = String(parsed.reason ?? '').trim();
    if (singleReason && !reasons.includes(singleReason)) reasons.unshift(singleReason);
    if (uncertain) reasons.push('اطمینان پایین — محدوده تقریبی است؛ در صورت نیاز مقدار را دستی تنظیم کنید.');
    if (reasons.length === 0) reasons.push('ارزش‌گذاری هوشمند بر اساس مشخصات کالای شما.');

    return {
      ok: true,
      value: value,
      value_fmt: formatCreditLocal(value),
      range_low: min,
      range_high: max,
      range_fmt: formatCreditLocal(min) + ' — ' + formatCreditLocal(max),
      confidence: confPct,
      uncertain: uncertain,
      reasons: reasons,
      note: uncertain
        ? 'ارزش‌گذاری با اطمینان پایین — پیشنهاد را راهنما در نظر بگیرید.'
        : 'ارزش‌گذاری هوشمند سواَپین بر اساس مشخصات و قوانین بازار.',
      ai_source: String(parsed.ai_source ?? parsed.provider ?? 'chat_ai'),
    };
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

    const titleRaw = document.getElementById('title').value;
    const descRaw  = document.getElementById('description').value;
    const condVal  = document.getElementById('condition').value;
    const catEl    = document.getElementById('category_id');
    const catId    = catEl ? catEl.value : '';

    const fd = new FormData();
    fd.append('title', titleRaw.trim());
    fd.append('description', descRaw.trim());
    fd.append('condition', condVal);
    fd.append('category_id', catId);

    const minDelay = new Promise(r => setTimeout(r, 2800));

    let data = null;

    try {
      if (typeof swaapinAiValuateFromBrowser !== 'function') {
        throw new Error('ai_client_missing');
      }
      const [_d, valuate] = await Promise.all([
        minDelay,
        swaapinAiValuateFromBrowser(getAppUrl(), fd),
      ]);
      data = valuate || buildValuationFallback(titleRaw, descRaw, condVal);
      showResult(data);
    } catch (err) {
      data = buildValuationFallback(titleRaw, descRaw, condVal);
      try {
        showResult(data);
        if (typeof showToast === 'function') {
          showToast('اتصال دستیار برقرار نشد — از تخمین محلی استفاده شد.', 'warning');
        }
      } catch (_e2) {
        hideOverlay();
        if (typeof showToast === 'function') {
          showToast(err.message || 'خطا در ارزش‌گذاری AI. دوباره تلاش کنید.', 'error');
        }
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
