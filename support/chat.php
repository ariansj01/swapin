<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/support.php';

$user   = auth_user();
$errors = [];

// ۵ سوال آماده پشتیبانی
$quickQuestions = [
    'چطور در سواپین ثبت‌نام کنم؟',
    'چطور کالایم را برای معاوضه ثبت کنم؟',
    'معاوضه در سواپین چطور انجام می‌شود؟',
    'ارزش کالایم را چطور تعیین کنم؟',
    'چطور آگهی‌ام را ویژه کنم؟',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_fail();

    $name    = trim((string)($_POST['name'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $topic   = trim((string)($_POST['topic'] ?? ''));

    if (mb_strlen($name) < 2) {
        $errors['name'] = 'نام و نام خانوادگی را وارد کنید.';
    }
    if ($phone === '' || !preg_match('/^09\d{9}$/', $phone)) {
        $errors['phone'] = 'شماره موبایل معتبر وارد کنید (مثال: ۰۹۱۲۳۴۵۶۷۸۹).';
    }

    if (!$errors) {
        $result = live_chat_start($name, $phone);
        if (isset($result['errors'])) {
            $errors = array_merge($errors, $result['errors']);
        } else {
            // ذخیره عنوان انتخاب‌شده به‌عنوان اولین پیام
            if ($topic !== '') {
                live_chat_send_message($result['token'], $topic);
            }
            header('Location: ' . APP_URL . '/support/live?token=' . urlencode($result['token']));
            exit;
        }
    }
}

render_head('پشتیبانی سواپین | چت آنلاین', 'پشتیبانی آنلاین سواپین — پاسخ به سوالات شما درباره ثبت آگهی، معاوضه و معامله.', [
    'canonical' => APP_URL . '/support/chat',
    'robots'    => 'noindex, follow',
]);
render_navbar($user);
?>

<style>
:root {
  --sup-green: #0A7A3D;
  --sup-green-dark: #075C2E;
  --sup-green-light: #E8F5EC;
  --sup-bubble-bg: #ffffff;
  --sup-bg: #F1F5F9;
}
.sup-page {
  background: var(--sup-bg);
  min-height: calc(100vh - 72px);
  padding: 0 0 40px;
}
.sup-container {
  max-width: 520px;
  margin: 0 auto;
}

/* هدر سبز */
.sup-header {
  background: linear-gradient(135deg, var(--sup-green) 0%, var(--sup-green-dark) 100%);
  color: #fff;
  padding: 18px 16px;
  border-radius: 0 0 22px 22px;
  box-shadow: 0 6px 22px rgba(10,122,61,.28);
  position: relative;
  overflow: hidden;
}
.sup-header::after {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 88% 18%, rgba(255,255,255,.14) 0, transparent 42%),
    radial-gradient(circle at 12% 85%, rgba(255,255,255,.08) 0, transparent 35%);
  pointer-events: none;
}
.sup-header__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  position: relative;
  z-index: 1;
}
.sup-header__close {
  width: 38px; height: 38px;
  border-radius: 50%;
  background: rgba(255,255,255,.18);
  border: none;
  color: #fff;
  font-size: 1.1rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background .15s;
  flex-shrink: 0;
}
.sup-header__close:hover { background: rgba(255,255,255,.3); }
.sup-header__info { text-align: center; flex: 1; }
.sup-header__info h1 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
}
.sup-header__info p {
  margin: 3px 0 0;
  font-size: .82rem;
  opacity: .92;
}
.sup-header__avatars {
  display: flex;
  flex-shrink: 0;
}
.sup-header__avatar {
  width: 40px; height: 40px;
  border-radius: 50%;
  background: #fff;
  border: 3px solid var(--sup-green);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: -10px;
  color: var(--sup-green);
  font-size: 1.05rem;
}
.sup-header__avatar:first-child { margin-right: 0; }

/* بدنه چت */
.sup-body {
  padding: 18px 14px 0;
}
.sup-msg {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  margin-bottom: 16px;
}
.sup-msg__avatar {
  flex-shrink: 0;
  width: 38px; height: 38px;
  border-radius: 50%;
  background: var(--sup-green-light);
  color: var(--sup-green-dark);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.05rem;
  order: 2;
}
.sup-msg__bubble {
  background: var(--sup-bubble-bg);
  border: 1px solid #E8EDF2;
  border-radius: 16px 16px 16px 4px;
  padding: 13px 16px;
  font-size: .88rem;
  line-height: 1.85;
  color: #334155;
  box-shadow: 0 2px 10px rgba(7,26,51,.05);
  max-width: 86%;
  order: 1;
}
.sup-msg__bubble--q {
  background: #fff;
}
.sup-msg__bubble--q p {
  margin: 0 0 12px;
  font-weight: 600;
  color: #0f172a;
}
.sup-qbuttons {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sup-qbtn {
  width: 100%;
  background: var(--sup-green);
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 11px 14px;
  font-size: .86rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  text-align: center;
  transition: background .15s, transform .1s;
}
.sup-qbtn:hover { background: var(--sup-green-dark); }
.sup-qbtn:active { transform: scale(.98); }
.sup-qbtn.is-selected {
  background: var(--sup-green-dark);
  box-shadow: inset 0 0 0 2px rgba(255,255,255,.5);
}

/* فرم */
.sup-form-card {
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 4px 20px rgba(7,26,51,.08);
  padding: 22px 18px 20px;
  margin: 4px 14px 0;
}
.sup-form-card__title {
  font-size: .92rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0 0 18px;
  text-align: center;
}
.sup-field {
  position: relative;
  margin-bottom: 22px;
}
.sup-field__input {
  width: 100%;
  border: none;
  border-bottom: 1.5px solid #CBD5E1;
  padding: 10px 0 8px;
  font-size: .95rem;
  font-family: inherit;
  outline: none;
  background: transparent;
  transition: border-color .15s;
  color: #0f172a;
}
.sup-field__input:focus {
  border-bottom-color: var(--sup-green);
}
.sup-field__input.is-invalid {
  border-bottom-color: #EF4444;
}
.sup-field__label {
  position: absolute;
  top: 10px;
  right: 0;
  font-size: .92rem;
  color: #94A3B8;
  pointer-events: none;
  transition: all .18s ease;
}
.sup-field__input:focus + .sup-field__label,
.sup-field__input:not(:placeholder-shown) + .sup-field__label {
  top: -14px;
  font-size: .72rem;
  color: var(--sup-green);
}
.sup-field__error {
  display: block;
  font-size: .75rem;
  color: #EF4444;
  margin-top: 5px;
}
.sup-submit {
  width: 100%;
  margin-top: 6px;
  padding: 14px;
  border: none;
  border-radius: 14px;
  background: linear-gradient(135deg, var(--sup-green) 0%, var(--sup-green-dark) 100%);
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: box-shadow .18s, transform .1s;
  box-shadow: 0 6px 18px rgba(10,122,61,.3);
}
.sup-submit:hover { box-shadow: 0 8px 24px rgba(10,122,61,.4); }
.sup-submit:active { transform: scale(.98); }
.sup-time {
  text-align: center;
  font-size: .72rem;
  color: #94A3B8;
  margin-top: 12px;
}
.sup-note {
  text-align: center;
  font-size: .76rem;
  color: #94A3B8;
  margin-top: 16px;
  padding: 0 18px;
}
</style>

<section class="sup-page">
  <div class="sup-container">

    <!-- هدر سبز -->
    <div class="sup-header">
      <div class="sup-header__row">
        <button type="button" class="sup-header__close" aria-label="بستن" onclick="history.back()">
          <i class="bi bi-x-lg"></i>
        </button>
        <div class="sup-header__info">
          <h1>پشتیبانی سواپین</h1>
          <p>پاسخگوی سوالات شما هستیم</p>
        </div>
        <div class="sup-header__avatars">
          <div class="sup-header__avatar"><i class="bi bi-headset"></i></div>
          <div class="sup-header__avatar"><i class="bi bi-person-badge"></i></div>
          <div class="sup-header__avatar"><i class="bi bi-chat-dots"></i></div>
        </div>
      </div>
    </div>

    <div class="sup-body">

      <!-- پیام سوالات آماده -->
      <div class="sup-msg">
        <div class="sup-msg__avatar"><i class="bi bi-headset"></i></div>
        <div class="sup-msg__bubble sup-msg__bubble--q">
          <p>چگونه می‌توانیم کمکتان کنیم؟</p>
          <div class="sup-qbuttons" id="sup-qbuttons">
            <?php foreach ($quickQuestions as $q): ?>
              <button type="button" class="sup-qbtn" data-topic="<?= h($q) ?>"><?= h($q) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- پیام ساعات کاری -->
      <div class="sup-msg">
        <div class="sup-msg__avatar"><i class="bi bi-headset"></i></div>
        <div class="sup-msg__bubble">
          کاربر گرامی؛ ساعت پاسخ‌دهی به سوالات و پیگیری مسائل شما در گفتگوی آنلاین، در روزهای کاری از شنبه تا پنجشنبه، ساعت ۹ تا ۲۱ می‌باشد. در صورت نیاز به دریافت سریع‌تر پاسخ، می‌توانید با شماره پشتیبانی تماس حاصل فرمایید.
        </div>
      </div>

    </div>

    <!-- فرم شروع گفتگو -->
    <div class="sup-form-card">
      <h2 class="sup-form-card__title">برای شروع گفتگو لطفا فرم زیر را تکمیل کنید</h2>
      <form method="POST" id="sup-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="topic" id="sup-topic" value="<?= h($_POST['topic'] ?? '') ?>">

        <div class="sup-field">
          <input type="text" id="name" name="name" class="sup-field__input <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                 value="<?= h($_POST['name'] ?? ($user['name'] ?? '')) ?>" placeholder=" " autocomplete="name" required>
          <label class="sup-field__label" for="name">نام و نام خانوادگی</label>
          <?php if (isset($errors['name'])): ?><span class="sup-field__error"><?= h($errors['name']) ?></span><?php endif; ?>
        </div>

        <div class="sup-field">
          <input type="tel" id="phone" name="phone" class="sup-field__input <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                 value="<?= h($_POST['phone'] ?? '') ?>" placeholder=" " autocomplete="tel" maxlength="11" required>
          <label class="sup-field__label" for="phone">تلفن همراه</label>
          <?php if (isset($errors['phone'])): ?><span class="sup-field__error"><?= h($errors['phone']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="sup-submit">شروع</button>
      </form>
      <div class="sup-time">لحظاتی پیش</div>
    </div>

    <p class="sup-note">با شروع گفتگو، شرایط پشتیبانی سواپین را می‌پذیرید.</p>

  </div>
</section>

<script>
(function() {
  const btns     = document.querySelectorAll('.sup-qbtn');
  const topicInp = document.getElementById('sup-topic');

  btns.forEach(btn => {
    btn.addEventListener('click', () => {
      btns.forEach(b => b.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      topicInp.value = btn.getAttribute('data-topic') || '';
    });
  });

  // پیش‌انتخاب اگر قبلاً انتخاب شده
  const prevTopic = (topicInp.value || '').trim();
  if (prevTopic) {
    btns.forEach(b => {
      if ((b.getAttribute('data-topic') || '') === prevTopic) b.classList.add('is-selected');
    });
  }

  // اعتبارسنجی سمت کلاینت
  const form = document.getElementById('sup-form');
  form.addEventListener('submit', (e) => {
    const name  = document.getElementById('name').value.trim();
    const phone = document.getElementById('phone').value.trim();
    let ok = true;

    document.querySelectorAll('.sup-field__error').forEach(el => el.remove());
    document.querySelectorAll('.sup-field__input').forEach(el => el.classList.remove('is-invalid'));

    if (name.length < 2) {
      e.preventDefault();
      addError('name', 'نام و نام خانوادگی را وارد کنید.');
      ok = false;
    }
    if (!/^09\d{9}$/.test(phone)) {
      e.preventDefault();
      addError('phone', 'شماره موبایل معتبر وارد کنید.');
      ok = false;
    }
  });

  function addError(fieldId, msg) {
    const inp = document.getElementById(fieldId);
    inp.classList.add('is-invalid');
    const span = document.createElement('span');
    span.className = 'sup-field__error';
    span.textContent = msg;
    inp.parentElement.appendChild(span);
  }
})();
</script>

<?php render_footer(); ?>
