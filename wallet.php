<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/dashboard_layout.php';

$user = require_auth();
$uid  = $user['id'];

$action  = clean($_GET['action'] ?? '');
$success = '';
$error   = '';

// Handle deposit (mock IPG — development only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deposit_amount'])) {
    csrf_verify_or_fail();
    if (!WALLET_DEMO_DEPOSIT) {
        $error = 'واریز آزمایشی در محیط production غیرفعال است.';
    } else {
    $amount = (float)($_POST['deposit_amount'] ?? 0);
    if ($amount < 10 || $amount > 1000000) {
        $error = 'مبلغ واریز باید بین ۱۰ تا ۱٬۰۰۰٬۰۰۰ ' . CREDIT_UNIT . ' باشد.';
    } else {
        credit_transact($uid, 'deposit', $amount, 'واریز دستی (درگاه آزمایشی)', ['ref_type' => 'external']);
        // Reload user
        $user    = DB::fetch('SELECT * FROM users WHERE id = ?', [$uid]);
        $success = 'با موفقیت ' . fmt_credit($amount) . ' به کیف پول شما اضافه شد!';
    }
    }
}

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$total   = (int)(DB::fetch('SELECT COUNT(*) AS c FROM wallet_transactions WHERE user_id = ?', [$uid])['c'] ?? 0);
$pag     = paginate($total, $perPage, $page);

$transactions = DB::fetchAll(
    'SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
    [$uid, $perPage, $pag['offset']]
);

// Stats
$totalEarned = (float)(DB::fetch(
    'SELECT COALESCE(SUM(amount),0) AS s FROM wallet_transactions WHERE user_id = ? AND amount > 0', [$uid]
)['s'] ?? 0);
$totalSpent  = abs((float)(DB::fetch(
    'SELECT COALESCE(SUM(amount),0) AS s FROM wallet_transactions WHERE user_id = ? AND amount < 0', [$uid]
)['s'] ?? 0));

render_head('کیف پول من');
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'wallet');
?>

  <div class="dash-panel">
    <?php render_panel_page_header('کیف پول', 'مدیریت موجودی و تراکنش‌ها'); ?>
    <div class="dash-page-head__actions" style="justify-content:flex-end;margin-bottom:24px">
      <a href="<?= APP_URL ?>/dashboard" class="btn btn-outline btn-sm">
        <i class="bi bi-arrow-right"></i> بازگشت
      </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> <?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger mb-5"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <!-- Balance Cards Row -->
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:var(--sp-4);margin-bottom:var(--sp-8)">

      <!-- Main Wallet Card -->
      <div class="wallet-card">
        <div class="wallet-card__label"><i class="bi bi-wallet2"></i> موجودی کل</div>
        <div class="wallet-card__balance"><?= number_format($user['credit_balance'], 0) ?></div>
        <div class="wallet-card__symbol"><?= CREDIT_UNIT ?> — اعتبار <?= APP_NAME ?></div>
        <p style="font-size:.8125rem; margin-top:var(--sp-2);color: #FFC107;">اعتبار کیف پول برای تعادل ارزش در معاملات استفاده می‌شود</p>
      </div>

      <div class="card">
        <div class="card-body" style="text-align:center;padding:var(--sp-5)">
          <i class="bi bi-arrow-down-circle" style="font-size:1.5rem;color:var(--success);margin-bottom:var(--sp-2);display:block"></i>
          <div style="font-size:1.25rem;font-weight:800;color:var(--success)"><?= number_format($totalEarned, 0) ?></div>
          <div class="fs-sm" style="color:var(--text-muted)">کل دریافتی</div>
        </div>
      </div>

      <div class="card">
        <div class="card-body" style="text-align:center;padding:var(--sp-5)">
          <i class="bi bi-arrow-up-circle" style="font-size:1.5rem;color:var(--danger);margin-bottom:var(--sp-2);display:block"></i>
          <div style="font-size:1.25rem;font-weight:800;color:var(--danger)"><?= number_format($totalSpent, 0) ?></div>
          <div class="fs-sm" style="color:var(--text-muted)">کل خرج‌شده</div>
        </div>
      </div>

    </div>

    <div style="display:grid;grid-template-columns:1fr 340px;gap:var(--sp-6);align-items:start">

      <!-- ── Transaction History ────────────────────────────────── -->
      <div>
        <div class="card">
          <div class="card-header">
            <h3 style="margin:0;font-size:1.0625rem">تاریخچه تراکنش‌ها</h3>
          </div>

          <?php if (empty($transactions)): ?>
          <div class="card-body">
            <div class="empty-state" style="padding:var(--sp-10) var(--sp-4)">
              <i class="bi bi-clock-history" style="font-size:3rem"></i>
              <h3>هنوز تراکنشی نیست</h3>
              <p>پس از شروع معامله، تاریخچه اعتبار شما اینجا نمایش داده می‌شود.</p>
            </div>
          </div>
          <?php else: ?>

          <?php
          foreach ($transactions as $tx):
            [$txIcon, $txLabel, $txColor] = tx_type_label($tx['type']);
            $isPos = (float)$tx['amount'] >= 0;
          ?>
          <div style="display:flex;align-items:center;gap:var(--sp-4);padding:var(--sp-4) var(--sp-5);border-bottom:1px solid var(--border)">
            <div style="width:40px;height:40px;border-radius:50%;background:var(--<?= $txColor ?>-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-<?= $txIcon ?>" style="color:var(--<?= $txColor ?>);font-size:1.1rem"></i>
            </div>
            <div style="flex:1">
              <div style="font-weight:600"><?= $txLabel ?></div>
              <?php if ($tx['note']): ?>
              <div class="fs-sm" style="color:var(--text-secondary)"><?= h($tx['note']) ?></div>
              <?php endif; ?>
              <div class="fs-xs" style="color:var(--text-muted)"><?= persian_datetime($tx['created_at']) ?></div>
            </div>
            <div style="text-align:left">
              <div style="font-weight:800;font-size:1rem;color:var(--<?= $isPos ? 'success' : 'danger' ?>)">
                <?= $isPos ? '+' : '' ?><?= fmt_credit((float)$tx['amount']) ?>
              </div>
              <div class="fs-xs" style="color:var(--text-muted)">مانده: <?= fmt_credit((float)$tx['balance_after'], false) ?></div>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- Pagination -->
          <?php if ($pag['pages'] > 1): ?>
          <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:var(--sp-2);flex-wrap:wrap">
            <span style="display:flex;align-items:center;font-size:.875rem;color:var(--text-muted)">
              صفحه <?= $page ?> از <?= $pag['pages'] ?>
            </span>
            <div style="display:flex;gap:var(--sp-2)">
              <?php if ($pag['has_prev']): ?>
              <a href="?page=<?= $page-1 ?>" class="btn btn-outline btn-sm"><i class="bi bi-chevron-right"></i> قبلی</a>
              <?php endif; ?>
              <?php if ($pag['has_next']): ?>
              <a href="?page=<?= $page+1 ?>" class="btn btn-outline btn-sm">نمایش بیشتر <i class="bi bi-chevron-left"></i></a>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>

      <!-- ── Deposit Sidebar ───────────────────────────────────── -->
      <div style="position:sticky;top:80px">

        <?php if (WALLET_DEMO_DEPOSIT): ?>
        <!-- Deposit Card (development / demo only) -->
        <div class="card mb-4" id="deposit-form-card">
          <div class="card-header">
            <h3 style="margin:0;font-size:1.0625rem"><i class="bi bi-plus-circle" style="color:var(--primary)"></i> افزودن اعتبار</h3>
          </div>
          <div class="card-body">
            <form method="POST">
            <?= csrf_field() ?>
              <div class="form-group">
                <label class="form-label">مبلغ (<?= CREDIT_UNIT ?>)</label>
                <input type="number" class="form-control" name="deposit_amount"
                       placeholder="مثلاً ۱۰۰" min="10" max="1000000" step="1" required>
              </div>

              <!-- Quick amounts -->
              <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-bottom:var(--sp-4)">
                <?php foreach ([50, 100, 250, 500] as $amt): ?>
                <button type="button" class="btn btn-outline btn-sm"
                        onclick="document.querySelector('[name=deposit_amount]').value=<?= $amt ?>">
                  +<?= $amt ?>
                </button>
                <?php endforeach; ?>
              </div>

              <div class="alert alert-info" style="font-size:.8125rem;margin-bottom:var(--sp-4)">
                <i class="bi bi-info-circle"></i>
                <div>
                  <strong>نسخه آزمایشی:</strong> این یک واریز شبیه‌سازی‌شده است. در نسخه نهایی به درگاه پرداخت هدایت می‌شوید.
                </div>
              </div>

              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-credit-card"></i> ادامه به پرداخت
              </button>
            </form>
          </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
          <div class="card-body">
            <div class="alert alert-info" style="margin:0">
              <i class="bi bi-info-circle"></i>
              واریز آنلاین از طریق درگاه پرداخت به‌زودی فعال می‌شود.
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- How Credits Work -->
        <div class="card">
          <div class="card-header">
            <h3 style="margin:0;font-size:1rem"><i class="bi bi-question-circle"></i> <?= CREDIT_UNIT ?> چگونه کار می‌کند؟</h3>
          </div>
          <div class="card-body" style="font-size:.875rem">
            <?php
            $howItems = [
              ['stars',        'با ثبت‌نام ' . number_format(WELCOME_BONUS, 0) . ' ' . CREDIT_UNIT . ' هدیه خوش‌آمدگویی دریافت می‌کنید'],
              ['arrow-down-circle', 'با معامله آگهی‌هایتان ' . CREDIT_UNIT . ' کسب کنید'],
              ['arrow-up-circle',   'برای افزودن ارزش به پیشنهادها ' . CREDIT_UNIT . ' خرج کنید'],
              ['percent',      '۲٪ کارمزد پلتفرم روی هر معامله موفق (خودکار کسر می‌شود)'],
              ['shield-check', 'اعتبارها هرگز منقضی نمی‌شوند'],
            ];
            foreach ($howItems as [$icon, $text]):
            ?>
            <div style="display:flex;gap:var(--sp-3);align-items:flex-start;margin-bottom:var(--sp-4)">
              <i class="bi bi-<?= $icon ?>" style="color:var(--primary);margin-top:2px;flex-shrink:0"></i>
              <span style="color:var(--text-secondary)"><?= $text ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>
    </div>

  </div>
<?php render_user_panel_close(); ?>

<script>
// Auto-open deposit form if action=deposit
<?php if ($action === 'deposit'): ?>
document.getElementById('deposit-form-card').scrollIntoView({behavior:'smooth',block:'center'});
document.querySelector('[name=deposit_amount]')?.focus();
<?php endif; ?>
</script>

<?php render_panel_scripts(); ?>
<?php render_footer(); ?>
