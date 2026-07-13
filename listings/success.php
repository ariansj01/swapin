<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$listingId = $_GET['id'] ?? null;

render_head('آگهی شما منتشر شد', '', ['robots' => 'noindex']);
render_navbar($user);
?>

<div class="wizard-page">
  <div class="wizard-container success-page">
    <div>
      <div class="success-icon">
        <i class="bi bi-check-lg"></i>
      </div>
      <h1 class="success-title">آگهی شما با موفقیت منتشر شد 🎉</h1>
      <p class="success-text">
        آگهی شما اکنون در سواپین منتشر شده است و سایر کاربران می‌توانند آن را مشاهده و برای معاوضه پیشنهاد ارسال کنند.
      </p>
      <div class="success-buttons">
        <?php if ($listingId): ?>
          <a href="<?= APP_URL ?>/listings/view?id=<?= $listingId ?>" class="wizard-btn wizard-btn-primary">
            مشاهده آگهی
          </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/dashboard" class="wizard-btn wizard-btn-secondary">
          بازگشت به داشبورد
        </a>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="<?= APP_URL ?>/src/css/listing-wizard.css">

<?php render_footer(); ?>
