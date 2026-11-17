<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/support.php';

$user  = auth_user();
$token = trim((string)($_GET['token'] ?? ''));

// اعتبارسنجی توکن
live_chat_ensure_tables();
$chat = null;
if ($token !== '') {
    $chat = DB::fetch('SELECT * FROM live_chats WHERE session_token = ?', [$token]);
}

if (!$chat) {
    http_response_code(404);
    render_head('جلسه چت یافت نشد');
    render_navbar($user);
    echo '<main class="section"><div class="container"><div class="empty-state" style="text-align:center;padding:60px 20px">';
    echo '<i class="bi bi-chat-x" style="font-size:3rem;color:#cbd5e1"></i>';
    echo '<h1 style="margin:16px 0 8px">جلسه چت یافت نشد</h1>';
    echo '<p style="color:#64748b">لینک چت نامعتبر یا منقضی شده است.</p>';
    echo '<a href="' . APP_URL . '/support/chat" class="btn btn-primary" style="margin-top:16px">شروع گفتگوی جدید</a>';
    echo '</div></div></main>';
    render_footer();
    exit;
}

// بارگذاری پیام‌های موجود
$messages = live_chat_get_messages($token);
$lastId   = $messages ? (int)end($messages)['id'] : 0;
$apiUrl   = APP_URL . '/api/live_chat.php';

render_head('چت پشتیبانی سواپین', 'گفتگوی زنده با تیم پشتیبانی سواپین.', [
    'canonical' => APP_URL . '/support/live',
    'robots'    => 'noindex, nofollow',
]);
render_navbar($user);
?>

<style>
:root {
  --sup-green: #0A7A3D;
  --sup-green-dark: #075C2E;
  --sup-green-light: #E8F5EC;
  --sup-bg: #ECE5DD;
}
.live-page {
  background: var(--sup-bg);
  min-height: calc(100vh - 72px);
  display: flex;
  flex-direction: column;
}
.live-container {
  max-width: 680px;
  margin: 0 auto;
  width: 100%;
  display: flex;
  flex-direction: column;
  flex: 1;
}

/* هدر */
.live-header {
  background: linear-gradient(135deg, var(--sup-green) 0%, var(--sup-green-dark) 100%);
  color: #fff;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 4px 14px rgba(10,122,61,.25);
}
.live-header__back {
  background: none;
  border: none;
  color: #fff;
  font-size: 1.3rem;
  cursor: pointer;
  padding: 4px;
}
.live-header__avatar {
  width: 44px; height: 44px;
  border-radius: 50%;
  background: rgba(255,255,255,.2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
}
.live-header__info h1 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
}
.live-header__info p {
  margin: 2px 0 0;
  font-size: .78rem;
  opacity: .9;
}
.live-header__status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.live-header__dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #4ade80;
}

/* ناحیه پیام‌ها */
.live-thread {
  flex: 1;
  padding: 18px 14px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 50vh;
}
.live-msg {
  display: flex;
  max-width: 80%;
}
.live-msg--guest { align-self: flex-end; }
.live-msg--admin,
.live-msg--ai { align-self: flex-start; }
.live-msg__bubble {
  padding: 10px 14px;
  border-radius: 14px;
  font-size: .9rem;
  line-height: 1.7;
  word-wrap: break-word;
  position: relative;
}
.live-msg--guest .live-msg__bubble {
  background: #D9FDD3;
  border-radius: 14px 14px 4px 14px;
  color: #111;
}
.live-msg--admin .live-msg__bubble,
.live-msg--ai .live-msg__bubble {
  background: #fff;
  border-radius: 14px 14px 14px 4px;
  color: #111;
  box-shadow: 0 1px 4px rgba(0,0,0,.08);
}
.live-msg__time {
  display: block;
  font-size: .68rem;
  color: #667781;
  margin-top: 4px;
  text-align: left;
}
.live-msg--guest .live-msg__time { text-align: left; }
.live-msg__sender {
  display: block;
  font-size: .72rem;
  font-weight: 700;
  color: var(--sup-green);
  margin-bottom: 2px;
}

/* کامپوزر */
.live-composer {
  background: #F0F2F5;
  padding: 10px 12px;
  display: flex;
  align-items: flex-end;
  gap: 8px;
  border-top: 1px solid #E2E8F0;
}
.live-composer__input {
  flex: 1;
  border: none;
  border-radius: 22px;
  padding: 12px 18px;
  font-size: .92rem;
  font-family: inherit;
  outline: none;
  background: #fff;
  resize: none;
  max-height: 120px;
  line-height: 1.5;
}
.live-composer__send {
  width: 44px; height: 44px;
  border-radius: 50%;
  border: none;
  background: var(--sup-green);
  color: #fff;
  font-size: 1.1rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: background .15s;
}
.live-composer__send:hover { background: var(--sup-green-dark); }
.live-composer__send:disabled { opacity: .6; cursor: not-allowed; }

/* تایپینگ */
.live-typing {
  align-self: flex-start;
  background: #fff;
  border-radius: 14px 14px 14px 4px;
  padding: 12px 16px;
  display: none;
}
.live-typing.is-visible { display: flex; }
.live-typing__dots { display: flex; gap: 4px; }
.live-typing__dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #94A3B8;
  animation: live-bounce 1.4s infinite ease-in-out both;
}
.live-typing__dot:nth-child(1) { animation-delay: -.32s; }
.live-typing__dot:nth-child(2) { animation-delay: -.16s; }
@keyframes live-bounce {
  0%, 80%, 100% { transform: scale(0); }
  40% { transform: scale(1); }
}

.live-empty {
  text-align: center;
  color: #64748b;
  padding: 40px 20px;
  font-size: .9rem;
}

@media (max-width: 480px) {
  .live-msg { max-width: 88%; }
  .live-thread { min-height: 55vh; }
}
</style>

<section class="live-page">
  <div class="live-container">

    <div class="live-header">
      <a href="<?= APP_URL ?>/support/chat" class="live-header__back" aria-label="بازگشت">
        <i class="bi bi-arrow-right"></i>
      </a>
      <div class="live-header__avatar"><i class="bi bi-headset"></i></div>
      <div class="live-header__info">
        <h1>پشتیبانی سواپین</h1>
        <p>
          <span class="live-header__status">
            <span class="live-header__dot"></span>
            <?= $chat['status'] === 'open' ? 'آنلاین' : 'آفلاین' ?>
          </span>
          · <?= h($chat['guest_name']) ?>
        </p>
      </div>
    </div>

    <div class="live-thread" id="live-thread">
      <?php if (empty($messages)): ?>
        <div class="live-empty">سلام <?= h($chat['guest_name']) ?>! 👋<br>به‌زودی یکی از همکاران ما پاسخگوی شما خواهد بود.</div>
      <?php else: ?>
        <?php foreach ($messages as $m): ?>
          <div class="live-msg live-msg--<?= h($m['sender_type']) ?>" data-id="<?= $m['id'] ?>">
            <div class="live-msg__bubble">
              <?php if ($m['sender_type'] !== 'guest'): ?>
                <span class="live-msg__sender"><?= $m['sender_type'] === 'admin' ? 'پشتیبانی' : 'دستیار' ?></span>
              <?php endif; ?>
              <div class="live-msg__body"><?= nl2br(h($m['body'])) ?></div>
              <span class="live-msg__time"><?= date('H:i', strtotime($m['created_at'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="live-typing" id="live-typing">
        <div class="live-typing__dots">
          <span class="live-typing__dot"></span>
          <span class="live-typing__dot"></span>
          <span class="live-typing__dot"></span>
        </div>
      </div>
    </div>

    <form class="live-composer" id="live-composer">
      <textarea class="live-composer__input" id="live-input" rows="1" placeholder="پیام خود را بنویسید…" autocomplete="off"></textarea>
      <button type="submit" class="live-composer__send" id="live-send" aria-label="ارسال">
        <i class="bi bi-send-fill"></i>
      </button>
    </form>

  </div>
</section>

<script>
(function() {
  const apiUrl   = <?= json_encode($apiUrl) ?>;
  const token    = <?= json_encode($token) ?>;
  let lastMsgId  = <?= $lastId ?>;
  const thread   = document.getElementById('live-thread');
  const composer = document.getElementById('live-composer');
  const input    = document.getElementById('live-input');
  const sendBtn  = document.getElementById('live-send');
  const typing   = document.getElementById('live-typing');

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function scrollBottom() {
    thread.scrollTop = thread.scrollHeight;
  }
  scrollBottom();

  // خودرشد تکست‌اریا
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });

  // ارسال پیام
  composer.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = input.value.trim();
    if (!body) return;
    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;

    // نمایش موقت پیام کاربر
    appendMsg({ id: 0, sender_type: 'guest', body: body, created_at: new Date().toISOString() }, true);

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'send', token: token, body: body })
      });
      const data = await res.json();
      if (!data.ok) {
        alert(data.error || 'خطا در ارسال پیام');
      }
    } catch {
      alert('خطای شبکه');
    } finally {
      sendBtn.disabled = false;
      input.focus();
    }
  });

  function appendMsg(m, temp) {
    const wrap = document.createElement('div');
    wrap.className = 'live-msg live-msg--' + (m.sender_type || 'guest');
    if (temp) wrap.dataset.temp = '1';
    const senderLabel = m.sender_type === 'admin' ? 'پشتیبانی' : (m.sender_type === 'ai' ? 'دستیار' : '');
    const senderHtml = senderLabel ? '<span class="live-msg__sender">' + esc(senderLabel) + '</span>' : '';
    let time;
    try { time = new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' }); }
    catch { time = ''; }
    wrap.innerHTML = '<div class="live-msg__bubble">' + senderHtml +
      '<div class="live-msg__body">' + esc(m.body).replace(/\n/g, '<br>') + '</div>' +
      '<span class="live-msg__time">' + esc(time) + '</span></div>';
    thread.insertBefore(wrap, typing);
    scrollBottom();
  }

  // نظرسنجی پیام‌های جدید
  setInterval(async () => {
    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'messages', token: token, after_id: lastMsgId })
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

  // Enter برای ارسال (Shift+Enter خط جدید)
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      composer.requestSubmit();
    }
  });
})();
</script>

<?php render_footer(); ?>
