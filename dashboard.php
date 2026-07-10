<?php
// FIRST DEBUG LOG: Did we even get here?
if (!defined('SWAPIN_REQUEST_ID')) {
    define('SWAPIN_REQUEST_ID', bin2hex(random_bytes(6)));
}
error_log('[swapin-dashboard] BOOTSTRAP STARTED - Request ID: ' . SWAPIN_REQUEST_ID . ' - URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

require_once __DIR__ . '/includes/config.php';
error_log('[swapin-dashboard] config.php loaded successfully');

require_once __DIR__ . '/includes/layout.php';
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
$unreadMsgs = (int)(DB::fetch('SELECT COUNT(*) AS c FROM messages WHERE to_user_id = ? AND is_read = 0', [$uid])['c'] ?? 0);

// Recent wallet transactions
$recentTx = $dashboardNeedsMigration
    ? []
    : DB::fetchAll(
        'SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5',
        [$uid]
    );

// Recent listings
$recentListings = DB::fetchAll(
    'SELECT l.*, (SELECT filename FROM listing_images WHERE listing_id=l.id AND is_primary=1 LIMIT 1) AS thumb,
            (SELECT COUNT(*) FROM trade_offers WHERE listing_id = l.id AND status="pending") AS offer_count
     FROM listings l WHERE l.user_id = ? ORDER BY l.created_at DESC LIMIT 4',
    [$uid]
);

// Incoming offers (on my listings)
$incomingOffers = [];
try {
    $incomingOffers = DB::fetchAll(
        'SELECT o.*, l.title AS listing_title, u.name AS from_name,
                ol.title AS offer_listing_title
         FROM trade_offers o
         JOIN listings l ON l.id = o.listing_id
         JOIN users u ON u.id = o.from_user_id
         LEFT JOIN listings ol ON ol.id = o.offer_listing_id
         WHERE l.user_id = ? AND o.status = "pending"
         ORDER BY o.created_at DESC LIMIT 5',
        [$uid]
    );
    swapin_debug_log('dashboard-incoming-offers-ok', ['count' => count($incomingOffers)]);
} catch (Throwable $e) {
    swapin_debug_log('dashboard-incoming-offers-failed', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
}

// Match engine — AI + rule-based swap suggestions
$swapMatches       = [];
$aiMatchSource     = 'system';
$userListingsMatch = [];
$triangularSwaps   = [];

if (!$dashboardNeedsMigration) {
    try {
        $aiMatchData       = ai_match_listings_cached($uid, null, false, 8);
        $swapMatches       = $aiMatchData['matches'];
        $aiMatchSource     = $aiMatchData['source'];
        $userListingsMatch = DB::fetchAll(
            'SELECT id, title FROM listings WHERE user_id = ? AND status = "active" AND listing_mode IN ("swap","both") ORDER BY created_at DESC',
            [$uid]
        );
        $triangularSwaps = find_triangular_swaps($uid, 3);
    } catch (Throwable $e) {
        $dashboardNeedsMigration = true;
        swapin_debug_log('dashboard-match-init-failed', [
            'user_id' => $uid,
            'message' => $e->getMessage(),
        ]);
    }
}

swapin_debug_log('dashboard-before-render', ['step' => 'before-render', 'my_listings_count' => $myListingsCount ?? 0]);

render_head('داشبورد', 'خلاصه حساب، آگهی‌ها و پیشنهادهای معاوضه در ' . APP_NAME, [
    'robots' => 'noindex, nofollow',
]);
render_navbar($user);
?>

<?php if (isset($_GET['welcome'])): ?>
<div class="alert alert-success" style="border-radius:0;border-left:0;border-right:0">
  <div class="container">
    <i class="bi bi-stars"></i>
    <strong>به <?= APP_NAME ?> خوش آمدید!</strong> مبلغ <strong><?= number_format(WELCOME_BONUS, 0) ?> <?= CREDIT_UNIT ?></strong> به عنوان پاداش خوش‌آمدگویی به کیف پول شما اضافه شد. با ثبت اولین آگهی شروع کنید!
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

<main id="main-content" class="section-sm">
  <div class="container">

    <header style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-6);flex-wrap:wrap;gap:var(--sp-3)">
      <div>
        <h1 style="font-size:1.5rem;margin:0">خوش آمدید، <?= h(explode(' ', $user['name'])[0]) ?>!</h1>
        <p style="color:var(--text-muted)">خلاصه‌ای از وضعیت حساب شما ·
          <a href="#swap-matches" style="font-weight:600">پیشنهادهای معاوضه ↓</a>
        </p>
      </div>
      <a href="<?= APP_URL ?>/listings/create" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> آگهی جدید
      </a>
    </header>

    <!-- Stats Row -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-8)">
      <?php
      $stats = [
        ['wallet2',          number_format($user['credit_balance'], 0) . ' ' . CREDIT_UNIT, 'موجودی اعتبار',   'primary', APP_URL . '/wallet'],
        ['grid',             $myListingsCount,                                    'آگهی‌های فعال',  'info',    APP_URL . '/listings/my'],
        ['inbox',            $pendingOffers,                                      'پیشنهادهای در انتظار',   $pendingOffers > 0 ? 'warning' : 'info', APP_URL . '/listings/offers'],
        ['arrow-left-right', $completedTrades,                                    'معاوضه‌های انجام‌شده', 'success', APP_URL . '/trades'],
        ['chat-dots',        $unreadMsgs,                                         'پیام‌های خوانده‌نشده',  $unreadMsgs > 0 ? 'warning' : 'info', APP_URL . '/messages'],
        ['send',             $sentOffers,                                         'پیشنهادهای ارسالی',      'info',    APP_URL . '/trades?tab=sent'],
      ];
      foreach ($stats as [$icon, $val, $label, $color, $link]):
      ?>
      <a href="<?= $link ?>" style="text-decoration:none">
        <div class="card" style="border-<?= in_array($color, ['warning']) && (int)$val > 0 ? 'top' : 'left' ?>:3px solid var(--<?= $color ?>)">
          <div class="card-body" style="padding:var(--sp-4)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--sp-2)">
              <i class="bi bi-<?= $icon ?>" style="font-size:1.25rem;color:var(--<?= $color ?>)"></i>
              <?php if (is_numeric($val) && (int)$val > 0 && in_array($color, ['warning', 'danger'])): ?>
              <span class="badge badge-<?= $color ?>"><?= $val ?></span>
              <?php endif; ?>
            </div>
            <div style="font-size:1.5rem;font-weight:800;color:var(--text-primary)"><?= $val ?></div>
            <div class="fs-sm" style="color:var(--text-muted)"><?= $label ?></div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
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
              <?php foreach ($swapMatches as $m): ?>
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

        <!-- 3-way swap -->
        <div class="card" id="triangular-swaps">
          <div class="card-header">
            <h3 style="margin:0;font-size:1rem">
              <i class="bi bi-diagram-3" style="color:var(--primary)"></i>
              معاوضه سه‌طرفه
            </h3>
          </div>
          <div class="card-body" style="display:flex;flex-direction:column;gap:var(--sp-4);min-height:160px">
            <?php if ($triangularSwaps): ?>
              <?php foreach ($triangularSwaps as $chain): ?>
              <div class="triangle-chain">
                <div class="triangle-chain__mine">
                  <span class="fs-xs" style="color:var(--text-muted)">شما</span>
                  <strong><?= h(mb_strimwidth($chain['mine']['title'], 0, 30, '…')) ?></strong>
                </div>
                <?php foreach ($chain['chain'] as $i => $step): ?>
                <div class="triangle-chain__arrow"><i class="bi bi-arrow-left"></i></div>
                <div class="triangle-chain__step">
                  <span class="fs-xs" style="color:var(--text-muted)">نفر <?= $i + 1 ?>: <?= h($step['seller_name']) ?></span>
                  <a href="<?= APP_URL ?>/listings/view?id=<?= $step['id'] ?>"><?= h(mb_strimwidth($step['title'], 0, 28, '…')) ?></a>
                </div>
                <?php endforeach; ?>
                <div class="triangle-chain__arrow"><i class="bi bi-arrow-left"></i></div>
                <div class="triangle-chain__back fs-xs" style="color:var(--success)">
                  <i class="bi bi-check-circle"></i> به شما برمی‌گردد
                </div>
              </div>
              <?php endforeach; ?>
            <?php elseif ($myListingsCount === 0): ?>
              <div class="empty-state" style="padding:var(--sp-6) 0">
                <i class="bi bi-diagram-3"></i>
                <p class="fs-sm" style="color:var(--text-muted)">با ثبت آگهی، زنجیره‌های سه‌نفره هم پیشنهاد می‌شود.</p>
              </div>
            <?php else: ?>
              <div class="empty-state" style="padding:var(--sp-6) 0">
                <i class="bi bi-diagram-3"></i>
                <p class="fs-sm" style="color:var(--text-muted)">فعلاً زنجیره سه‌طرفه‌ای پیدا نشد. هرچه آگهی‌های بیشتری در سیستم باشد، شانس بیشتر است.</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 380px;gap:var(--sp-6);align-items:start">

      <!-- ── Left Column ─────────────────────────────────────────── -->
      <div>

        <!-- Incoming Offers -->
        <?php if ($incomingOffers): ?>
        <div class="card mb-6">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-inbox" style="color:var(--primary)"></i> پیشنهادهای دریافتی</h3>
            <span class="badge badge-warning"><?= count($incomingOffers) ?></span>
          </div>
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
                  <i class="bi bi-wallet2"></i> + <?= number_format((float)$offer['offer_credit'], 0) ?> <?= CREDIT_UNIT ?>
                </div>
                <?php endif; ?>
                <?php if ($offer['message']): ?>
                <div class="fs-xs" style="color:var(--text-muted);margin-top:4px;font-style:italic">
                  «<?= h(mb_strimwidth($offer['message'], 0, 80, '…')) ?>»
                </div>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:var(--sp-2);flex-shrink:0">
                <a href="<?= APP_URL ?>/listings/offers?id=<?= $offer['listing_id'] ?>&accept=<?= $offer['id'] ?>"
                   class="btn btn-primary btn-sm">پذیرفتن</a>
                <a href="<?= APP_URL ?>/listings/offers?id=<?= $offer['listing_id'] ?>&reject=<?= $offer['id'] ?>"
                   class="btn btn-ghost btn-sm" style="color:var(--danger)">رد</a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="card-footer">
            <a href="<?= APP_URL ?>/listings/offers" class="fs-sm">مشاهده همه پیشنهادها ←</a>
          </div>
        </div>
        <?php endif; ?>

        <!-- My Listings -->
        <div class="card mb-6">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-grid" style="color:var(--primary)"></i> آگهی‌های فعال من</h3>
            <a href="<?= APP_URL ?>/listings/my" class="fs-sm">مشاهده همه</a>
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
          <?php foreach ($recentListings as $listing): ?>
          <div style="display:flex;align-items:center;gap:var(--sp-4);padding:var(--sp-4) var(--sp-5);border-bottom:1px solid var(--border)">
            <?php if ($listing['thumb']): ?>
            <img src="<?= UPLOAD_URL . h($listing['thumb']) ?>" alt="<?= h($listing['title']) ?>"
                 style="width:52px;height:52px;border-radius:var(--radius-md);object-fit:cover;flex-shrink:0">
            <?php else: ?>
            <div style="width:52px;height:52px;border-radius:var(--radius-md);background:var(--bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-image" style="color:var(--text-muted)"></i>
            </div>
            <?php endif; ?>
            <div style="flex:1;min-width:0">
              <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= h($listing['title']) ?>
              </div>
              <div class="fs-xs" style="color:var(--text-muted)">
                <?php if ($listing['offer_count'] > 0): ?>
                <span style="color:var(--warning);font-weight:600"><i class="bi bi-inbox"></i> <?= $listing['offer_count'] ?> پیشنهاد</span>
                <?php else: ?>
                ثبت شده <?= date('M j', strtotime($listing['created_at'])) ?>
                <?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:var(--sp-2)">
              <?php if ($listing['offer_count'] > 0): ?>
              <a href="<?= APP_URL ?>/listings/offers?id=<?= $listing['id'] ?>" class="btn btn-accent btn-sm">
                <i class="bi bi-inbox"></i> پیشنهادها
              </a>
              <?php endif; ?>
              <a href="<?= APP_URL ?>/listings/view?id=<?= $listing['id'] ?>" class="btn btn-ghost btn-sm">مشاهده</a>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>

      <!-- ── Right: Wallet ──────────────────────────────────────── -->
      <div>
        <div class="wallet-card mb-4">
          <div class="wallet-card__label"><i class="bi bi-wallet2"></i> موجودی <?= CREDIT_UNIT ?></div>
          <div class="wallet-card__balance"><?= number_format($user['credit_balance'], 0) ?></div>
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
        <div class="card">
          <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
            <h3 style="margin:0;font-size:1rem">تراکنش‌های اخیر</h3>
            <a href="<?= APP_URL ?>/wallet" class="fs-sm">مشاهده همه</a>
          </div>
          <?php if (empty($recentTx)): ?>
          <div class="card-body" style="text-align:center;color:var(--text-muted);font-size:.875rem;padding:var(--sp-8)">
            <i class="bi bi-clock-history" style="font-size:2rem;opacity:.3;display:block;margin-bottom:var(--sp-3)"></i>
            هنوز تراکنشی ثبت نشده
          </div>
          <?php else: ?>
          <?php foreach ($recentTx as $tx): ?>
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
          <div style="display:flex;align-items:center;gap:var(--sp-3);padding:var(--sp-3) var(--sp-5);border-bottom:1px solid var(--border)">
            <div style="width:34px;height:34px;border-radius:50%;background:var(--<?= $txColor ?>-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-<?= $txIcon ?>" style="color:var(--<?= $txColor ?>)"></i>
            </div>
            <div style="flex:1">
              <div style="font-weight:600;font-size:.875rem"><?= $txLabel ?></div>
              <?php if ($tx['note']): ?>
              <div class="fs-xs" style="color:var(--text-muted)"><?= h(mb_strimwidth($tx['note'], 0, 40, '…')) ?></div>
              <?php endif; ?>
              <div class="fs-xs" style="color:var(--text-muted)"><?= date('M j, g:ia', strtotime($tx['created_at'])) ?></div>
            </div>
            <div style="font-weight:700;font-size:.9375rem;color:var(--<?= $isPos ? 'success' : 'danger' ?>)">
              <?= $isPos ? '+' : '' ?><?= number_format($tx['amount'], 0) ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </div>
</main>

<?php render_footer(); ?>
