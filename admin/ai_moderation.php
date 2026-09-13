<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/ai_moderation.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $enabled              = (int)!empty($_POST['enabled']);
        $shadowMode           = (int)!empty($_POST['shadow_mode']);
        $approveThreshold     = max(50, min(100, (int)($_POST['auto_approve_threshold'] ?? 93)));
        $rejectThreshold      = max(50, min(100, (int)($_POST['auto_reject_threshold'] ?? 96)));
        $safeSlugs            = array_values(array_filter(array_map('trim', explode(',', $_POST['safe_category_slugs'] ?? ''))));
        $neverSlugs           = array_values(array_filter(array_map('trim', explode(',', $_POST['never_approve_slugs'] ?? ''))));

        $stmt = DB::query(
            "UPDATE `ai_moderation_settings` SET
                `enabled` = ?, `shadow_mode` = ?,
                `auto_approve_threshold` = ?, `auto_reject_threshold` = ?,
                `safe_category_slugs` = ?, `never_approve_slugs` = ?,
                `updated_by` = ?, `updated_at` = NOW()
             WHERE `id` = 1 LIMIT 1",
            [
                $enabled, $shadowMode,
                $approveThreshold, $rejectThreshold,
                json_encode($safeSlugs,  JSON_UNESCAPED_UNICODE),
                json_encode($neverSlugs, JSON_UNESCAPED_UNICODE),
                (int)$admin['id'],
            ]
        );

        if ($stmt->rowCount() === 0) {
            DB::query(
                "INSERT INTO `ai_moderation_settings`
                 (`id`,`enabled`,`shadow_mode`,`auto_approve_threshold`,`auto_reject_threshold`,`safe_category_slugs`,`never_approve_slugs`,`updated_by`)
                 VALUES (1,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    `enabled` = VALUES(`enabled`),
                    `shadow_mode` = VALUES(`shadow_mode`),
                    `auto_approve_threshold` = VALUES(`auto_approve_threshold`),
                    `auto_reject_threshold` = VALUES(`auto_reject_threshold`),
                    `safe_category_slugs` = VALUES(`safe_category_slugs`),
                    `never_approve_slugs` = VALUES(`never_approve_slugs`),
                    `updated_by` = VALUES(`updated_by`)",
                [
                    $enabled, $shadowMode,
                    $approveThreshold, $rejectThreshold,
                    json_encode($safeSlugs,  JSON_UNESCAPED_UNICODE),
                    json_encode($neverSlugs, JSON_UNESCAPED_UNICODE),
                    (int)$admin['id'],
                ]
            );
        }

        admin_set_flash('تنظیمات ربات نظارت با موفقیت ذخیره شد.');
        header('Location: ' . APP_URL . '/admin/ai_moderation.php');
        exit;
    }

    if ($action === 'backfill') {
        $limit = min(100, max(1, (int)($_POST['limit'] ?? 20)));
        $pending = DB::fetchAll(
            "SELECT l.id FROM listings l
             LEFT JOIN listing_ai_reviews r ON r.listing_id = l.id
             WHERE r.id IS NULL AND l.review_status IN ('pending','approved','rejected')
             ORDER BY l.id DESC LIMIT ?",
            [$limit]
        );
        $done = 0;
        foreach ($pending as $row) {
            try {
                ai_mod_review_listing((int)$row['id']);
                $done++;
            } catch (Throwable) {}
        }
        admin_set_flash("بررسی هوش مصنوعی برای {$done} آگهی انجام شد.");
        header('Location: ' . APP_URL . '/admin/ai_moderation.php');
        exit;
    }
}

[$flash, $flashType] = admin_flash();
$settings = ai_mod_settings();
$stats    = ai_mod_get_stats();

$recentReviews = DB::fetchAll(
    "SELECT r.*, l.title, l.review_status AS actual_status, u.name AS seller_name
     FROM listing_ai_reviews r
     JOIN listings l ON l.id = r.listing_id
     JOIN users u    ON u.id = r.user_id
     ORDER BY r.created_at DESC LIMIT 30"
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1><i class="bi bi-robot"></i> ربات نظارت هوشمند سواَپین</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">
      بررسی خودکار آگهی‌ها با ترکیب قوانین قاعده‌محور و هوش مصنوعی (Groq / OpenRouter)
    </p>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:var(--sp-3);margin-bottom:var(--sp-5)">
  <div class="card" style="margin:0">
    <div class="card-body" style="padding:var(--sp-4)">
      <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)">کل بررسی‌شده‌ها</div>
      <div style="font-size:2rem;font-weight:700"><?= fmt_num($stats['total_reviewed']) ?></div>
    </div>
  </div>
  <div class="card" style="margin:0">
    <div class="card-body" style="padding:var(--sp-4)">
      <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)">پیشنهاد تأیید AI</div>
      <div style="font-size:2rem;font-weight:700;color:var(--success)"><?= fmt_num($stats['decisions']['approve'] ?? 0) ?></div>
    </div>
  </div>
  <div class="card" style="margin:0">
    <div class="card-body" style="padding:var(--sp-4)">
      <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)">پیشنهاد رد AI</div>
      <div style="font-size:2rem;font-weight:700;color:var(--danger)"><?= fmt_num($stats['decisions']['reject'] ?? 0) ?></div>
    </div>
  </div>
  <div class="card" style="margin:0">
    <div class="card-body" style="padding:var(--sp-4)">
      <div class="fs-xs" style="color:var(--text-muted);margin-bottom:var(--sp-1)">درصد تطابق با ادمین</div>
      <div style="font-size:2rem;font-weight:700">
        <?php if ($stats['accuracy_pct'] !== null): ?>
          <?= fmt_num($stats['accuracy_pct']) ?>٪
        <?php else: ?>
          <span style="color:var(--text-muted)">—</span>
        <?php endif; ?>
      </div>
      <div class="fs-xs" style="color:var(--text-muted)"><?= fmt_num($stats['admin_compared']) ?> مورد مقایسه‌شده</div>
    </div>
  </div>
</div>

<div class="admin-detail-grid" style="grid-template-columns:1.3fr 1fr">
  <div class="card">
    <div class="card-header"><h3 style="margin:0">تنظیمات ربات</h3></div>
    <div class="card-body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3);margin-bottom:var(--sp-4)">
          <label class="admin-toggle">
            <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
            <span>
              <strong>فعال‌سازی ربات</strong>
              <span class="fs-xs" style="color:var(--text-muted)">غیرفعال = بدون هیچ بررسی خودکار</span>
            </span>
          </label>
          <label class="admin-toggle">
            <input type="checkbox" name="shadow_mode" value="1" <?= $settings['shadow_mode'] ? 'checked' : '' ?>>
            <span>
              <strong>حالت سایه‌زن (Shadow Mode)</strong>
              <span class="fs-xs" style="color:var(--text-muted)">فعال = فقط پیشنهاد ذخیره می‌شود — هیچ تأیید/رد خودکار</span>
            </span>
          </label>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4);margin-bottom:var(--sp-4)">
          <div class="form-group">
            <label class="form-label">آستانه تأیید خودکار (٪)</label>
            <input type="number" name="auto_approve_threshold" min="50" max="100" value="<?= (int)$settings['auto_approve_threshold'] ?>" class="form-control">
            <div class="fs-xs" style="color:var(--text-muted);margin-top:4px">فقط برای دسته‌های امن و با اطمینان بالاتر از این مقدار</div>
          </div>
          <div class="form-group">
            <label class="form-label">آستانه رد خودکار (٪)</label>
            <input type="number" name="auto_reject_threshold" min="50" max="100" value="<?= (int)$settings['auto_reject_threshold'] ?>" class="form-control">
            <div class="fs-xs" style="color:var(--text-muted);margin-top:4px">رد قطعی فقط با اطمینان بسیار بالا</div>
          </div>
        </div>

        <div class="form-group mb-4">
          <label class="form-label">دسته‌های امن (Auto-Approve مجاز)</label>
          <input type="text" name="safe_category_slugs" value="<?= h(implode(',', $settings['safe_category_slugs'])) ?>" class="form-control" placeholder="book,game-console,toy,household-small">
          <div class="fs-xs" style="color:var(--text-muted);margin-top:4px">اسلاگ دسته‌ها را با کاما جدا کنید</div>
        </div>

        <div class="form-group mb-5">
          <label class="form-label">دسته‌های هرگز Auto-Approve نشوند</label>
          <input type="text" name="never_approve_slugs" value="<?= h(implode(',', $settings['never_approve_slugs'])) ?>" class="form-control" placeholder="real-estate,car,motorcycle,service,job">
          <div class="fs-xs" style="color:var(--text-muted);margin-top:4px">این دسته‌ها همیشه به ادمین ارجاع داده می‌شوند</div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> ذخیره تنظیمات</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">ابزارها</h3></div>
    <div class="card-body">
      <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-3)">
        برای بررسی مجدد دسته‌ای آگهی‌های قدیمی که قبل از فعال‌سازی ربات ثبت شده‌اند، از ابزار زیر استفاده کنید:
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="backfill">
        <div class="form-group mb-3">
          <label class="form-label">تعداد آگهی برای بررسی مجدد</label>
          <input type="number" name="limit" min="1" max="100" value="20" class="form-control">
        </div>
        <button type="submit" class="btn btn-outline w-100"><i class="bi bi-arrow-repeat"></i> اجرای بررسی دسته‌ای</button>
      </form>

      <hr style="border-color:var(--border);margin:var(--sp-5) 0">

      <div class="fs-sm" style="color:var(--text-muted);margin-bottom:var(--sp-3)">
        <strong>وضعیت فعلی:</strong>
      </div>
      <ul style="list-style:none;padding:0;margin:0;display:grid;gap:var(--sp-2)" class="fs-sm">
        <li>
          <span class="badge <?= $settings['enabled'] ? 'badge-success' : 'badge-danger' ?>"><?= $settings['enabled'] ? 'روشن' : 'خاموش' ?></span>
          <span style="margin-right:8px">ربات نظارت</span>
        </li>
        <li>
          <span class="badge <?= $settings['shadow_mode'] ? 'badge-warning' : 'badge-success' ?>"><?= $settings['shadow_mode'] ? 'Shadow (فعال)' : 'زنده (خودکار)' ?></span>
          <span style="margin-right:8px">حالت عملیاتی</span>
        </li>
        <li>
          <span class="badge badge-primary"><?= fmt_num(count($settings['safe_category_slugs'])) ?> دسته</span>
          <span style="margin-right:8px">آماده Auto-Approve</span>
        </li>
      </ul>
    </div>
  </div>
</div>

<div class="card mt-5">
  <div class="card-header">
    <h3 style="margin:0;font-size:1rem">آخرین بررسی‌های انجام‌شده توسط AI</h3>
  </div>
  <div class="card-body" style="padding:0">
    <?php if (empty($recentReviews)): ?>
      <div style="padding:var(--sp-6);text-align:center;color:var(--text-muted)">هنوز بررسی‌ای انجام نشده است.</div>
    <?php else: ?>
      <table class="admin-table">
        <thead>
          <tr>
            <th>آگهی</th>
            <th>پیشنهاد AI</th>
            <th>اطمینان</th>
            <th>وضعیت واقعی</th>
            <th>تاریخ</th>
            <th>جزئیات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentReviews as $r):
            $aiBadge = $r['ai_decision'] === 'approve' ? 'success' : ($r['ai_decision'] === 'reject' ? 'danger' : 'warning');
            $aiLabel = $r['ai_decision'] === 'approve' ? 'تأیید' : ($r['ai_decision'] === 'reject' ? 'رد' : 'ارجاع');
            $matches = $r['admin_decision'] === null ? null :
              (($r['ai_decision'] === 'approve' && $r['admin_decision'] === 'approved') ||
               ($r['ai_decision'] === 'reject'  && $r['admin_decision'] === 'rejected'));
          ?>
          <tr>
            <td>
              <div style="font-weight:500"><?= h(mb_strimwidth($r['title'], 0, 45, '…')) ?></div>
              <div class="fs-xs" style="color:var(--text-muted)">فروشنده: <?= h($r['seller_name']) ?></div>
            </td>
            <td>
              <span class="badge badge-<?= $aiBadge ?>"><?= $aiLabel ?></span>
              <?php if ($r['review_mode'] === 'shadow'): ?>
                <span class="fs-xs" style="color:var(--warning);margin-right:4px">(Shadow)</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="width:100px;height:6px;background:var(--surface-2);border-radius:999px;overflow:hidden;display:inline-block">
                <div style="height:100%;width:<?= (int)$r['ai_confidence'] ?>%;background:linear-gradient(90deg,var(--primary),#f5c06a)"></div>
              </div>
              <span class="fs-xs" style="margin-right:8px"><?= fmt_num((int)$r['ai_confidence']) ?>٪</span>
            </td>
            <td>
              <span class="badge badge-<?= listing_review_badge($r['actual_status']) ?>"><?= listing_review_label($r['actual_status']) ?></span>
              <?php if ($matches !== null): ?>
                <span class="fs-xs" style="margin-right:6px;color:<?= $matches ? 'var(--success)' : 'var(--danger)' ?>">
                  <i class="bi <?= $matches ? 'bi-check2-all' : 'bi-x-circle' ?>"></i>
                  <?= $matches ? 'تطابق' : 'عدم تطابق' ?>
                </span>
              <?php endif; ?>
            </td>
            <td class="fs-xs"><?= persian_date($r['created_at']) ?></td>
            <td style="text-align:left">
              <a href="<?= APP_URL ?>/admin/listings.php?id=<?= (int)$r['listing_id'] ?>" class="btn btn-sm btn-outline">مشاهده</a>
              <button class="btn btn-sm btn-info view-ai-details" data-review='<?= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS) ?>'>جزئیات AI</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- Modal for AI Details -->
<div id="aiDetailsModal" class="modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">جزئیات بررسی AI برای آگهی: <span id="modalListingTitle"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p><strong>تصمیم AI:</strong> <span id="modalAiDecision"></span> (<span id="modalAiConfidence"></span>٪)</p>
        <p><strong>ارائه‌دهنده AI:</strong> <span id="modalAiProvider"></span></p>
        <p><strong>یادداشت قابل فهم انسانی:</strong> <span id="modalHumanReadableNote"></span></p>
        <hr>
        <h6>سیگنال‌های قوانین:</h6>
        <pre id="modalRuleSignals" style="white-space: pre-wrap;"></pre>
        <h6>پرچم‌های ناامن:</h6>
        <pre id="modalUnsafeFlags" style="white-space: pre-wrap;"></pre>
        <h6>کدهای دلیل:</h6>
        <pre id="modalReasonCodes" style="white-space: pre-wrap;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">بستن</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var aiDetailsModal = document.getElementById('aiDetailsModal');

    // Function to open the modal
    function openModal() {
        aiDetailsModal.classList.add('show');
        aiDetailsModal.style.display = 'block';
        document.body.classList.add('modal-open');
    }

    // Function to close the modal
    function closeModal() {
        aiDetailsModal.classList.remove('show');
        aiDetailsModal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    // Event listeners for opening the modal
    document.querySelectorAll('.view-ai-details').forEach(function(button) {
        button.addEventListener('click', function() {
            var reviewData = JSON.parse(this.getAttribute('data-review'));
            
            document.getElementById('modalListingTitle').textContent = reviewData.title;
            document.getElementById('modalAiDecision').textContent = reviewData.ai_decision;
            document.getElementById('modalAiConfidence').textContent = reviewData.ai_confidence;
            document.getElementById('modalAiProvider').textContent = reviewData.ai_provider || 'نامشخص';
            document.getElementById('modalHumanReadableNote').textContent = reviewData.human_readable_note || '—';

            document.getElementById('modalRuleSignals').textContent = JSON.stringify(reviewData.rule_signals, null, 2);
            document.getElementById('modalUnsafeFlags').textContent = JSON.stringify(reviewData.unsafe_flags, null, 2);
            document.getElementById('modalReasonCodes').textContent = JSON.stringify(reviewData.reason_codes, null, 2);

            openModal();
        });
    });

    // Event listeners for closing the modal
    aiDetailsModal.querySelector('.close').addEventListener('click', closeModal);
    aiDetailsModal.querySelector('.btn-secondary').addEventListener('click', closeModal);
    aiDetailsModal.addEventListener('click', function(e) {
        if (e.target === aiDetailsModal) {
            closeModal();
        }
    });
});
</script>

<?php
$content = ob_get_clean();
render_admin_head('ربات نظارت AI');
render_admin_shell($admin, 'ai_moderation', $content);
render_admin_footer();
