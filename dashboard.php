<?php
// FIRST DEBUG LOG: Did we even get here?
if (!defined('SWAPIN_REQUEST_ID')) {
    define('SWAPIN_REQUEST_ID', bin2hex(random_bytes(6)));
}
error_log('[swapin-dashboard] BOOTSTRAP STARTED - Request ID: ' . SWAPIN_REQUEST_ID . ' - URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

require_once __DIR__ . '/includes/config.php';
error_log('[swapin-dashboard] config.php loaded successfully');

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/dashboard_layout.php';
require_once __DIR__ . '/includes/iso.php';
require_once __DIR__ . '/includes/asset_valuation.php';
error_log('[swapin-dashboard] layout.php loaded successfully');

swapin_debug_log('dashboard-started', ['step' => 'init', 'uri' => $_SERVER['REQUEST_URI'] ?? '']);

$user = require_auth();
swapin_debug_log('dashboard-auth-ok', ['step' => 'auth', 'user_id' => $user['id'] ?? null]);
$uid  = $user['id'];

$dashboardNeedsMigration = true;
try {
    $dashboardNeedsMigration = !db_has_table('wallet_transactions')
        || !db_has_column('listings', 'listing_mode')
        || !db_has_column('listings', 'review_status');
    swapin_debug_log('dashboard-migration-check-ok', ['needs_migration' => var_export($dashboardNeedsMigration, true)]);
} catch (Throwable $e) {
    swapin_debug_log('dashboard-migration-check-failed', ['message' => $e->getMessage()]);
    $dashboardNeedsMigration = true;
}

// Stats
$myListingsCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status="active"', [$uid])['c'] ?? 0);
$pendingOffers   = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers o JOIN listings l ON l.id = o.listing_id WHERE l.user_id = ? AND o.status="pending"',
    [$uid]
)['c'] ?? 0);
$completedTrades = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trades WHERE (user_a_id = ? OR user_b_id = ?) AND status = "completed"',
    [$uid, $uid]
)['c'] ?? 0);
$sentOffers = (int)(DB::fetch(
    'SELECT COUNT(*) AS c FROM trade_offers WHERE from_user_id = ? AND status = "pending"', [$uid]
)['c'] ?? 0);

$myOrdersCount = store_orders_enabled()
    ? (int)(DB::fetch('SELECT COUNT(*) AS c FROM store_orders WHERE buyer_id = ? AND status NOT IN ("pending_payment","canceled")', [$uid])['c'] ?? 0)
    : 0;
$activeOrdersCount = store_orders_enabled()
    ? (int)(DB::fetch('SELECT COUNT(*) AS c FROM store_orders WHERE buyer_id = ? AND status IN ("paid","preparing","shipped")', [$uid])['c'] ?? 0)
    : 0;

$expiredListingsCount = (int)(DB::fetch('SELECT COUNT(*) AS c FROM listings WHERE user_id = ? AND status = "expired"', [$uid])['c'] ?? 0);

// Recent wallet transactions
$recentTx = $dashboardNeedsMigration
    ? []
    : DB::fetchAll(
        'SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 3',
        [$uid]
    );

// Recent listings
$recentListings = DB::fetchAll(
    'SELECT l.*, (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS thumb,
            (SELECT COUNT(*) FROM trade_offers WHERE listing_id = l.id AND status="pending") AS offer_count
     FROM listings l WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 4',
    [$uid]
);

// Top-performing active listing for performance card — removed from dashboard UI

// Incoming offers (on my listings) with pagination
$offersPerPage = 3;
$offersPage = max(1, (int)($_GET['offers_page'] ?? 1));
$offersOffset = ($offersPage - 1) * $offersPerPage;
$incomingOffers = [];
$incomingOffersCount = 0;
try {
    $incomingOffersCount = (int)(DB::fetch(
        'SELECT COUNT(*) AS c FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         WHERE l.user_id = ? AND o.status = "pending"',
        [$uid]
    )['c'] ?? 0);
    $incomingOffers = DB::fetchAll(
        'SELECT o.*, l.title AS listing_title, u.name AS from_name,
                ol.title AS offer_listing_title
         FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         JOIN users u ON u.id = o.from_user_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE l.user_id = ? AND o.status = "pending"
         ORDER BY o.created_at DESC LIMIT ? OFFSET ?',
        [$uid, $offersPerPage, $offersOffset]
    );
    swapin_debug_log('dashboard-incoming-offers-ok', ['count' => count($incomingOffers), 'total' => $incomingOffersCount]);
} catch (Throwable $e) {
    swapin_debug_log('dashboard-incoming-offers-failed', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
}

// Match engine — AI + rule-based swap suggestions
$swapMatches       = [];
$aiMatchSource     = 'system';
$userListingsMatch = [];

if (!$dashboardNeedsMigration) {
    try {
        $aiMatchData       = ai_match_listings_cached($uid, null, false, 3);
        $swapMatches       = $aiMatchData['matches'];
        $aiMatchSource     = $aiMatchData['source'];
        $userListingsMatch = DB::fetchAll(
            'SELECT id, title FROM listings WHERE user_id = ? AND status = "active" ORDER BY created_at DESC',
            [$uid]
        );
    } catch (Throwable $e) {
        $dashboardNeedsMigration = true;
        swapin_debug_log('dashboard-match-init-failed', [
            'user_id' => $uid,
            'message' => $e->getMessage(),
        ]);
    }
}

// ISO reverse matches — users looking for items I own
$isoReverseMatchesDashboard = [];
if (!$dashboardNeedsMigration && db_has_table('iso_requests')) {
    try {
        $isoReverseMatchesDashboard = iso_get_matches_for_user_listings($uid, 6);
    } catch (Throwable $e) {
        swapin_debug_log('dashboard-iso-reverse-failed', ['user_id' => $uid, 'msg' => $e->getMessage()]);
    }
}

// Asset Value Dashboard data
$assetValueData = null;
try {
    $assetValueData = av_get_user_assets($uid);
} catch (Throwable $e) {
    swapin_debug_log('dashboard-asset-value-failed', ['user_id' => $uid, 'msg' => $e->getMessage()]);
    $assetValueData = null;
}

swapin_debug_log('dashboard-before-render', ['step' => 'before-render', 'my_listings_count' => $myListingsCount ?? 0]);

render_head('داشبورد', 'خلاصه حساب، آگهی‌ها و پیشنهادهای معاوضه در ' . APP_NAME, [
    'robots' => 'noindex, nofollow',
]);
render_panel_styles();
render_navbar($user);
?>

<?php if (isset($_GET['welcome'])): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-stars"></i>
    <span><strong>به <?= APP_NAME ?> خوش آمدید!</strong> برای ثبت آگهی یا معامله، پروفایل خود را تکمیل کنید.</span>
    <?php if (!user_profile_is_complete($user)): ?>
    <a href="<?= APP_URL ?>/profile/edit" class="btn btn-accent btn-sm ms-auto">تکمیل پروفایل</a>
    <?php endif; ?>
  </div>
</div>
<?php elseif (!user_profile_is_complete($user)): ?>
<div class="alert alert-info" style="border-radius:0;border-left:0;border-right:0">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-person-circle"></i>
    <span>برای ثبت آگهی یا معامله، <strong>نام و شهر</strong> خود را در پروفایل وارد کنید.</span>
    <a href="<?= APP_URL ?>/profile/edit" class="btn btn-accent btn-sm ms-auto">تکمیل پروفایل</a>
  </div>
</div>
<?php endif; ?>

<?php if ($expiredListingsCount > 0): ?>
<div class="alert alert-warning" style="border-radius:0;border-left:0;border-right:0" id="expired-listings-alert">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-clock-history"></i>
    <span><?= fmt_num($expiredListingsCount) ?> آگهی شما به‌دلیل سپری شدن ۶۰ روز منقضی شده است. برای بازگردانی آنها به تب «منقضی» بروید.</span>
    <button type="button" class="btn btn-ghost btn-sm" style="margin-inline-start:auto" onclick="this.closest('.alert')?.remove()">
      <i class="bi bi-x-lg"></i> بستن
    </button>
  </div>
</div>
<?php endif; ?>

<?php if (($user['kyc_status'] ?? 'none') === 'none'): ?>
<div class="alert alert-warning" style="border-radius:0;border-left:0;border-right:0">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-shield-exclamation"></i>
    <span>برای فعال‌سازی فروش و پرداخت، <strong>احراز هویت (KYC)</strong> را تکمیل کنید.</span>
    <a href="<?= APP_URL ?>/profile/edit" class="btn btn-accent btn-sm ms-auto">تأیید الآن</a>
  </div>
</div>
<?php endif; ?>

<?php if ($dashboardNeedsMigration): ?>
<div class="alert alert-warning" style="border-radius:0;border-left:0;border-right:0">
  <div class="container d-flex align-center gap-3" style="flex-wrap:wrap">
    <i class="bi bi-exclamation-triangle"></i>
    <span>بخشی از جدول‌ها یا ستون‌های لازم برای داشبورد هنوز روی سرور ساخته نشده‌اند. Migration دیتابیس را اجرا کنید.</span>
    <a href="<?= APP_URL ?>/migrate" class="btn btn-accent btn-sm ms-auto">اجرای Migration</a>
  </div>
</div>
<?php endif; ?>

<?php render_user_panel_open($user, 'dashboard'); ?>
  <div class="dash-panel">
    <?php render_panel_page_header('سلام، ' . explode(' ', $user['name'])[0] . '!', 'خلاصه حساب شما و میان‌برهای سریع', APP_URL . '/', 'بازگشت به خانه'); ?>
    <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px;display:flex;gap:.5rem;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/search/ai" class="btn btn-outline btn-sm">
        <i class="bi bi-stars"></i> جستجوی هوشمند
      </a>
      <a href="<?= APP_URL ?>/search/saved" class="btn btn-outline btn-sm">
        <i class="bi bi-bookmark-star"></i> جستجوهای ذخیره‌شده
      </a>
      <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> آگهی جدید
      </a>
    </div>

    <!-- Stats -->
    <div class="dash-stat-grid" style="grid-template-columns: repeat(4, 1fr);">
      <?php
      $statIcons = [
        ['wallet2', 'primary', fmt_num($user['credit_balance']) . ' ' . CREDIT_UNIT, 'موجودی', APP_URL . '/wallet'],
        ['grid', 'info', fmt_num($myListingsCount), 'آگهی فعال', APP_URL . '/listings/my'],
        ['bag-check', $activeOrdersCount > 0 ? 'warning' : 'info', fmt_num($myOrdersCount), 'سفارش‌های خرید', APP_URL . '/orders/'],
        ['inbox', $pendingOffers > 0 ? 'warning' : 'info', fmt_num($pendingOffers), 'پیشنهاد در انتظار', APP_URL . '/trades?tab=received'],
      ];
      foreach ($statIcons as [$icon, $color, $val, $label, $link]):
      ?>
      <a href="<?= $link ?>" class="dash-stat">
        <span class="dash-stat__icon dash-stat__icon--<?= $color ?>"><i class="bi bi-<?= $icon ?>"></i></span>
        <span>
          <div class="dash-stat__value"><?= $val ?></div>
          <div class="dash-stat__label"><?= h($label) ?></div>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- ── Asset Value Dashboard + Swap Opportunities ─────────────────── -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:var(--sp-5);margin:var(--sp-7) 0;">
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-3)">
          <div>
            <h3 style="margin:0;font-size:1rem">
              <i class="bi bi-piggy-bank" style="color:var(--primary)"></i>
              ارزش تقریبی دارایی‌های شما
            </h3>
            <p class="fs-xs" style="color:var(--text-muted);margin:var(--sp-1) 0 0">
              این اعداد تخمینی هستند و به عنوان قیمت قطعی در نظر گرفته نشوند.
            </p>
          </div>
          <?php if ($assetValueData && $assetValueData['total_value'] > 0): ?>
          <span class="badge badge-<?= $assetValueData['confidence'] === 'high' ? 'success' : ($assetValueData['confidence'] === 'medium' ? 'warning' : 'info') ?> fs-xs">
            اطمینان: <?= av_confidence_label($assetValueData['confidence']) ?>
          </span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (!$assetValueData || empty($assetValueData['assets'])): ?>
            <div class="empty-state" style="padding:var(--sp-5) 0">
              <i class="bi bi-tag"></i>
              <p class="fs-sm" style="color:var(--text-muted)">هنوز آگهی فعال برای محاسبه ارزش ثبت نکرده‌اید.</p>
              <a href="<?= APP_URL ?>/listings/create" class="btn btn-accent btn-sm">ثبت آگهی جدید</a>
            </div>
          <?php else: ?>
            <div style="padding:var(--sp-4);background-color: #081B45;border-radius:16px;margin-bottom:var(--sp-4);color: #FFC107;">
              <div class="fs-xs" style="color: #fff;">مجموع ارزش تقریبی بازار</div>
              <div style="font-size:1.625rem;font-weight:800;margin-top:var(--sp-1);color:var(--text)">
                <?= fmt_num($assetValueData['total_value']) ?> <span class="fs-sm" style="color: #fff;"><?= CREDIT_UNIT ?></span>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:var(--sp-3);max-height:260px;overflow:auto;padding-inline-end:4px">
              <?php foreach (array_slice($assetValueData['assets'], 0, 6) as $a): ?>
              <a href="<?= h($a['view_url']) ?>" class="d-flex align-items-center gap-3" style="text-decoration:none;padding:var(--sp-2);border-radius:12px;transition:background .15s" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">
                <div style="width:48px;height:48px;border-radius:12px;background:var(--surface);flex-shrink:0;display:flex;align-items:center;justify-content:center;overflow:hidden">
                  <?php if (!empty($a['thumb'])): ?>
                    <img src="<?= h($a['thumb']) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                  <?php else: ?>
                    <i class="bi bi-image" style="color:var(--text-muted)"></i>
                  <?php endif; ?>
                </div>
                <div style="flex:1;min-width:0">
                  <div class="fw-700" style="font-size:.9375rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($a['title']) ?></div>
                  <div class="fs-xs" style="color:var(--text-muted)">
                    ارزش تخمینی: <strong style="color:var(--primary)"><?= fmt_num($a['estimated_value']) ?></strong>
                    <span class="fs-xs" style="color:var(--text-muted);margin-inline:6px">·</span>
                    اطمینان: <?= av_confidence_label($a['confidence']) ?>
                  </div>
                </div>
                <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
              </a>
              <?php endforeach; ?>
            </div>
            <?php if (count($assetValueData['assets']) > 6): ?>
            <a href="<?= APP_URL ?>/listings/my" class="btn btn-outline btn-sm w-100 mt-3">
              مشاهده همه <?= fmt_num(count($assetValueData['assets'])) ?> آگهی
            </a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-3)">
          <div>
            <h3 style="margin:0;font-size:1rem">
              <i class="bi bi-lightbulb" style="color:var(--warning)"></i>
              فرصت‌های معاوضه
            </h3>
            <p class="fs-xs" style="color:var(--text-muted);margin:var(--sp-1) 0 0">
              کاربرانی که در جستجوی کالاهای شما هستند یا کالای مناسب برای شما دارند.
            </p>
          </div>
        </div>
        <div class="card-body">
          <?php $soppCount = $assetValueData ? (int)$assetValueData['swap_opportunities'] : count($isoReverseMatchesDashboard); ?>
          <div style="padding:var(--sp-4);background:linear-gradient(135deg,#fff8e6,#ffe8cc);border-radius:16px;margin-bottom:var(--sp-4)">
            <div class="fs-xs" style="color:var(--text-muted)">تعداد آگهی‌های مناسب برای معاوضه</div>
            <div style="font-size:1.625rem;font-weight:800;margin-top:var(--sp-1);color:var(--text)">
              💡 <?= fmt_num($soppCount) ?> <span class="fs-sm" style="color:var(--text-muted)">پیشنهاد</span>
            </div>
          </div>
          <?php if (empty($isoReverseMatchesDashboard)): ?>
            <div class="empty-state" style="padding:var(--sp-5) 0">
              <i class="bi bi-search"></i>
              <p class="fs-sm" style="color:var(--text-muted)">هنوز کاربری در جستجوی کالاهای شما نبوده یا تطابقی پیدا نشده است.</p>
              <a href="<?= APP_URL ?>/iso/create" class="btn btn-accent btn-sm">ساخت درخواست ISO</a>
            </div>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:var(--sp-3);max-height:260px;overflow:auto;padding-inline-end:4px">
              <?php foreach (array_slice($isoReverseMatchesDashboard, 0, 6) as $m):
                  $matchTitle = (string)($m['source_listing_title'] ?? $m['title'] ?? '');
                  $matchedFrom = (string)($m['matched_from_listing_title'] ?? '');
              ?>
              <a href="<?= APP_URL ?>/iso/view.php?id=<?= (int)$m['id'] ?>" class="d-flex align-items-center gap-3" style="text-decoration:none;padding:var(--sp-2);border-radius:12px" onmouseover="this.style.background='var(--surface)'" onmouseout="this.style.background=''">
                <div style="width:48px;height:48px;border-radius:12px;background:var(--surface);flex-shrink:0;display:flex;align-items:center;justify-content:center">
                  <span class="badge badge-<?= ($m['match_score'] ?? 0) >= 70 ? 'success' : (($m['match_score'] ?? 0) >= 50 ? 'warning' : 'info') ?>" style="font-weight:800">
                    <?= fmt_num((int)($m['match_score'] ?? 0)) ?>٪
                  </span>
                </div>
                <div style="flex:1;min-width:0">
                  <div class="fw-700" style="font-size:.9375rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($matchTitle) ?></div>
                  <div class="fs-xs" style="color:var(--text-muted)">
                    درخواست شده برای: <strong style="color:var(--primary)"><?= h(mb_strimwidth($matchedFrom, 0, 26, '…')) ?></strong>
                    <span class="fs-xs" style="color:var(--text-muted);margin-inline:6px">·</span>
                    توسط: <?= h((string)($m['iso_user_name'] ?? '')) ?>
                  </div>
                </div>
                <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
              </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Match Hub (AI Matching Engine) ─────────────────────────────── -->
    <div id="swap-matches" class="match-hub mb-8" data-ai-match="1">
      <div class="match-hub__title" style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">
        <div>
          <h2 style="margin:0;font-size:1.25rem">
            <i class="bi bi-stars" style="color:var(--accent-dark)"></i>
            پیشنهادهای معاوضه
            <?php if (ai_source_is_ai($aiMatchSource)): ?>
            <span class="badge badge-gold fs-xs">AI</span>
            <?php endif; ?>
          </h2>
          <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-2) 0 0">
            موتور Matching هوشمند — بر اساس «نیازمند»، دسته و شباهت آگهی‌ها
          </p>
        </div>
        <div class="match-hub__actions" style="display:flex;gap:var(--sp-2);flex-wrap:wrap;align-items:center">
          <?php if (count($userListingsMatch) > 1): ?>
          <select id="ai-match-listing" class="form-control" style="width:auto;min-width:180px;height:50px;font-size:.8125rem">
            <?php foreach ($userListingsMatch as $ul): ?>
            <option value="<?= (int)$ul['id'] ?>"><?= h(mb_strimwidth($ul['title'], 0, 40, '…')) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <button type="button" class="btn btn-outline btn-sm" id="ai-match-refresh" title="تحلیل مجدد با AI">
            <i class="bi bi-arrow-clockwise"></i> بروزرسانی AI
          </button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-5);margin-top:var(--sp-5)">
        <!-- 1:1 Swap matches -->
        <div class="card">
          <div class="card-header">
            <h3 style="margin:0;font-size:1rem">
              <i class="bi bi-arrow-left-right" style="color:var(--primary)"></i>
              پیشنهاد معاوضه
            </h3>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:var(--sp-3);min-height:160px">
            <div id="ai-match-loading" class="ai-match-loading" hidden aria-live="polite" aria-busy="true">
<?php require_once __DIR__ . '/includes/skeleton.php'; echo skeleton_match_rows(3); ?>
            </div>
            <div id="ai-match-list" style="display:flex;flex-direction:column;gap:var(--sp-3)">
            <?php if ($swapMatches): ?>
              <?php foreach (array_slice($swapMatches, 0, 3) as $m):
                  $need  = (int)($m['score_need']     ?? 0);
                  $cat   = (int)($m['score_category'] ?? 0);
                  $val   = (int)($m['score_value']    ?? 0);
                  $succ  = (int)($m['score_success']  ?? 0);
              ?>
              <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$m['listing_id'] ?>" class="match-row" data-listing-id="<?= (int)$m['listing_id'] ?>">
                <div class="match-row__score"><?= (int)$m['match_score'] ?>٪</div>
                <div class="match-row__body">
                  <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <span style="font-weight:700"><?= h($m['title']) ?></span>
                    <?php if (ai_source_is_ai($m['ai_source'] ?? '')): ?>
                    <span class="badge badge-gold fs-xs">AI</span>
                    <?php endif; ?>
                    <?php if (!empty($m['mutual'])): ?>
                    <span class="badge badge-gold fs-xs">دوطرفه</span>
                    <?php endif; ?>
                    <?php if (($m['trade_type'] ?? '') === 'credit'): ?>
                    <span class="badge badge-primary fs-xs">اعتباری</span>
                    <?php endif; ?>
                  </div>
                  <div class="fs-xs" style="color:var(--text-muted)">
                    <?= h($m['seller_name']) ?> · برای: <?= h(mb_strimwidth($m['match_title'], 0, 30, '…')) ?>
                  </div>
                  <?php if (!empty($m['reason'])): ?>
                  <p class="match-row__reason fs-xs"><?= h($m['reason']) ?></p>
                  <?php endif; ?>
                  <div class="match-pillars" aria-label="چهار عامل تطبیق هوشمند">
                    <div class="match-pillar" title="نیاز کاربران — <?= fmt_num($need) ?>٪">
                      <div class="match-pillar__label">نیاز</div>
                      <div class="match-pillar__bar"><span class="match-pillar__fill match-pillar__fill--need" style="width:<?= $need ?>%"></span></div>
                      <div class="match-pillar__pct"><?= fmt_num($need) ?>٪</div>
                    </div>
                    <div class="match-pillar" title="ارزش کالا — <?= fmt_num($val) ?>٪">
                      <div class="match-pillar__label">ارزش</div>
                      <div class="match-pillar__bar"><span class="match-pillar__fill match-pillar__fill--value" style="width:<?= $val ?>%"></span></div>
                      <div class="match-pillar__pct"><?= fmt_num($val) ?>٪</div>
                    </div>
                    <div class="match-pillar" title="دسته‌بندی — <?= fmt_num($cat) ?>٪">
                      <div class="match-pillar__label">دسته</div>
                      <div class="match-pillar__bar"><span class="match-pillar__fill match-pillar__fill--cat" style="width:<?= $cat ?>%"></span></div>
                      <div class="match-pillar__pct"><?= fmt_num($cat) ?>٪</div>
                    </div>
                    <div class="match-pillar" title="احتمال موفقیت — <?= fmt_num($succ) ?>٪">
                      <div class="match-pillar__label">موفقیت</div>
                      <div class="match-pillar__bar"><span class="match-pillar__fill match-pillar__fill--success" style="width:<?= $succ ?>%"></span></div>
                      <div class="match-pillar__pct"><?= fmt_num($succ) ?>٪</div>
                    </div>
                  </div>
                </div>
                <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
              </a>
              <?php endforeach; ?>
            <?php elseif ($myListingsCount === 0): ?>
              <div class="empty-state" style="padding:var(--sp-6) 0">
                <i class="bi bi-plus-circle"></i>
                <p class="fs-sm" style="color:var(--text-muted)">اول یک آگهی ثبت کنید تا پیشنهاد دریافت کنید.</p>
                <a href="<?= APP_URL ?>/listings/create" class="btn btn-accent btn-sm">ثبت آگهی</a>
              </div>
            <?php else: ?>
              <div class="empty-state" style="padding:var(--sp-6) 0">
                <i class="bi bi-search"></i>
                <p class="fs-sm" style="color:var(--text-muted)">هنوز تطابق دقیقی نیست. «نیازمند» را دقیق‌تر بنویسید (مثلاً لپ‌تاپ / موبایل).</p>
              </div>
            <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Incoming Offers -->
        <div class="card">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem"><i class="bi bi-inbox" style="color:var(--primary)"></i> پیشنهادهای دریافتی</h3>
            <span class="badge badge-warning"><?= fmt_num($incomingOffersCount) ?></span>
          </div>
          <div class="card-body" style="padding:0">
            <?php if ($incomingOffers): ?>
              <?php foreach ($incomingOffers as $offer): ?>
              <div style="padding:var(--sp-4) var(--sp-5);border-bottom:1px solid var(--border)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--sp-3)">
                  <div style="flex:1;min-width:0">
                    <div class="fs-sm fw-700"><?= h($offer['from_name']) ?> پیشنهاد داد برای:</div>
                    <div style="font-size:.9375rem;font-weight:600;color:var(--primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                      <?= h($offer['listing_title']) ?>
                    </div>
                    <?php if ($offer['offer_listing_title']): ?>
                    <div class="fs-sm" style="color:var(--text-secondary);margin-top:2px">
                      <i class="bi bi-box"></i> کالای او: <?= h(mb_strimwidth($offer['offer_listing_title'], 0, 40, '…')) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($offer['offer_credit'] > 0): ?>
                    <div class="fs-sm" style="color:var(--primary);margin-top:2px">
                      <i class="bi bi-wallet2"></i> + <?= fmt_credit((float)$offer['offer_credit']) ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($offer['message']): ?>
                    <div class="fs-xs" style="color:var(--text-muted);margin-top:4px;font-style:italic">
                      «<?= h(mb_strimwidth($offer['message'], 0, 80, '…')) ?>»
                    </div>
                    <?php endif; ?>
                  </div>
                  <div style="display:flex;gap:var(--sp-2);flex-shrink:0">
                    <a href="<?= APP_URL ?>/trades?tab=received"
                       class="btn btn-primary btn-sm">مدیریت</a>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state" style="padding:var(--sp-6) 0">
                <i class="bi bi-inbox"></i>
                <p class="fs-sm" style="color:var(--text-muted)">هنوز پیشنهادی دریافت نکردید</p>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($incomingOffersCount > $offersPerPage): ?>
          <div class="card-footer" style="display:flex;justify-content:space-between;gap:var(--sp-3);align-items:center">
            <div>
              <?php if ($offersPage > 1): ?>
              <a href="?<?= http_build_query(array_merge($_GET, ['offers_page' => $offersPage - 1])) ?>" class="btn btn-outline btn-sm">قبلی</a>
              <?php endif; ?>
            </div>
            <div class="fs-sm" style="color:var(--text-muted)">
              صفحه <?= fmt_num($offersPage) ?> از <?= fmt_num(ceil($incomingOffersCount / $offersPerPage)) ?>
            </div>
            <div>
              <?php if ($offersPage < ceil($incomingOffersCount / $offersPerPage)): ?>
              <a href="?<?= http_build_query(array_merge($_GET, ['offers_page' => $offersPage + 1])) ?>" class="btn btn-outline btn-sm">بعدی</a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ISO Reverse Match Hub — users looking for items I own -->
    <?php if (!empty($isoReverseMatchesDashboard)): ?>
    <div id="iso-reverse-hub" class="match-hub mb-8">
      <div class="match-hub__title" style="display:flex;align-items:flex-start;justify-content:space-between;gap:var(--sp-4);flex-wrap:wrap">
        <div>
          <h2 style="margin:0;font-size:1.25rem">
            <i class="bi bi-search-heart" style="color:var(--accent-dark)"></i>
            کاربرانی که دنبال کالای شما هستند
            <span class="badge badge-accent fs-xs"><?= fmt_num(count($isoReverseMatchesDashboard)) ?> تطابق</span>
          </h2>
          <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-2) 0 0">
            این کاربران در لیست نیاز ISOشان کالایی مشابه با آگهی‌های شما ثبت کرده‌اند — می‌توانید پیشنهاد معاوضه بدهید
          </p>
        </div>
        <a href="<?= APP_URL ?>/iso" class="btn btn-outline btn-sm">
          <i class="bi bi-list-ul"></i> مدیریت ISOهای من
        </a>
      </div>

      <div class="card mt-5">
        <div class="card-body" style="padding:var(--sp-3)">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)">
          <?php foreach ($isoReverseMatchesDashboard as $irm):
            $irmScore = (int)($irm['match_score'] ?? 0);
          ?>
            <a href="<?= APP_URL ?>/listings/view?id=<?= (int)$irm['listing_id'] ?>" class="match-row" data-listing-id="<?= (int)$irm['listing_id'] ?>" style="text-decoration:none;color:inherit">
              <div class="match-row__score" style="background:<?= $irmScore >= 80 ? 'linear-gradient(135deg,#22c55e,#16a34a);color:#fff' : ($irmScore >= 60 ? 'linear-gradient(135deg,#3b82f6,#6366f1);color:#fff' : 'linear-gradient(135deg,#f59e0b,#d97706);color:#fff') ?>">
                <?= $irmScore ?>٪
              </div>
              <div class="match-row__body">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                  <span style="font-weight:700"><?= h(mb_strimwidth($irm['title'], 0, 40, '…')) ?></span>
                  <span class="badge fs-xs">آگهی <?= h($irm['iso_user_name']) ?></span>
                </div>
                <div class="fs-xs" style="color:var(--text-muted)">
                  <i class="bi bi-arrow-left-right"></i>
                  او دارد: <strong><?= h(mb_strimwidth($irm['source_listing_title'] ?? '', 0, 30, '…')) ?></strong>
                  <span class="mx-2">·</span>
                  دنبالش می‌گردد: <strong><?= h(mb_strimwidth($irm['matched_from_listing_title'] ?? '', 0, 30, '…')) ?></strong>
                </div>
                <?php if (!empty($irm['match_reason'])): ?>
                <p class="match-row__reason fs-xs"><?= h($irm['match_reason']) ?></p>
                <?php endif; ?>
              </div>
              <i class="bi bi-chevron-left" style="color:var(--text-muted)"></i>
            </a>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 380px;gap:var(--sp-6);align-items:start">

      <!-- ── Left Column ─────────────────────────────────────────── -->
      <div>

        <!-- My Listings -->
        <div class="dash-panel-card mb-6">
          <div class="dash-panel-card__head">
            <h3><i class="bi bi-grid" style="color:var(--primary)"></i> آگهی‌های فعال من</h3>
            <a href="<?= APP_URL ?>/listings/my">مشاهده همه</a>
          </div>
          <?php if (empty($recentListings)): ?>
          <div class="card-body">
            <div class="empty-state" style="padding:var(--sp-8) var(--sp-4)">
              <i class="bi bi-box-seam" style="font-size:2.5rem"></i>
              <h3 style="font-size:1.125rem">هنوز آگهی‌ای ندارید</h3>
              <p style="font-size:.875rem">اولین آگهی خود را ثبت کنید و معاوضه را شروع کنید!</p>
              <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary btn-sm">ثبت آگهی</a>
            </div>
          </div>
          <?php else: ?>
          <div style="padding:14px 18px">
            <div class="dash-listings-grid">
              <?php foreach ($recentListings as $listing): ?>
              <a href="<?= APP_URL ?>/listings/view?id=<?= $listing['id'] ?>" class="dash-listing-card">
                <div class="dash-listing-card__thumb">
                  <?php if ($listing['thumb']): ?>
                  <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="<?= h($listing['title']) ?>">
                  <?php else: ?>
                  <div class="dash-listing-card__thumb--empty"><i class="bi bi-image"></i></div>
                  <?php endif; ?>
                </div>
                <div class="dash-listing-card__body">
                  <p class="dash-listing-card__title"><?= h($listing['title']) ?></p>
                  <p class="dash-listing-card__meta">
                    <?php if ($listing['offer_count'] > 0): ?>
                    <span style="color:var(--warning);font-weight:600"><i class="bi bi-inbox"></i> <?= fmt_num($listing['offer_count']) ?> پیشنهاد</span>
                    <?php else: ?>
                    <?= persian_date($listing['created_at']) ?>
                    <?php endif; ?>
                  </p>
                </div>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

      </div>

      <!-- ── Right: Wallet ──────────────────────────────────────── -->
      <div>
        <div class="wallet-card mb-4">
          <div class="wallet-card__label"><i class="bi bi-wallet2"></i> موجودی <?= CREDIT_UNIT ?></div>
          <div class="wallet-card__balance"><?= fmt_num($user['credit_balance']) ?></div>
          <div class="wallet-card__symbol">اعتبار <?= APP_NAME ?></div>
          <div style="display:flex;gap:var(--sp-3);margin-top:var(--sp-6)">
            <a href="<?= APP_URL ?>/wallet?action=deposit" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border-color:rgba(255,255,255,.3);flex:1">
              <i class="bi bi-plus-circle"></i> افزودن اعتبار
            </a>
            <a href="<?= APP_URL ?>/wallet" class="btn btn-sm" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.2);flex:1">
              تاریخچه
            </a>
          </div>
        </div>

        <!-- Transaction History -->
        <div class="dash-panel-card">
          <div class="dash-panel-card__head">
            <h3>تراکنش‌های اخیر</h3>
            <a href="<?= APP_URL ?>/wallet" class="btn btn-outline btn-sm" style="font-size:.75rem;padding:6px 12px;">مشاهده همه</a>
          </div>
          <?php if (empty($recentTx)): ?>
          <div style="text-align:center;color:var(--text-muted);font-size:.875rem;padding:var(--sp-8)">
            <i class="bi bi-clock-history" style="font-size:2rem;opacity:.3;display:block;margin-bottom:var(--sp-3)"></i>
            هنوز تراکنشی ثبت نشده
          </div>
          <?php else: ?>
          <div class="dash-tx-list">
          <?php foreach (array_slice($recentTx, 0, 4) as $tx): ?>
          <?php
            $isPos = $tx['amount'] >= 0;
            $typeLabels = [
              'deposit'      => ['arrow-down-circle', 'واریز',          'success'],
              'withdraw'     => ['arrow-up-circle',   'برداشت',       'danger'],
              'trade_credit' => ['arrow-down-circle', 'دریافت از معاوضه',   'success'],
              'trade_debit'  => ['arrow-up-circle',   'پرداخت در معاوضه',       'danger'],
              'fee'          => ['dash-circle',        'کارمزد پلتفرم',     'warning'],
              'refund'       => ['arrow-counterclockwise','بازپرداخت',        'info'],
            ];
            [$txIcon, $txLabel, $txColor] = $typeLabels[$tx['type']] ?? ['circle', 'تراکنش', 'info'];
          ?>
          <div class="dash-tx-item">
            <div class="dash-tx-item__icon" style="background:var(--<?= $txColor ?>-bg);color:var(--<?= $txColor ?>)">
              <i class="bi bi-<?= $txIcon ?>"></i>
            </div>
            <div class="dash-tx-item__body">
              <div class="dash-tx-item__label"><?= $txLabel ?></div>
              <?php if ($tx['note']): ?>
              <div class="dash-tx-item__note"><?= h(mb_strimwidth($tx['note'], 0, 40, '…')) ?></div>
              <?php endif; ?>
            </div>
            <div style="font-weight:700;font-size:.875rem;color:var(--<?= $isPos ? 'success' : 'danger' ?>)">
              <?= $isPos ? '+' : '' ?><?= fmt_num($tx['amount']) ?>
            </div>
          </div>
          <?php endforeach; ?>
          </div>
          <a href="<?= APP_URL ?>/wallet" class="dash-tx-more">نمایش بیشتر ←</a>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
