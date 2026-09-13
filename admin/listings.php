<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/ai_moderation.php';

$admin = require_admin();
$id    = (int)($_GET['id'] ?? 0);
$filter = clean($_GET['filter'] ?? 'pending');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $listingId = (int)($_POST['listing_id'] ?? 0);
    $action    = clean($_POST['action'] ?? '');
    $note      = clean($_POST['note'] ?? '');

    if ($action === 'approve' && $listingId) {
        admin_approve_listing($listingId, $note);
        ai_mod_mark_admin_action($listingId, 'approved', (int)$admin['id'], $note);
        admin_set_flash('آگهی تأیید و منتشر شد.');
    } elseif ($action === 'reject' && $listingId) {
        if (mb_strlen($note) < 5) {
            admin_set_flash('برای رد آگهی، دلیل را بنویسید (حداقل ۵ کاراکتر).', 'error');
        } else {
            admin_reject_listing($listingId, $note);
            ai_mod_mark_admin_action($listingId, 'rejected', (int)$admin['id'], $note);
            admin_set_flash('آگهی رد شد.');
        }
    } elseif ($action === 'delete' && $listingId) {
        admin_delete_listing($listingId);
        admin_set_flash('آگهی حذف شد.');
    } elseif ($action === 'rerun_ai' && $listingId) {
        ai_mod_review_listing($listingId);
        admin_set_flash('بررسی هوش مصنوعی مجدداً انجام شد.');
    }
    header('Location: ' . APP_URL . '/admin/listings.php' . ($listingId && $action !== 'delete' ? "?id=$listingId" : ''));
    exit;
}

[$flash, $flashType] = admin_flash();

if ($id) {
    $listing = DB::fetch(
        'SELECT l.*, u.name AS seller_name, u.email AS seller_email, c.name AS cat_name,
                r.rule_signals, r.unsafe_flags, r.reason_codes, r.ai_provider, r.human_readable_note
         FROM listings l
         JOIN users u ON u.id = l.user_id
         JOIN categories c ON c.id = l.category_id
         LEFT JOIN listing_ai_reviews r ON r.listing_id = l.id
         WHERE l.id = ?',
        [$id]
    );
    $images = $listing
        ? DB::fetchAll('SELECT * FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC', [$id])
        : [];
}

$where = match ($filter) {
    'approved' => 'l.review_status = "approved"',
    'rejected' => 'l.review_status = "rejected"',
    default    => 'l.review_status = "pending"',
};

$list = DB::fetchAll(
    "SELECT l.id, l.title, l.review_status, l.status, l.created_at, l.ai_suggestion, l.ai_confidence, l.ai_reviewed,
            u.name AS seller_name
     FROM listings l JOIN users u ON u.id = l.user_id
     WHERE {$where} AND l.status != 'deleted'
     ORDER BY l.created_at DESC LIMIT 100"
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header">
  <div>
    <h1>مدیریت آگهی‌ها</h1>
    <p class="fs-sm" style="color:var(--text-muted);margin:var(--sp-1) 0 0">تأیید یا رد آگهی‌های ثبت‌شده</p>
  </div>
  <div class="admin-actions">
    <a href="?filter=pending" class="btn btn-sm <?= $filter === 'pending' ? 'btn-primary' : 'btn-outline' ?>">در انتظار</a>
    <a href="?filter=approved" class="btn btn-sm <?= $filter === 'approved' ? 'btn-primary' : 'btn-outline' ?>">تأیید شده</a>
    <a href="?filter=rejected" class="btn btn-sm <?= $filter === 'rejected' ? 'btn-primary' : 'btn-outline' ?>">رد شده</a>
  </div>
</div>

<?php if ($id && !empty($listing)): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0"><?= h($listing['title']) ?></h3></div>
    <div class="card-body">
      <p style="line-height:1.8;white-space:pre-wrap"><?= h($listing['description']) ?></p>
      <hr style="border-color:var(--border);margin:var(--sp-4) 0">
      <div class="fs-sm" style="display:grid;gap:var(--sp-2)">
        <div><strong>دسته:</strong> <?= h($listing['cat_name']) ?></div>
        <div><strong>وضعیت کالا:</strong> <?= condition_label($listing['condition']) ?></div>
        <div><strong>ارزش:</strong> <?= $listing['estimated_value'] > 0 ? fmt_credit((float)$listing['estimated_value']) : '—' ?></div>
        <div><strong>در ازای:</strong> <?= h($listing['want_in_return']) ?></div>
        <div><strong>فروشنده:</strong> <?= h($listing['seller_name']) ?> (<?= h($listing['seller_email']) ?>)</div>
        <div><strong>شهر:</strong> <?= h($listing['city'] ?: '—') ?></div>
        <div><strong>وضعیت بررسی:</strong>
          <span class="badge badge-<?= listing_review_badge($listing['review_status']) ?>">
            <?= listing_review_label($listing['review_status']) ?>
          </span>
        </div>
        <?php if ($listing['review_note']): ?>
        <div><strong>یادداشت قبلی:</strong> <?= h($listing['review_note']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($images): ?>
      <div style="display:flex;gap:var(--sp-2);flex-wrap:wrap;margin-top:var(--sp-4)">
        <?php foreach ($images as $img): ?>
        <a href="<?= UPLOAD_URL . h($img['filename']) ?>" target="_blank">
          <img src="<?= UPLOAD_URL . h($img['filename']) ?>" alt="" style="width:100px;height:80px;object-fit:cover;border-radius:var(--radius-md);border:1px solid var(--border)">
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($listing['ai_reviewed'])): ?>
  <div class="card" style="border-left:4px solid <?= $listing['ai_suggestion'] === 'approve' ? 'var(--success)' : ($listing['ai_suggestion'] === 'reject' ? 'var(--danger)' : 'var(--warning)') ?>">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
      <h3 style="margin:0;font-size:1rem"><i class="bi bi-robot"></i> پیشنهاد هوش مصنوعی سواَپین</h3>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="rerun_ai">
        <button type="submit" class="btn btn-sm btn-outline"><i class="bi bi-arrow-repeat"></i> اجرای مجدد</button>
            <button type="button" class="btn btn-sm btn-info view-ai-details"
                    data-listing='<?= json_encode([
                        'title' => $listing['title'],
                        'ai_decision' => $listing['ai_suggestion'],
                        'ai_confidence' => $listing['ai_confidence'],
                        'ai_provider' => $listing['ai_provider'],
                        'human_readable_note' => $listing['human_readable_note'],
                        'rule_signals' => json_decode($listing['rule_signals'], true),
                        'unsafe_flags' => json_decode($listing['unsafe_flags'], true),
                        'reason_codes' => json_decode($listing['reason_codes'], true),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_APOS) ?>'>
                جزئیات AI
            </button>
      </form>
    </div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:var(--sp-3);margin-bottom:var(--sp-3)">
        <?php if ($listing['ai_suggestion'] === 'approve'): ?>
          <span class="badge badge-success" style="font-size:0.95rem;padding:8px 14px"><i class="bi bi-check-lg"></i> پیشنهاد: تأیید خودکار</span>
        <?php elseif ($listing['ai_suggestion'] === 'reject'): ?>
          <span class="badge badge-danger" style="font-size:0.95rem;padding:8px 14px"><i class="bi bi-x-lg"></i> پیشنهاد: رد خودکار</span>
        <?php else: ?>
          <span class="badge badge-warning" style="font-size:0.95rem;padding:8px 14px"><i class="bi bi-person-check"></i> پیشنهاد: بررسی دستی</span>
        <?php endif; ?>
        <div style="flex:1">
          <div style="height:8px;background:var(--surface-2);border-radius:999px;overflow:hidden">
            <div style="height:100%;width:<?= (int)$listing['ai_confidence'] ?>%;background:linear-gradient(90deg,var(--primary),#f5c06a)"></div>
          </div>
          <div class="fs-xs" style="color:var(--text-muted);margin-top:4px">اطمینان: <?= (int)$listing['ai_confidence'] ?>٪</div>
        </div>
      </div>
      <?php if (!empty($listing['ai_note'])): ?>
        <p class="fs-sm" style="margin:0;padding:var(--sp-3);background:var(--surface-1);border-radius:var(--radius-md);line-height:1.8">
          <strong style="color:var(--text-muted)">دلیل AI:</strong> <?= h($listing['ai_note']) ?>
        </p>
      <?php endif; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem"><i class="bi bi-robot"></i> بررسی هوش مصنوعی</h3></div>
    <div class="card-body">
      <p class="fs-sm" style="color:var(--text-muted);margin:0 0 var(--sp-3)">هنوز بررسی هوش مصنوعی برای این آگهی انجام نشده است.</p>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="rerun_ai">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-play-fill"></i> اجرای بررسی AI</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">اقدام مدیر</h3></div>
    <div class="card-body">
      <?php if ($listing['review_status'] === 'pending'): ?>
      <form method="POST" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="approve">
        <div class="form-group">
          <label class="form-label">یادداشت (اختیاری)</label>
          <textarea class="form-control" name="note" rows="2"></textarea>
        </div>
        <button type="submit" class="btn btn-success w-100"><i class="bi bi-check-lg"></i> تأیید و انتشار</button>
      </form>
      <form method="POST" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="reject">
        <div class="form-group">
          <label class="form-label">دلیل رد <span class="required">*</span></label>
          <textarea class="form-control" name="note" rows="3" required placeholder="مثلاً: توضیحات ناقص یا نامعتبر"><?= !empty($listing['ai_suggestion']) && $listing['ai_suggestion'] === 'reject' ? h($listing['ai_note']) : '' ?></textarea>
        </div>
        <button type="submit" class="btn btn-danger w-100" onclick="return confirm('آگهی رد شود؟')"><i class="bi bi-x-lg"></i> رد آگهی</button>
      </form>
      <?php else: ?>
      <p class="fs-sm" style="color:var(--text-muted)">این آگهی قبلاً بررسی شده است.</p>
      <?php if ($listing['review_status'] === 'rejected'): ?>
      <form method="POST" class="mt-4">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="approve">
        <button type="submit" class="btn btn-outline w-100">تأیید مجدد</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('آگهی به صورت دائمی حذف شود؟ این عملیات قابل بازگشت نیست.');">
        <?= csrf_field() ?>
        <input type="hidden" name="listing_id" value="<?= $id ?>">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn btn-ghost w-100 mt-3" style="color:var(--danger);border:1px solid var(--danger)">
          <i class="bi bi-trash-fill"></i> حذف دائمی آگهی
        </button>
      </form>
      <a href="<?= APP_URL ?>/listings/view?id=<?= $id ?>" class="btn btn-ghost w-100 mt-3" target="_blank">مشاهده در سایت</a>
    </div>
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

<?php elseif ($id): ?>
<div class="alert alert-danger">آگهی یافت نشد.</div>
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
            var listingData = JSON.parse(this.getAttribute('data-listing'));
            
            document.getElementById('modalListingTitle').textContent = listingData.title;
            document.getElementById('modalAiDecision').textContent = listingData.ai_decision;
            document.getElementById('modalAiConfidence').textContent = listingData.ai_confidence;
            document.getElementById('modalAiProvider').textContent = listingData.ai_provider || 'نامشخص';
            document.getElementById('modalHumanReadableNote').textContent = listingData.human_readable_note || '—';

            document.getElementById('modalRuleSignals').textContent = JSON.stringify(listingData.rule_signals, null, 2);
            document.getElementById('modalUnsafeFlags').textContent = JSON.stringify(listingData.unsafe_flags, null, 2);
            document.getElementById('modalReasonCodes').textContent = JSON.stringify(listingData.reason_codes, null, 2);

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
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>#</th>
        <th>عنوان</th>
        <th>فروشنده</th>
        <th>وضعیت</th>
        <th>پیشنهاد AI</th>
        <th>تاریخ</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($list)): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:var(--sp-6)">موردی یافت نشد</td></tr>
      <?php else: foreach ($list as $l): ?>
      <tr>
        <td><?= $l['id'] ?></td>
        <td><?= h(mb_strimwidth($l['title'], 0, 50, '…')) ?></td>
        <td><?= h($l['seller_name']) ?></td>
        <td><span class="badge badge-<?= listing_review_badge($l['review_status']) ?>"><?= listing_review_label($l['review_status']) ?></span></td>
        <td>
          <?php if (empty($l['ai_reviewed'])): ?>
            <span class="fs-xs" style="color:var(--text-muted)">—</span>
          <?php else: ?>
            <?php
              $aiBadge = $l['ai_suggestion'] === 'approve' ? 'success' : ($l['ai_suggestion'] === 'reject' ? 'danger' : 'warning');
              $aiLabel = $l['ai_suggestion'] === 'approve' ? 'تأیید' : ($l['ai_suggestion'] === 'reject' ? 'رد' : 'بررسی دستی');
              $aiIcon  = $l['ai_suggestion'] === 'approve' ? 'bi-check-lg' : ($l['ai_suggestion'] === 'reject' ? 'bi-x-lg' : 'bi-person-check');
            ?>
            <span class="badge badge-<?= $aiBadge ?>" title="اطمینان: <?= (int)$l['ai_confidence'] ?>٪"><i class="bi <?= $aiIcon ?>"></i> <?= $aiLabel ?> · <?= (int)$l['ai_confidence'] ?>٪</span>
          <?php endif; ?>
        </td>
        <td class="fs-xs"><?= persian_date($l['created_at']) ?></td>
        <td style="display:flex;gap:6px;justify-content:flex-end">
          <a href="?id=<?= $l['id'] ?>" class="btn btn-sm btn-outline">بررسی</a>
          <form method="POST" onsubmit="return confirm('آگهی #<?= $l['id'] ?> حذف شود؟');" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--danger);border:1px solid var(--danger);padding:4px 8px" title="حذف">
              <i class="bi bi-trash-fill"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
render_admin_head('آگهی‌ها');
render_admin_shell($admin, 'listings', $content);
render_admin_footer();
