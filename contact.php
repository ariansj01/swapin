<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user     = auth_user();
$success  = false;
$mailWarn = '';
$errors   = [];
$vals     = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
$mailMode = contact_mail_mode();
$useEmailJs = $mailMode === 'emailjs';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$useEmailJs) {
    csrf_verify_or_fail();
    rate_limit_ip_or_fail('contact_form', 10, 3600);
    $result = handle_contact_submission(
        $_POST['name'] ?? '',
        $_POST['email'] ?? '',
        $_POST['subject'] ?? '',
        $_POST['message'] ?? ''
    );
    if (isset($result['errors'])) {
        $errors = $result['errors'];
        $vals['name']    = clean($_POST['name'] ?? '');
        $vals['email']   = trim($_POST['email'] ?? '');
        $vals['subject'] = clean($_POST['subject'] ?? '');
        $vals['message'] = clean($_POST['message'] ?? '');
    } else {
        $success = true;
        $vals    = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
        if (empty($result['mail_sent'])) {
            $mailWarn = $result['mail_error'] ?? 'ایمیل ارسال نشد.';
        }
    }
}

if (!$_POST && $user) {
    $vals['name']  = $user['name'];
    $vals['email'] = $user['email'];
}

$emailJsConfig = emailjs_public_config();
$secretsMissing = !is_readable(__DIR__ . '/includes/mail_secrets.php');

render_head('تماس با ما', 'با تیم ' . APP_NAME . ' در ارتباط باشید.', [
    'canonical' => APP_URL . '/contact.php',
    'json_ld'   => seo_json_ld_organization(),
]);
render_navbar($user);
?>

<main id="main-content" class="section-sm">
  <div class="container-sm">

    <div style="text-align:center;padding:var(--sp-8) 0 var(--sp-6)">
      <div style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:var(--primary);margin-bottom:var(--sp-4)">
        <i class="bi bi-envelope-paper" style="font-size:1.5rem;color:#fff"></i>
      </div>
      <h1 style="font-size:1.75rem;margin:0 0 var(--sp-2)">تماس با ما</h1>
      <p style="color:var(--text-muted)">سؤال، پیشنهاد یا مشکلی دارید؟ خوشحال می‌شویم از شما بشنویم.</p>
    </div>

    <?php if ($secretsMissing && !app_is_production()): ?>
    <div class="alert alert-warning mb-6">
      <i class="bi bi-exclamation-triangle"></i>
      <div>
        <strong>تنظیمات ایمیل وجود ندارد.</strong>
        فایل <code>includes/mail_secrets.php</code> را از روی
        <code>includes/mail_secrets.example.php</code> بسازید و SMTP یا EmailJS را فعال کنید.
      </div>
    </div>
    <?php elseif ($secretsMissing && app_is_production()): ?>
    <div class="alert alert-warning mb-6">
      <i class="bi bi-exclamation-triangle"></i>
      <div><strong>ارسال ایمیل موقتاً غیرفعال است.</strong> پیام شما در سیستم ثبت می‌شود.</div>
    </div>
    <?php elseif ($mailMode === 'none'): ?>
    <div class="alert alert-warning mb-6">
      <i class="bi bi-exclamation-triangle"></i>
      <div>
        <strong>ارسال ایمیل غیرفعال است.</strong>
        در <code>mail_secrets.php</code> مقدار <code>MAIL_ENABLED</code> یا <code>EMAILJS_ENABLED</code> را true کنید
        و اطلاعات SMTP / EmailJS را وارد کنید.
      </div>
    </div>
    <?php endif; ?>

    <div id="contact-alert" class="alert alert-success mb-6" style="display:none">
      <i class="bi bi-check-circle-fill"></i>
      <div><strong>پیام ارسال شد!</strong> در اسرع وقت پاسخ می‌دهیم.</div>
    </div>

    <?php if ($success && !$mailWarn): ?>
    <div class="alert alert-success mb-6">
      <i class="bi bi-check-circle-fill"></i>
      <div><strong>پیام ارسال شد!</strong> ایمیل هم برای تیم ارسال شد.</div>
    </div>
    <?php elseif ($success && $mailWarn): ?>
    <div class="alert alert-warning mb-6">
      <i class="bi bi-exclamation-triangle"></i>
      <div>
        <strong>پیام در سیستم ثبت شد</strong> اما ایمیل ارسال نشد.<br>
        <span class="fs-sm"><?= h(safe_mail_error($mailWarn)) ?></span>
      </div>
    </div>
    <?php endif; ?>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-7)">
        <form method="POST" id="contact-form" novalidate
              data-emailjs="<?= $useEmailJs ? '1' : '0' ?>"
              data-api-url="<?= h(APP_URL . '/api/contact.php') ?>">
          <?= csrf_field() ?>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4)">
            <div class="form-group">
              <label class="form-label" for="name">نام شما <span class="required">*</span></label>
              <input type="text" id="name" name="name"
                     class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                     value="<?= h($vals['name']) ?>" placeholder="نام و نام خانوادگی" required>
              <?php if (isset($errors['name'])): ?>
              <div class="invalid-feedback"><?= h($errors['name']) ?></div>
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label class="form-label" for="email">ایمیل <span class="required">*</span></label>
              <input type="email" id="email" name="email"
                     class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                     value="<?= h($vals['email']) ?>" placeholder="you@example.com" required>
              <?php if (isset($errors['email'])): ?>
              <div class="invalid-feedback"><?= h($errors['email']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="subject">موضوع <span class="required">*</span></label>
            <input type="text" id="subject" name="subject"
                   class="form-control <?= isset($errors['subject']) ? 'is-invalid' : '' ?>"
                   value="<?= h($vals['subject']) ?>" placeholder="موضوع پیام چیست؟" required>
            <?php if (isset($errors['subject'])): ?>
            <div class="invalid-feedback"><?= h($errors['subject']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="message">پیام <span class="required">*</span></label>
            <textarea id="message" name="message" rows="6"
                      class="form-control <?= isset($errors['message']) ? 'is-invalid' : '' ?>"
                      placeholder="بگویید چطور می‌توانیم کمک کنیم…" required><?= h($vals['message']) ?></textarea>
            <?php if (isset($errors['message'])): ?>
            <div class="invalid-feedback"><?= h($errors['message']) ?></div>
            <?php endif; ?>
          </div>

          <div id="contact-form-error" class="alert alert-danger mb-4" style="display:none"></div>

          <button type="submit" class="btn btn-primary w-100" id="contact-submit">
            <i class="bi bi-send"></i> ارسال پیام
          </button>

        </form>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-4);margin-bottom:var(--sp-10)">
      <div class="card">
        <div class="card-body" style="text-align:center;padding:var(--sp-5)">
          <i class="bi bi-envelope" style="font-size:1.5rem;color:var(--primary);display:block;margin-bottom:var(--sp-3)"></i>
          <div style="font-weight:600;margin-bottom:4px">ایمیل</div>
          <a href="mailto:info@swaapin.ir" class="fs-sm" style="color:var(--text-secondary)">info@swaapin.ir</a>
        </div>
      </div>
      <div class="card">
        <div class="card-body" style="text-align:center;padding:var(--sp-5)">
          <i class="bi bi-clock" style="font-size:1.5rem;color:var(--primary);display:block;margin-bottom:var(--sp-3)"></i>
          <div style="font-weight:600;margin-bottom:4px">زمان پاسخ‌گویی</div>
          <span class="fs-sm" style="color:var(--text-secondary)">معمولاً ظرف ۲۴ ساعت</span>
        </div>
      </div>
    </div>

  </div>
</main>

<?php if ($useEmailJs): ?>
<script type="application/json" id="emailjs-config"><?= json_encode($emailJsConfig, JSON_UNESCAPED_UNICODE) ?></script>
<script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
<script src="<?= APP_URL ?>/src/js/emailjs-contact.js"></script>
<?php endif; ?>

<?php render_footer(); ?>
