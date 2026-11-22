/*
 * رفتار کاربران — ثبت رخدادهای رفتاری (فاز ۱ سیستم هوشمند سواپین)
 *
 * - رخداد صفحه را از context سرور (یا پارامترهای URL) تشخیص می‌دهد.
 * - کلیک روی کارت/لینک آگهی را ثبت می‌کند.
 * - رخدادها را دسته‌ای و به‌صورت non-blocking به /api/track می‌فرستد.
 * - API عمومی: window.swaapinTrack(eventType, payload)
 */
(function () {
  'use strict';

  var ctx = {};
  var ctxEl = document.getElementById('swaapin-behavior-context');
  if (ctxEl) {
    try { ctx = JSON.parse(ctxEl.textContent || '{}') || {}; } catch (e) { ctx = {}; }
  }

  // غیرفعال‌سازی: پرچم جهانی یا انتخاب کاربر (opt-out)
  if (window.__SWAAPIN_TRACK_OFF) return;
  try {
    if (window.localStorage && window.localStorage.getItem('swapin_optout') === '1') return;
  } catch (e) { /* دسترسی به localStorage ممکن نیست */ }

  var metaUrl = document.querySelector('meta[name="app-url"]');
  var appUrl = ctx.app_url || (metaUrl ? metaUrl.getAttribute('content') : '') || '';
  var endpoint = appUrl + '/api/track';

  var metaCsrf = document.querySelector('meta[name="csrf-token"]');
  var csrf = metaCsrf ? (metaCsrf.getAttribute('content') || '') : '';

  var queue = [];
  var timer = null;

  function flush() {
    if (!queue.length) return;
    var payload = { events: queue.splice(0, 25), _csrf: csrf };
    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        if (navigator.sendBeacon(endpoint, blob)) return;
      }
    } catch (e) { /* ادامه با fetch */ }
    try {
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: body,
        keepalive: true,
        credentials: 'same-origin'
      });
    } catch (e) { /* نادیده */ }
  }

  function schedule() {
    if (timer) return;
    timer = window.setTimeout(function () { timer = null; flush(); }, 2500);
  }

  function track(type, data) {
    if (!type) return;
    var ev = { event_type: type };
    if (data && typeof data === 'object') {
      for (var k in data) {
        if (Object.prototype.hasOwnProperty.call(data, k)) ev[k] = data[k];
      }
      ev.event_type = type;
    }
    queue.push(ev);
    if (queue.length >= 25) {
      if (timer) { clearTimeout(timer); timer = null; }
      flush();
    } else {
      schedule();
    }
  }

  window.swaapinTrack = track;

  // ─── رخداد صفحه: از context سرور، در غیر این صورت از پارامترهای URL ───
  function pageEvent() {
    var payload = {};
    var type = ctx.default_event || '';

    if (ctx.listing_id) {
      type = type || 'view_listing';
      payload.object_type = 'listing';
      payload.object_id = ctx.listing_id;
    }
    if (ctx.category_id) payload.category_id = ctx.category_id;
    if (ctx.brand_id) payload.brand_id = ctx.brand_id;
    if (ctx.model_id) payload.model_id = ctx.model_id;

    if (!type) {
      var q = '';
      try { q = new URLSearchParams(window.location.search).get('q') || ''; } catch (e) { q = ''; }
      if (q) {
        type = 'search';
        payload.keywords = q;
        var meta = {};
        var qs;
        try { qs = new URLSearchParams(window.location.search); } catch (e) { qs = null; }
        if (qs) {
          if (qs.get('city')) meta.city = qs.get('city');
          if (qs.get('cat')) meta.cat = qs.get('cat');
        }
        if (Object.keys(meta).length) payload.meta = meta;
      } else {
        type = 'view_page';
      }
    }
    track(type, payload);
  }

  // ─── ثبت کلیک روی کارت/لینک آگهی ───
  document.addEventListener('click', function (e) {
    var el = e.target;
    if (!el || !el.closest) return;

    var card = el.closest('[data-listing-id]');
    if (card) {
      var id = parseInt(card.getAttribute('data-listing-id') || '0', 10);
      if (id > 0) {
        var catId = parseInt(card.getAttribute('data-category-id') || '0', 10);
        var payload = { object_type: 'listing', object_id: id };
        if (catId > 0) payload.category_id = catId;
        track('click_listing', payload);
        return;
      }
    }

    var link = el.closest('a[href*="/listings/"]');
    if (link) {
      track('click_listing', { object_type: 'listing', meta: { href: link.getAttribute('href') || '' } });
    }
  }, true);

  // ─── ارسال باقی‌مانده هنگام ترک صفحه ───
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pageEvent);
  } else {
    pageEvent();
  }
})();
