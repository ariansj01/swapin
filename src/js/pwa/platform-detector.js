(function (global) {
  'use strict';

  const ua = (navigator.userAgent || '').toLowerCase();
  const vendor = (navigator.vendor || '').toLowerCase();
  const platform = (navigator.platform || '').toLowerCase();

  function isIos() {
    if (/iphone|ipad|ipod/.test(ua)) return true;
    if (/mac/.test(platform) && 'ontouchend' in document) return true;
    return /ipad.*mac os x/.test(ua);
  }

  function isAndroid() {
    return /android/.test(ua);
  }

  function isMobile() {
    return /mobi|android|iphone|ipad|ipod|opera mini|iemobile|wpdesktop|blackberry/i.test(ua);
  }

  function isStandalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      (navigator.standalone === true) ||
      document.referrer.includes('android-app://')
    );
  }

  function isSafari() {
    return /safari/.test(ua) && !/chrome|crios|opr|opera|edg|edga|edgios|brave/i.test(ua);
  }

  function isChrome() {
    return /chrome|crios/.test(ua) && !/edg|brave/i.test(ua);
  }

  function isSamsungBrowser() {
    return /samsungbrowser/.test(ua);
  }

  function getPwaInstallSupport() {
    if (global.SwaapinPlatform.isIos()) {
      return {
        canPrompt: false,
        supportsBeforeInstallPrompt: false,
        addToHomeManual: true,
        shareSheetHint: global.SwaapinPlatform.isSafari(),
      };
    }
    if (global.SwaapinPlatform.isAndroid()) {
      return {
        canPrompt: true,
        supportsBeforeInstallPrompt: true,
        addToHomeManual: true,
        shareSheetHint: false,
      };
    }
    return {
      canPrompt: 'BeforeInstallPrompt' in window || !!global.SwaapinPlatform.isChrome(),
      supportsBeforeInstallPrompt: true,
      addToHomeManual: true,
      shareSheetHint: false,
    };
  }

  function getPlatform() {
    if (isIos()) return 'ios';
    if (isAndroid()) return 'android';
    if (isMobile()) return 'mobile-other';
    return 'desktop';
  }

  function getMajorOsVersion() {
    if (isIos()) {
      const match = ua.match(/os (\d+)[._]/);
      return match ? parseInt(match[1], 10) : null;
    }
    if (isAndroid()) {
      const match = ua.match(/android\s(\d+)/);
      return match ? parseInt(match[1], 10) : null;
    }
    return null;
  }

  function supportsWebPush() {
    return 'serviceWorker' in navigator && 'PushManager' in window;
  }

  function supportsBadge() {
    return 'setAppBadge' in navigator && 'clearAppBadge' in navigator;
  }

  function supportsContactPicker() {
    return 'contacts' in navigator && 'ContactsManager' in window;
  }

  function supportsWebShare() {
    return 'share' in navigator;
  }

  function supportsFileHandling() {
    return 'launchQueue' in window;
  }

  function supportsShortcuts() {
    if (isIos()) {
      const v = getMajorOsVersion();
      return v !== null && v >= 16_4;
    }
    return true;
  }

  global.SwaapinPlatform = Object.freeze({
    isIos,
    isAndroid,
    isMobile,
    isStandalone,
    isSafari,
    isChrome,
    isSamsungBrowser,
    getPlatform,
    getMajorOsVersion,
    getPwaInstallSupport,
    supportsWebPush,
    supportsBadge,
    supportsContactPicker,
    supportsWebShare,
    supportsFileHandling,
    supportsShortcuts,
  });
})(typeof window !== 'undefined' ? window : this);
