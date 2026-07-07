<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

http_response_code(404);

render_head('صفحه یافت نشد');
render_navbar(auth_user());
?>

<div style="min-height: calc(100vh - 130px); display: flex; align-items: center; justify-content: center; padding: var(--sp-8) 0;">
  <div class="container-sm text-center">
    <div style="position: relative; margin-bottom: var(--sp-8);">
      <!-- Animated 404 Text -->
      <div style="font-size: clamp(5rem, 20vw, 12rem); font-weight: 900; line-height: 1; background: linear-gradient(135deg, var(--primary), var(--accent)); -webkit-background-clip: text; background-clip: text; color: transparent; text-shadow: 0 0 60px rgba(59, 130, 246, 0.3); animation: float 3s ease-in-out infinite;">
        404
      </div>
      
      <!-- Floating Decorations -->
      <div style="position: absolute; top: -20px; right: 10%; width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #fef3c7, #fcd34d); animation: bounce 2s ease-in-out infinite 0.2s;"></div>
      <div style="position: absolute; bottom: -10px; left: 10%; width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, #dbeafe, #93c5fd); animation: bounce 2.5s ease-in-out infinite 0.5s;"></div>
      <div style="position: absolute; top: 40%; left: 5%; width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg, #dcfce7, #86efac); animation: bounce 2.2s ease-in-out infinite 0.3s;"></div>
    </div>

    <h1 style="font-size: clamp(1.5rem, 4vw, 2.5rem); margin-bottom: var(--sp-4); color: var(--text-primary);">
      صفحه مورد نظر پیدا نشد!
    </h1>
    
    <p style="font-size: 1.125rem; color: var(--text-muted); margin-bottom: var(--sp-8); max-width: 500px; margin-left: auto; margin-right: auto;">
      آدرسی که دنبالش هستید وجود ندارد یا منتقل شده است! لطفاً آدرس را چک کنید یا به صفحه اصلی برگردید.
    </p>

    <div style="display: flex; gap: var(--sp-4); justify-content: center; flex-wrap: wrap;">
      <a href="<?= APP_URL ?>/" class="btn btn-primary btn-lg">
        <i class="bi bi-house-door"></i> صفحه اصلی
      </a>
      <a href="javascript:history.back()" class="btn btn-ghost btn-lg">
        <i class="bi bi-arrow-left"></i> برگشت
      </a>
    </div>
  </div>
</div>

<style>
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-20px); }
}

@keyframes bounce {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-15px) scale(1.1); }
}
</style>

<?php render_footer(); ?>
