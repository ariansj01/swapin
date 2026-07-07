<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

http_response_code(500);

render_head('خطای سرور');
render_navbar(auth_user());
?>

<div style="min-height: calc(100vh - 130px); display: flex; align-items: center; justify-content: center; padding: var(--sp-8) 0;">
  <div class="container-sm text-center">
    <div style="position: relative; margin-bottom: var(--sp-8);">
      <!-- Animated 500 Text -->
      <div style="font-size: clamp(5rem, 20vw, 12rem); font-weight: 900; line-height: 1; background: linear-gradient(135deg, #ef4444, #f97316); -webkit-background-clip: text; background-clip: text; color: transparent; text-shadow: 0 0 60px rgba(239, 68, 68, 0.3); animation: shake 0.5s ease-in-out infinite alternate;">
        500
      </div>
      
      <!-- Floating Decorations -->
      <div style="position: absolute; top: -20px; right: 10%; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #fee2e2, #fca5a5); animation: wobble 3s ease-in-out infinite 0.2s;"></div>
      <div style="position: absolute; bottom: -10px; left: 10%; width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, #ffedd5, #fdba74); animation: wobble 2.5s ease-in-out infinite 0.5s;"></div>
      <div style="position: absolute; top: 40%; left: 5%; width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #fef3c7, #fcd34d); animation: wobble 2.2s ease-in-out infinite 0.3s;"></div>
    </div>

    <h1 style="font-size: clamp(1.5rem, 4vw, 2.5rem); margin-bottom: var(--sp-4); color: var(--text-primary);">
      مشکلی در سرور پیش آمد!
    </h1>
    
    <p style="font-size: 1.125rem; color: var(--text-muted); margin-bottom: var(--sp-8); max-width: 500px; margin-left: auto; margin-right: auto;">
      تیم ما در حال بررسی مشکل است! لطفاً دقایقی دیگر دوباره تلاش کنید یا به صفحه اصلی برگردید.
    </p>

    <div style="display: flex; gap: var(--sp-4); justify-content: center; flex-wrap: wrap;">
      <a href="<?= APP_URL ?>/" class="btn btn-primary btn-lg">
        <i class="bi bi-house-door"></i> صفحه اصلی
      </a>
      <a href="javascript:location.reload()" class="btn btn-ghost btn-lg">
        <i class="bi bi-arrow-clockwise"></i> تلاش مجدد
      </a>
    </div>
  </div>
</div>

<style>
@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-5px); }
  50% { transform: translateX(0); }
  75% { transform: translateX(5px); }
  100% { transform: translateX(0); }
}

@keyframes wobble {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  25% { transform: translateY(-10px) rotate(-5deg); }
  50% { transform: translateY(0) rotate(0deg); }
  75% { transform: translateY(-15px) rotate(5deg); }
}
</style>

<?php render_footer(); ?>
