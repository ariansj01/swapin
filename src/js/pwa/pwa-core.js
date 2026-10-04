(function (global) {
  'use strict';

  const P = global.SwaapinPlatform;
  if (!P) {
    console.warn('[PWA] platform-detector.js must be loaded before pwa-core.js');
    return;
  }

  const appUrl = (document.querySelector('meta[name="app-url"]')?.content || '').replace(/\/$/, '') || '';
  const STORAGE_KEYS = {
    INSTALL_DISMISSED_AT: 'swaapin_pwa_install_dismissed_at',
    INSTALL_LAST_SHOWN: 'swaapin_pwa_install_last_shown',
    INSTALL_ACCEPTED: 'swaapin_pwa_install_accepted',
    SW_UPDATED: 'swaapin_pwa_sw_updated',
  };
  const DISMISS_COOLDOWN_MS = 7 * 24 * 60 * 60 * 1000;

  function getAppUrl() { return appUrl; }

  function getSwUrl() {
    if (P.isIos()) return `${appUrl}/sw-ios.js`;
    if (P.isAndroid()) return `${appUrl}/sw-android.js`;
    return `${appUrl}/sw.js`;
  }

  function getManifestUrl() {
    if (P.isIos()) return `${appUrl}/src/img/fav_icon/site-ios.webmanifest`;
    if (P.isAndroid()) return `${appUrl}/src/img/fav_icon/site-android.webmanifest`;
    return `${appUrl}/src/img/fav_icon/site.webmanifest`;
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return Promise.resolve(null);
    const swUrl = getSwUrl();
    const regOptions = { scope: '/' };
    return navigator.serviceWorker.register(swUrl, regOptions)
      .then((reg) => {
        SwaapinPWA.emit('sw:registered', { registration: reg });
        if (reg.waiting) SwaapinPWA.emit('sw:waiting', { registration: reg });
        reg.addEventListener('updatefound', () => {
          const nw = reg.installing;
          if (!nw) return;
          nw.addEventListener('statechange', () => {
            if (nw.state === 'installed' && navigator.serviceWorker.controller) {
              SwaapinPWA.emit('sw:update-ready', { registration: reg, worker: nw });
            }
          });
        });
        return reg;
      })
      .catch((err) => {
        console.warn('[PWA] SW registration failed:', err);
        return null;
      });
  }

  function ensureManifest() {
    const existing = document.querySelector('link[rel="manifest"]');
    const target = getManifestUrl();
    if (existing && existing.href.endsWith(target.replace(/^\//, ''))) return;
    if (existing) existing.remove();
    const link = document.createElement('link');
    link.rel = 'manifest';
    link.href = target;
    link.crossOrigin = 'use-credentials';
    document.head.appendChild(link);
  }

  let deferredPrompt = null;
  function captureBeforeInstallPrompt() {
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      SwaapinPWA.emit('install:prompt-captured', { prompt: e });
    });
    window.addEventListener('appinstalled', () => {
      try { localStorage.setItem(STORAGE_KEYS.INSTALL_ACCEPTED, '1'); } catch {}
      deferredPrompt = null;
      SwaapinPWA.emit('install:installed', {});
    });
  }

  function canShowInstallBanner() {
    if (P.isStandalone()) return false;
    try {
      if (localStorage.getItem(STORAGE_KEYS.INSTALL_ACCEPTED) === '1') return false;
      const dismissed = parseInt(localStorage.getItem(STORAGE_KEYS.INSTALL_DISMISSED_AT) || '0', 10);
      if (dismissed && Date.now() - dismissed < DISMISS_COOLDOWN_MS) return false;
    } catch {}
    const support = P.getPwaInstallSupport();
    if (P.isAndroid()) return support.canPrompt && !!deferredPrompt;
    if (P.isIos()) return support.addToHomeManual && P.isSafari();
    return false;
  }

  function showInstallPrompt() {
    if (!canShowInstallBanner()) return Promise.resolve(false);
    if (P.isAndroid() && deferredPrompt) {
      return deferredPrompt.prompt().then((choice) => {
        deferredPrompt = null;
        return choice.outcome === 'accepted';
      });
    }
    SwaapinPWA.emit('install:manual-instructions', { platform: P.getPlatform() });
    return Promise.resolve(null);
  }

  function dismissInstallBanner() {
    try { localStorage.setItem(STORAGE_KEYS.INSTALL_DISMISSED_AT, String(Date.now())); } catch {}
    SwaapinPWA.emit('install:dismissed', {});
  }

  function applyPlatformBadge(count) {
    if (!P.supportsBadge()) return;
    if (count > 0) navigator.setAppBadge(count).catch(() => {});
    else navigator.clearAppBadge().catch(() => {});
  }

  function clearPlatformBadge() {
    if (!P.supportsBadge()) return;
    navigator.clearAppBadge().catch(() => {});
  }

  const listeners = new Map();
  function on(event, handler) {
    if (!listeners.has(event)) listeners.set(event, new Set());
    listeners.get(event).add(handler);
    return () => listeners.get(event)?.delete(handler);
  }
  function emit(event, payload) {
    const set = listeners.get(event);
    if (!set) return;
    set.forEach((fn) => {
      try { fn(payload); } catch (err) { console.warn('[PWA] listener error', event, err); }
    });
  }

  function init() {
    ensureManifest();
    captureBeforeInstallPrompt();
    registerServiceWorker();
    emit('core:init-done', { platform: P.getPlatform() });
  }

  global.SwaapinPWA = Object.freeze({
    init,
    on,
    emit,
    getAppUrl,
    getSwUrl,
    getManifestUrl,
    registerServiceWorker,
    ensureManifest,
    canShowInstallBanner,
    showInstallPrompt,
    dismissInstallBanner,
    applyPlatformBadge,
    clearPlatformBadge,
    STORAGE_KEYS,
    _platform: P,
  });
})(typeof window !== 'undefined' ? window : this);
