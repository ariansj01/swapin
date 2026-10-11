<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin_layout.php';

$admin = require_admin();
$id    = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();
    $action = clean($_POST['action'] ?? '');

    if ($action === 'reply') {
        $chatId = (int)($_POST['chat_id'] ?? 0);
        $body   = $_POST['body'] ?? '';
        $result = live_chat_admin_reply($chatId, (int)$admin['id'], $body);
        if (isset($result['error'])) {
            admin_set_flash($result['error'], 'error');
        } else {
            admin_set_flash('پاسخ ارسال شد.');
        }
        header('Location: ' . APP_URL . '/admin/live_chat.php?id=' . $chatId);
        exit;
    }

    if ($action === 'close') {
        $chatId = (int)($_POST['chat_id'] ?? 0);
        live_chat_admin_close($chatId);
        admin_set_flash('چت بسته شد.');
        header('Location: ' . APP_URL . '/admin/live_chat.php');
        exit;
    }
}

[$flash, $flashType] = admin_flash();

$chatDetail = null;
if ($id) {
    $chatDetail = live_chat_admin_get($id);
}

$chats = live_chat_admin_list();
$apiUrl = APP_URL . '/api/live_chat.php';

ob_start();
?>
<?= admin_alert_html($flash, $flashType) ?>

<div class="admin-header"><h1>چت زنده</h1></div>

<?php if ($chatDetail): ?>
<?php
$chat     = $chatDetail['chat'];
$messages = $chatDetail['messages'];
$lastId   = $messages ? (int)end($messages)['id'] : 0;
?>
<div class="live-chat-layout">
  <aside class="live-chat-list">
    <div class="live-chat-list__head">
      <h3>گفتگوها</h3>
      <a href="<?= APP_URL ?>/admin/live_chat.php" class="btn btn-sm btn-outline">همه</a>
    </div>
    <div class="live-chat-list__items" id="chat-list-items">
      <?php foreach ($chats as $c): ?>
      <a href="?id=<?= $c['id'] ?>" class="live-chat-list__item <?= (int)$c['id'] === $id ? 'is-active' : '' ?>" data-id="<?= $c['id'] ?>">
        <div class="live-chat-list__row">
          <strong><?= h($c['guest_name']) ?></strong>
          <?php if (!empty($c['unread'])): ?>
            <span class="live-chat-list__badge"><?= $c['unread'] ?></span>
          <?php endif; ?>
        </div>
        <div class="live-chat-list__meta">
          <span dir="ltr"><?= h($c['guest_phone']) ?></span>
          <span class="fs-xs"><?= timeago($c['last_message_at']) ?></span>
        </div>
        <div class="live-chat-list__status">
          <?php if ($c['status'] === 'open'): ?>
            <span class="badge badge-success">باز</span>
          <?php else: ?>
            <span class="badge badge-info">بسته</span>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (empty($chats)): ?>
        <p class="fs-sm" style="padding:var(--sp-4);color:var(--text-muted);text-align:center">هنوز چتی ثبت نشده است.</p>
      <?php endif; ?>
    </div>
  </aside>

  <section class="live-chat-panel">
    <div class="live-chat-panel__head">
      <div>
        <h2 style="margin:0;font-size:1.1rem"><?= h($chat['guest_name']) ?></h2>
        <div class="fs-xs" style="color:var(--text-muted)">
          <span dir="ltr"><?= h($chat['guest_phone']) ?></span>
          ·
          <?= $chat['status'] === 'open' ? 'باز' : 'بسته شده' ?>
        </div>
      </div>
      <?php if ($chat['status'] !== 'closed'): ?>
      <button type="button" class="btn btn-sm btn-outline" id="close-chat-btn" data-id="<?= $id ?>">
        <i class="bi bi-lock"></i> بستن چت
      </button>
      <?php endif; ?>
    </div>

    <div class="live-chat-thread" id="live-chat-thread">
      <?php foreach ($messages as $m): ?>
      <div class="lc-msg lc-msg--<?= $m['sender_type'] ?>" data-id="<?= $m['id'] ?>">
        <div class="lc-msg__bubble">
          <div class="lc-msg__body"><?= nl2br(h($m['body'])) ?></div>
          <div class="lc-msg__time"><?= timeago($m['created_at']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($messages)): ?>
        <p class="fs-sm" style="padding:var(--sp-6);color:var(--text-muted);text-align:center">هنوز پیامی ثبت نشده است.</p>
      <?php endif; ?>
    </div>

    <?php if ($chat['status'] !== 'closed'): ?>
    <form class="live-chat-composer" id="live-chat-composer" data-id="<?= $id ?>" data-last="<?= $lastId ?>">
      <?= csrf_field() ?>
      <textarea class="form-control" id="live-chat-input" rows="1" placeholder="پاسخ به کاربر…" autofocus></textarea>
      <button type="submit" class="btn btn-primary" id="live-chat-send">
        <i class="bi bi-send"></i>
      </button>
    </form>
    <?php else: ?>
    <div class="live-chat-closed">این چت بسته شده است.</div>
    <?php endif; ?>
  </section>
</div>

<script>
(function() {
  const apiUrl   = <?= json_encode($apiUrl) ?>;
  const chatId   = <?= $id ?>;
  let lastMsgId  = <?= $lastId ?>;
  const thread   = document.getElementById('live-chat-thread');
  const composer = document.getElementById('live-chat-composer');
  const input    = document.getElementById('live-chat-input');
  const sendBtn  = document.getElementById('live-chat-send');
  const closeBtn = document.getElementById('close-chat-btn');

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function timeago(ts) {
    const d = new Date(ts.replace(' ', 'T'));
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'هم‌اکنون';
    if (diff < 3600) return Math.floor(diff / 60) + ' دقیقه پیش';
    if (diff < 86400) return Math.floor(diff / 3600) + ' ساعت پیش';
    return d.toLocaleDateString('fa-IR');
  }

  function appendMsg(m) {
    const wrap = document.createElement('div');
    wrap.className = 'lc-msg lc-msg--' + m.sender_type;
    wrap.dataset.id = m.id;
    wrap.innerHTML = '<div class="lc-msg__bubble"><div class="lc-msg__body">' +
      esc(m.body).replace(/\n/g, '<br>') +
      '</div><div class="lc-msg__time">' + timeago(m.created_at) + '</div></div>';
    thread.appendChild(wrap);
    thread.scrollTop = thread.scrollHeight;
  }

  function scrollBottom() {
    thread.scrollTop = thread.scrollHeight;
  }
  scrollBottom();

  if (composer) {
    composer.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = input.value.trim();
      if (!body) return;
      input.value = '';
      sendBtn.disabled = true;
      try {
        const res = await fetch(apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'admin_reply', chat_id: chatId, body: body })
        });
        const data = await res.json();
        if (!data.ok) {
          input.value = body;
          alert(data.error || 'خطا در ارسال');
        }
      } catch {
        input.value = body;
        alert('خطای شبکه');
      } finally {
        sendBtn.disabled = false;
        input.focus();
      }
    });

    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        composer.requestSubmit();
      }
    });
  }

  if (closeBtn) {
    closeBtn.addEventListener('click', async () => {
      if (!confirm('چت بسته شود؟')) return;
      try {
        await fetch(apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'admin_close', chat_id: chatId })
        });
        location.reload();
      } catch {
        alert('خطا در بستن چت');
      }
    });
  }

  setInterval(async () => {
    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'admin_messages', chat_id: chatId, after_id: lastMsgId })
      });
      const data = await res.json();
      if (data.ok && Array.isArray(data.messages)) {
        for (const m of data.messages) {
          appendMsg(m);
          lastMsgId = Math.max(lastMsgId, parseInt(m.id, 10));
        }
      }
    } catch {}
  }, 3000);
})();
</script>

<?php else: ?>

<div class="card">
  <div class="card-header">
    <h3 style="margin:0">گفتگوهای زنده</h3>
  </div>
  <table class="admin-table">
    <thead><tr><th>#</th><th>نام</th><th>شماره</th><th>وضعیت</th><th>آخرین پیام</th><th></th></tr></thead>
    <tbody id="chat-tbody">
      <?php foreach ($chats as $c): ?>
      <tr>
        <td><?= $c['id'] ?></td>
        <td><?= h($c['guest_name']) ?></td>
        <td dir="ltr"><?= h($c['guest_phone']) ?></td>
        <td>
          <?php if ($c['status'] === 'open'): ?>
            <span class="badge badge-success">باز</span>
          <?php else: ?>
            <span class="badge badge-info">بسته</span>
          <?php endif; ?>
          <?php if (!empty($c['unread'])): ?>
            <span class="admin-nav__badge"><?= $c['unread'] ?></span>
          <?php endif; ?>
        </td>
        <td class="fs-xs"><?= timeago($c['last_message_at']) ?></td>
        <td><a href="?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline">بررسی</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($chats)): ?>
      <tr><td colspan="6" style="text-align:center;padding:var(--sp-6);color:var(--text-muted)">هنوز چتی ثبت نشده است.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
(function() {
  const apiUrl = <?= json_encode($apiUrl) ?>;
  const tbody  = document.getElementById('chat-tbody');
  if (!tbody) return;

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  setInterval(async () => {
    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'admin_list' })
      });
      const data = await res.json();
      if (!data.ok || !Array.isArray(data.chats)) return;
      if (data.chats.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:var(--sp-6);color:var(--text-muted)">هنوز چتی ثبت نشده است.</td></tr>';
        return;
      }
      tbody.innerHTML = data.chats.map(c => {
        const statusBadge = c.status === 'open'
          ? '<span class="badge badge-success">باز</span>'
          : '<span class="badge badge-info">بسته</span>';
        const unread = c.unread ? ' <span class="admin-nav__badge">' + esc(c.unread) + '</span>' : '';
        return '<tr>' +
          '<td>' + esc(c.id) + '</td>' +
          '<td>' + esc(c.guest_name) + '</td>' +
          '<td dir="ltr">' + esc(c.guest_phone) + '</td>' +
          '<td>' + statusBadge + unread + '</td>' +
          '<td class="fs-xs">' + esc(c.last_message_at) + '</td>' +
          '<td><a href="?id=' + esc(c.id) + '" class="btn btn-sm btn-outline">بررسی</a></td>' +
          '</tr>';
      }).join('');
    } catch {}
  }, 5000);
})();
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
render_admin_head('چت زنده');
render_admin_shell($admin, 'live_chat', $content);
render_admin_footer();
