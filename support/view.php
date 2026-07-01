<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_auth();
$id   = (int)($_GET['id'] ?? 0);
$error = '';

$ticket = DB::fetch(
    'SELECT t.*, u.name AS user_name FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.user_id = ?',
    [$id, $user['id']]
);

if (!$ticket) {
    http_response_code(404);
    render_head('تیکت یافت نشد');
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container"><div class="empty-state"><i class="bi bi-exclamation-circle"></i><h1>تیکت یافت نشد</h1><a href="' . APP_URL . '/support/index.php" class="btn btn-primary">بازگشت</a></div></div></main>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ticket['status'] !== 'closed') {
    csrf_verify_or_fail();
    $result = add_ticket_message($id, 'user', (int)$user['id'], $_POST['body'] ?? '');
    if (isset($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: ' . APP_URL . '/support/view.php?id=' . $id);
        exit;
    }
}

$messages = DB::fetchAll(
    'SELECT m.*, u.name AS sender_name FROM support_messages m
     LEFT JOIN users u ON u.id = m.sender_id
     WHERE m.ticket_id = ? ORDER BY m.created_at ASC',
    [$id]
);

$catLabels    = support_category_labels();
$statusLabels = support_status_labels();

render_head('تیکت #' . $id, h($ticket['subject']));
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-sm">

    <nav style="font-size:.875rem;margin-bottom:var(--sp-4)">
      <a href="<?= APP_URL ?>/support/index.php"><i class="bi bi-arrow-right"></i> بازگشت به پشتیبانی</a>
    </nav>

    <?php if (isset($_GET['created'])): ?>
    <div class="alert alert-success mb-5"><i class="bi bi-check-circle"></i> تیکت شما ثبت شد. به‌زودی پاسخ می‌دهیم.</div>
    <?php endif; ?>

    <div class="card mb-5">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-3)">
        <h1 style="margin:0;font-size:1.25rem">#<?= $id ?> — <?= h($ticket['subject']) ?></h1>
        <span class="badge badge-<?= $ticket['status'] === 'open' ? 'warning' : ($ticket['status'] === 'answered' ? 'success' : 'info') ?>">
          <?= h($statusLabels[$ticket['status']] ?? $ticket['status']) ?>
        </span>
      </div>
      <div class="card-body fs-sm" style="display:flex;gap:var(--sp-4);flex-wrap:wrap;color:var(--text-muted)">
        <span><i class="bi bi-folder"></i> <?= h($catLabels[$ticket['category']] ?? $ticket['category']) ?></span>
        <span><i class="bi bi-clock"></i> <?= timeago($ticket['created_at']) ?></span>
      </div>
    </div>

    <div class="support-thread mb-6">
      <?php foreach ($messages as $m): ?>
      <div class="support-msg support-msg--<?= $m['sender_type'] ?>">
        <div class="support-msg__head">
          <strong><?= $m['sender_type'] === 'admin' ? 'پشتیبانی ' . APP_NAME : h($m['sender_name']) ?></strong>
          <span class="fs-xs" style="color:var(--text-muted)"><?= timeago($m['created_at']) ?></span>
        </div>
        <div class="support-msg__body"><?= nl2br(h($m['body'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($ticket['status'] !== 'closed'): ?>
    <div class="card">
      <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger mb-4"><?= h($error) ?></div><?php endif; ?>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="form-group">
            <label class="form-label" for="body">پاسخ شما</label>
            <textarea id="body" name="body" rows="4" class="form-control" placeholder="پیام خود را بنویسید…" required></textarea>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> ارسال</button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info"><i class="bi bi-lock"></i> این تیکت بسته شده است. برای موضوع جدید، <a href="<?= APP_URL ?>/support/index.php">تیکت جدید</a> ثبت کنید.</div>
    <?php endif; ?>

  </div>
</main>

<?php render_footer(); ?>
