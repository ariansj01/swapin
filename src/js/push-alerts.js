(function () {
  'use strict';

  const appUrl = document.querySelector('meta[name="app-url"]')?.content || '';
  const statusEl = document.getElementById('push-status');
  const enableBtn = document.getElementById('enable-push-btn');
  const STORAGE_KEY = 'swapin_last_notif_id';

  function setStatus(text) {
    if (statusEl) statusEl.textContent = text;
  }

  async function requestPermission() {
    if (!('Notification' in window)) {
      setStatus('مرورگر شما اعلان را پشتیبانی نمی‌کند.');
      return false;
    }
    const perm = await Notification.requestPermission();
    if (perm === 'granted') {
      setStatus('اعلان مرورگر فعال شد.');
      localStorage.setItem('swapin_push_enabled', '1');
      return true;
    }
    setStatus('اجازه اعلان داده نشد.');
    return false;
  }

  function showBrowserNotification(item) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      const n = new Notification(item.title || 'سواَپین', {
        body: item.body || '',
        icon: appUrl + '/src/img/fav_icon/web-app-manifest-192x192.png',
        tag: 'swapin-' + (item.id || item.type || 'alert'),
      });
      n.onclick = function () {
        window.focus();
        if (item.url) window.location.href = item.url;
        n.close();
      };
    } catch (_) { /* ignore */ }
  }

  async function pollNotifications() {
    if (!appUrl || localStorage.getItem('swapin_push_enabled') !== '1') return;
    try {
      const res = await fetch(appUrl + '/api/notifications.php', { credentials: 'same-origin' });
      if (!res.ok) return;
      const data = await res.json();
      if (!data.ok || !Array.isArray(data.items)) return;

      const lastId = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
      let maxId = lastId;
      const fresh = [];

      data.items.forEach(function (item) {
        const id = parseInt(item.id || 0, 10);
        if (id > maxId) maxId = id;
        if (id > lastId && (item.type === 'saved_search' || item.type === 'notification')) {
          fresh.push(item);
        }
      });

      fresh.reverse().forEach(showBrowserNotification);
      if (maxId > lastId) localStorage.setItem(STORAGE_KEY, String(maxId));
    } catch (_) { /* ignore */ }
  }

  enableBtn?.addEventListener('click', requestPermission);

  if (localStorage.getItem('swapin_push_enabled') === '1') {
    setStatus('اعلان مرورگر فعال است.');
    pollNotifications();
    setInterval(pollNotifications, 60000);
  }
})();
