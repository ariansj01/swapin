(function () {
  const appUrl = document.querySelector('meta[name="app-url"]')?.content || '';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function decisionMeta(d) {
    if (d === 'approve') return { label: 'پیشنهاد: تأیید خودکار', cls: 'approve', icon: 'check-lg' };
    if (d === 'reject') return { label: 'پیشنهاد: رد خودکار', cls: 'reject', icon: 'x-lg' };
    return { label: 'پیشنهاد: بررسی دستی', cls: 'escalate', icon: 'person-check' };
  }

  async function postDemo(mode, form) {
    const fd = new FormData(form);
    fd.set('mode', mode);
    if (csrf) fd.set('_csrf', csrf);
    const res = await fetch(appUrl + '/api/ai_demo.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: csrf ? { 'X-CSRF-Token': csrf } : {},
    });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (_) {
      const start = text.indexOf('{');
      const end = text.lastIndexOf('}');
      if (start >= 0 && end > start) {
        try {
          data = JSON.parse(text.slice(start, end + 1));
        } catch (__) {
          data = { ok: false, error: 'bad_json' };
        }
      } else {
        data = { ok: false, error: 'bad_json' };
      }
    }
    if (!data.ok) {
      const map = {
        title_too_short: 'عنوان باید حداقل ۵ کاراکتر باشد.',
        need_too_short: 'نیاز را کمی کامل‌تر بنویسید.',
        empty_message: 'پیام خالی است.',
        message_too_long: 'پیام خیلی طولانی است.',
        rate_limited: data.message || 'تعداد درخواست دمو به سقف ساعتی رسیده است.',
      };
      throw new Error(map[data.error] || 'اجرای دمو ناموفق بود. دوباره تلاش کنید.');
    }
    return data;
  }

  function bind(formId, mode, render) {
    const form = document.getElementById(formId);
    if (!form) return;
    const loading = form.querySelector('.ailab-loading');
    const result = form.querySelector('.ailab-result');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      result.hidden = true;
      loading.hidden = false;
      try {
        const data = await postDemo(mode, form);
        render(result, data);
        result.hidden = false;
      } catch (err) {
        result.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>';
        result.hidden = false;
      } finally {
        loading.hidden = true;
      }
    });
  }

  bind('form-moderate', 'moderate', (el, d) => {
    const m = decisionMeta(d.suggestion);
    const flags = (d.flags || []).map((f) => '<span>' + esc(f) + '</span>').join('');
    const reasons = (d.reasons || []).map((f) => '<span>' + esc(f) + '</span>').join('');
    el.innerHTML =
      '<div class="ailab-decision ailab-decision--' + m.cls + '">' +
      '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">' +
      '<span class="badge badge-' + (m.cls === 'approve' ? 'success' : m.cls === 'reject' ? 'danger' : 'warning') + '">' +
      '<i class="bi bi-' + m.icon + '"></i> ' + m.label + '</span>' +
      '<small>منبع: ' + esc(d.public_provider === 'assistant' ? 'دستیار AI' : 'قوانین + آستانه') + '</small></div>' +
      '<div class="ailab-bar"><i style="width:' + Number(d.confidence || 0) + '%"></i></div>' +
      '<div class="fs-xs" style="margin-top:4px;color:var(--text-muted)">اطمینان: ' + Number(d.confidence || 0) + '٪</div>' +
      '<p class="fs-sm" style="margin:12px 0 0;line-height:1.8"><strong>دلیل AI: </strong>' + esc(d.note || '—') + '</p>' +
      (flags ? '<div class="ailab-flags">' + flags + '</div>' : '') +
      (reasons ? '<div class="ailab-flags">' + reasons + '</div>' : '') +
      '<p class="fs-xs" style="margin:12px 0 0;color:var(--text-muted)">اقدام واقعی: انجام نشد (sandbox). قانون آستانه: ' + esc(d.matched || '—') + '</p>' +
      '</div>' +
      '<pre class="ailab-signals">' + esc(JSON.stringify({
        suggestion: d.suggestion,
        confidence: d.confidence,
        flags: d.flags,
        reasons: d.reasons,
        rule_signals: d.rule_signals,
        llm: d.llm,
      }, null, 2)) + '</pre>';
  });

  bind('form-pricing', 'pricing', (el, d) => {
    el.innerHTML =
      '<div class="ailab-price">' +
      '<span class="fs-sm" style="color:var(--text-muted)">ارزش پیشنهادی</span>' +
      '<strong>' + esc(d.value_fmt) + '</strong>' +
      '<div>محدوده پیشنهادی: ' + esc(d.range_fmt) + '</div>' +
      '<div>' + (d.uncertain ? '⚠ ' : '') + 'اطمینان AI: ' + Number(d.confidence) + '٪</div></div>' +
      '<ul class="ailab-pricing-reasons" style="padding:0;list-style:none">' +
      (d.reasons || []).map((r) => '<li style="margin-bottom:6px"><i class="bi bi-check2" style="color:var(--success)"></i> ' + esc(r) + '</li>').join('') +
      '</ul><p class="fs-sm" style="color:var(--text-muted)">' + esc(d.note || '') + '</p>';
  });

  bind('form-matching', 'matching', (el, d) => {
    const cards = (d.matches || []).map((m) =>
      '<article class="ailab-match"><h4>' + esc(m.title) +
      ' <span class="ailab-chip">' + Number(m.match_score) + '٪</span></h4>' +
      '<div class="fs-sm">' + esc(m.cat_name) + ' · ' + esc(m.city) + ' · ' + esc(m.value_fmt) + '</div>' +
      '<div class="fs-xs" style="color:var(--text-muted);margin-top:4px">نیاز طرف مقابل: ' + esc(m.want_in_return) + '</div>' +
      '<p class="fs-sm" style="margin:8px 0 0">' + esc(m.reason) + '</p>' +
      '<span class="ailab-chip">' + (m.trade_type === 'credit' ? 'اعتباری' : 'معاوضه مستقیم') + '</span></article>'
    ).join('');
    el.innerHTML =
      '<p class="fs-sm" style="margin-top:0">آگهی شما: <strong>' + esc(d.user_listing?.title) +
      '</strong> — منبع: ' + esc(d.source === 'assistant' ? 'دستیار AI' : 'موتور قوانین') + '</p>' + cards;
  });

  bind('form-swap', 'swap', (el, d) => {
    const cards = (d.suggestions || []).map((s) =>
      '<article class="ailab-match"><h4>' + esc(s.title) +
      ' <span class="ailab-chip">نمره نهایی ' + Number(s.final_score) + '</span></h4>' +
      '<div class="fs-sm">' + esc(s.city) + ' · ' + Number(s.distance_km) + ' کیلومتر · ' + esc(s.value_fmt) + '</div>' +
      '<div class="ailab-flags">' +
      '<span>سازگاری معاوضه ' + Number(s.swap_compatibility) + '</span>' +
      '<span>ارزش ' + Number(s.value_compatibility) + '</span>' +
      '<span>دسته ' + Number(s.category_compatibility) + '</span>' +
      '<span>مکان ' + Number(s.location_score) + '</span></div>' +
      '<p class="fs-sm" style="margin:8px 0 0">' + esc((s.reasons || []).join(' · ')) + '</p></article>'
    ).join('');
    el.innerHTML =
      '<p class="fs-sm" style="margin-top:0">نمره نهایی = سازگاری معاوضه × ' +
      esc(String(Math.round((d.weights?.swap || 0.82) * 100))) + '% + مکان × ' +
      esc(String(Math.round((d.weights?.location || 0.18) * 100))) + '%</p>' + cards;
  });

  bind('form-search', 'need_search', (el, d) => {
    const f = d.filters || {};
    const chips = (f.keywords || []).map((k) => '<span>' + esc(k) + '</span>').join('');
    const cards = (d.listings || []).map((l) =>
      '<article class="ailab-match"><h4>' + esc(l.title) + '</h4>' +
      '<div class="fs-sm">' + esc(l.cat_name) + ' · ' + esc(l.city) + ' · ' + esc(l.value_fmt) + '</div>' +
      '<div class="fs-xs" style="color:var(--text-muted)">در ازای: ' + esc(l.want_in_return) + '</div></article>'
    ).join('');
    el.innerHTML =
      '<p class="fs-sm" style="margin-top:0">' + esc(f.summary || '') +
      ' — ' + Number(d.total) + ' نتیجه نمونه</p>' +
      '<div class="ailab-flags">' + chips +
      (f.category_slug ? '<span>' + esc(f.category_slug) + '</span>' : '') +
      (f.city ? '<span>' + esc(f.city) + '</span>' : '') + '</div>' +
      '<p class="fs-xs" style="color:var(--text-muted)">' + esc(d.note || '') + '</p>' + cards;
  });

  const chatForm = document.getElementById('form-chat');
  const chatLog = document.getElementById('chat-log');
  const history = [];
  if (chatForm && chatLog) {
    chatForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const input = chatForm.querySelector('[name="message"]');
      const text = (input.value || '').trim();
      if (!text) return;
      chatLog.innerHTML += '<div class="ailab-msg ailab-msg--user"><div>' + esc(text) + '</div></div>';
      input.value = '';
      const loading = chatForm.querySelector('.ailab-loading');
      loading.hidden = false;
      const fd = new FormData();
      fd.set('mode', 'chat');
      fd.set('message', text);
      fd.set('history', JSON.stringify(history));
      if (csrf) fd.set('_csrf', csrf);
      try {
        const res = await fetch(appUrl + '/api/ai_demo.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: csrf ? { 'X-CSRF-Token': csrf } : {},
        });
        const data = await res.json();
        if (!data.ok) throw new Error('chat');
        history.push({ role: 'user', content: text });
        history.push({ role: 'assistant', content: data.message });
        chatLog.innerHTML += '<div class="ailab-msg ailab-msg--bot"><div>' + esc(data.message) + '</div></div>';
        chatLog.scrollTop = chatLog.scrollHeight;
      } catch (_) {
        chatLog.innerHTML += '<div class="ailab-msg ailab-msg--bot"><div>الان پاسخ دمو در دسترس نیست. کمی بعد دوباره تلاش کنید.</div></div>';
      } finally {
        loading.hidden = true;
      }
    });
  }

  const links = [...document.querySelectorAll('.ailab-toc a')];
  const sections = links.map((a) => document.querySelector(a.getAttribute('href'))).filter(Boolean);
  const onScroll = () => {
    let current = sections[0];
    sections.forEach((s) => {
      if (s.getBoundingClientRect().top < 140) current = s;
    });
    links.forEach((a) => a.classList.toggle('is-active', a.getAttribute('href') === '#' + current.id));
  };
  window.addEventListener('scroll', onScroll, { passive: true });
})();
