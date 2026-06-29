<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$tab   = in_array($_GET['tab'] ?? '', ['tickets', 'errors']) ? $_GET['tab'] : 'tickets';
$id    = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean($_POST['action'] ?? '');

    if ($action === 'reply_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $result   = add_ticket_message($ticketId, 'admin', (int)$admin['id'], $_POST['body'] ?? '');
        if (isset($result['error'])) {
            admin_set_flash($result['error'], 'error');
        } else {
            admin_set_flash('پاسخ ارسال شد.');
        }
        header('Location: ' . APP_URL . '/admin/tickets.php?tab=tickets&id=' . $ticketId);
        exit;
    }

    if ($action === 'close_ticket') {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        admin_close_ticket($ticketId);
        admin_set_flash('تیکت بسته شد.');
        header('Location: ' . APP_URL . '/admin/tickets.php?tab=tickets&id=' . $ticketId);
        exit;
    }

    if ($action === 'resolve_error') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $status   = clean($_POST['status'] ?? '');
        $note     = clean($_POST['note'] ?? '');
        if (admin_resolve_error_report($reportId, $status, $note)) {
            admin_set_flash('گزارش خطا به‌روزرسانی شد.');
        } else {
            admin_set_flash('عملیات ناموفق.', 'error');
        }
        header('Location: ' . APP_URL . '/admin/tickets.php?tab=errors&id=' . $reportId);
        exit;
    }
}

[$flash, $flashType] = admin_flash();

$catLabels     = support_category_labels();
$statusLabels  = support_status_labels();
$errorLabels   = error_report_status_labels();

$ticketDetail = null;
$ticketMsgs   = [];
$errorDetail  = null;

if ($tab === 'tickets' && $id) {
    $ticketDetail = DB::fetch(
        'SELECT t.*, u.name AS user_name, u.email AS user_email FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?',
        [$id]
    );
    if ($ticketDetail) {
        $ticketMsgs = DB::fetchAll(
            'SELECT m.*, u.name AS sender_name FROM support_messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.ticket_id = ? ORDER BY m.created_at ASC',
            [$id]
        );
    }
}

if ($tab === 'errors' && $id) {
    $errorDetail = DB::fetch(
        'SELECT e.*, u.name AS user_name, u.email AS user_email FROM error_reports e LEFT JOIN users u ON u.id = e.user_id WHERE e.id = ?',
        [$id]
    );
}

$tickets = DB::fetchAll(
    'SELECT t.*, u.name AS user_name FROM support_tickets t JOIN users u ON u.id = t.user_id
     ORDER BY FIELD(t.status,"open","answered","closed"), t.updated_at DESC LIMIT 100'
);

$errors = DB::fetchAll(
    'SELECT e.*, u.name AS user_name FROM error_reports e LEFT JOIN users u ON u.id = e.user_id
     ORDER BY FIELD(e.status,"new","reviewing","resolved","dismissed"), e.created_at DESC LIMIT 100'
);

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header"><h1>پشتیبانی و گزارش خطا</h1></div>

<div class="admin-tabs mb-5">
  <a href="?tab=tickets" class="admin-tab<?= $tab === 'tickets' ? ' admin-tab--active' : '' ?>">
    تیکت‌ها <?php $open = support_open_ticket_count(); if ($open): ?><span class="admin-nav__badge"><?= $open ?></span><?php endif; ?>
  </a>
  <a href="?tab=errors" class="admin-tab<?= $tab === 'errors' ? ' admin-tab--active' : '' ?>">
    گزارش خطا <?php $newErr = support_new_error_count(); if ($newErr): ?><span class="admin-nav__badge"><?= $newErr ?></span><?php endif; ?>
  </a>
</div>

<?php if ($tab === 'tickets'): ?>

<?php if ($ticketDetail): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0">تیکت #<?= $ticketDetail['id'] ?> — <?= h($ticketDetail['subject']) ?></h3></div>
    <div class="card-body fs-sm" style="display:grid;gap:var(--sp-2)">
      <div><strong>کاربر:</strong> <?= h($ticketDetail['user_name']) ?> (<?= h($ticketDetail['user_email']) ?>)</div>
      <div><strong>دسته:</strong> <?= h($catLabels[$ticketDetail['category']] ?? $ticketDetail['category']) ?></div>
      <div><strong>وضعیت:</strong> <?= h($statusLabels[$ticketDetail['status']] ?? $ticketDetail['status']) ?></div>
      <div><strong>ثبت:</strong> <?= timeago($ticketDetail['created_at']) ?></div>
    </div>
    <div class="card-body support-thread" style="border-top:1px solid var(--border)">
      <?php foreach ($ticketMsgs as $m): ?>
      <div class="support-msg support-msg--<?= $m['sender_type'] ?>">
        <div class="support-msg__head">
          <strong><?= $m['sender_type'] === 'admin' ? 'پشتیبانی' : h($m['sender_name']) ?></strong>
          <span class="fs-xs"><?= timeago($m['created_at']) ?></span>
        </div>
        <div class="support-msg__body"><?= nl2br(h($m['body'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">پاسخ / مدیریت</h3></div>
    <div class="card-body">
      <?php if ($ticketDetail['status'] !== 'closed'): ?>
      <form method="POST" class="mb-4">
        <input type="hidden" name="action" value="reply_ticket">
        <input type="hidden" name="ticket_id" value="<?= $id ?>">
        <div class="form-group">
          <textarea class="form-control" name="body" rows="4" placeholder="پاسخ به کاربر…" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> ارسال پاسخ</button>
      </form>
      <form method="POST" onsubmit="return confirm('تیکت بسته شود؟')">
        <input type="hidden" name="action" value="close_ticket">
        <input type="hidden" name="ticket_id" value="<?= $id ?>">
        <button type="submit" class="btn btn-outline w-100"><i class="bi bi-lock"></i> بستن تیکت</button>
      </form>
      <?php else: ?>
      <p class="fs-sm" style="color:var(--text-muted)">این تیکت بسته شده است.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead><tr><th>#</th><th>کاربر</th><th>موضوع</th><th>دسته</th><th>وضعیت</th><th>به‌روزرسانی</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($tickets as $t): ?>
      <tr>
        <td><?= $t['id'] ?></td>
        <td><?= h($t['user_name']) ?></td>
        <td><?= h(mb_strimwidth($t['subject'], 0, 35, '…')) ?></td>
        <td><?= h($catLabels[$t['category']] ?? $t['category']) ?></td>
        <td><span class="badge badge-<?= $t['status'] === 'open' ? 'warning' : ($t['status'] === 'answered' ? 'success' : 'info') ?>"><?= h($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
        <td class="fs-xs"><?= timeago($t['updated_at']) ?></td>
        <td><a href="?tab=tickets&id=<?= $t['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: ?>

<?php if ($errorDetail): ?>
<div class="admin-detail-grid mb-6">
  <div class="card">
    <div class="card-header"><h3 style="margin:0">گزارش خطا #<?= $errorDetail['id'] ?></h3></div>
    <div class="card-body fs-sm" style="display:grid;gap:var(--sp-2)">
      <?php if ($errorDetail['user_name']): ?>
      <div><strong>کاربر:</strong> <?= h($errorDetail['user_name']) ?> (<?= h($errorDetail['user_email'] ?? '') ?>)</div>
      <?php else: ?>
      <div><strong>کاربر:</strong> مهمان</div>
      <?php endif; ?>
      <div><strong>وضعیت:</strong> <?= h($errorLabels[$errorDetail['status']] ?? $errorDetail['status']) ?></div>
      <div><strong>زمان:</strong> <?= timeago($errorDetail['created_at']) ?></div>
      <?php if ($errorDetail['page_url']): ?>
      <div><strong>صفحه:</strong> <a href="<?= h($errorDetail['page_url']) ?>" target="_blank" dir="ltr"><?= h(mb_strimwidth($errorDetail['page_url'], 0, 60, '…')) ?></a></div>
      <?php endif; ?>
      <?php if ($errorDetail['user_agent']): ?>
      <div><strong>مرورگر:</strong> <span dir="ltr" class="fs-xs"><?= h(mb_strimwidth($errorDetail['user_agent'], 0, 80, '…')) ?></span></div>
      <?php endif; ?>
      <div style="margin-top:var(--sp-2)"><strong>شرح خطا:</strong><br><?= nl2br(h($errorDetail['message'])) ?></div>
      <?php if ($errorDetail['steps']): ?>
      <div><strong>مراحل تکرار:</strong><br><?= nl2br(h($errorDetail['steps'])) ?></div>
      <?php endif; ?>
      <?php if ($errorDetail['admin_note']): ?>
      <div><strong>یادداشت:</strong> <?= h($errorDetail['admin_note']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 style="margin:0;font-size:1rem">مدیریت</h3></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="resolve_error">
        <input type="hidden" name="report_id" value="<?= $id ?>">
        <div class="form-group">
          <label class="form-label">وضعیت</label>
          <select name="status" class="form-control">
            <?php foreach ($errorLabels as $k => $v): ?>
            <option value="<?= $k ?>" <?= $errorDetail['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">یادداشت مدیر</label>
          <textarea class="form-control" name="note" rows="3"><?= h($errorDetail['admin_note'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary w-100">ذخیره</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <table class="admin-table">
    <thead><tr><th>#</th><th>کاربر</th><th>خلاصه</th><th>وضعیت</th><th>زمان</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($errors as $e): ?>
      <tr>
        <td><?= $e['id'] ?></td>
        <td><?= h($e['user_name'] ?? 'مهمان') ?></td>
        <td><?= h(mb_strimwidth($e['message'], 0, 40, '…')) ?></td>
        <td><span class="badge badge-<?= $e['status'] === 'new' ? 'warning' : 'info' ?>"><?= h($errorLabels[$e['status']] ?? $e['status']) ?></span></td>
        <td class="fs-xs"><?= timeago($e['created_at']) ?></td>
        <td><a href="?tab=errors&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
render_admin_head('پشتیبانی');
render_admin_shell($admin, 'tickets', $content);
render_admin_footer();
