(function (global) {
  'use strict';

  const Core = global.SwaapinPWA;
  const P = global.SwaapinPlatform;
  if (!Core || !P) {
    console.warn('[PWA-Android] Core or Platform detector missing');
    return;
  }
  if (!P.isAndroid()) {
    global.SwaapinPWAAndroid = Object.freeze({ init: () => {}, active: false });
    return;
  }

  const appUrl = Core.getAppUrl();

  function applyAndroidThemeHints() {
    const meta = document.createElement('meta');
    meta.name = 'theme-color';
    meta.content = '#0a2540';
    document.head.appendChild(meta);
    const navBar = document.createElement('meta');
    navBar.name = 'mobile-web-app-capable';
    navBar.content = 'yes';
    document.head.appendChild(navBar);
    const navBarColor = document.createElement('meta');
    navBarColor.name = 'navigation-bar-color';
    navBarColor.content = '#ffffff';
    document.head.appendChild(navBarColor);
  }

  function handleAndroidBackButton() {
    if (!P.isStandalone()) return;
    window.addEventListener('popstate', () => {});
  }

  function showAndroidInstallBanner(options) {
    const opts = Object.assign({
      container: 'body',
      title: 'سواَپین را نصب کنید',
      message: 'برای دسترسی سریع‌تر، اپ را به صفحه اصلی اضافه کنید.',
      primaryLabel: 'نصب',
      dismissLabel: 'بعداً',
      position: 'bottom',
    }, options || {});

    if (!Core.canShowInstallBanner()) return null;

    const banner = document.createElement('div');
    banner.className = 'pwa-install-banner pwa-install-banner--android';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-labelledby', 'pwa-install-title');
    banner.innerHTML = `
      <div class="pwa-install-banner__inner">
        <img src="${appUrl}/src/img/fav_icon/web-app-manifest-192x192.png" alt="" aria-hidden="true" class="pwa-install-banner__icon">
        <div class="pwa-install-banner__body">
          <strong id="pwa-install-title" class="pwa-install-banner__title">${opts.title}</strong>
          <p class="pwa-install-banner__text">${opts.message}</p>
        </div>
        <div class="pwa-install-banner__actions">
          <button type="button" class="btn btn-ghost btn-sm pwa-install-banner__dismiss" aria-label="${opts.dismissLabel}">${opts.dismissLabel}</button>
          <button type="button" class="btn btn-accent btn-sm pwa-install-banner__install">${opts.primaryLabel}</button>
        </div>
      </div>
    `;
    document.querySelector(opts.container)?.appendChild(banner);

    banner.querySelector('.pwa-install-banner__install').addEventListener('click', () => {
      Core.showInstallPrompt().then((accepted) => {
        banner.remove();
        if (accepted) Core.emit('android:install-accepted', {});
      });
    });
    banner.querySelector('.pwa-install-banner__dismiss').addEventListener('click', () => {
      banner.remove();
      Core.dismissInstallBanner();
    });
    Core.on('install:installed', () => banner.remove());
    return banner;
  }

  function setupAndroidOfflineIndicator() {
    Core.on('sw:registered', () => {
      window.addEventListener('offline', () => {
        document.body.classList.add('is-offline');
        Core.emit('android:offline', {});
      });
      window.addEventListener('online', () => {
        document.body.classList.remove('is-offline');
        Core.emit('android:online', {});
      });
    });
  }

  function autoShowInstallBannerIfEligible(options) {
    const tryShow = () => {
      if (!Core.canShowInstallBanner()) return;
      showAndroidInstallBanner(options);
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', tryShow, { once: true });
    } else {
      setTimeout(tryShow, 2500);
    }
    Core.on('install:prompt-captured', () => tryShow());
  }

  function init(options) {
    applyAndroidThemeHints();
    handleAndroidBackButton();
    setupAndroidOfflineIndicator();
    if (options?.autoBanner !== false) autoShowInstallBannerIfEligible(options?.bannerOptions);
    Core.emit('android:init-done', {});
  }

  global.SwaapinPWAAndroid = Object.freeze({
    init,
    showInstallBanner: showAndroidInstallBanner,
    autoShowInstallBannerIfEligible,
    active: true,
  });
})(typeof window !== 'undefined' ? window : this);
