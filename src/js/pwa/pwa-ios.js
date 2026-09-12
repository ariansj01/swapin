(function (global) {
  'use strict';

  const Core = global.SwaapinPWA;
  const P = global.SwaapinPlatform;
  if (!Core || !P) {
    console.warn('[PWA-iOS] Core or Platform detector missing');
    return;
  }
  if (!P.isIos()) {
    global.SwaapinPWAIos = Object.freeze({ init: () => {}, active: false });
    return;
  }

  const appUrl = Core.getAppUrl();
  const STORAGE_KEY_IOS_HINT_SHOWN = 'swaapin_ios_a2hs_shown_at';
  const IOS_HINT_COOLDOWN = 7 * 24 * 60 * 60 * 1000;

  function applyIosMetaTags() {
    const tags = [
      { name: 'apple-mobile-web-app-capable', content: 'yes' },
      { name: 'apple-mobile-web-app-status-bar-style', content: 'black-translucent' },
      { name: 'apple-mobile-web-app-title', content: 'سواَپین' },
      { name: 'format-detection', content: 'telephone=no' },
      { name: 'theme-color', content: '#0a2540' },
    ];
    tags.forEach((t) => {
      const existing = document.querySelector(`meta[name="${t.name}"]`);
      if (existing) { existing.content = t.content; return; }
      const m = document.createElement('meta');
      m.name = t.name;
      m.content = t.content;
      document.head.appendChild(m);
    });
  }

  function applyIosTouchIcons() {
    const sizes = ['180x180', '167x167', '152x152', '120x120'];
    const baseIcon = `${appUrl}/src/img/fav_icon/apple-touch-icon.png`;
    sizes.forEach((sz) => {
      const existing = document.querySelector(`link[rel="apple-touch-icon"][sizes="${sz}"]`);
      if (existing) return;
      const link = document.createElement('link');
      link.rel = 'apple-touch-icon';
      link.sizes = sz;
      link.href = baseIcon;
      document.head.appendChild(link);
    });
    const defaultIcon = document.querySelector('link[rel="apple-touch-icon"]:not([sizes])');
    if (!defaultIcon) {
      const link = document.createElement('link');
      link.rel = 'apple-touch-icon';
      link.href = baseIcon;
      document.head.appendChild(link);
    }
  }

  function applyIosStartupImages() {
    const baseIcon = `${appUrl}/src/img/fav_icon/apple-touch-icon.png`;
    const link = document.createElement('link');
    link.rel = 'apple-touch-startup-image';
    link.media = '(device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3)';
    link.href = baseIcon;
    document.head.appendChild(link);
  }

  function fixIosStandaloneViewport() {
    document.documentElement.classList.add('swaapin-ios');
    if (P.isStandalone()) {
      document.documentElement.classList.add('swaapin-ios--standalone');
    }
  }

  function preventIosDoubleTapZoom() {
    let lastTouchEnd = 0;
    document.addEventListener('touchend', (e) => {
      const now = Date.now();
      if (now - lastTouchEnd <= 300) e.preventDefault();
      lastTouchEnd = now;
    }, { passive: false });
  }

  function handleIosOverscrollBounce() {
    document.body.classList.add('swaapin-ios--overscroll');
  }

  function shouldShowIosAddHint() {
    if (P.isStandalone()) return false;
    if (!P.isSafari()) return false;
    try {
      const accepted = localStorage.getItem(Core.STORAGE_KEYS.INSTALL_ACCEPTED) === '1';
      if (accepted) return false;
      const lastShown = parseInt(localStorage.getItem(STORAGE_KEY_IOS_HINT_SHOWN) || '0', 10);
      if (lastShown && Date.now() - lastShown < IOS_HINT_COOLDOWN) return false;
    } catch {}
    return true;
  }

  function showIosAddToHomeSheet(options) {
    const opts = Object.assign({
      container: 'body',
      title: 'نصب سواَپین در آیفون',
      step1: 'روی دکمه Share در پایین مرورگر بزنید',
      step2: 'گزینه Add to Home Screen را انتخاب کنید',
      step3: 'روی Add بزنید تا اپ در صفحه اصلی شما اضافه شود',
      dismissLabel: 'باشه، فهمیدم',
    }, options || {});

    if (!shouldShowIosAddHint()) return null;

    const sheet = document.createElement('div');
    sheet.className = 'pwa-ios-a2hs';
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.innerHTML = `
      <div class="pwa-ios-a2hs__overlay"></div>
      <div class="pwa-ios-a2hs__sheet" role="document">
        <div class="pwa-ios-a2hs__header">
          <img src="${appUrl}/src/img/fav_icon/apple-touch-icon.png" alt="" aria-hidden="true" class="pwa-ios-a2hs__icon">
          <strong class="pwa-ios-a2hs__title">${opts.title}</strong>
        </div>
        <ol class="pwa-ios-a2hs__steps">
          <li><i class="bi bi-share-fill"></i><span>${opts.step1}</span></li>
          <li><i class="bi bi-plus-square"></i><span>${opts.step2}</span></li>
          <li><i class="bi bi-check2-square"></i><span>${opts.step3}</span></li>
        </ol>
        <button type="button" class="btn btn-primary btn-block pwa-ios-a2hs__dismiss">${opts.dismissLabel}</button>
      </div>
    `;
    document.querySelector(opts.container)?.appendChild(sheet);
    setTimeout(() => sheet.classList.add('is-open'), 30);

    const close = () => {
      sheet.classList.remove('is-open');
      setTimeout(() => sheet.remove(), 320);
      try { localStorage.setItem(STORAGE_KEY_IOS_HINT_SHOWN, String(Date.now())); } catch {}
      Core.emit('ios:a2hs-dismissed', {});
    };
    sheet.querySelector('.pwa-ios-a2hs__overlay').addEventListener('click', close);
    sheet.querySelector('.pwa-ios-a2hs__dismiss').addEventListener('click', close);
    Core.emit('ios:a2hs-shown', {});
    return { element: sheet, close };
  }

  function autoShowIosAddHint(options) {
    const tryShow = () => {
      if (!shouldShowIosAddHint()) return;
      setTimeout(() => showIosAddToHomeSheet(options), 3000);
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', tryShow, { once: true });
    } else {
      tryShow();
    }
  }

  function setupIosFocusHack() {
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach((el) => {
      el.addEventListener('focus', () => {
        document.body.classList.add('swaapin-ios--kb-open');
      });
      el.addEventListener('blur', () => {
        setTimeout(() => document.body.classList.remove('swaapin-ios--kb-open'), 50);
      });
    });
  }

  function setupIosSwipeBackPrevention() {
    if (!P.isStandalone()) return;
    let startX = 0;
    document.addEventListener('touchstart', (e) => {
      startX = e.touches[0].clientX;
    }, { passive: true });
    document.addEventListener('touchmove', (e) => {
      const dx = e.touches[0].clientX - startX;
      if (dx > 0 && window.scrollX === 0) {
        // allow native where possible, no-op
      }
    }, { passive: true });
  }

  function init(options) {
    applyIosMetaTags();
    applyIosTouchIcons();
    applyIosStartupImages();
    fixIosStandaloneViewport();
    preventIosDoubleTapZoom();
    handleIosOverscrollBounce();
    setupIosSwipeBackPrevention();
    setupIosFocusHack();
    if (options?.autoBanner !== false) autoShowIosAddHint(options?.bannerOptions);
    Core.emit('ios:init-done', {});
  }

  global.SwaapinPWAIos = Object.freeze({
    init,
    showInstallBanner: showIosAddToHomeSheet,
    autoShowInstallBannerIfEligible: autoShowIosAddHint,
    active: true,
  });
})(typeof window !== 'undefined' ? window : this);
