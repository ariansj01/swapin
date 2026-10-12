(function (global) {
  'use strict';

  function detectPlatform() {
    var ua = (navigator.userAgent || '').toLowerCase();
    if (/iphone|ipad|ipod/.test(ua)) return 'ios';
    if (/mac/.test((navigator.platform || '').toLowerCase()) && 'ontouchend' in document) return 'ios';
    if (/ipad.*mac os x/.test(ua)) return 'ios';
    if (/android/.test(ua)) return 'android';
    return 'other';
  }

  var platform = detectPlatform();

  global.SwaapinPlatform = {
    getPlatform: function () { return platform; },
    isIos: function () { return platform === 'ios'; },
    isAndroid: function () { return platform === 'android'; },
    isOther: function () { return platform === 'other'; }
  };

  document.documentElement.setAttribute('data-swaapin-platform', platform);
})(window);
