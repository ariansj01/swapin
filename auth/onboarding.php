<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
if ($user['onboarding_completed']) {
    $redir = safe_redirect_path(clean($_GET['redirect'] ?? ''));
    header('Location: ' . ($redir ? APP_URL . $redir : APP_URL . '/dashboard'));
    exit;
}

$errors = [];
$redir = safe_redirect_path(clean($_GET['redirect'] ?? ''));

// Get categories for selection
$categories = DB::fetchAll('SELECT id, name FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY sort_order, id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('onboarding', 5, 3600);

    $primaryGoal = clean($_POST['primary_goal'] ?? '');
    $interestedCategories = $_POST['interested_categories'] ?? [];
    $city = clean($_POST['city'] ?? '');
    $typicalValueRange = clean($_POST['typical_value_range'] ?? '');
    $canShip = isset($_POST['can_ship']) ? (int)$_POST['can_ship'] : null;

    if (!in_array($primaryGoal, ['swap', 'buy', 'sell', 'any'])) {
        $errors['primary_goal'] = 'لطفاً یک هدف اصلی انتخاب کنید';
    }

    if (empty($errors)) {
        DB::update('users', [
            'primary_goal' => $primaryGoal,
            'interested_categories' => json_encode(array_map('intval', $interestedCategories)),
            'city' => $city ?: $user['city'],
            'typical_value_range' => $typicalValueRange ?: null,
            'can_ship' => $canShip,
            'onboarding_completed' => 1,
        ], 'id = ?', [$user['id']]);

        $dest = $redir ? APP_URL . $redir : APP_URL . '/dashboard?welcome=1';
        header('Location: ' . $dest);
        exit;
    }
}

render_head('خوش آمدید! | سواَپین', 'تنظیمات اولیه برای شروع استفاده از سواَپین');
render_navbar($user);
?>

<div style="min-height:calc(100vh - 130px);display:flex;align-items:center;padding:var(--sp-8) 0">
  <div class="container-sm">
    <div class="card" style="max-width:600px;margin:0 auto">
      <div class="card-body" style="padding:var(--sp-8)">

        <div class="text-center mb-8">
          <img src="<?= LOGO_URL ?>" alt="<?= APP_NAME ?>" class="brand-logo" style="height:56px;margin:0 auto var(--sp-4)">
          <h2>خوش آمدید!</h2>
          <p style="color:var(--text-muted);margin-top:var(--sp-2)">چند سوال کوتاه برای شخصی‌سازی تجربه شما</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mb-6">
          <i class="bi bi-exclamation-circle"></i>
          <div>لطفاً خطاهای زیر را برطرف کنید.</div>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrf_field() ?>
          
          <div class="form-group mb-6">
            <label class="form-label">بیشتر قصد دارید... <span class="required">*</span></label>
            <div class="grid" style="grid-template-columns: repeat(2, 1fr); gap: var(--sp-3)">
              <label class="card" style="cursor:pointer; padding: var(--sp-4); margin:0; <?= ($_POST['primary_goal'] ?? '') === 'swap' ? 'border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05)' : '' ?>">
                <input type="radio" name="primary_goal" value="swap" <?= ($_POST['primary_goal'] ?? '') === 'swap' ? 'checked' : '' ?> style="margin-bottom: var(--sp-2)">
                <div style="font-weight: 600">کالا تعویض کنید</div>
              </label>
              <label class="card" style="cursor:pointer; padding: var(--sp-4); margin:0; <?= ($_POST['primary_goal'] ?? '') === 'buy' ? 'border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05)' : '' ?>">
                <input type="radio" name="primary_goal" value="buy" <?= ($_POST['primary_goal'] ?? '') === 'buy' ? 'checked' : '' ?> style="margin-bottom: var(--sp-2)">
                <div style="font-weight: 600">کالا بخرید</div>
              </label>
              <label class="card" style="cursor:pointer; padding: var(--sp-4); margin:0; <?= ($_POST['primary_goal'] ?? '') === 'sell' ? 'border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05)' : '' ?>">
                <input type="radio" name="primary_goal" value="sell" <?= ($_POST['primary_goal'] ?? '') === 'sell' ? 'checked' : '' ?> style="margin-bottom: var(--sp-2)">
                <div style="font-weight: 600">کالا بفروشید</div>
              </label>
              <label class="card" style="cursor:pointer; padding: var(--sp-4); margin:0; <?= ($_POST['primary_goal'] ?? '') === 'any' ? 'border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05)' : '' ?>">
                <input type="radio" name="primary_goal" value="any" <?= ($_POST['primary_goal'] ?? '') === 'any' ? 'checked' : '' ?> style="margin-bottom: var(--sp-2)">
                <div style="font-weight: 600">هر سه</div>
              </label>
            </div>
          </div>

          <div class="form-group mb-6">
            <label class="form-label">بیشتر دنبال چه دسته‌بندی‌هایی هستید؟</label>
            <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: var(--sp-2)">
              <?php foreach ($categories as $cat): ?>
              <label class="card" style="cursor:pointer; padding: var(--sp-3); margin:0">
                <input type="checkbox" name="interested_categories[]" value="<?= $cat['id'] ?>" <?= in_array($cat['id'], ($_POST['interested_categories'] ?? [])) ? 'checked' : '' ?>>
                <span><?= h($cat['name']) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="form-group mb-6">
            <label class="form-label" for="city">شهر محل فعالیت</label>
            <input type="text" class="form-control" id="city" name="city" value="<?= h($_POST['city'] ?? $user['city'] ?? '') ?>" placeholder="شهر شما">
          </div>

          <div class="form-group mb-6">
            <label class="form-label">حدود ارزش کالاهایی که معمولاً معامله می‌کنید</label>
            <select class="form-select" name="typical_value_range">
              <option value="">انتخاب کنید</option>
              <option value="under_1m" <?= ($_POST['typical_value_range'] ?? '') === 'under_1m' ? 'selected' : '' ?>>زیر ۱ میلیون تومان</option>
              <option value="1m_5m" <?= ($_POST['typical_value_range'] ?? '') === '1m_5m' ? 'selected' : '' ?>>۱ تا ۵ میلیون تومان</option>
              <option value="5m_20m" <?= ($_POST['typical_value_range'] ?? '') === '5m_20m' ? 'selected' : '' ?>>۵ تا ۲۰ میلیون تومان</option>
              <option value="20m_100m" <?= ($_POST['typical_value_range'] ?? '') === '20m_100m' ? 'selected' : '' ?>>۲۰ تا ۱۰۰ میلیون تومان</option>
              <option value="over_100m" <?= ($_POST['typical_value_range'] ?? '') === 'over_100m' ? 'selected' : '' ?>>بالای ۱۰۰ میلیون تومان</option>
            </select>
          </div>

          <div class="form-group mb-8">
            <label class="form-label">آیا امکان ارسال دارید یا فقط حضوری معامله می‌کنید؟</label>
            <div class="grid" style="grid-template-columns: repeat(2, 1fr); gap: var(--sp-3)">
              <label class="card" style="cursor:pointer; padding: var(--sp-4); margin:0; <?= ($_POST['can_ship'] ?? '') === '1' ? 'border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05)' : '' ?>">
                <input type="radio" name="can_ship" value="1" <?= ($_POST['can_ship'] ?? '') === '1' ? 'checked' : '' ?> style="margin-bottom: var(--sp-2)">
                <div style="font-weight: 600">امکان ارسال دارم</div>
              </label>
              <label class="card" style="cursor:pointer; padding: var(--sp-4); margin:0; <?= ($_POST['can_ship'] ?? '') === '0' ? 'border-color: var(--primary); background: rgba(var(--primary-rgb), 0.05)' : '' ?>">
                <input type="radio" name="can_ship" value="0" <?= ($_POST['can_ship'] ?? '') === '0' ? 'checked' : '' ?> style="margin-bottom: var(--sp-2)">
                <div style="font-weight: 600">فقط حضوری</div>
              </label>
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-100 btn-lg">شروع کنید</button>
        </form>

      </div>
    </div>
  </div>
</div>

<?php render_footer(); ?>
