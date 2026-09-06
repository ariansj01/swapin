<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = auth_user();

render_head(
    'نصب اپلیکیشن سواَپین',
    'نصب اپلیکیشن سواَپین',
    [
        'canonical' => APP_URL . '/pwa',
        'robots'    => 'noindex, nofollow',
    ]
);
render_navbar($user);
?>
<div id="pwa-stage" style="min-height:calc(100vh - 72px);display:flex;align-items:center;justify-content:center;padding:24px;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%)">
  <div style="width:100%;max-width:520px;text-align:center">
    <div style="font-size:5rem;margin-bottom:12px">📲</div>
    <h1 style="font-size:1.5rem;font-weight:800;margin:0 0 4px;color:#0f172a">در حال آماده‌سازی نصب…</h1>
    <p id="pwa-sub" style="color:#475569;margin:0">در حال انتقال به صفحه اصلی پس از نصب…</p>
  </div>
</div>

<script>
  (function () {
    let deferredPrompt = null;
    let installed = false;
    const sub = document.getElementById('pwa-sub');
    const title = document.querySelector('#pwa-stage h1');
    const HOME_URL = '/';
    const REDIRECT_DELAY = 2500;

    const goHome = function (delay) {
      setTimeout(function () {
        location.replace(HOME_URL);
      }, typeof delay === 'number' ? delay : 0);
    };

    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
      if (title) title.textContent = 'اپلیکیشن فعال است';
      if (sub) sub.textContent = 'در حال باز کردن اپلیکیشن…';
      goHome(600);
      return;
    }

    const tryPrompt = async function () {
      if (!deferredPrompt) return false;
      try {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
        if (outcome === 'accepted') {
          installed = true;
          if (title) title.textContent = 'در حال نصب اپلیکیشن…';
          if (sub) sub.textContent = 'پس از پایان نصب، آیکن سواَپین روی صفحه‌خانه شما ظاهر می‌شود.';
          goHome(REDIRECT_DELAY);
        } else {
          if (title) title.textContent = 'نصب لغو شد';
          if (sub) sub.textContent = 'در حال بازگشت به صفحه اصلی…';
          goHome(1500);
        }
        return true;
      } catch (e) {
        return false;
      }
    };

    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      if (title) title.textContent = 'در حال نمایش پنجره نصب…';
      if (sub) sub.textContent = 'لطفاً در پنجره باز شده، «نصب» را انتخاب کنید.';
      setTimeout(tryPrompt, 150);
    });

    window.addEventListener('appinstalled', function () {
      installed = true;
      deferredPrompt = null;
      if (title) title.textContent = '✅ اپلیکیشن نصب شد!';
      if (sub) sub.textContent = 'در حال انتقال به صفحه اصلی…';
      goHome(1000);
    });

    setTimeout(function () {
      if (installed) return;
      if (!deferredPrompt) {
        if (title) title.textContent = 'نصب با مرورگر شما';
        if (sub) sub.textContent = 'در منوی مرورگر به «افزودن به صفحه اصلی» مراجعه کنید. در حال بازگشت…';
        goHome(2500);
      }
    }, 5000);
  })();
</script>

</body>
</html>
