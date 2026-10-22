<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();

$metaTitle = 'ارزش‌گذاری تقریبی کالا با هوش مصنوعی | تخمین قیمت رایگان در سواَپین';
$metaDesc  = 'ارزش تقریبی کالا یا وسیله‌ات را در چند ثانیه و کاملاً رایگان برآورد کن. هوش مصنوعی سواَپین بازار معاوضه را تحلیل می‌کند و محدوده قیمت تقریبی را نشان می‌دهد. بدون ثبت‌نام.';
$canonical = APP_URL . '/valuation';
$ogImage   = LOGO_URL;

$webPageJsonLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'WebPage',
    'name'        => $metaTitle,
    'description' => $metaDesc,
    'url'         => $canonical,
    'image'       => $ogImage,
    'publisher'   => [
        '@type' => 'Organization',
        'name'  => APP_NAME,
        'logo'  => ['@type' => 'ImageObject', 'url' => LOGO_URL],
    ],
];

render_head($metaTitle, $metaDesc, [
    'canonical' => $canonical,
    'og_image'  => $ogImage,
    'og_type'   => 'website',
    'json_ld'   => [$webPageJsonLd],
]);
render_navbar($user);
?>

<main id="main-content" class="vl-page">

  <!-- ── Hero + working valuation form ─────────────────────────────────── -->
  <section class="vl-hero">
    <span class="vl-hero__glow vl-hero__glow--a" aria-hidden="true"></span>
    <span class="vl-hero__glow vl-hero__glow--b" aria-hidden="true"></span>
    <span class="vl-hero__grid" aria-hidden="true"></span>

    <div class="container vl-hero__inner">

      <div class="vl-hero__copy">
        <span class="vl-badge"><i class="bi bi-stars"></i> دستیار هوشمند سواَپین</span>
        <h1 class="vl-hero__title">ارزش تقریبی کالات<br>چقدره؟</h1>
        <p class="vl-hero__desc">
          فقط اسم و توضیح کوتاه کالا رو بنویس؛ هوش مصنوعی سواَپین با تحلیل بازار معاوضه،
          محدوده قیمت تقریبی رو در چند ثانیه بهت نشون می‌ده.
        </p>
        <ul class="vl-hero__points">
          <li><i class="bi bi-lightning-charge-fill"></i> در چند ثانیه</li>
          <li><i class="bi bi-shield-check"></i> بدون ثبت‌نام</li>
          <li><i class="bi bi-graph-up-arrow"></i> بر پایه بازار واقعی</li>
        </ul>
      </div>

      <div class="vl-card">
        <form id="valuation-form" class="vl-form" method="POST" novalidate>
          <?= csrf_input() ?>

          <div class="vl-field">
            <label class="vl-label" for="vl_title">اسم کالا</label>
            <input type="text" id="vl_title" name="title" class="vl-input"
                   placeholder="مثال: آیفون ۱۵ پرو مکس ۲۵۶ گیگابایت"
                   required minlength="3" maxlength="150" autocomplete="off">
          </div>

          <div class="vl-field">
            <label class="vl-label" for="vl_description">
              توضیحات کالا <span class="vl-label__hint">(اختیاری، ولی دقیق‌ترش می‌کنه)</span>
            </label>
            <textarea id="vl_description" name="description" class="vl-input vl-input--area" rows="3"
                      placeholder="مثال: دست‌اول، جعبه و شارژر کامل، بدون خط و خش"
                      maxlength="500"></textarea>
          </div>

          <div id="vl-error" class="vl-error" role="alert" aria-live="polite" style="display:none"></div>

          <button type="submit" id="vl-submit" class="vl-submit">
            <i class="bi bi-cpu"></i> <span>قیمت کالا رو ببین</span>
          </button>

          <div class="vl-form__tags">
            <span><i class="bi bi-lightning-charge"></i> سریع</span>
            <span><i class="bi bi-bullseye"></i> دقیق</span>
            <span><i class="bi bi-emoji-smile"></i> بدون ثبت‌نام</span>
          </div>
        </form>

        <div id="vl-result" class="vl-result" role="status" aria-live="polite">
          <div class="vl-result__head">
            <i class="bi bi-graph-up-arrow"></i>
            <span>نتیجه ارزش‌گذاری تقریبی</span>
          </div>

          <div class="vl-range">
            <div class="vl-pill vl-pill--low">
              <span>کمترین ارزش</span>
              <strong id="vl-min">—</strong>
            </div>
            <div class="vl-range__arrow"><i class="bi bi-arrow-left-right"></i></div>
            <div class="vl-pill vl-pill--high">
              <span>بیشترین ارزش</span>
              <strong id="vl-max">—</strong>
            </div>
          </div>

          <div class="vl-mid">
            <span>ارزش پیشنهادی بازار</span>
            <strong id="vl-mid">—</strong>
          </div>

          <p id="vl-note" class="vl-note"></p>

          <a href="<?= APP_URL ?>/listings/create" class="vl-result__cta">
            <i class="bi bi-plus-circle"></i> ثبت کالا و دریافت معاوضه
          </a>
        </div>
      </div>

    </div>
  </section>

  <!-- ── How it works ──────────────────────────────────────────────────── -->
  <section class="vl-steps">
    <div class="container">
      <h2 class="vl-section-title">چطور ارزش‌گذاری می‌کنیم؟</h2>
      <div class="vl-steps__grid">
        <article class="vl-step">
          <span class="vl-step__num">۱</span>
          <h3>کالا رو معرفی کن</h3>
          <p>اسم و توضیح کالا رو بنویس تا دستیار هوشمند مشخصاتش رو بفهمه.</p>
        </article>
        <article class="vl-step">
          <span class="vl-step__num">۲</span>
          <h3>تحلیل هوشمند بازار</h3>
          <p>قیمت‌ها و آگهی‌های مشابه در بازار معاوضه سواَپین بررسی می‌شن.</p>
        </article>
        <article class="vl-step">
          <span class="vl-step__num">۳</span>
          <h3>محدوده قیمت رو بگیر</h3>
          <p>کمترین، بیشترین و ارزش پیشنهادی بازار رو در چند ثانیه ببین.</p>
        </article>
      </div>
    </div>
  </section>

</main>

<style>
/* =========================================================
   Valuation landing page (vl-*)
   ========================================================= */
.vl-page { background: #fff; }

/* ── Hero ── */
.vl-hero {
  position: relative;
  overflow: hidden;
  background: #071A33;
  padding: var(--sp-12) 0 var(--sp-16);
}
.vl-hero__glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(70px);
  pointer-events: none;
}
.vl-hero__glow--a { width: 460px; height: 460px; top: -160px; left: -120px; background: rgba(37, 99, 235, .38); }
.vl-hero__glow--b { width: 420px; height: 420px; bottom: -180px; right: -100px; background: rgba(255, 201, 40, .22); }
.vl-hero__grid {
  position: absolute; inset: 0; pointer-events: none; opacity: .18;
  background-image:
    linear-gradient(rgba(255,255,255,.06) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.06) 1px, transparent 1px);
  background-size: 46px 46px;
  mask-image: radial-gradient(circle at 50% 30%, #000 0%, transparent 78%);
  -webkit-mask-image: radial-gradient(circle at 50% 30%, #000 0%, transparent 78%);
}
.vl-hero__inner {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: 1.05fr 1fr;
  gap: var(--sp-10);
  align-items: center;
}

.vl-hero__copy { color: #fff; }
.vl-badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: var(--radius-full);
  background: rgba(255, 201, 40, .14);
  border: 1px solid rgba(255, 201, 40, .35);
  color: #FFD85E; font-size: .8rem; font-weight: 700;
}
.vl-hero__title {
  margin: var(--sp-5) 0 var(--sp-4);
  font-size: clamp(1.9rem, 4vw, 2.9rem);
  line-height: 1.35; font-weight: 900; color: #fff;
}
.vl-hero__desc {
  margin: 0 0 var(--sp-6);
  font-size: 1.05rem; line-height: 2;
  color: rgba(255,255,255,.75);
  max-width: 520px;
}
.vl-hero__points {
  list-style: none; margin: 0; padding: 0;
  display: flex; flex-wrap: wrap; gap: var(--sp-3) var(--sp-5);
}
.vl-hero__points li {
  display: inline-flex; align-items: center; gap: 7px;
  font-size: .9rem; font-weight: 600; color: rgba(255,255,255,.9);
}
.vl-hero__points i { color: #FFC928; }

/* ── Form card (glass on dark) ── */
.vl-card {
  position: relative;
  background: rgba(255, 255, 255, .98);
  border-radius: var(--radius-xl);
  padding: var(--sp-6) var(--sp-6) var(--sp-5);
  box-shadow: 0 34px 80px -26px rgba(4, 16, 32, .75), 0 0 0 1px rgba(255,255,255,.55) inset;
  backdrop-filter: blur(8px);
}
.vl-form { display: flex; flex-direction: column; gap: var(--sp-4); }
.vl-field { display: flex; flex-direction: column; gap: 7px; }
.vl-label { font-size: .85rem; font-weight: 700; color: #071A33; }
.vl-label__hint { font-weight: 500; color: var(--text-muted); font-size: .78rem; }
.vl-input {
  width: 100%; box-sizing: border-box;
  padding: 13px 15px; border-radius: var(--radius-md);
  border: 1.5px solid var(--border);
  background: #fff; color: var(--text-primary);
  font: inherit; font-size: .95rem;
  transition: border-color .18s ease, box-shadow .18s ease;
}
.vl-input::placeholder { color: var(--text-light); }
.vl-input:focus {
  outline: none;
  border-color: #FFC928;
  box-shadow: 0 0 0 4px rgba(255, 201, 40, .22);
}
.vl-input--area { resize: vertical; min-height: 84px; line-height: 1.9; }

.vl-submit {
  margin-top: 2px;
  padding: 15px 20px; border: none; border-radius: var(--radius-md);
  background: linear-gradient(135deg, #FFC928 0%, #F59E0B 55%, #FF6600 100%);
  color: #1c1206; font: inherit; font-size: 1.02rem; font-weight: 900;
  display: inline-flex; align-items: center; justify-content: center; gap: 9px;
  box-shadow: 0 14px 30px -12px rgba(245, 158, 11, .8);
  cursor: pointer; transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
}
.vl-submit:hover { transform: translateY(-2px); box-shadow: 0 18px 36px -14px rgba(245, 158, 11, .95); }
.vl-submit:active { transform: translateY(0); }
.vl-submit:disabled, .vl-submit.is-loading { cursor: progress; opacity: .92; transform: none; }
.vl-submit.is-loading i { animation: vlSpin .9s linear infinite; }
@keyframes vlSpin { to { transform: rotate(360deg); } }

.vl-form__tags {
  display: flex; justify-content: center; gap: var(--sp-4);
  padding-top: 2px; font-size: .8rem; font-weight: 600; color: var(--text-muted);
}
.vl-form__tags span { display: inline-flex; align-items: center; gap: 5px; }
.vl-form__tags i { color: #F59E0B; }

.vl-error {
  padding: 12px 14px; border-radius: var(--radius-md);
  background: rgba(239, 68, 68, .08);
  border: 1.5px solid rgba(239, 68, 68, .3);
  color: #b91c1c; font-size: .85rem; font-weight: 700; text-align: center;
}

/* ── Result ── */
.vl-result { display: none; margin-top: var(--sp-5); }
.vl-result.is-visible { display: block; animation: vlFade .4s ease; }
@keyframes vlFade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

.vl-result__head {
  display: flex; align-items: center; gap: 8px;
  font-size: .9rem; font-weight: 800; color: #071A33;
  padding-bottom: 10px; margin-bottom: 14px;
  border-bottom: 1px dashed rgba(7, 26, 51, .14);
}
.vl-result__head i { color: #F59E0B; }

.vl-range { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 10px; }
.vl-pill {
  border-radius: var(--radius-lg); padding: 13px 10px; text-align: center;
}
.vl-pill span { display: block; font-size: .72rem; font-weight: 700; color: var(--text-muted); margin-bottom: 4px; }
.vl-pill strong { display: block; font-size: 1.02rem; font-weight: 900; color: #071A33; }
.vl-pill--low { background: rgba(37, 99, 235, .07); border: 1.5px solid rgba(37, 99, 235, .2); }
.vl-pill--high { background: rgba(255, 102, 0, .07); border: 1.5px solid rgba(255, 102, 0, .2); }
.vl-range__arrow { color: var(--text-light); font-size: 1.1rem; }

.vl-mid {
  margin-top: 12px; padding: 14px; border-radius: var(--radius-lg); text-align: center;
  background: linear-gradient(135deg, #FFC928 0%, #F5B700 100%);
  box-shadow: 0 12px 26px -14px rgba(245, 183, 0, .9);
}
.vl-mid span { display: block; font-size: .78rem; font-weight: 800; color: rgba(28, 18, 6, .72); margin-bottom: 3px; }
.vl-mid strong { display: block; font-size: 1.3rem; font-weight: 900; color: #1c1206; }

.vl-note {
  margin: 12px 0 0; text-align: center;
  font-size: .8rem; line-height: 1.9; font-weight: 600; color: var(--text-muted);
}
.vl-result__cta {
  margin-top: 16px; width: 100%; box-sizing: border-box;
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 13px 18px; border-radius: var(--radius-md);
  background: #071A33; color: #fff; text-decoration: none;
  font-weight: 800; font-size: .92rem;
  transition: background .18s ease;
}
.vl-result__cta:hover { background: #14305e; }

/* ── Steps ── */
.vl-steps { background: var(--bg); padding: var(--sp-12) 0; }
.vl-section-title {
  text-align: center; margin: 0 0 var(--sp-8);
  font-size: 1.5rem; font-weight: 900; color: #071A33;
}
.vl-steps__grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sp-5); }
.vl-step {
  position: relative;
  background: #fff; border: 1px solid var(--border);
  border-radius: var(--radius-lg); padding: var(--sp-6) var(--sp-5);
  text-align: center;
  transition: transform .2s ease, box-shadow .2s ease;
}
.vl-step:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
.vl-step__num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 44px; height: 44px; margin-bottom: var(--sp-4);
  border-radius: 50%; font-size: 1.05rem; font-weight: 900;
  background: rgba(255, 201, 40, .16); color: #071A33;
}
.vl-step h3 { margin: 0 0 8px; font-size: 1rem; font-weight: 800; color: #071A33; }
.vl-step p { margin: 0; font-size: .875rem; line-height: 1.95; color: var(--text-muted); }

/* ── Responsive ── */
@media (max-width: 1024px) {
  .vl-hero__inner { grid-template-columns: 1fr; gap: var(--sp-8); }
  .vl-hero__desc { max-width: none; }
}
@media (max-width: 720px) {
  .vl-hero { padding: var(--sp-8) 0 var(--sp-10); }
  .vl-hero__title { font-size: 1.7rem; }
  .vl-hero__desc { font-size: .95rem; }
  .vl-card { padding: var(--sp-5) var(--sp-4); border-radius: var(--radius-lg); }
  .vl-steps__grid { grid-template-columns: 1fr; gap: var(--sp-4); }
  .vl-steps { padding: var(--sp-10) 0; }
  .vl-range { gap: 6px; }
  .vl-pill strong { font-size: .92rem; }
  .vl-mid strong { font-size: 1.1rem; }
}
</style>

<script>
(function () {
  const APP_URL = '<?= APP_URL ?>';
  const CREDIT_UNIT = '<?= CREDIT_UNIT ?>';

  function fmtCredit(amount) {
    const n = Math.round(Number(amount) || 0);
    try {
      return new Intl.NumberFormat('fa-IR').format(n) + ' ' + CREDIT_UNIT;
    } catch (_) {
      return n.toLocaleString('fa-IR') + ' ' + CREDIT_UNIT;
    }
  }

  function getCsrfToken() {
    const inp = document.querySelector('#valuation-form input[name="_csrf"]');
    if (inp && inp.value) return inp.value;
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function withCsrfHeaders() {
    const token = getCsrfToken();
    return token ? { 'X-CSRF-Token': token } : {};
  }

  function appendCsrf(fd) {
    const token = getCsrfToken();
    if (token && !fd.has('_csrf')) fd.append('_csrf', token);
  }

  function normalizePricingFromAi(parsed) {
    if (!parsed || typeof parsed !== 'object') return null;
    const type = String(parsed.type || '');
    if (type === 'chat' || type === 'error') return null;
    let min = 0, max = 0;
    if (parsed.value_range && typeof parsed.value_range === 'object') {
      min = Number(parsed.value_range.min) || 0;
      max = Number(parsed.value_range.max) || 0;
    }
    if (min <= 0 && max <= 0) {
      min = Number(parsed.min) || 0;
      max = Number(parsed.max) || 0;
    }
    if (min <= 0 && max <= 0) {
      const val = Number(parsed.estimated_value ?? parsed.valuation ?? parsed.value ?? parsed.price ?? 0) || 0;
      if (val > 0) { min = Math.round(val * 0.85); max = Math.round(val * 1.15); }
    }
    if (min <= 0 && max <= 0) return null;
    if (min > max) { const t = min; min = max; max = t; }
    if (min <= 0) min = Math.max(500000, Math.round(max * 0.82));
    if (max <= 0) max = Math.round(min * 1.18);
    min = Math.round(min / 100000) * 100000;
    max = Math.round(max / 100000) * 100000;
    min = Math.max(500000, min);
    if (min > max) min = Math.round(max * 0.86 / 100000) * 100000;
    const mid = Math.round(((0.42 * min) + (0.58 * max)) * 1.06 / 100000) * 100000;
    let conf = parsed.confidence ?? parsed.certainty ?? 0.55;
    if (typeof conf === 'string') conf = (Number(conf) || 55) / 100;
    if (conf > 1) conf = conf / 100;
    const confPct = Math.round(Math.max(0, Math.min(1, Number(conf) || 0.55)) * 100);
    const uncertain = confPct < 62;
    return {
      ok: true, value: mid, range_low: min, range_high: max,
      note: uncertain
        ? 'ارزش‌گذاری تقریبی و راهنماست؛ برای قیمت دقیق‌تر توضیحات بیشتری وارد کن.'
        : 'ارزش‌گذاری هوشمند بر اساس اطلاعات تو و آگهی‌های مشابه بازار معاوضه.'
    };
  }

  function extractJsonFromText(raw) {
    if (!raw) return null;
    const text = String(raw).trim();
    const first = text.indexOf('{');
    const last = text.lastIndexOf('}');
    if (first === -1 || last === -1 || last <= first) return null;
    const snip = text.substring(first, last + 1);
    try { return JSON.parse(snip); } catch (_) {}
    try { return JSON.parse(snip.replace(/,\s*([\]}])/g, '$1').replace(/\bNaN\b/g, 'null')); } catch (_) { return null; }
  }

  // تابع جهانی app.js هم Gemini و هم OpenAI را پشتیبانی می‌کند؛ از همان استفاده می‌کنیم.
  async function browserCompleteChat(prepare) {
    if (typeof swaapinBrowserCompleteChat !== 'function') return null;
    try { return await swaapinBrowserCompleteChat(prepare); } catch (_) { return null; }
  }

  const form = document.getElementById('valuation-form');
  if (!form) return;

  const btn        = document.getElementById('vl-submit');
  const btnLabel   = btn.querySelector('span');
  const resultBox  = document.getElementById('vl-result');
  const errBox     = document.getElementById('vl-error');
  const elMin      = document.getElementById('vl-min');
  const elMax      = document.getElementById('vl-max');
  const elMid      = document.getElementById('vl-mid');
  const elNote     = document.getElementById('vl-note');

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    errBox.style.display = 'none';
    resultBox.classList.remove('is-visible');

    const titleEl = document.getElementById('vl_title');
    const title = (titleEl.value || '').trim();
    if (title.length < 3) {
      errBox.textContent = 'نام کالا باید حداقل ۳ کاراکتر باشد.';
      errBox.style.display = 'block';
      titleEl.focus();
      return;
    }

    const description = (document.getElementById('vl_description').value || '').trim();

    const fd = new FormData();
    fd.append('title', title);
    fd.append('description', description);
    fd.append('condition', 'good');
    appendCsrf(fd);

    btn.classList.add('is-loading');
    btn.disabled = true;
    const prevLabel = btnLabel.textContent;
    btnLabel.textContent = 'در حال محاسبه قیمت…';

    try {
      const res = await fetch(APP_URL + '/api/ai_estimate_public.php', {
        method: 'POST', body: fd, credentials: 'same-origin', headers: withCsrfHeaders(),
      });
      let prepare;
      try { prepare = await res.json(); } catch (_) { prepare = { ok: false }; }

      let result = null;
      if (prepare && prepare.ok === true && prepare.type === 'server_result' && prepare.data) {
        const d = prepare.data;
        result = {
          value: Number(d.mid) || 0,
          range_low: Number(d.min) || 0,
          range_high: Number(d.max) || 0,
          note: String(d.note || ''),
        };
        if (result.value <= 0 && (result.range_low > 0 || result.range_high > 0)) {
          result.value = Math.round(((result.range_low || 0) + (result.range_high || 0)) / 2);
        }
      } else if (prepare && prepare.ok === true && prepare.type === 'client_prepare') {
        const done = await browserCompleteChat(prepare);
        if (done && done.content) {
          result = normalizePricingFromAi(extractJsonFromText(done.content));
        }
        if (!result && prepare.fallback && typeof prepare.fallback === 'object') {
          const fb = prepare.fallback;
          if (Number(fb.mid) > 0 || Number(fb.min) > 0 || Number(fb.max) > 0) {
            result = {
              value: Number(fb.mid) || Math.round(((Number(fb.min) || 0) + (Number(fb.max) || 0)) / 2),
              range_low: Number(fb.min) || 0,
              range_high: Number(fb.max) || 0,
              note: String(fb.note || ''),
            };
          }
        }
      } else if (prepare && prepare.error === 'rate_limited') {
        throw new Error('درخواست‌های زیادی ارسال شده. لطفاً کمی بعد دوباره تلاش کن.');
      } else if (prepare && prepare.ok === false && prepare.msg) {
        throw new Error(String(prepare.msg));
      } else if (!res.ok) {
        throw new Error('خطا در برقراری ارتباط. کد ' + res.status);
      }

      if (!result || (!result.value && !result.range_low && !result.range_high)) {
        throw new Error('متأسفانه تخمین قیمت ممکن نشد. لطفاً توضیحات بیشتری وارد کن.');
      }

      const min = result.range_low || Math.round(result.value * 0.85);
      const max = result.range_high || Math.round(result.value * 1.15);
      const mid = result.value || Math.round((min + max) / 2);

      elMin.textContent = fmtCredit(min);
      elMax.textContent = fmtCredit(max);
      elMid.textContent = fmtCredit(mid);
      elNote.textContent = (result.note && String(result.note).trim())
        || 'ارزش‌گذاری هوشمند بر اساس اطلاعات تو و بازار معاوضه سواَپین.';

      resultBox.classList.add('is-visible');
      resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (err) {
      errBox.textContent = (err && err.message) ? String(err.message) : 'خطای ناشناخته در ارزش‌گذاری.';
      errBox.style.display = 'block';
    } finally {
      btn.classList.remove('is-loading');
      btn.disabled = false;
      btnLabel.textContent = prevLabel;
    }
  });
})();
</script>

<?php render_footer(); ?>
