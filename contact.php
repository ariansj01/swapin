<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';

$user    = auth_user();
$success = false;
$errors  = [];
$vals    = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vals['name']    = clean($_POST['name']    ?? '');
    $vals['email']   = trim($_POST['email']    ?? '');
    $vals['subject'] = clean($_POST['subject'] ?? '');
    $vals['message'] = clean($_POST['message'] ?? '');

    if (strlen($vals['name']) < 2)                          $errors['name']    = 'لطفاً نام خود را وارد کنید.';
    if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) $errors['email']   = 'لطفاً یک ایمیل معتبر وارد کنید.';
    if (strlen($vals['subject']) < 3)                       $errors['subject'] = 'لطفاً موضوع را وارد کنید.';
    if (strlen($vals['message']) < 10)                      $errors['message'] = 'پیام باید حداقل ۱۰ کاراکتر باشد.';

    if (empty($errors)) {
        // Store as notification to admin (user id=1) — replace with real email in production
        $body = "From: {$vals['name']} <{$vals['email']}>\n\n{$vals['message']}";
        DB::insert('notifications', [
            'user_id'  => 1,
            'type'     => 'contact',
            'title'    => mb_strimwidth('تماس: ' . $vals['subject'], 0, 200),
            'body'     => $body,
            'is_read'  => 0,
        ]);
        $success = true;
        $vals    = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
    }
}

// Pre-fill for logged-in users
if (!$_POST && $user) {
    $vals['name']  = $user['name'];
    $vals['email'] = $user['email'];
}

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

    <?php if ($success): ?>
    <div class="alert alert-success mb-6">
      <i class="bi bi-check-circle-fill"></i>
      <div><strong>پیام ارسال شد!</strong> در اسرع وقت پاسخ می‌دهیم.</div>
    </div>
    <?php endif; ?>

    <div class="card mb-6">
      <div class="card-body" style="padding:var(--sp-7)">
        <form method="POST" novalidate>

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

          <button type="submit" class="btn btn-primary w-100">
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
          <a href="mailto:support@swapin.ir" class="fs-sm" style="color:var(--text-secondary)">support@swapin.ir</a>
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

<?php render_footer(); ?>
