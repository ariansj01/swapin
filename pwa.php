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
    <h1 id="pwa-title" style="font-size:1.5rem;font-weight:800;margin:0 0 4px;color:#0f172a">در حال آماده‌سازی نصب…</h1>
    <p id="pwa-sub" style="color:#475569;margin:0">در حال بررسی وضعیت نصب…</p>
    <div id="pwa-actions" style="display:none;margin-top:24px">
      <button type="button" id="pwa-install-btn" style="display:none;background:#0066ff;color:#fff;border:0;border-radius:14px;padding:12px 28px;font-weight:700;font-size:1rem;cursor:pointer">نصب اپلیکیشن</button>
      <div style="margin-top:14px">
        <a href="<?= h(APP_URL) ?>/" style="color:#0066ff;font-weight:600;text-decoration:none">بازگشت به صفحه اصلی</a>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    let deferredPrompt = null;
    let settled = false;
    const title = document.getElementById('pwa-title');
    const sub = document.getElementById('pwa-sub');
    const actions = document.getElementById('pwa-actions');
    const installBtn = document.getElementById('pwa-install-btn');
    const HOME_URL = '/';
    const REDIRECT_DELAY = 2500;
    const WATCHDOG_DELAY = 8000;

    const setText = function (t, s) {
      if (title && t) title.textContent = t;
      if (sub && s) sub.textContent = s;
    };

    const showActions = function (withButton) {
      if (actions) actions.style.display = 'block';
      if (installBtn) installBtn.style.display = withButton ? 'inline-block' : 'none';
    };

    const goHome = function (delay) {
      setTimeout(function () {
        location.replace(HOME_URL);
      }, typeof delay === 'number' ? delay : 0);
    };

    if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
      setText('اپلیکیشن فعال است', 'در حال باز کردن اپلیکیشن…');
      goHome(600);
      return;
    }

    const finishChoice = function (outcome) {
      settled = true;
      deferredPrompt = null;
      showActions(false);
      if (outcome === 'accepted') {
        setText('در حال نصب اپلیکیشن…', 'پس از پایان نصب، آیکن سواَپین روی صفحه‌خانه شما ظاهر می‌شود.');
        goHome(REDIRECT_DELAY);
      } else {
        setText('نصب لغو شد', 'در حال بازگشت به صفحه اصلی…');
        goHome(1500);
      }
    };

    const tryPrompt = function () {
      if (settled || !deferredPrompt) return;
      let result;
      try {
        result = deferredPrompt.prompt();
      } catch (e) {
        deferredPrompt = null;
        return;
      }
      const choice = (result && typeof result.then === 'function')
        ? result
        : (deferredPrompt.userChoice && typeof deferredPrompt.userChoice.then === 'function')
          ? deferredPrompt.userChoice
          : null;
      if (!choice) {
        deferredPrompt = null;
        return;
      }
      choice.then(function (res) {
        finishChoice(res && res.outcome);
      }).catch(function () {
        deferredPrompt = null;
      });
    };

    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      setText('اپلیکیشن سواَپین را نصب کنید', 'برای نصب، دکمه زیر را لمس کنید.');
      showActions(true);
    });

    window.addEventListener('appinstalled', function () {
      settled = true;
      deferredPrompt = null;
      setText('✅ اپلیکیشن نصب شد!', 'در حال انتقال به صفحه اصلی…');
      goHome(1000);
    });

    if (installBtn) installBtn.addEventListener('click', tryPrompt);

    // پنجرهٔ نصب فقط با تعامل کاربر باز می‌شود؛ این watchdog تضمین می‌کند صفحه هرگز قفل نشود
    setTimeout(function () {
      if (settled) return;
      if (deferredPrompt) {
        setText('اپلیکیشن سواَپین را نصب کنید', 'اگر پنجرهٔ نصب باز نشد، دکمه زیر را لمس کنید.');
        showActions(true);
      } else {
        setText('نصب دستی اپلیکیشن', 'در منوی مرورگر گزینه «نصب برنامه» یا «افزودن به صفحه اصلی» را انتخاب کنید.');
        showActions(false);
      }
    }, WATCHDOG_DELAY);
  })();
</script>

</body>
</html>
