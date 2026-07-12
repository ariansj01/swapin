<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$uid = $user['id'];

$listingId = (int)($_GET['id'] ?? 0);

$listing = DB::fetch(
    'SELECT * FROM listings WHERE id = ? AND user_id = ? AND status = "active"',
    [$listingId, $uid]
);

if (!$listing) {
    header('Location: ' . APP_URL . '/listings/my.php');
    exit;
}

$plans = [
    'boost' => [
        'name' => 'افزایش فوری',
        'icon' => 'bi-rocket-takeoff',
        'price' => 50000,
        'duration' => '24 ساعت',
        'description' => 'آگهی در بالاترین جایگاه لیست قرار می‌گیرد',
    ],
    'featured' => [
        'name' => 'ویژه',
        'icon' => 'bi-star',
        'price' => 100000,
        'duration' => '7 روز',
        'description' => 'نمایش در بخش آگهی‌های ویژه',
    ],
    'vip' => [
        'name' => 'VIP',
        'icon' => 'bi-award',
        'price' => 200000,
        'duration' => '14 روز',
        'description' => 'نمایش در صفحه اول، اولویت در نتایج جستجو',
    ],
    'targeted' => [
        'name' => 'هدفمند',
        'icon' => 'bi-bullseye',
        'price' => 150000,
        'duration' => '7 روز',
        'description' => 'نمایش فقط به مخاطبان مرتبط بر اساس شهر و دسته‌بندی',
    ],
    'ai' => [
        'name' => 'نمایش هوشمند',
        'icon' => 'bi-robot',
        'price' => 250000,
        'duration' => '7 روز',
        'description' => 'هوش مصنوعی آگهی را به کاربران دقیقاً مرتبط نمایش می‌دهد',
    ],
    'gold' => [
        'name' => 'پکیج طلایی',
        'icon' => 'bi-gem',
        'price' => 500000,
        'duration' => '14 روز',
        'description' => 'شامل تمام امکانات: Boost, Featured, VIP, Targeted, AI Promotion',
    ],
];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $plan = clean($_POST['plan'] ?? '');
    if (!isset($plans[$plan])) {
        $error = 'پلن انتخاب شده نامعتبر است';
    } else {
        $planData = $plans[$plan];
        $price = $planData['price'];
        if ((float)$user['credit_balance'] < $price) {
            $error = 'موجودی کیف پول شما کافی نیست';
        } else {
            // Deduct credit
            credit_transact($uid, 'listing_promotion', -$price, 'پرداخت برای پلن ' . $planData['name'], [
                'ref_type' => 'listing',
                'ref_id' => $listingId,
                'listing_id' => $listingId,
            ]);
            
            $durationHours = [
                'boost' => 24,
                'featured' => 24 * 7,
                'vip' => 24 * 14,
                'targeted' => 24 * 7,
                'ai' => 24 * 7,
                'gold' => 24 * 14,
            ];
            
            $endsAt = date('Y-m-d H:i:s', time() + $durationHours[$plan] * 3600);
            
            DB::insert('listing_promotions', [
                'listing_id' => $listingId,
                'user_id' => $uid,
                'plan' => $plan,
                'starts_at' => date('Y-m-d H:i:s'),
                'ends_at' => $endsAt,
                'amount_paid' => $price,
            ]);
            
            // Update listing fields
            $updateData = [];
            if ($plan === 'boost' || $plan === 'gold') {
                $updateData['bump_until'] = $endsAt;
            }
            if ($plan === 'featured' || $plan === 'gold') {
                $updateData['featured_until'] = $endsAt;
                $updateData['is_featured'] = 1;
            }
            if ($plan === 'vip' || $plan === 'gold') {
                $updateData['vip_until'] = $endsAt;
            }
            if ($plan === 'targeted' || $plan === 'gold') {
                $updateData['targeted_until'] = $endsAt;
            }
            if ($plan === 'ai' || $plan === 'gold') {
                $updateData['ai_promo_until'] = $endsAt;
            }
            
            if (!empty($updateData)) {
                DB::update('listings', $updateData, 'id = ?', [$listingId]);
            }
            
            $success = 'پلن با موفقیت فعال شد';
        }
    }
}

render_head('ارتقای آگهی');
render_navbar($user);
?>

<div class="section-sm">
  <div class="container-md">
    <div class="mb-6">
      <a href="<?= APP_URL ?>/listings/my.php" style="color:var(--text-muted);font-size:.875rem">
        <i class="bi bi-arrow-right"></i> بازگشت به آگهی‌های من
      </a>
      <h2 class="mt-3">ارتقای آگهی: <?= h($listing['title']) ?></h2>
    </div>
    
    <?php if ($success): ?>
      <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:var(--sp-4)">
      <?php foreach ($plans as $key => $plan): ?>
        <div class="card" style="text-align:center">
          <div class="card-body" style="padding:var(--sp-5)">
            <div style="width:80px;height:80px;margin:0 auto var(--sp-3);border-radius:50%;background:linear-gradient(135deg, var(--primary), var(--accent));display:flex;align-items:center;justify-content:center;color:#fff">
              <i class="bi <?= $plan['icon'] ?>" style="font-size:2rem"></i>
            </div>
            <h3 style="margin-bottom:var(--sp-2)"><?= h($plan['name']) ?></h3>
            <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-2)">مدت: <?= h($plan['duration']) ?></div>
            <div style="font-size:1.5rem;font-weight:800;color:var(--primary);margin-bottom:var(--sp-3)"><?= fmt_credit($plan['price']) ?></div>
            <p class="fs-sm" style="color:var(--text-secondary);margin-bottom:var(--sp-4)"><?= h($plan['description']) ?></p>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="plan" value="<?= $key ?>">
              <button type="submit" class="btn btn-primary w-100">خرید پلن</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php render_footer(); ?>
