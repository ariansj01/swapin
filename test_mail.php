<?php
/**
 * SMTP test page — localhost only.
 * Visit: /test_mail.php
 */
require_once __DIR__ . '/includes/config.php';

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1'], true)
    || str_starts_with($host, 'localhost:')
    || str_starts_with($host, '127.0.0.1:');

header('Content-Type: text/html; charset=utf-8');

if (!$isLocal) {
    http_response_code(403);
    echo '<p>فقط روی localhost.</p>';
    exit;
}

$result   = null;
$diagnosis = mail_config_diagnosis();
$to       = defined('MAIL_ADMIN_TO') && MAIL_ADMIN_TO !== '' ? MAIL_ADMIN_TO : ADMIN_EMAIL;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? $to);
    if ($to && mail_is_enabled()) {
        $ok = send_mail($to, APP_NAME . ' — تست SMTP', '<p>این یک ایمیل تست از Swapin است.</p><p>زمان: ' . date('Y-m-d H:i:s') . '</p>');
        $result = $ok ? 'success' : 'fail';
    } else {
        $result = 'not_configured';
    }
}

$secretsFile = is_readable(__DIR__ . '/includes/mail_secrets.php');
$passSet     = defined('MAIL_SMTP_PASS') && MAIL_SMTP_PASS !== '' && !mail_is_placeholder(MAIL_SMTP_PASS);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تست ایمیل — Swapin</title>
<style>
  body { font-family: Tahoma, sans-serif; max-width: 680px; margin: 40px auto; padding: 0 16px; line-height: 1.6; }
  .ok { color: #059669; } .err { color: #dc2626; } .warn { color: #d97706; }
  code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  pre { background: #0f172a; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; }
  table { width: 100%; border-collapse: collapse; margin: 16px 0; }
  td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  td:first-child { font-weight: 600; width: 38%; }
  ol li { margin-bottom: 8px; }
  .box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 16px; margin: 16px 0; }
</style>
</head>
<body>
<h1>تست ارسال ایمیل (SMTP)</h1>

<?php if ($result === 'success'): ?>
<p class="ok"><strong>✓ ایمیل با موفقیت ارسال شد.</strong> صندوق <code><?= h($to) ?></code> را بررسی کنید (پوشه Spam هم).</p>
<?php elseif ($result === 'fail'): ?>
<p class="err"><strong>✗ ارسال ناموفق.</strong><br><?= h(last_mail_error()) ?></p>
<?php elseif ($result === 'not_configured'): ?>
<p class="warn"><strong>SMTP هنوز آماده ارسال نیست.</strong> مراحل زیر را انجام دهید.</p>
<?php endif; ?>

<?php if (!empty($diagnosis)): ?>
<div class="box">
  <strong>کارهایی که باید انجام دهید:</strong>
  <ol>
    <?php foreach ($diagnosis as $item): ?>
    <li><?= h($item) ?></li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<h2>وضعیت فعلی</h2>
<table>
  <tr><td>mail_secrets.php</td><td><?= $secretsFile ? '<span class="ok">موجود</span>' : '<span class="err">وجود ندارد</span>' ?></td></tr>
  <tr><td>PHPMailer</td><td><?= class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? '<span class="ok">نصب</span>' : '<span class="err">composer install</span>' ?></td></tr>
  <tr><td>MAIL_ENABLED</td><td><?= defined('MAIL_ENABLED') && MAIL_ENABLED ? '<span class="ok">true</span>' : '<span class="err">false</span>' ?></td></tr>
  <tr><td>SMTP User</td><td><code><?= h(defined('MAIL_SMTP_USER') && MAIL_SMTP_USER !== '' ? MAIL_SMTP_USER : '(خالی)') ?></code></td></tr>
  <tr><td>SMTP Pass</td><td><?= $passSet ? '<span class="ok">تنظیم شده</span>' : '<span class="err">خالی / placeholder</span>' ?></td></tr>
  <tr><td>SMTP فعال</td><td><?= mail_is_enabled() ? '<span class="ok">بله ✓</span>' : '<span class="err">خیر</span>' ?></td></tr>
  <tr><td>حالت تماس</td><td><code><?= h(contact_mail_mode()) ?></code></td></tr>
</table>

<h2>فایل را این‌طور پر کنید</h2>
<p>فایل <code>includes/mail_secrets.php</code> را باز کنید:</p>
<pre>define('MAIL_ENABLED', true);

define('MAIL_SMTP_USER', 'YOUR@gmail.com');      // ایمیل Gmail
define('MAIL_SMTP_PASS', 'xxxx xxxx xxxx xxxx'); // App Password
define('MAIL_FROM_EMAIL', 'YOUR@gmail.com');     // همان ایمیل
define('MAIL_ADMIN_TO', 'YOUR@gmail.com');       // گیرنده پیام تماس</pre>

<h3>ساخت App Password در Gmail</h3>
<ol>
  <li>Two-Step Verification را در Google فعال کن</li>
  <li>برو به <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a></li>
  <li>App Password برای «Mail» بساز (۱۶ کاراکتر)</li>
  <li>در <code>MAIL_SMTP_PASS</code> بگذار (با یا بدون فاصله)</li>
</ol>

<form method="POST" style="margin-top:24px">
  <label>ارسال تست به:<br>
    <input type="email" name="to" value="<?= h($to) ?>" style="width:100%;padding:8px;margin-top:8px" required>
  </label>
  <p style="margin-top:12px">
    <button type="submit" style="padding:10px 20px;cursor:pointer" <?= mail_is_enabled() ? '' : 'disabled' ?>>
      ارسال ایمیل تست
    </button>
    <a href="<?= APP_URL ?>/contact.php" style="margin-right:12px">فرم تماس</a>
  </p>
  <?php if (!mail_is_enabled()): ?>
  <p class="warn fs-sm">دکمه تست بعد از پر کردن SMTP User و Pass فعال می‌شود.</p>
  <?php endif; ?>
</form>
</body>
</html>
