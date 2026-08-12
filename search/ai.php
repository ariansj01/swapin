<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = auth_user();
$need  = trim(clean($_GET['need'] ?? $_POST['need'] ?? ''));
$city  = clean($_GET['city'] ?? $_POST['city'] ?? '');
$error = '';
$result = null;
$savedOk = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_search']) && $user) {
    csrf_verify_or_fail();
    $saveResult = create_saved_search((int)$user['id'], [
        'need_text'     => $_POST['need'] ?? '',
        'city'          => $_POST['city'] ?? '',
        'alert_enabled' => 1,
    ]);
    if (isset($saveResult['error'])) {
        $error = $saveResult['error'];
    } else {
        $savedOk = true;
    }
    $need = trim(clean($_POST['need'] ?? ''));
    $city = clean($_POST['city'] ?? '');
}

if ($need && mb_strlen($need) >= 3) {
    $result = ai_search_listings_by_need($need, $city ?: null, 24);
}

render_head('جستجوی هوشمند AI | ' . APP_NAME, 'نیاز خود را بنویسید — AI بهترین آگهی‌های معاوضه را پیدا می‌کند', [
    'canonical' => APP_URL . '/search/ai',
    'robots'    => 'noindex, nofollow',
]);
render_navbar($user);
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/shops.css?v=<?= @filemtime(__DIR__ . '/../src/css/shops.css') ?: time() ?>">

<main class="section-sm" id="main-content">
  <div class="container-md">
    <header class="ai-search-head">
      <span class="ai-search-head__badge"><i class="bi bi-stars"></i> جستجوی هوشمند</span>
      <h1>جستجو بر اساس نیازمندی</h1>
      <p>نیاز خود را به زبان ساده بنویسید — مثلاً «لپ‌تاپ گیمینگ دست دوم در تهران تا ۳۰ میلیون»</p>
    </header>

    <?php if ($savedOk): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> جستجو ذخیره شد. با ثبت آگهی جدید مطابق، هشدار دریافت می‌کنید.</div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="card ai-search-form mb-6" id="ai-search-form">
      <?= csrf_field() ?>
      <div class="card-body">
        <label for="need" class="form-label">چه چیزی می‌خواهید؟</label>
        <textarea id="need" name="need" class="form-control ai-search-form__textarea" rows="3"
                  placeholder="مثال: گوشی سامسونگ A54 سالم در اصفهان برای معاوضه با تبلت"
                  required minlength="3"><?= h($need) ?></textarea>

        <div class="ai-search-form__row">
          <div>
            <label for="city" class="form-label">شهر (اختیاری)</label>
            <select id="city" name="city" class="form-control">
              <option value="">همه شهرها</option>
              <?= render_city_options($city) ?>
            </select>
          </div>
          <div class="ai-search-form__actions">
            <button type="submit" class="btn btn-accent btn-lg" name="search" value="1">
              <i class="bi bi-stars"></i> جستجوی هوشمند
            </button>
            <?php if ($user && $need): ?>
            <button type="submit" class="btn btn-outline btn-lg" name="save_search" value="1">
              <i class="bi bi-bookmark-plus"></i> ذخیره + هشدار
            </button>
            <?php elseif (!$user): ?>
            <a href="<?= APP_URL ?>/auth/login?redirect=<?= urlencode('/search/ai') ?>" class="btn btn-outline btn-lg">
              <i class="bi bi-bookmark-plus"></i> ورود برای ذخیره
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>

    <?php if ($result): ?>
    <section class="ai-search-results">
      <header class="ai-search-results__head">
        <h2><?= $result['total'] ?> نتیجه</h2>
        <?php if (!empty($result['filters']['summary'])): ?>
        <p class="text-muted"><?= h($result['filters']['summary']) ?></p>
        <?php endif; ?>
        <span class="badge badge-secondary">منبع: <?= h($result['source'] === 'assistant' ? 'دستیار AI' : 'موتور جستجو') ?></span>
      </header>

      <?php if (empty($result['listings'])): ?>
      <div class="card"><div class="card-body text-center py-6">
        <i class="bi bi-search" style="font-size:2rem;color:var(--text-muted)"></i>
        <p class="mt-3">آگهی مطابقی یافت نشد. جستجو را ذخیره کنید تا با ثبت آگهی جدید مطلع شوید.</p>
        <?php if ($user): ?>
        <form method="POST" class="mt-4">
          <?= csrf_field() ?>
          <input type="hidden" name="need" value="<?= h($need) ?>">
          <input type="hidden" name="city" value="<?= h($city) ?>">
          <button type="submit" name="save_search" value="1" class="btn btn-accent">
            <i class="bi bi-bell"></i> ذخیره و فعال‌سازی هشدار
          </button>
        </form>
        <?php endif; ?>
      </div></div>
      <?php else: ?>
      <div class="listings-grid">
        <?php foreach ($result['listings'] as $l): ?>
          <?php include __DIR__ . '/../includes/listing_card.php'; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>
</main>

<?php
render_footer();
