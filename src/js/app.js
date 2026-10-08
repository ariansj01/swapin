/**
 * Swapin (سواَپین) — app.js
 * Vanilla JS — no dependencies
 */

function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function withCsrfHeaders(headers = {}) {
  const token = getCsrfToken();
  if (token) {
    headers['X-CSRF-Token'] = token;
  }
  return headers;
}

function appendCsrf(formData) {
  const token = getCsrfToken();
  if (token && formData instanceof FormData) {
    formData.append('_csrf', token);
  }
  return formData;
}

function swaapinExtractJsonFromText(text) {
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
  const braceEnd = clean.lastIndexOf('}');
  if (braceStart !== -1 && braceEnd !== -1 && braceEnd > braceStart) {
    try { return JSON.parse(clean.substring(braceStart, braceEnd + 1)); } catch {}
  }
  return null;
}

function swaapinAiContentFromCompletion(body) {
  const msg = body && body.choices && body.choices[0] && body.choices[0].message;
  const text = msg && msg.content != null ? msg.content : '';
  return String(text).trim();
}

function swaapinAiContentFromGemini(body) {
  const parts = body && body.candidates && body.candidates[0]
    && body.candidates[0].content && body.candidates[0].content.parts;
  if (!Array.isArray(parts)) return '';
  return parts.map(p => (p && p.text) ? String(p.text) : '').join('').trim();
}

function swaapinGeminiBodyFromMessages(messages, temperature, maxTokens, useSearch) {
  let systemText = '';
  const contents = [];
  (messages || []).forEach(m => {
    const text = m && m.content != null ? String(m.content) : '';
    if (!text) return;
    if (m.role === 'system') {
      systemText += (systemText ? '\n\n' : '') + text;
      return;
    }
    contents.push({
      role: m.role === 'assistant' ? 'model' : 'user',
      parts: [{ text: text }],
    });
  });
  const body = {
    contents: contents,
    generationConfig: {
      temperature: temperature,
      maxOutputTokens: maxTokens,
    },
  };
  if (systemText) {
    body.systemInstruction = { parts: [{ text: systemText }] };
  }
  if (useSearch) {
    body.tools = [{ google_search: {} }];
  }
  return body;
}

async function swaapinProviderGeminiOnce(provider, messages, temperature, maxTokens) {
  const headers = Object.assign({
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }, provider.headers || {});
  const res = await fetch(provider.url, {
    method: 'POST',
    headers: headers,
    body: JSON.stringify(swaapinGeminiBodyFromMessages(
      messages,
      temperature,
      maxTokens,
      provider.google_search !== false
    )),
  });
  let body = null;
  try { body = await res.json(); } catch { body = null; }
  if (!res.ok) {
    throw new Error((provider.id || 'gemini') + '_http_' + res.status);
  }
  const content = swaapinAiContentFromGemini(body);
  if (!content) throw new Error('empty_completion');
  return { content: content, provider: provider.id || 'gemini' };
}

async function swaapinProviderChatOnce(provider, messages, temperature, maxTokens) {
  if ((provider.api || provider.id) === 'gemini') {
    return swaapinProviderGeminiOnce(provider, provider.messages || messages, temperature, maxTokens);
  }
  const headers = Object.assign({
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }, provider.headers || {});
  if (provider.id === 'openrouter') {
    headers['HTTP-Referer'] = headers['HTTP-Referer'] || window.location.origin;
  }
  const res = await fetch(provider.url, {
    method: 'POST',
    headers: headers,
    body: JSON.stringify({
      model: provider.model,
      messages: messages,
      temperature: temperature,
      max_tokens: maxTokens,
    }),
  });
  let body = null;
  try { body = await res.json(); } catch { body = null; }
  if (!res.ok) {
    throw new Error((provider.id || 'ai') + '_http_' + res.status);
  }
  const content = swaapinAiContentFromCompletion(body);
  if (!content) throw new Error('empty_completion');
  return { content: content, provider: provider.id };
}

async function swaapinBrowserCompleteChat(prepare) {
  const providers = Array.isArray(prepare.providers) ? prepare.providers : [];
  const messages = prepare.messages || [];
  const temperature = Number(prepare.temperature) || 0.35;
  const maxTokens = Number(prepare.max_tokens) || 1200;
  for (let i = 0; i < providers.length; i++) {
    try {
      return await swaapinProviderChatOnce(providers[i], messages, temperature, maxTokens);
    } catch (_e) {
      /* next provider — request is from the visitor IP */
    }
  }
  return null;
}

function swaapinChatDisplayMessage(content, fallbackMessage) {
  const parsed = swaapinExtractJsonFromText(content);
  if (parsed && typeof parsed.message === 'string' && parsed.message.trim()) {
    return parsed.message.trim();
  }
  if (content && String(content).trim()) {
    return String(content).trim();
  }
  return fallbackMessage || '';
}

function swaapinNormaliseNumber(v) {
  if (v == null) return 0;
  let s = String(v).trim();
  s = s.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
  s = s.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  s = s.replace(/[^\d.-]/g, '');
  const n = Number(s);
  return isFinite(n) ? Math.round(n) : 0;
}

function swaapinFormatCreditLocal(amount) {
  const n = Math.round(Number(amount) || 0);
  const unit = (typeof getCreditUnit === 'function') ? getCreditUnit() : 'تومان';
  return new Intl.NumberFormat('fa-IR').format(n) + ' ' + unit;
}

/** Normalize LLM pricing JSON into UI fields. Returns null if not a pricing payload. */
function swaapinNormalisePricingFromAi(parsed) {
  if (!parsed || typeof parsed !== 'object') return null;

  const type = String(parsed.type || '');
  if (type === 'chat' || type === 'error') return null;

  let min = 0, max = 0;
  if (parsed.value_range && typeof parsed.value_range === 'object') {
    min = swaapinNormaliseNumber(parsed.value_range.min);
    max = swaapinNormaliseNumber(parsed.value_range.max);
  }
  if (min <= 0 && max <= 0) {
    min = swaapinNormaliseNumber(parsed.min);
    max = swaapinNormaliseNumber(parsed.max);
  }
  if (min <= 0 && max <= 0) {
    const val = swaapinNormaliseNumber(
      parsed.estimated_value ?? parsed.valuation ?? parsed.value ?? parsed.price ?? 0
    );
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

  const inflationMul = 1.07;
  const value = Math.round(((0.40 * min) + (0.60 * max)) * inflationMul / 100000) * 100000;
  let conf = parsed.confidence ?? parsed.certainty ?? 0.55;
  if (typeof conf === 'string') conf = swaapinNormaliseNumber(conf) / 100;
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
    value_fmt: swaapinFormatCreditLocal(value),
    range_low: min,
    range_high: max,
    range_fmt: swaapinFormatCreditLocal(min) + ' — ' + swaapinFormatCreditLocal(max),
    confidence: confPct,
    uncertain: uncertain,
    reasons: reasons,
    note: uncertain
      ? 'ارزش‌گذاری با اطمینان پایین — پیشنهاد را راهنما در نظر بگیرید.'
      : 'ارزش‌گذاری هوشمند سواَپین بر اساس مشخصات و آگهی‌های مشابه.',
    ai_source: String(parsed.ai_source ?? parsed.provider ?? 'assistant'),
    fallback: false,
  };
}

/**
 * Prepare on server (mode:pricing messages), complete from browser IP.
 * Falls back to prepare.fallback when providers are unreachable.
 */
async function swaapinAiValuateFromBrowser(appUrl, formData) {
  appendCsrf(formData);
  const res = await fetch(appUrl + '/api/ai_valuate.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
    headers: withCsrfHeaders(),
  });
  let prepare;
  try { prepare = await res.json(); } catch { prepare = { ok: false }; }

  if (prepare && prepare.error === 'rate_limited') {
    throw new Error(prepare.message || 'سقف درخواست‌های ارزش‌گذاری پر شده. کمی بعد دوباره تلاش کنید.');
  }
  if (prepare && prepare.error === 'login_required') {
    throw new Error('برای تخمین قیمت باید وارد حساب کاربری شوید.');
  }
  if (!res.ok || !prepare || prepare.ok !== true) {
    throw new Error((prepare && (prepare.message || prepare.error)) || 'خطا در آماده‌سازی ارزش‌گذاری.');
  }

  const serverFallback = prepare.fallback && typeof prepare.fallback === 'object'
    ? Object.assign({ ok: true, fallback: true, ai_source: 'fallback' }, prepare.fallback)
    : null;

  if (prepare.type === 'client_prepare') {
    const done = await swaapinBrowserCompleteChat(prepare);
    if (done && done.content) {
      const parsed = swaapinExtractJsonFromText(done.content);
      const normalised = swaapinNormalisePricingFromAi(parsed);
      if (normalised) {
        normalised.ai_source = done.provider || normalised.ai_source;
        return normalised;
      }
    }
    if (serverFallback) return serverFallback;
    throw new Error('پاسخ ارزش‌گذاری قابل خواندن نبود.');
  }

  // Legacy direct-result response
  if (prepare.value != null || prepare.value_fmt) {
    return Object.assign({ ok: true, fallback: !!prepare.fallback }, prepare);
  }
  if (serverFallback) return serverFallback;
  throw new Error('پاسخ ارزش‌گذاری نامعتبر بود.');
}

async function swaapinAiChatFromBrowser(appUrl, message, history) {
  const fd = new FormData();
  fd.append('message', message);
  fd.append('history', JSON.stringify(history || []));
  appendCsrf(fd);
  const res = await fetch(appUrl + '/api/ai_chat.php', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin',
    headers: withCsrfHeaders(),
  });
  let prepare;
  try { prepare = await res.json(); } catch { prepare = { ok: false }; }
  if (prepare && prepare.error === 'rate_limited') {
    throw new Error(prepare.message || 'سقف پیام‌های AI پر شده. کمی بعد دوباره تلاش کنید.');
  }
  if (!res.ok || !prepare || prepare.ok !== true) {
    throw new Error((prepare && (prepare.message || prepare.error)) || 'خطا');
  }
  const done = await swaapinBrowserCompleteChat(prepare);
  if (!done) {
    return {
      ok: true,
      message: prepare.fallback_message || 'سؤال شما دریافت شد. لطفاً کمی بعد دوباره تلاش کنید یا از بخش ثبت کالا و راهنما کمک بگیرید.',
      content: '',
      provider: null,
      fallback: true,
    };
  }
  return {
    ok: true,
    message: swaapinChatDisplayMessage(done.content, prepare.fallback_message),
    content: done.content,
    provider: done.provider,
    fallback: false,
  };
}

/* ── Toast notification system ─────────────────────────────────────────── */
function showToast(msg, type = 'info', duration = 3500) {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-circle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-triangle-fill' };
  const colors = { success: 'var(--success)', error: 'var(--danger)', info: 'var(--info)', warning: 'var(--warning)' };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type === 'error' ? 'error' : type}`;
  const icon = document.createElement('i');
  icon.className = `bi ${icons[type] || icons.info}`;
  icon.style.cssText = `color:${colors[type]};font-size:1rem;flex-shrink:0`;
  const span = document.createElement('span');
  span.textContent = String(msg ?? '');
  toast.appendChild(icon);
  toast.appendChild(span);
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(8px)';
    toast.style.transition = 'all .2s ease';
    setTimeout(() => toast.remove(), 220);
  }, duration);
}

/* ── Skeleton loading helpers ──────────────────────────────────────────── */
function skeletonListingCardHtml() {
  return `<article class="listing-card listing-card--skeleton" aria-hidden="true">
    <div class="listing-card__header"><div class="skeleton skeleton-line skeleton-line--sm"></div></div>
    <div class="listing-card__product">
      <div class="listing-card__details">
        <div class="skeleton skeleton-line skeleton-line--title"></div>
        <div class="skeleton skeleton-line skeleton-line--md"></div>
        <div class="skeleton skeleton-line skeleton-line--value"></div>
      </div>
      <div class="skeleton skeleton-block skeleton-block--img"></div>
    </div>
    <div class="skeleton skeleton-line skeleton-line--meta"></div>
    <div class="skeleton skeleton-line skeleton-line--cta"></div>
  </article>`;
}

function showListingsGridSkeleton(count = 6) {
  const grid = document.getElementById('listings-grid');
  if (!grid) return;
  grid.classList.add('is-loading');
  grid.setAttribute('aria-busy', 'true');
  grid.innerHTML = Array.from({ length: count }, skeletonListingCardHtml).join('');
}

function skeletonMatchRowsHtml(count = 3) {
  const pillar = (label, cls, w) => `
    <div class="match-pillar" aria-hidden="true">
      <div class="match-pillar__label" style="opacity:0">${label}</div>
      <div class="match-pillar__bar"><span class="match-pillar__fill match-pillar__fill--${cls}" style="width:${w}%;opacity:.25"></span></div>
      <div class="match-pillar__pct" style="opacity:0">٪</div>
    </div>`;
  return Array.from({ length: count }, () =>
    `<div class="match-row match-row--skeleton" aria-hidden="true">
      <div class="skeleton skeleton-circle skeleton-circle--score"></div>
      <div class="match-row__body" style="flex:1">
        <div class="skeleton skeleton-line skeleton-line--sm"></div>
        <div class="skeleton skeleton-line skeleton-line--md"></div>
        <div class="skeleton skeleton-line skeleton-line--xs" style="margin-top:6px"></div>
        <div class="match-pillars" style="margin-top:10px;pointer-events:none">
          ${pillar('نیاز',    'need',    55)}
          ${pillar('ارزش',   'value',   70)}
          ${pillar('دسته',   'cat',     60)}
          ${pillar('موفقیت','success',  50)}
        </div>
      </div>
    </div>`
  ).join('');
}

function skeletonNotifItemsHtml(count = 4) {
  return Array.from({ length: count }, () =>
    `<div class="notif-item notif-item--skeleton" aria-hidden="true">
      <div class="skeleton skeleton-circle skeleton-circle--md"></div>
      <div class="notif-item__body" style="flex:1">
        <div class="skeleton skeleton-line skeleton-line--sm"></div>
        <div class="skeleton skeleton-line skeleton-line--md"></div>
      </div>
      <div class="skeleton skeleton-line skeleton-line--xs"></div>
    </div>`
  ).join('');
}

/* ── Dropdown menus ────────────────────────────────────────────────────── */
function initDropdowns() {
  document.querySelectorAll('.dropdown').forEach(dropdown => {
    const btn  = dropdown.querySelector('[id$="-btn"], button');
    const menu = dropdown.querySelector('.dropdown-menu');
    if (!btn || !menu) return;

    btn.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = menu.classList.contains('open');
      // Close all others
      document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
      if (!isOpen) menu.classList.add('open');
    });
  });

  document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
  });
}

/* ── Navbar hover dropdowns (desktop) — ensure .open on hover for JS-level compat ── */
function initNavbarHoverDropdowns() {
  if (window.matchMedia('(max-width: 991px)').matches) return;
  const nav = document.querySelector('.navbar-nav');
  if (!nav) return;

  // Category & other dropdowns: apply .open class while hovering so nested subs stay visible
  nav.querySelectorAll(':scope > .dropdown').forEach(dd => {
    const menu = dd.querySelector(':scope > .dropdown-menu');
    if (!menu) return;
    const show = () => {
      document.querySelectorAll('.navbar-nav > .dropdown > .dropdown-menu.open').forEach(m => { if (m !== menu) m.classList.remove('open'); });
      menu.classList.add('open');
    };
    const hide = () => { menu.classList.remove('open'); };
    dd.addEventListener('mouseenter', show);
    dd.addEventListener('mouseleave', () => {
      setTimeout(() => { if (!dd.matches(':hover')) hide(); }, 80);
    });
  });

  // Apply forced inline styles to ensure submenu position & visibility (CSS override fallback)
  const forceSubmenuStyles = (sub, open) => {
    if (!sub) return;
    if (open) {
      sub.style.setProperty('display', 'block', 'important');
      sub.style.setProperty('visibility', 'visible', 'important');
      sub.style.setProperty('opacity', '1', 'important');
      sub.style.setProperty('position', 'absolute', 'important');
      sub.style.setProperty('top', '0', 'important');
      sub.style.setProperty('right', '100%', 'important');
      sub.style.setProperty('left', 'auto', 'important');
      sub.style.setProperty('bottom', 'auto', 'important');
      sub.style.setProperty('margin-right', '2px', 'important');
      sub.style.setProperty('margin-top', '0', 'important');
      sub.style.setProperty('min-width', '220px', 'important');
      sub.style.setProperty('width', 'max-content', 'important');
      sub.style.setProperty('overflow', 'visible', 'important');
      sub.style.setProperty('overflow-x', 'visible', 'important');
      sub.style.setProperty('overflow-y', 'visible', 'important');
      sub.style.setProperty('z-index', '9999', 'important');
      sub.style.setProperty('background', '#ffffff', 'important');
      sub.style.setProperty('border', '1px solid #e5e7eb', 'important');
      sub.style.setProperty('border-radius', '16px', 'important');
      sub.style.setProperty('box-shadow', '0 10px 25px -5px rgba(10, 37, 64, 0.15), 0 8px 10px -6px rgba(10, 37, 64, 0.12)', 'important');
    } else {
      sub.style.setProperty('display', 'none', 'important');
    }
  };

  // Nested submenus (any depth): add .open on hover inside dropdown-menu for sub-submenu display
  const applySubmenuHover = (root) => {
    root.querySelectorAll('.dropdown-submenu').forEach(sm => {
      const sub = sm.querySelector(':scope > .dropdown-menu--sub');
      if (!sub) return;
      sm.addEventListener('mouseenter', () => {
        // Close siblings under same parent first
        const parent = sm.parentNode;
        parent.querySelectorAll(':scope > .dropdown-submenu > .dropdown-menu--sub.open').forEach(s => {
          if (s !== sub) {
            s.classList.remove('open');
            forceSubmenuStyles(s, false);
          }
        });
        sub.classList.add('open');
        forceSubmenuStyles(sub, true);
      });
      sm.addEventListener('mouseleave', () => {
        setTimeout(() => {
          if (!sm.matches(':hover')) {
            sub.classList.remove('open');
            forceSubmenuStyles(sub, false);
          }
        }, 60);
      });
    });
  };
  document.querySelectorAll('.navbar-nav .dropdown-menu').forEach(applySubmenuHover);

  // Click on parent items (dropdown-item--parent): prevent link, toggle nested submenu
  document.querySelectorAll('.navbar-nav .dropdown-item--parent').forEach(parent => {
    parent.addEventListener('click', e => {
      const sm = parent.closest('.dropdown-submenu');
      const sub = sm ? sm.querySelector(':scope > .dropdown-menu--sub') : null;
      if (!sub) return;
      e.preventDefault();
      e.stopPropagation();
      const willOpen = !sub.classList.contains('open');
      // Close all sibling submenus at same level
      if (sm && sm.parentNode) {
        sm.parentNode.querySelectorAll(':scope > .dropdown-submenu > .dropdown-menu--sub.open').forEach(s => {
          if (s !== sub) {
            s.classList.remove('open');
            forceSubmenuStyles(s, false);
          }
        });
      }
      if (willOpen) {
        sub.classList.add('open');
        forceSubmenuStyles(sub, true);
      } else {
        sub.classList.remove('open');
        forceSubmenuStyles(sub, false);
      }
    });
  });
}

/* ── Category tree toggles in listings sidebar ─────────────────────────── */
function initCatTreeToggles() {
  document.querySelectorAll('.cat-tree__toggle').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      e.stopPropagation();
      const item = btn.closest('.cat-tree__item');
      if (!item) return;
      item.classList.toggle('is-open');
    });
  });
}

/* ── Tab switching (generic) ───────────────────────────────────────────── */
function switchTab(tabId) {
  const allBtns   = document.querySelectorAll('.tab-btn');
  const allPanels = document.querySelectorAll('.tab-panel');

  allBtns.forEach(btn => btn.classList.remove('active'));
  allPanels.forEach(panel => panel.classList.remove('active'));

  const targetBtn   = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
  const targetPanel = document.getElementById(`panel-${tabId}`);

  if (targetBtn)   targetBtn.classList.add('active');
  if (targetPanel) targetPanel.classList.add('active');

  // Push to history without reload
  const url = new URL(window.location);
  url.searchParams.set('tab', tabId);
  history.replaceState(null, '', url.toString());
}

/* ── Confirm dialogs ───────────────────────────────────────────────────── */
function confirmAction(msg) {
  return window.confirm(msg);
}

/* ── Password visibility toggle ────────────────────────────────────────── */
function togglePass(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  if (!input) return;
  const isPass = input.type === 'password';
  input.type   = isPass ? 'text' : 'password';
  if (icon) icon.className = isPass ? 'bi bi-eye-slash' : 'bi bi-eye';
}

/* ── Character counter helper ───────────────────────────────────────────── */
function initCharCounters() {
  document.querySelectorAll('[data-count-target]').forEach(input => {
    const targetId = input.dataset.countTarget;
    const counter  = document.getElementById(targetId);
    if (!counter) return;

    const update = () => { counter.textContent = input.value.length; };
    input.addEventListener('input', update);
    update();
  });
}

/* ── Auto-dismiss alerts ────────────────────────────────────────────────── */
function initAutoDismissAlerts() {
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(alert => {
    const delay = parseInt(alert.dataset.autoDismiss) || 4000;
    setTimeout(() => {
      alert.style.transition = 'opacity .4s';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 420);
    }, delay);
  });
}

/* ── Smooth scroll to anchor ────────────────────────────────────────────── */
function initSmoothAnchors() {
  document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', e => {
      const target = document.querySelector(link.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });
}

/* ── Sticky navbar shadow on scroll ────────────────────────────────────── */
function initNavbarScroll() {
  const nav = document.querySelector('.navbar');
  if (!nav) return;
  window.addEventListener('scroll', () => {
    nav.style.boxShadow = window.scrollY > 10 ? 'var(--shadow-md)' : 'var(--shadow-sm)';
  }, { passive: true });
}

/* ── Image lazy loading fallback ────────────────────────────────────────── */
function initLazyImages() {
  const imgs = document.querySelectorAll('img[loading="lazy"]');
  if ('loading' in HTMLImageElement.prototype) return; // native support

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const img = entry.target;
          img.src = img.dataset.src || img.src;
          observer.unobserve(img);
        }
      });
    });
    imgs.forEach(img => observer.observe(img));
  }
}

/* ── Global search input sync ───────────────────────────────────────────── */
function getAppUrl() {
  return document.querySelector('meta[name="app-url"]')?.content?.replace(/\/$/, '') || '';
}

function initGlobalSearch() {
  const globalSearch = document.getElementById('global-search');
  if (!globalSearch) return;

  const appUrl = getAppUrl();
  const homePath = appUrl ? new URL(appUrl + '/').pathname : '/';

  const q = new URLSearchParams(window.location.search).get('q');
  if (q && (window.location.pathname === homePath || window.location.pathname.endsWith('/index.php'))) {
    globalSearch.value = q;
  }

  globalSearch.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const v = globalSearch.value.trim();
      const base = appUrl || window.location.origin;
      window.location.href = v ? `${base}/?q=${encodeURIComponent(v)}` : `${base}/`;
    }
  });
}

/* ── Homepage filter bar ────────────────────────────────────────────────── */
function initHomeFilters() {
  const searchInput = document.getElementById('search-input');
  if (!searchInput) return;

  const appUrl = getAppUrl() || window.location.origin;

  function applyFilter() {
    const p = new URLSearchParams(window.location.search);
    const q    = searchInput.value.trim();
    const city = document.getElementById('city-filter')?.value || '';
    const want = document.getElementById('want-filter')?.value || '';
    const sort = document.getElementById('sort-filter')?.value || 'new';

    if (q)    p.set('q', q);    else p.delete('q');
    if (city) p.set('city', city); else p.delete('city');
    if (want) p.set('want', want); else p.delete('want');
    p.set('sort', sort);
    p.delete('page');

    showListingsGridSkeleton(6);
    const qs = p.toString();
    window.location.href = qs ? `${appUrl}/?${qs}` : `${appUrl}/`;
  }

  let searchTimer;
  searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilter, 500);
  });
  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { clearTimeout(searchTimer); applyFilter(); }
  });

  document.getElementById('city-filter')?.addEventListener('change', applyFilter);
  document.getElementById('want-filter')?.addEventListener('change', applyFilter);
  document.getElementById('sort-filter')?.addEventListener('change', applyFilter);

  document.querySelectorAll('.cat-pill').forEach(pill => {
    pill.addEventListener('click', () => showListingsGridSkeleton(6));
  });
}

/* ── Save / unsave button feedback ─────────────────────────────────────── */
function initSaveButtons() {
  const appUrl = getAppUrl();

  document.querySelectorAll('[data-save-toggle]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();

      const listingId = btn.dataset.listingId;
      if (!listingId || !appUrl) return;

      const wasSaved = btn.dataset.saveToggle === 'true';
      const icon = btn.querySelector('i');

      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('listing_id', listingId);
        appendCsrf(fd);
        const res = await fetch(`${appUrl}/api/save_listing.php`, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: withCsrfHeaders(),
        });
        const data = await res.json();

        if (!res.ok) {
          if (data.error === 'login_required') {
            window.location.href = `${appUrl}/auth/login.php?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
            return;
          }
          throw new Error(data.error || 'save_failed');
        }

        const saved = !!data.saved;
        btn.dataset.saveToggle = saved ? 'true' : 'false';
        btn.classList.toggle('is-saved', saved);
        btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
        btn.setAttribute('aria-label', saved ? 'حذف از علاقه‌مندی‌ها' : 'افزودن به علاقه‌مندی‌ها');
        if (icon) icon.className = saved ? 'bi bi-heart-fill' : 'bi bi-heart';
        showToast(saved ? 'به علاقه‌مندی‌ها اضافه شد' : 'از علاقه‌مندی‌ها حذف شد', 'success', 2500);
      } catch {
        btn.dataset.saveToggle = wasSaved ? 'true' : 'false';
        showToast('خطا در ذخیره. دوباره تلاش کنید.', 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
}

/* ── Number formatting for credit amounts ──────────────────────────────── */
function getCreditUnit() {
  return document.querySelector('meta[name="credit-unit"]')?.content || 'تومان';
}

function formatKBC(amount) {
  return new Intl.NumberFormat('fa-IR').format(Math.round(amount)) + ' ' + getCreditUnit();
}

/* ── Offer form validation ──────────────────────────────────────────────── */
function initOfferForm() {
  // This is now handled in listings/view.php's inline JS, so we'll keep this empty or remove it
  // to avoid conflicting validation
}

/* ── Loading button state ───────────────────────────────────────────────── */
function initLoadingForms() {
  document.querySelectorAll('form[data-loading]').forEach(form => {
    form.addEventListener('submit', function() {
      const btn     = this.querySelector('[type="submit"]');
      const txtEl   = btn?.querySelector('[data-btn-text]');
      const spinEl  = btn?.querySelector('[data-btn-spinner]');
      if (btn)    btn.disabled = true;
      if (txtEl)  txtEl.style.display = 'none';
      if (spinEl) spinEl.style.display = 'inline-block';
    });
  });
}

/* ── Modal helpers ──────────────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id)?.classList.add('show'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('show'); }

// Close modal on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('show');
  }
});

// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
    document.querySelectorAll('.dropdown-menu.open').forEach(m => m.classList.remove('open'));
  }
});

/* ── Star rating picker ─────────────────────────────────────────────────── */
function initStarPicker(containerId) {
  const container = document.getElementById(containerId || 'star-picker');
  if (!container) return;

  const radios = container.querySelectorAll('input[type="radio"]');
  const icons  = container.querySelectorAll('i');

  radios.forEach((radio, i) => {
    radio.addEventListener('change', () => {
      icons.forEach((icon, j) => {
        icon.className    = j <= i ? 'bi bi-star-fill' : 'bi bi-star';
        icon.style.color  = j <= i ? 'var(--accent)' : 'var(--border-strong)';
      });
    });
  });
}

/* ── Trade type button picker ───────────────────────────────────────────── */
function initTradeTypePicker() {
  document.querySelectorAll('.want-radio').forEach(radio => {
    radio.addEventListener('change', function() {
      document.querySelectorAll('.trade-type-btn').forEach(btn => {
        btn.style.borderColor  = 'var(--border)';
        btn.style.background   = '';
        const icon = btn.querySelector('i');
        if (icon) icon.style.color = 'var(--text-muted)';
      });
      const btn = this.nextElementSibling;
      if (btn) {
        btn.style.borderColor = 'var(--primary)';
        btn.style.background  = 'rgba(26,107,74,.05)';
        const icon = btn.querySelector('i');
        if (icon) icon.style.color = 'var(--primary)';
      }
    });
  });

  // Trigger for pre-selected
  const checked = document.querySelector('.want-radio:checked');
  if (checked) checked.dispatchEvent(new Event('change'));
}

/* ── Image gallery (listing view) ───────────────────────────────────────── */
function initImageGallery() {
  const mainImg = document.getElementById('main-img');
  const thumbs  = document.querySelectorAll('.thumb-img');
  const gallery = mainImg?.closest('.listing-gallery__main');

  if (mainImg && gallery) {
    const hideSkeleton = () => gallery.classList.remove('is-loading');
    if (mainImg.complete && mainImg.naturalWidth > 0) {
      hideSkeleton();
    } else {
      mainImg.addEventListener('load', hideSkeleton, { once: true });
      mainImg.addEventListener('error', hideSkeleton, { once: true });
    }
  }

  if (!mainImg || !thumbs.length) return;

  thumbs.forEach(thumb => {
    thumb.addEventListener('click', function() {
      if (gallery) gallery.classList.add('is-loading');
      mainImg.addEventListener('load', () => gallery?.classList.remove('is-loading'), { once: true });
      mainImg.src = this.src;
      thumbs.forEach(t => t.style.outline = 'none');
      this.style.outline = '2.5px solid var(--primary)';
    });
  });
}

/* ── Upload zone (create listing) ───────────────────────────────────────── */
function initUploadZone() {
  const zone  = document.getElementById('upload-zone');
  const input = document.getElementById('images');
  const grid  = document.getElementById('preview-grid');
  const maxImages = parseInt(zone?.dataset.max || 8);

  if (!zone || !input || !grid) return;

  let files = [];

  zone.addEventListener('click', e => {
    if (e.target === zone || e.target.closest('.upload-zone') === zone) {
      input.click();
    }
  });

  input.addEventListener('change', () => addFiles(Array.from(input.files)));

  zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragging'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
  zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragging');
    addFiles(Array.from(e.dataTransfer.files));
  });

  function addFiles(newFiles) {
    newFiles.forEach(f => {
      if (files.filter(Boolean).length >= maxImages) return;
      if (!f.type.match('image.*')) return;
      const idx = files.length;
      files.push(f);
      const reader = new FileReader();
      reader.onload = ev => renderPreview(ev.target.result, idx);
      reader.readAsDataURL(f);
    });
    syncInput();
  }

  function renderPreview(src, idx) {
    const wrap = document.createElement('div');
    wrap.className = 'preview-img-wrap';
    wrap.id = 'prev-' + idx;
    const isPrimary = files.filter(Boolean).indexOf(files[idx]) === 0;
    if (isPrimary) wrap.style.outline = '2.5px solid var(--primary)';
    wrap.innerHTML = `<img src="${src}" alt=""><button type="button" class="preview-img-remove" aria-label="Remove"><i class="bi bi-x"></i></button>`;
    wrap.querySelector('button').addEventListener('click', () => removeImg(idx));
    grid.appendChild(wrap);
  }

  function removeImg(idx) {
    files[idx] = null;
    document.getElementById('prev-' + idx)?.remove();
    syncInput();
  }

  function syncInput() {
    const dt = new DataTransfer();
    files.filter(Boolean).forEach(f => dt.items.add(f));
    input.files = dt.files;
  }
}

/* ── Quick amount buttons (wallet) ─────────────────────────────────────── */
function initQuickAmounts() {
  document.querySelectorAll('[data-quick-amount]').forEach(btn => {
    btn.addEventListener('click', function() {
      const targetName = this.dataset.quickAmount;
      const input = document.querySelector(`[name="${targetName}"]`);
      if (input) input.value = this.dataset.amount;
    });
  });
}

/* ── Password strength meter ────────────────────────────────────────────── */
function initPasswordStrength() {
  const passInput = document.getElementById('password');
  const strengthEl = document.getElementById('pass-strength');
  if (!passInput || !strengthEl) return;

  passInput.addEventListener('input', function() {
    const v = this.value;
    let score = 0;
    if (v.length >= 8) score++;
    if (/[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;

    const labels = [
      '',
      '<span style="color:var(--danger)">ضعیف</span>',
      '<span style="color:var(--warning)">متوسط</span>',
      '<span style="color:var(--info)">خوب</span>',
      '<span style="color:var(--success)">قوی ✓</span>',
    ];
    strengthEl.innerHTML = v ? 'قدرت رمز: ' + (labels[score] || '') : '';
  });
}

/* ── Login tab switcher ─────────────────────────────────────────────────── */
function switchLoginTab(t) {
  const emailPanel = document.getElementById('tab-email');
  const otpPanel   = document.getElementById('tab-otp');
  const btns       = document.querySelectorAll('.tab-btn');

  if (emailPanel) emailPanel.classList.toggle('active', t === 'email');
  if (otpPanel)   otpPanel.classList.toggle('active', t !== 'email');
  btns.forEach((b, i) => b.classList.toggle('active', i === (t === 'email' ? 0 : 1)));
}

/* ── Copy to clipboard ──────────────────────────────────────────────────── */
async function copyToClipboard(text, successMsg = 'کپی شد!') {
  try {
    await navigator.clipboard.writeText(text);
    showToast(successMsg, 'success');
  } catch {
    showToast('امکان کپی وجود ندارد.', 'error');
  }
}

/* ── Share button helper ────────────────────────────────────────────────── */
function shareOrCopy(title, url) {
  if (navigator.share) {
    navigator.share({ title, url }).catch(() => {});
  } else {
    copyToClipboard(url, 'لینک کپی شد!');
  }
}

/* ── Confirm forms ──────────────────────────────────────────────────────── */
function initConfirmForms() {
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', function(e) {
      const msg = this.dataset.confirm;
      if (!window.confirm(msg)) e.preventDefault();
    });
  });
}

/* ── Global forced navigation handler (for data-navigate attr) ─────────── */
function initNavigateHandlers() {
  document.addEventListener('click', function(e) {
    const clickedInteractive = e.target.closest('a, button, [data-save-toggle], input, select, textarea, label, [data-bs-toggle]');
    const navEl = e.target.closest('[data-navigate]');
    if (!navEl) return;
    if (clickedInteractive && clickedInteractive !== navEl) {
      if (!clickedInteractive.hasAttribute('data-navigate')) {
        return;
      }
    }
    const url = navEl.getAttribute('data-navigate');
    if (!url) return;
    e.preventDefault();
    e.stopPropagation();
    window.location.href = url;
  });
}

/* ── Main init ──────────────────────────────────────────────────────────── */
/* ── Mobile nav drawer ─────────────────────────────────────────────────── */
function initMobileNav() {
  const hamburger = document.getElementById('nav-hamburger');
  const closeBtn  = document.getElementById('nav-hamburger-close');
  const drawer    = document.getElementById('mobile-drawer');
  const overlay   = document.getElementById('mobile-nav-overlay');
  if (!hamburger || !drawer || !overlay) return;

  const open  = () => {
    drawer.classList.add('is-open');
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  };
  const close = () => {
    drawer.classList.remove('is-open');
    overlay.classList.remove('is-open');
    document.body.style.overflow = '';
  };

  hamburger.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  overlay.addEventListener('click', close);
  drawer.querySelectorAll('a').forEach(a => a.addEventListener('click', close));

  const searchLink = document.getElementById('mobile-search-link');
  if (searchLink) {
    searchLink.addEventListener('click', e => {
      e.preventDefault();
      openModal('search-modal');
      const searchModalInput = document.getElementById('search-modal-input');
      if (searchModalInput) {
        searchModalInput.focus();
      }
    });
  }
}

/* ── Notification modal ─────────────────────────────────────────────────── */
function initNotifModal() {
  const bell    = document.getElementById('notif-bell-btn');
  const modal   = document.getElementById('notif-modal');
  const closeBtn= document.getElementById('notif-modal-close');
  const listEl  = document.getElementById('notif-list');
  const loadEl  = document.getElementById('notif-loading');
  if (!bell || !modal) return;

  const appUrl = getAppUrl();

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  async function loadNotifications() {
    if (!listEl || !loadEl) return;
    loadEl.innerHTML = skeletonNotifItemsHtml(4);
    loadEl.style.display = 'block';
    loadEl.setAttribute('aria-busy', 'true');
    listEl.style.display = 'none';
    listEl.innerHTML = '';

    try {
      const res  = await fetch(appUrl + '/api/notifications.php');
      const data = await res.json();
      loadEl.style.display = 'none';
      loadEl.setAttribute('aria-busy', 'false');
      listEl.style.display = 'block';

      if (!data.ok || !data.items?.length) {
        listEl.innerHTML = '<div class="notif-empty"><i class="bi bi-bell-slash"></i><p>اعلان جدیدی ندارید.</p></div>';
        return;
      }

      listEl.innerHTML = data.items.map(item => `
        <a href="${escHtml(item.url)}" class="notif-item notif-item--${escHtml(item.type)}">
          <span class="notif-item__icon"><i class="bi ${escHtml(item.icon)}"></i></span>
          <span class="notif-item__body">
            <strong>${escHtml(item.title)}</strong>
            <span>${escHtml(item.body)}</span>
          </span>
          <span class="notif-item__time">${escHtml(item.time_ago)}</span>
        </a>
      `).join('');
    } catch {
      loadEl.style.display = 'none';
      listEl.style.display = 'block';
      listEl.innerHTML = '<div class="notif-empty"><p>خطا در بارگذاری اعلان‌ها.</p></div>';
    }
  }

  bell.addEventListener('click', () => {
    openModal('notif-modal');
    loadNotifications();
  });

  closeBtn?.addEventListener('click', () => closeModal('notif-modal'));
}

/* ── Search modal ─────────────────────────────────────────────────── */
function initSearchModal() {
  const searchModalTriggers = document.querySelectorAll('#search-modal-trigger');
  const searchModalClose = document.getElementById('search-modal-close');
  const searchModalForm = document.getElementById('search-modal-form');
  
  searchModalTriggers.forEach(trigger => {
    trigger.addEventListener('click', () => {
      openModal('search-modal');
      const searchModalInput = document.getElementById('search-modal-input');
      if (searchModalInput) {
        searchModalInput.focus();
      }
    });
  });
  
  searchModalClose?.addEventListener('click', () => closeModal('search-modal'));
  
  if (searchModalForm) {
    searchModalForm.addEventListener('submit', (e) => {
      // Let the form submit normally
    });
  }
}

/* ── AI Chat ─────────────────────────────────────────────────────────────── */
function initAiChat() {
  const app     = document.getElementById('ai-chat-app');
  const form    = document.getElementById('ai-chat-form');
  const input   = document.getElementById('ai-chat-input');
  const messages= document.getElementById('ai-chat-messages');
  if (!app || !form || !input || !messages) return;

  const appUrl  = getAppUrl();
  const history = [];
  let sending   = false;

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function appendMsg(text, role) {
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg ai-msg--' + (role === 'user' ? 'user' : 'bot');
    const safe = escHtml(text).replace(/\n/g, '<br>');
    wrap.innerHTML = role === 'bot'
      ? `<div class="ai-msg__avatar"><i class="bi bi-robot"></i></div><div class="ai-msg__bubble">${safe}</div>`
      : `<div class="ai-msg__bubble">${safe}</div>`;
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function appendTyping() {
    const wrap = document.createElement('div');
    wrap.className = 'ai-msg ai-msg--bot ai-msg--typing';
    wrap.id = 'ai-typing-indicator';
    wrap.innerHTML = '<div class="ai-msg__avatar"><i class="bi bi-robot"></i></div><div class="ai-msg__bubble"><span class="ai-typing-dots"><span></span><span></span><span></span></span></div>';
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;
  }

  function removeTyping() {
    document.getElementById('ai-typing-indicator')?.remove();
  }

  async function send(text) {
    const msg = text.trim();
    if (!msg || sending) return;
    sending = true;
    input.disabled = true;

    appendMsg(msg, 'user');
    history.push({ role: 'user', content: msg });
    input.value = '';
    appendTyping();

    try {
      const data = await swaapinAiChatFromBrowser(appUrl, msg, history.slice(0, -1));
      removeTyping();

      if (!data.ok || !data.message) {
        throw new Error(data.error || 'خطا');
      }

      appendMsg(data.message, 'bot');
      history.push({ role: 'assistant', content: data.message });
      if (history.length > 20) history.splice(0, history.length - 20);
    } catch (err) {
      removeTyping();
      appendMsg(err.message || 'متأسفانه پاسخ AI دریافت نشد. لطفاً دوباره تلاش کنید.', 'bot');
    }

    sending = false;
    input.disabled = false;
    input.focus();
  }

  form.addEventListener('submit', e => {
    e.preventDefault();
    send(input.value);
  });

  document.querySelectorAll('.ai-chip').forEach(chip => {
    chip.addEventListener('click', () => send(chip.dataset.prompt || chip.textContent));
  });
}

/* ── AI Valuation Form (section below chat) ─────────────────────────────── */
(function () {
  function init() {
    const form          = document.getElementById('ai-valuation-form');
    const valSection    = document.getElementById('ai-valuation-section');

    if (!form) return;

    const titleInput   = document.getElementById('val-title');
    const descInput    = document.getElementById('val-description');
    const catSelect    = document.getElementById('val-category');
    const condSelect   = document.getElementById('val-condition');
    const submitBtn    = document.getElementById('val-submit-btn');
    const btnLabel     = document.getElementById('val-btn-label');
    const btnLoading   = document.getElementById('val-btn-loading');
    const errorDiv     = document.getElementById('ai-valuation-error');
    const resultDiv    = document.getElementById('ai-valuation-result');
    const createLink   = document.getElementById('ai-valuation-create-link');
    const chatMessages = document.getElementById('ai-chat-messages');
    const valBox       = document.querySelector('.ai-valuation-box');

    if (!titleInput || !descInput || !catSelect || !condSelect || !submitBtn) return;

    const appUrl = (typeof getAppUrl === 'function') ? getAppUrl() : '';

    function escHtml(str) {
      const d = document.createElement('div');
      d.textContent = str == null ? '' : String(str);
      return d.innerHTML;
    }

    function setLoading(loading) {
      if (btnLabel)   btnLabel.style.display   = loading ? 'none'       : '';
      if (btnLoading) btnLoading.style.display = loading ? 'inline-flex' : 'none';
      submitBtn.disabled = !!loading;
    }

    function showError(msg) {
      if (!errorDiv) return;
      errorDiv.textContent = msg;
      errorDiv.style.display = 'block';
    }

    function hideError() {
      if (!errorDiv) return;
      errorDiv.style.display = 'none';
      errorDiv.textContent = '';
    }

    function appendBotMsg(text) {
      if (!chatMessages) return;
      const wrap = document.createElement('div');
      wrap.className = 'ai-msg ai-msg--bot';
      const safe = escHtml(text).replace(/\n/g, '<br>');
      wrap.innerHTML =
        '<div class="ai-msg__avatar"><i class="bi bi-robot"></i></div>' +
        '<div class="ai-msg__bubble">' + safe + '</div>';
      chatMessages.appendChild(wrap);
      chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function pulseHighlight() {
      const target = valBox || valSection || form;
      if (!target) return;
      const orig = target.style.boxShadow;
      const origTrans = target.style.transition;
      target.style.transition = 'box-shadow .25s ease';
      let step = 0;
      const tick = () => {
        step++;
        target.style.boxShadow = step % 2 === 1
          ? '0 0 0 4px rgba(255,209,102,.55), 0 10px 25px -5px rgba(10,37,64,.15)'
          : '0 0 0 2px rgba(255,209,102,.28), 0 4px 12px -4px rgba(10,37,64,.1)';
        if (step < 5) setTimeout(tick, 200);
        else {
          target.style.boxShadow = orig;
          target.style.transition = origTrans;
        }
      };
      tick();
    }

    document.querySelectorAll('[data-prompt], .ai-chip').forEach(chip => {
      const scrollTargetId = chip.getAttribute('data-scroll-target');
      const prompt = (chip.getAttribute('data-prompt') || chip.textContent || '').toString();
      const txt    = (chip.textContent || '').toString();
      if (scrollTargetId === 'ai-valuation-section' || prompt.includes('ارزش‌گذاری') || txt.includes('ارزش‌گذاری')) {
        chip.addEventListener('click', () => {
          setTimeout(() => {
            const scrollTo = valSection || valBox || form;
            if (scrollTo) scrollTo.scrollIntoView({ behavior: 'smooth', block: 'start' });
            pulseHighlight();
            if (titleInput) titleInput.focus({ preventScroll: true });
          }, 40);
        });
      }
    });

    function toFaDigits(s) {
      return String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
    }

    function formatCreditLocal(amount) {
      const n = Math.round(Number(amount) || 0);
      const unit = getCreditUnit ? getCreditUnit() : 'تومان';
      const formatted = new Intl.NumberFormat('fa-IR').format(n);
      return formatted + ' ' + unit;
    }

    function buildValuationFallback(title, desc, cond, catId) {
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
        fallback: true,
      };
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      hideError();

      const title = titleInput.value.trim();
      const desc  = descInput.value.trim();
      const catId = catSelect.value;
      const cond  = condSelect.value;

      if (title.length < 5)      { showError('عنوان حداقل ۵ کاراکتر باشد');           return; }
      if (desc.length  < 20)     { showError('توضیحات حداقل ۲۰ کاراکتر باشد');        return; }
      if (!(Number(catId) > 0))  { showError('دسته‌بندی را انتخاب کنید');             return; }
      if (!cond)                 { showError('وضعیت کالا را انتخاب کنید');            return; }

      setLoading(true);

      // Server prepares mode:pricing messages; browser completes (server IP is often 403'd).
      const fd = new FormData(form);
      fd.set('title', title);
      fd.set('description', desc);
      fd.set('category_id', catId);
      fd.set('condition', cond);

      const minDelay = new Promise(r => setTimeout(r, 2800));

      let data = null;

      try {
        const [valuate] = await Promise.all([
          swaapinAiValuateFromBrowser(appUrl, fd),
          minDelay,
        ]);
        data = valuate;
      } catch (err) {
        data = buildValuationFallback(title, desc, cond, catId);
        if (chatMessages) {
          const msg = err && err.message ? String(err.message) : 'خطای نامشخص';
          appendBotMsg('خطا در ارزش‌گذاری: ' + msg + ' — از تخمین محلی مرورگر استفاده شد.');
        }
      }

      if (!data) {
        data = buildValuationFallback(title, desc, cond, catId);
      }

      try {
        if (resultDiv) {
          resultDiv.style.display = 'none';

          resultDiv.querySelectorAll('[data-result]').forEach(el => {
            const key = el.getAttribute('data-result');
            if (key === 'confidence') {
              const warn = data.uncertain ? '⚠ ' : '';
              el.textContent = warn + 'اطمینان AI: ' + (data.confidence ?? '') + '%';
            } else if (key === 'reasons') {
              el.innerHTML = '';
              if (Array.isArray(data.reasons)) {
                data.reasons.forEach(r => {
                  const li = document.createElement('li');
                  li.innerHTML = '<i class="bi bi-check2"></i>' + escHtml(r);
                  el.appendChild(li);
                });
              }
            } else if (data && key in data) {
              el.textContent = data[key] == null ? '' : String(data[key]);
            }
          });

          resultDiv.style.display = 'block';
          resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        if (createLink) {
          const params = new URLSearchParams();
          params.set('prefill_title', title);
          params.set('prefill_desc',  desc);
          params.set('prefill_cat',   catId);
          params.set('prefill_cond',  cond);
          if (data.value !== undefined && data.value !== null) {
            params.set('prefill_value', String(data.value));
          }
          const base = createLink.getAttribute('href') || (appUrl + '/listings/create.php');
          const sep  = base.includes('?') ? '&' : '?';
          createLink.href = base + sep + params.toString();
        }

        if (chatMessages && !data.fallback && data.ai_source !== 'fallback_local') {
          const summary =
            'برآورد ارزش برای «' + title + '»: ' +
            (data.value_fmt || '') +
            (data.range_fmt ? ' (محدوده: ' + data.range_fmt + ')' : '');
          appendBotMsg(summary);
        }
      } catch (renderErr) {
        showError(renderErr && renderErr.message ? renderErr.message : 'خطا در نمایش نتیجه.');
      } finally {
        setLoading(false);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/* ── AI Matching Engine (dashboard) ─────────────────────────────────────── */
function initAiMatch() {
  const hub     = document.getElementById('swap-matches');
  const listEl  = document.getElementById('ai-match-list');
  const loadEl  = document.getElementById('ai-match-loading');
  const refresh = document.getElementById('ai-match-refresh');
  const select  = document.getElementById('ai-match-listing');
  if (!hub || !listEl) return;

  const appUrl = getAppUrl();

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  const toFaDigits = (s) => String(s).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

  function pillarBar(label, cls, pct, title) {
    const p = Math.max(0, Math.min(100, Number(pct) || 0));
    return `
      <div class="match-pillar" title="${escHtml(title + ' — ' + p + '٪')}">
        <div class="match-pillar__label">${escHtml(label)}</div>
        <div class="match-pillar__bar"><span class="match-pillar__fill match-pillar__fill--${cls}" style="width:${p}%"></span></div>
        <div class="match-pillar__pct">${toFaDigits(p)}٪</div>
      </div>`;
  }

  function renderMatches(matches, source) {
    if (!matches.length) {
      listEl.innerHTML = `
        <div class="empty-state" style="padding:var(--sp-6) 0">
          <i class="bi bi-search"></i>
          <p class="fs-sm" style="color:var(--text-muted)">تطابق AI پیدا نشد. «نیازمند» را دقیق‌تر بنویسید.</p>
        </div>`;
      return;
    }

    listEl.innerHTML = matches.map(m => {
      const badges = [
        (source === 'assistant') ? '<span class="badge badge-gold fs-xs">هوشمند</span>' : '',
        m.mutual ? '<span class="badge badge-gold fs-xs">دوطرفه</span>' : '',
        m.trade_type === 'credit' ? '<span class="badge badge-primary fs-xs">اعتباری</span>' : '',
      ].filter(Boolean).join(' ');

      const need  = Number(m.score_need || 0);
      const val   = Number(m.score_value || 0);
      const cat   = Number(m.score_category || 0);
      const succ  = Number(m.score_success || 0);

      return `
        <a href="${escHtml(m.url)}" class="match-row" data-listing-id="${escHtml(String(m.listing_id))}">
          <div class="match-row__score">${toFaDigits(m.match_score)}٪</div>
          <div class="match-row__body">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span style="font-weight:700">${escHtml(m.title)}</span>
              ${badges}
            </div>
            <div class="fs-xs" style="color:var(--text-muted)">
              ${escHtml(m.seller_name)} · برای: ${escHtml((m.match_title || '').slice(0, 30))}${(m.match_title || '').length > 30 ? '…' : ''}
            </div>
            ${m.reason ? `<p class="match-row__reason fs-xs">${escHtml(m.reason)}</p>` : ''}
            <div class="match-pillars" aria-label="چهار عامل تطبیق هوشمند">
              ${pillarBar('نیاز',     'need',    need, 'نیاز کاربران')}
              ${pillarBar('ارزش',    'value',   val,  'ارزش کالا')}
              ${pillarBar('دسته',    'cat',     cat,  'دسته‌بندی')}
              ${pillarBar('موفقیت', 'success', succ,  'احتمال موفقیت')}
            </div>
          </div>
          <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
        </a>`;
    }).join('');

    const badge = hub.querySelector('.match-hub__title .badge-gold');
    if (source === 'assistant' && !badge) {
      const h2 = hub.querySelector('.match-hub__title h2');
      if (h2) h2.insertAdjacentHTML('beforeend', ' <span class="badge badge-gold fs-xs">هوشمند</span>');
    }
  }

  async function loadMatches(forceRefresh = false) {
    if (loadEl) {
      loadEl.hidden = false;
      loadEl.innerHTML = skeletonMatchRowsHtml(3);
      loadEl.setAttribute('aria-busy', 'true');
    }
    if (refresh) refresh.disabled = true;
    listEl.style.visibility = 'hidden';
    listEl.setAttribute('aria-busy', 'true');

    const params = new URLSearchParams();
    if (select?.value) params.set('listing_id', select.value);
    if (forceRefresh) params.set('refresh', '1');

    try {
      const res  = await fetch(`${appUrl}/api/ai_match.php?${params}`, {
        credentials: 'same-origin',
        headers: forceRefresh ? withCsrfHeaders() : {},
      });
      const data = await res.json();
      if (!data.ok) {
        if (data.error === 'rate_limited') {
          throw new Error(data.message || 'سقف بروزرسانی AI پر شده. کمی بعد دوباره تلاش کنید.');
        }
        throw new Error(data.error || 'خطا');
      }
      renderMatches(data.matches || [], data.source || 'rules');
      if (forceRefresh && typeof showToast === 'function') {
        showToast('پیشنهادهای معاوضه با AI بروزرسانی شد.', 'success');
      }
    } catch (err) {
      if (typeof showToast === 'function') {
        showToast(err.message || 'خطا در بارگذاری تطابق‌های AI.', 'error');
      }
    } finally {
      if (loadEl) {
        loadEl.hidden = true;
        loadEl.setAttribute('aria-busy', 'false');
      }
      listEl.style.visibility = '';
      listEl.setAttribute('aria-busy', 'false');
      if (refresh) refresh.disabled = false;
    }
  }

  refresh?.addEventListener('click', () => loadMatches(true));
  select?.addEventListener('change', () => loadMatches(false));
}

/* ── Support floating widget ───────────────────────────────────────────── */
function initSupportWidget() {
  const toggle = document.getElementById('support-widget-toggle');
  const menu   = document.getElementById('support-widget-menu');
  if (!toggle || !menu) return;

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = !menu.hidden;
    menu.hidden = open;
    toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
    toggle.classList.toggle('is-open', !open);
  });

  document.addEventListener('click', (e) => {
    if (!document.getElementById('support-widget')?.contains(e.target)) {
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.classList.remove('is-open');
    }
  });
}

/* ── Homepage steps reveal ─────────────────────────────────────────────── */
function initStepsReveal() {
  const cards = document.querySelectorAll('.step-card');
  if (!cards.length) return;

  if (!('IntersectionObserver' in window)) {
    cards.forEach(card => card.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver((entries, obs) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      obs.unobserve(entry.target);
    });
  }, {
    threshold: 0.2,
    rootMargin: '0px 0px -40px 0px',
  });

  cards.forEach(card => observer.observe(card));
}

function initListingsSliderArrows() {
  const arrows = document.querySelectorAll('.listings-slider-arrow');
  if (!arrows.length) return;

  arrows.forEach((arrow) => {
    arrow.addEventListener('click', () => {
      const targetId = arrow.getAttribute('data-target');
      const row = targetId ? document.getElementById(targetId) : null;
      if (!row) return;

      const firstCard = row.querySelector('.listings-scroll-card') || row.querySelector('.home-stores-card');
      const scrollStep = firstCard
        ? firstCard.getBoundingClientRect().width + 20
        : Math.max(row.clientWidth * 0.75, 280);
      const direction = arrow.classList.contains('listings-slider-arrow--prev') ? -1 : 1;

      row.scrollBy({
        left: direction * scrollStep,
        behavior: 'smooth',
      });
    });
  });
}

function initFilterModal() {
  const openBtn = document.getElementById('open-filter-modal');
  const closeBtn = document.getElementById('close-filter-modal');
  const modal = document.getElementById('filter-modal');
  const overlay = document.getElementById('filter-modal-overlay');

  if (!openBtn || !closeBtn || !modal || !overlay) return;

  function openModal() {
    modal.classList.add('open');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.classList.remove('open');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  openBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);
  overlay.addEventListener('click', closeModal);
}

function initListingsLocationFilter() {
  const form = document.getElementById('all-listings-filters');
  const locSelect = document.getElementById('loc-mode');
  const nearbyInput = document.getElementById('nearby-cities');
  const citySelect = document.getElementById('city');
  const alertBox = document.getElementById('loc-filter-alert');

  if (!form || !locSelect || !nearbyInput) return;

  const appUrl = getAppUrl() || window.location.origin;

  function showAlert(message) {
    if (!alertBox) return;
    alertBox.textContent = message;
    alertBox.hidden = false;
  }

  function hideAlert() {
    if (!alertBox) return;
    alertBox.hidden = true;
    alertBox.textContent = '';
  }

  function resetToAllCities() {
    locSelect.value = '';
    nearbyInput.value = '';
    if (citySelect) citySelect.disabled = false;
  }

  function buildFilterUrl(extraParams = {}) {
    const params = new URLSearchParams(new FormData(form));
    params.delete('page');

    if (locSelect.value !== 'nearby') {
      params.delete('loc');
      params.delete('nearby_cities');
    }

    Object.entries(extraParams).forEach(([key, value]) => {
      if (value === '' || value === null || value === undefined) {
        params.delete(key);
      } else {
        params.set(key, value);
      }
    });

    const qs = params.toString();
    return `${appUrl}/listings/all.php${qs ? `?${qs}` : ''}`;
  }

  locSelect.addEventListener('change', () => {
    hideAlert();

    if (locSelect.value !== 'nearby') {
      nearbyInput.value = '';
      if (citySelect) citySelect.disabled = false;
      window.location.href = buildFilterUrl({ loc: '', nearby_cities: '' });
      return;
    }

    if (citySelect) {
      citySelect.value = '';
      citySelect.disabled = true;
    }

    if (!navigator.geolocation) {
      showAlert('مرورگر شما از موقعیت مکانی پشتیبانی نمی‌کند. فیلتر به حالت «همه شهرها» برگشت.');
      resetToAllCities();
      return;
    }

    locSelect.disabled = true;

    navigator.geolocation.getCurrentPosition(
      async (position) => {
        try {
          const { latitude, longitude } = position.coords;
          const res = await fetch(
            `${appUrl}/api/nearby_cities.php?lat=${encodeURIComponent(latitude)}&lng=${encodeURIComponent(longitude)}`,
            { credentials: 'same-origin' }
          );
          const data = await res.json();

          if (!res.ok || !data.ok || !Array.isArray(data.cities) || !data.cities.length) {
            throw new Error(data.error || 'no_cities_found');
          }

          nearbyInput.value = data.cities.join(',');
          window.location.href = buildFilterUrl({
            loc: 'nearby',
            nearby_cities: nearbyInput.value,
            city: '',
          });
        } catch {
          showAlert('شهر نزدیکی یافت نشد. فیلتر به حالت «همه شهرها» برگشت.');
          resetToAllCities();
          locSelect.disabled = false;
        }
      },
      () => {
        showAlert('دسترسی به موقعیت مکانی رد شد. فیلتر به حالت «همه شهرها» برگشت.');
        resetToAllCities();
        locSelect.disabled = false;
      },
      { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 }
    );
  });
}

/* ── OTP / verification input — one box per digit ──────────────────────── */
function initOtpInputs() {
  const faDigits = { '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9' };
  const arDigits = { '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9' };
  const normDigit = (ch) => {
    if (!ch || !ch.length) return '';
    const c = ch.charAt(ch.length - 1);
    return (faDigits[c] ?? arDigits[c] ?? c);
  };

  document.querySelectorAll('.otp-group').forEach(group => {
    const inputs = Array.from(group.querySelectorAll('.otp-group__digit'));
    if (!inputs.length) return;

    let target = null;
    const targetName = group.dataset.target || '';
    if (targetName) {
      target = group.closest('form')?.querySelector(`input[name="${targetName}"]`);
    }
    if (!target) {
      const form = group.closest('form');
      target = form?.querySelector('input[name="code"]') || form?.querySelector('input[name="otp"]');
    }

    const digitsOnly = (group.dataset.mode || 'digits') === 'digits';

    const updateTarget = () => {
      const joined = inputs.map(i => i.value || '').join('');
      if (target) target.value = joined;
      group.dispatchEvent(new CustomEvent('otp-change', { detail: { value: joined, complete: joined.length === inputs.length } }));
    };

    const moveFocus = (idx) => {
      if (idx < 0) idx = 0;
      if (idx >= inputs.length) idx = inputs.length - 1;
      const el = inputs[idx];
      if (!el) return;
      try { el.focus(); } catch {}
      try { el.setSelectionRange(el.value.length, el.value.length); } catch {}
    };

    const setShake = (on) => {
      inputs.forEach(i => i.classList.toggle('is-invalid', !!on));
      if (on) {
        setTimeout(() => inputs.forEach(i => i.classList.remove('is-invalid')), 500);
      }
    };
    group.addEventListener('otp-shake', () => setShake(true));

    inputs.forEach((inp, idx) => {
      inp.addEventListener('input', (e) => {
        let raw = inp.value || '';
        let cleaned = '';
        for (const ch of raw) {
          const nc = normDigit(ch);
          if (digitsOnly && !/^[0-9]$/.test(nc)) continue;
          if (!/^\s$/.test(nc)) cleaned += nc;
        }
        if (!cleaned) {
          inp.value = '';
          inp.classList.remove('is-filled');
          updateTarget();
          return;
        }
        if (cleaned.length > 1) {
          let extras = cleaned.slice(1);
          inp.value = cleaned.charAt(0);
          let next = idx + 1;
          while (extras && next < inputs.length) {
            inputs[next].value = extras.charAt(0);
            inputs[next].classList.add('is-filled');
            extras = extras.slice(1);
            next++;
          }
          inputs.forEach(i => i.classList.toggle('is-filled', i.value.length > 0));
          updateTarget();
          moveFocus(next - 1);
          return;
        }
        inp.value = cleaned.charAt(0);
        inp.classList.add('is-filled');
        updateTarget();
        if (idx < inputs.length - 1) moveFocus(idx + 1);
      });

      inp.addEventListener('keydown', (e) => {
        const key = e.key;
        if (key === 'Backspace') {
          e.stopPropagation();
          if (!inp.value) {
            if (idx > 0) {
              inputs[idx - 1].value = '';
              inputs[idx - 1].classList.remove('is-filled');
              updateTarget();
              moveFocus(idx - 1);
              e.preventDefault();
            }
          } else {
            // let default behavior clear value; after clearing our input will fire
          }
          return;
        }
        if (key === 'Delete') {
          if (inp.value) {
            inp.value = '';
            inp.classList.remove('is-filled');
            updateTarget();
            e.preventDefault();
          }
          return;
        }
        if (key === 'ArrowLeft') {
          e.preventDefault();
          moveFocus(idx - 1);
          return;
        }
        if (key === 'ArrowRight') {
          e.preventDefault();
          moveFocus(idx + 1);
          return;
        }
        if (key === 'Enter') {
          // let form submit
          return;
        }
        if (key === 'Tab') {
          return;
        }
        if (digitsOnly) {
          if (/^F[0-9]+$/.test(key) || key === 'Escape' || key === 'Control' || key === 'Shift' || key === 'Alt' || key === 'Meta') return;
          if (!/^[0-9۰-۹٠-٩]$/.test(key)) {
            e.preventDefault();
          }
        }
      });

      inp.addEventListener('paste', (e) => {
        e.preventDefault();
        const txt = (e.clipboardData || window.clipboardData).getData('text') || '';
        if (!txt) return;
        let clean = '';
        for (const ch of txt) {
          const nc = normDigit(ch);
          if (digitsOnly && !/^[0-9]$/.test(nc)) continue;
          if (!/^\s$/.test(nc)) clean += nc;
        }
        if (!clean) return;
        const start = idx;
        for (let i = 0; i < inputs.length - start && i < clean.length; i++) {
          inputs[start + i].value = clean.charAt(i);
          inputs[start + i].classList.add('is-filled');
        }
        inputs.forEach(i => i.classList.toggle('is-filled', i.value.length > 0));
        updateTarget();
        const nextIdx = Math.min(start + clean.length, inputs.length - 1);
        moveFocus(nextIdx);
      });

      inp.addEventListener('focus', () => {
        try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch {}
      });
    });

    // Browser one-time-code autofill on any of the inputs
    group.addEventListener('input', () => {
      // already handled above via individual input listeners
    }, true);

    updateTarget();
  });
}

/* ── Reset scroll position on page load/reload ────────────────────────── */
function initScrollReset() {
  if ('scrollRestoration' in window.history) {
    window.history.scrollRestoration = 'manual';
  }

  const resetToTop = () => {
    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
  };

  const restoreTop = () => {
    window.requestAnimationFrame(() => {
      resetToTop();
      window.requestAnimationFrame(resetToTop);
    });
  };

  window.addEventListener('load', restoreTop);
  window.addEventListener('pageshow', restoreTop);
  restoreTop();
}

initScrollReset();

document.addEventListener('DOMContentLoaded', () => {
  // Global forced navigation handler (data-navigate) — register ASAP (before everything else via capture), but also here
  initNavigateHandlers();

  // Prevent back buttons from accidentally triggering other clicks
  document.querySelectorAll('.dash-back-btn, .trade-room__back, .promote-back-link, .btn').forEach(btn => {
    // Check if the button has a bi-arrow-right icon
    if (btn.querySelector('i.bi-arrow-right') || (btn.querySelector('.bi-arrow-right'))) {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
      });
    }
  });

  initDropdowns();
  initMobileNav();
  initNotifModal();
  initSearchModal();
  initAiChat();
  initAiMatch();
  initCharCounters();
  initAutoDismissAlerts();
  initSmoothAnchors();
  initNavbarScroll();
  initLazyImages();
  initGlobalSearch();
  initHomeFilters();
  initSaveButtons();
  initOfferForm();
  initLoadingForms();
  initStarPicker();
  initTradeTypePicker();
  initImageGallery();
  initUploadZone();
  initQuickAmounts();
  initPasswordStrength();
  initConfirmForms();
  initSupportWidget();
  initStepsReveal();
  initListingsSliderArrows();
  initFilterModal();
  initListingsLocationFilter();
  initOtpInputs();
  initCatTreeToggles();
  initNavbarHoverDropdowns();

  // Restore active tab from URL
  const tabParam = new URLSearchParams(window.location.search).get('tab');
  if (tabParam && document.querySelector(`.tab-btn[data-tab="${tabParam}"]`)) {
    switchTab(tabParam);
  }

  // Show success toast if URL has ?toast param
  const toastMsg = new URLSearchParams(window.location.search).get('toast');
  if (toastMsg) {
    const safe = decodeURIComponent(toastMsg).replace(/<[^>]*>/g, '').slice(0, 200);
    if (safe) showToast(safe, 'success');
  }
});


/*  Mobile AI Dropdown Toggle  */
function toggleMobileAiDropdown() {
  const btn = document.getElementById('mobile-ai-dropdown-btn');
  const submenu = document.getElementById('mobile-ai-submenu');
  if (!btn || !submenu) return;
  const expanded = btn.getAttribute('aria-expanded') === 'true';
  if (expanded) {
    btn.setAttribute('aria-expanded', 'false');
    submenu.classList.remove('open');
  } else {
    btn.setAttribute('aria-expanded', 'true');
    submenu.classList.add('open');
  }
}

document.addEventListener('click', (e) => {
  const wrapper = document.getElementById('mobile-ai-dropdown-wrapper');
  const submenu = document.getElementById('mobile-ai-submenu');
  const btn = document.getElementById('mobile-ai-dropdown-btn');
  if (!wrapper || !submenu) return;
  if (!wrapper.contains(e.target)) {
    submenu.classList.remove('open');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }
});

/* ── Filter Chips & Filter Bar Redirect ────────────────────────────── */
(function () {
  function applyFilterAndRedirect(name, value, baseHref) {
    const url = new URL(window.location.href);
    if (value === '' || value == null) {
      url.searchParams.delete(name);
    } else {
      url.searchParams.set(name, value);
    }
    url.searchParams.delete('page');
    window.location.href = url.toString();
  }

  document.addEventListener('click', function (e) {
    const chip = e.target.closest('.filter-chip');
    if (!chip) return;
    const name = chip.getAttribute('data-filter');
    const value = chip.getAttribute('data-value') ?? '';
    if (!name) return;
    applyFilterAndRedirect(name, value);
  });

  function bindAutoSubmitFilterForm() {
    const forms = document.querySelectorAll('form.filter-bar, .home-filter-bar');
    forms.forEach((form) => {
      if (form.dataset.autobound === '1') return;
      form.dataset.autobound = '1';

      const selects = form.querySelectorAll('select');
      selects.forEach((sel) => {
        sel.addEventListener('change', () => {
          const name = sel.name;
          const value = sel.value;
          if (!name) return;
          applyFilterAndRedirect(name, value);
        });
      });

      const searchInputs = form.querySelectorAll('input[type="search"], input[name="q"]');
      searchInputs.forEach((inp) => {
        inp.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            applyFilterAndRedirect('q', inp.value);
          }
        });
        if (inp.type === 'search') {
          inp.addEventListener('search', () => {
            applyFilterAndRedirect('q', inp.value);
          });
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAutoSubmitFilterForm);
  } else {
    bindAutoSubmitFilterForm();
  }
})();
