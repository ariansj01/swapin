<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$listingId = $_GET['id'] ?? null;

// بازگشت به کلاب در صورت ثبت آگهی از داخل کلاب
$clubBackUrl = '';
$backClubId = (int)($_GET['club'] ?? 0);
if ($backClubId > 0) {
    require_once __DIR__ . '/../includes/clubs.php';
    $backClub = function_exists('clubs_get') ? clubs_get($backClubId) : null;
    if ($backClub && !empty($backClub['slug'])) {
        $clubBackUrl = APP_URL . '/clubs/' . rawurlencode((string)$backClub['slug']);
    }
}

render_head('آگهی شما منتشر شد', '', ['robots' => 'noindex']);
render_navbar($user);
?>

<div class="wizard-page">
  <div class="wizard-container success-page">
    <div>
      <div class="success-icon">
        <i class="bi bi-check-lg"></i>
      </div>
      <h1 class="success-title">آگهی شما با موفقیت ثبت شد 🎉</h1>
      <p class="success-text">
        آگهی شما در صف بررسی قرار گرفت. پس از تأیید تیم پشتیبانی سواَپین، در سایت منتشر شده و کاربران می‌توانند آن را مشاهده و برای معاوضه پیشنهاد ارسال کنند.
      </p>
      <div class="success-buttons">
        <?php if ($clubBackUrl): ?>
          <a href="<?= h($clubBackUrl) ?>" class="wizard-btn wizard-btn-primary">
            <i class="bi bi-people"></i> بازگشت به کلاب
          </a>
        <?php endif; ?>
        <?php if ($listingId): ?>
          <a href="<?= h(listing_url((int)$listingId)) ?>" class="wizard-btn <?= $clubBackUrl ? 'wizard-btn-secondary' : 'wizard-btn-primary' ?>">
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

<script>
// پاک‌سازی پیش‌نویس پس از انتشار موفق آگهی
try { localStorage.removeItem(<?= json_encode('swaapin_listing_draft_u' . (int)$user['id']) ?>); } catch (e) {}
try { localStorage.removeItem('swaapin_listing_draft_guest'); } catch (e) {}
</script>

<?php render_footer(); ?>
