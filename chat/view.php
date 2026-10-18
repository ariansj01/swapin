<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/dashboard_layout.php';
require_once __DIR__ . '/../includes/user_chat.php';

$user = require_auth();
$uid  = (int)$user['id'];

user_chat_ensure_tables();

$convId    = (int)($_GET['id'] ?? 0);
$listingId = (int)($_GET['listing_id'] ?? 0);

/** Renders a "not found" page and stops. */
function user_chat_not_found(?array $user): void {
    http_response_code(404);
    render_head('گفتگو یافت نشد', '', ['robots' => 'noindex, nofollow']);
    render_navbar($user);
    echo '<main id="main-content" class="section"><div class="container">'
       . '<div class="empty-state"><i class="bi bi-exclamation-circle"></i>'
       . '<h1>گفتگو یافت نشد</h1><p>این گفتگو حذف شده یا به آن دسترسی ندارید.</p>'
       . '<a href="' . APP_URL . '/chat" class="btn btn-primary">گفتگوهای من</a></div>'
       . '</div></main>';
    render_footer();
    exit;
}

// شروع گفتگو از صفحه آگهی: /chat/view?listing_id=X  →  تبدیل به گفتگوی پایدار
if ($convId <= 0 && $listingId > 0) {
    $listing = DB::fetch('SELECT id, user_id FROM listings WHERE id = ?', [$listingId]);
    if (!$listing) {
        user_chat_not_found($user);
    }
    $otherId = (int)$listing['user_id'];
    if ($otherId === $uid) {
        header('Location: ' . APP_URL . '/listings/view?id=' . $listingId);
        exit;
    }
    $newId = user_chat_start($listingId, $uid, $otherId);
    if (!$newId) {
        user_chat_not_found($user);
    }
    header('Location: ' . APP_URL . '/chat/view?id=' . $newId);
    exit;
}

$conv = user_chat_conversation($convId, $uid);
if (!$conv) {
    user_chat_not_found($user);
}

$sendError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('user_chat_send', 60, 60);
    $res = user_chat_send($convId, $uid, (string)($_POST['body'] ?? ''));
    if (!empty($res['error'])) {
        $sendError = (string)$res['error'];
    } else {
        header('Location: ' . APP_URL . '/chat/view?id=' . $convId);
        exit;
    }
}

$messages = user_chat_messages($convId, 0, 300);
user_chat_mark_read($convId, $uid);

$lastId    = $messages ? (int)$messages[count($messages) - 1]['id'] : 0;
$otherName = (string)($conv['other_name'] ?? 'کاربر');
$myName    = (string)($user['name'] ?? '');

render_head('گفتگو با ' . $otherName, 'گفتگوی خصوصی در ' . APP_NAME, ['robots' => 'noindex, nofollow']);
render_panel_styles();
render_navbar($user);
render_user_panel_open($user, 'chat');
?>
<link rel="stylesheet" href="<?= APP_URL ?>/src/css/user-chat.css?v=<?= @filemtime(__DIR__ . '/../src/css/user-chat.css') ?: time() ?>">

  <div class="dash-panel">
    <?php render_panel_page_header('گفتگو با ' . $otherName, '', APP_URL . '/chat', 'گفتگوهای من'); ?>

    <div class="uc-head">
      <div class="uc-conv__avatar">
        <?= avatar_html($conv['other_avatar'] ?? null, $otherName, 'md') ?>
      </div>
      <div class="uc-head__info">
        <div class="uc-head__name"><?= h($otherName) ?></div>
        <?php if (!empty($conv['listing_title'])): ?>
          <div class="uc-head__sub">درباره: <?= h((string)$conv['listing_title']) ?></div>
        <?php else: ?>
          <div class="uc-head__sub">گفتگوی خصوصی</div>
        <?php endif; ?>
      </div>
      <?php if (!empty($conv['listing_id'])): ?>
        <a class="uc-head__link" href="<?= APP_URL ?>/listings/view?id=<?= (int)$conv['listing_id'] ?>">
          <i class="bi bi-box-arrow-up-right"></i> <span>مشاهده آگهی</span>
        </a>
      <?php endif; ?>
    </div>

    <div class="uc-thread" id="uc-thread" data-conversation="<?= $convId ?>" data-last-id="<?= $lastId ?>"
         data-poll-url="<?= h(APP_URL . '/api/user_chat.php') ?>">
      <?php if (empty($messages)): ?>
        <div class="uc-thread__empty" id="uc-thread-empty">
          <i class="bi bi-chat-dots"></i>
          <div>هنوز پیامی رد و بدل نشده. اولین پیام را بفرستید.</div>
        </div>
      <?php else: ?>
        <?php foreach ($messages as $msg): ?>
          <?php $isMine = (int)$msg['sender_id'] === $uid; ?>
          <div class="uc-msg <?= $isMine ? 'uc-msg--mine' : 'uc-msg--other' ?>" data-id="<?= (int)$msg['id'] ?>">
            <?php if (!$isMine): ?>
              <div class="uc-msg__avatar"><?= h(user_initial($otherName)) ?></div>
            <?php endif; ?>
            <div class="uc-bubble">
              <?= h((string)$msg['body']) ?>
              <div class="uc-bubble__meta">
                <?= h($isMine ? 'شما' : $otherName) ?> · <?= h(persian_date($msg['created_at'])) ?>
              </div>
            </div>
            <?php if ($isMine): ?>
              <div class="uc-msg__avatar"><?= h(user_initial($myName)) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($sendError !== ''): ?>
      <div class="uc-error"><?= h($sendError) ?></div>
    <?php endif; ?>

    <form method="POST" class="uc-composer" id="uc-form" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_message">
      <textarea name="body" id="uc-input" rows="1" placeholder="پیام خود را بنویسید..." required></textarea>
      <button type="submit" class="uc-send" id="uc-send-btn" aria-label="ارسال پیام">
        <i class="bi bi-send"></i>
      </button>
    </form>
  </div>
<?php render_user_panel_close(); ?>
<?php render_panel_scripts(); ?>
<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const thread = document.getElementById('uc-thread');
  const form = document.getElementById('uc-form');
  const input = document.getElementById('uc-input');
  const sendBtn = document.getElementById('uc-send-btn');
  if (!thread || !form || !input) return;

  const myInitial = <?= json_encode(user_initial($myName), JSON_UNESCAPED_UNICODE) ?>;
  const otherName = <?= json_encode($otherName, JSON_UNESCAPED_UNICODE) ?>;
  const otherInitial = <?= json_encode(user_initial($otherName), JSON_UNESCAPED_UNICODE) ?>;
  const conversationId = thread.dataset.conversation;
  const pollUrl = thread.dataset.pollUrl;

  // نشانگر دریافت پیام‌های جدید: فقط با نتایج پاسخ سرور جلو می‌رود، نه با پیام‌های خودمان
  let pollCursor = parseInt(thread.dataset.lastId, 10) || 0;

  function scrollDown() { thread.scrollTop = thread.scrollHeight; }
  scrollDown();

  function makeAvatar(letter) {
    const av = document.createElement('div');
    av.className = 'uc-msg__avatar';
    av.textContent = letter || '';
    return av;
  }

  function buildMessage(m, mine) {
    const row = document.createElement('div');
    row.className = 'uc-msg ' + (mine ? 'uc-msg--mine' : 'uc-msg--other');
    row.dataset.id = m.id;
    const bubble = document.createElement('div');
    bubble.className = 'uc-bubble';
    bubble.textContent = m.body;
    const meta = document.createElement('div');
    meta.className = 'uc-bubble__meta';
    meta.textContent = (mine ? 'شما' : otherName) + ' · ' + (m.date || '');
    bubble.appendChild(meta);
    if (!mine) row.appendChild(makeAvatar(otherInitial));
    row.appendChild(bubble);
    if (mine) row.appendChild(makeAvatar(myInitial));
    return row;
  }

  function append(m, mine) {
    if (thread.querySelector('.uc-msg[data-id="' + m.id + '"]')) return false;
    const empty = document.getElementById('uc-thread-empty');
    if (empty) empty.remove();
    thread.appendChild(buildMessage(m, mine));
    return true;
  }

  function poll() {
    fetch(pollUrl + '?conversation_id=' + encodeURIComponent(conversationId) + '&after_id=' + encodeURIComponent(pollCursor), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.ok || !Array.isArray(data.messages)) return;
        let appended = false;
        data.messages.forEach(m => {
          if (append(m, !!m.mine)) appended = true;
          pollCursor = Math.max(pollCursor, parseInt(m.id, 10) || 0);
        });
        if (appended) scrollDown();
      })
      .catch(() => {});
  }

  form.addEventListener('submit', function (e) {
    const body = input.value.trim();
    if (body === '') { e.preventDefault(); return; }
    if (!window.fetch) return; // بدون JS: ارسال معمولی فرم
    e.preventDefault();
    sendBtn.disabled = true;
    fetch(pollUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify({ conversation_id: conversationId, body: body, _csrf: csrf })
    })
      .then(r => r.json())
      .then(data => {
        if (data && data.ok && data.message) {
          append(data.message, true);
          input.value = '';
          input.style.height = 'auto';
          scrollDown();
        } else if (data && data.error) {
          alert(data.error);
        }
      })
      .catch(() => {})
      .finally(() => { sendBtn.disabled = false; input.focus(); });
  });

  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 140) + 'px';
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
  });

  setInterval(() => { document.hidden || poll(); }, 7000);
  window.addEventListener('focus', poll);
})();
</script>
<?php render_footer(); ?>
