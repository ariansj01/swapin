<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_auth();
$uid  = $user['id'];

$toUserId = (int)($_GET['to'] ?? 0);
$success  = '';
$error    = '';

// ── Send message ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('send_message', 40, 900);
    $toId = (int)($_POST['to_user_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');

    if (!$body || !$toId) {
        $error = 'پیام نمی‌تواند خالی باشد.';
    } else {
        $recipient = DB::fetch('SELECT id FROM users WHERE id = ? AND is_active = 1', [$toId]);
        if (!$recipient) {
            $error = 'کاربر یافت نشد.';
        } else {
            $existing = DB::fetch(
                'SELECT thread_id FROM messages
                 WHERE (from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?)
                 ORDER BY id ASC LIMIT 1',
                [$uid, $toId, $toId, $uid]
            );
            $threadId = $existing['thread_id'] ?? message_thread_id($uid, $toId);
            DB::insert('messages', [
                'thread_id'    => $threadId,
                'from_user_id' => $uid,
                'to_user_id'   => $toId,
                'body'         => $body,
            ]);
            header('Location: ' . APP_URL . '/messages?to=' . $toId . '#chat-end'); exit;
        }
    }
}

// ── Mark messages as read ─────────────────────────────────────────────────
if ($toUserId) {
    DB::query(
        'UPDATE messages SET is_read = 1 WHERE to_user_id = ? AND from_user_id = ?',
        [$uid, $toUserId]
    );
}

// ── Load all threads (inbox) ──────────────────────────────────────────────
$threads = DB::fetchAll(
    'SELECT
       CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END AS partner_id,
       u.name AS partner_name,
       m.body AS last_message,
       m.created_at AS last_at,
       m.thread_id,
       SUM(CASE WHEN m.to_user_id = ? AND m.is_read = 0 THEN 1 ELSE 0 END) AS unread_count
     FROM messages m
     JOIN users u ON u.id = (CASE WHEN m.from_user_id = ? THEN m.to_user_id ELSE m.from_user_id END)
     WHERE m.from_user_id = ? OR m.to_user_id = ?
     GROUP BY partner_id, u.name, m.thread_id
     ORDER BY last_at DESC',
    [$uid, $uid, $uid, $uid, $uid]
);

// Deduplicate by partner_id (keep latest)
$seen    = [];
$threads = array_filter($threads, function ($t) use (&$seen) {
    if (isset($seen[$t['partner_id']])) return false;
    $seen[$t['partner_id']] = true;
    return true;
});
$threads = array_values($threads);

// ── Load conversation ─────────────────────────────────────────────────────
$chatUser = null;
$chatMessages = [];
$threadId = '';

if ($toUserId) {
    $chatUser = DB::fetch(
        'SELECT id, name, city, rating, verification_level FROM users WHERE id = ? AND is_active = 1',
        [$toUserId]
    );
    if ($chatUser) {
        $chatMessages = DB::fetchAll(
            'SELECT * FROM messages
             WHERE (from_user_id = ? AND to_user_id = ?)
                OR (from_user_id = ? AND to_user_id = ?)
             ORDER BY created_at ASC',
            [$uid, $toUserId, $toUserId, $uid]
        );
        // Determine thread id
        if ($chatMessages) {
            $threadId = $chatMessages[0]['thread_id'];
        } else {
            $threadId = message_thread_id($uid, $toUserId);
        }
    }
}

render_head('پیام‌ها', '', ['canonical' => APP_URL . '/messages']);
render_navbar($user);
?>

<div class="section-sm">
  <div class="container">
    <div style="display:grid;grid-template-columns:320px 1fr;gap:var(--sp-5);height:calc(100vh - 140px);min-height:500px">

      <!-- ── Thread List ───────────────────────────────────────────── -->
      <div class="card" style="overflow:hidden;display:flex;flex-direction:column">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
          <h3 style="margin:0;font-size:1rem"><i class="bi bi-chat-dots" style="color:var(--primary)"></i> پیام‌ها</h3>
          <span class="fs-xs" style="color:var(--text-muted)"><?= count($threads) ?> مکالمه</span>
        </div>

        <div style="overflow-y:auto;flex:1" class="message-thread-list">
          <?php if (empty($threads)): ?>
          <div class="empty-state" style="padding:var(--sp-10) var(--sp-4)">
            <i class="bi bi-chat" style="font-size:2.5rem"></i>
            <h3 style="font-size:1rem">هنوز پیامی نیست</h3>
            <p style="font-size:.875rem">پیام‌های معاملات و پیشنهادها اینجا نمایش داده می‌شوند.</p>
          </div>
          <?php else: ?>
          <?php foreach ($threads as $thread):
            $isActive = ($thread['partner_id'] == $toUserId);
          ?>
          <a href="<?= APP_URL ?>/messages?to=<?= $thread['partner_id'] ?>"
             class="thread-item <?= $thread['unread_count'] > 0 ? 'unread' : '' ?> <?= $isActive ? 'active' : '' ?>"
             style="<?= $isActive ? 'background:rgba(26,107,74,.06);border-inline-start:3px solid var(--primary)' : '' ?>">
            <div class="avatar avatar-sm"><?= strtoupper(substr($thread['partner_name'], 0, 1)) ?></div>
            <div style="flex:1;min-width:0">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <span style="font-weight:700;font-size:.875rem"><?= h($thread['partner_name']) ?></span>
                <span class="fs-xs" style="color:var(--text-muted);flex-shrink:0"><?= timeago($thread['last_at']) ?></span>
              </div>
              <div class="thread-preview"><?= h(mb_strimwidth($thread['last_message'], 0, 55, '…')) ?></div>
            </div>
            <?php if ($thread['unread_count'] > 0): ?>
            <span class="badge badge-primary" style="flex-shrink:0"><?= $thread['unread_count'] ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- ── Chat Window ──────────────────────────────────────────── -->
      <div class="card" style="overflow:hidden;display:flex;flex-direction:column">

        <?php if (!$chatUser): ?>
        <div class="empty-state" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center">
          <i class="bi bi-chat-square-text" style="font-size:3rem"></i>
          <h3>یک مکالمه را انتخاب کنید</h3>
          <p>از فهرست سمت راست یک گفتگو را برای شروع چت انتخاب کنید.</p>
        </div>

        <?php else: ?>
        <!-- Chat Header -->
        <div class="card-header" style="display:flex;align-items:center;gap:var(--sp-3)">
          <div class="avatar avatar-sm"><?= strtoupper(substr($chatUser['name'], 0, 1)) ?></div>
          <div>
            <div style="font-weight:700"><?= h($chatUser['name']) ?></div>
            <div class="fs-xs" style="color:var(--text-muted)">
              <?php if ($chatUser['city']): ?><i class="bi bi-geo-alt"></i> <?= h($chatUser['city']) ?><?php endif; ?>
              <?php if ($chatUser['verification_level'] >= 2): ?>
              &nbsp;<span class="badge badge-success" style="padding:1px 6px"><i class="bi bi-patch-check"></i> تأیید‌شده</span>
              <?php endif; ?>
            </div>
          </div>
          <div style="margin-inline-start:auto;display:flex;gap:var(--sp-2)">
            <a href="#" class="btn btn-outline btn-sm" title="معامله امن">
              <i class="bi bi-shield-lock"></i> معامله امن
            </a>
            <a href="<?= APP_URL ?>/profile.php?id=<?= $chatUser['id'] ?>" class="btn btn-ghost btn-sm btn-icon" title="مشاهده پروفایل">
              <i class="bi bi-person"></i>
            </a>
          </div>
        </div>

        <!-- Messages -->
        <div style="flex:1;overflow-y:auto;padding:var(--sp-5);display:flex;flex-direction:column;gap:var(--sp-3)" id="chat-box">
          <?php if (empty($chatMessages)): ?>
          <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:.875rem">
            <div style="text-align:center">
              <i class="bi bi-chat" style="font-size:2rem;opacity:.3;display:block;margin-bottom:var(--sp-3)"></i>
              گفتگو را شروع کنید!
            </div>
          </div>
          <?php else: ?>
          <?php foreach ($chatMessages as $msg):
            $isMine = $msg['from_user_id'] == $uid;
          ?>
          <div class="chat-bubble-wrap <?= $isMine ? 'mine' : '' ?>">
            <?php if (!$isMine): ?>
            <div class="avatar avatar-sm" style="flex-shrink:0;align-self:flex-end"><?= strtoupper(substr($chatUser['name'], 0, 1)) ?></div>
            <?php endif; ?>
            <div>
              <div class="chat-bubble <?= $isMine ? 'mine' : 'theirs' ?>"><?= nl2br(h($msg['body'])) ?></div>
              <div class="chat-time <?= $isMine ? '' : '' ?>" style="<?= $isMine ? 'text-align:left' : '' ?>">
                <?= persian_datetime($msg['created_at']) ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <div id="chat-end"></div>
          <?php endif; ?>
        </div>

        <!-- Message input -->
        <?php if ($error): ?>
        <div style="padding:0 var(--sp-5)">
          <div class="alert alert-danger" style="margin-bottom:var(--sp-3)"><i class="bi bi-exclamation-circle"></i> <?= h($error) ?></div>
        </div>
        <?php endif; ?>

        <div style="padding:var(--sp-4);border-top:1px solid var(--border)">
          <form method="POST" style="display:flex;gap:var(--sp-3);align-items:flex-end">
            <?= csrf_field() ?>
            <input type="hidden" name="to_user_id" value="<?= $chatUser['id'] ?>">
            <textarea name="body" class="form-control" rows="2" placeholder="پیام خود را بنویسید…" required
                      style="flex:1;resize:none;min-height:50px"
                      onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();this.closest('form').submit()}"></textarea>
            <button type="submit" class="btn btn-primary" style="height:50px;flex-shrink:0">
              <i class="bi bi-send"></i>
            </button>
          </form>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>
</div>

<script>
// Auto scroll to bottom of chat
const chatBox = document.getElementById('chat-box');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;
</script>

<?php render_footer(); ?>
