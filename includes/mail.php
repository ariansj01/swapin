<?php
/**
 * Email helpers — PHPMailer (SMTP) + EmailJS config for frontend.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function mail_is_placeholder(string $value, array $placeholders = []): bool {
    $value = trim($value);
    if ($value === '') return true;
    $defaults = ['your@gmail.com', 'your-app-password', 'your_public_key', 'your_service_id', 'your_template_id'];
    return in_array($value, array_merge($defaults, $placeholders), true);
}

function mail_is_enabled(): bool {
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) return false;
    if (!defined('MAIL_SMTP_HOST') || MAIL_SMTP_HOST === '') return false;
    if (mail_is_placeholder(MAIL_SMTP_USER ?? '')) return false;
    if (mail_is_placeholder(MAIL_SMTP_PASS ?? '')) return false;
    return true;
}

function emailjs_is_enabled(): bool {
    if (!defined('EMAILJS_ENABLED') || !EMAILJS_ENABLED) return false;
    if (!defined('EMAILJS_PUBLIC_KEY') || mail_is_placeholder(EMAILJS_PUBLIC_KEY)) return false;
    if (!defined('EMAILJS_SERVICE_ID') || mail_is_placeholder(EMAILJS_SERVICE_ID)) return false;
    if (!defined('EMAILJS_CONTACT_TEMPLATE_ID') || mail_is_placeholder(EMAILJS_CONTACT_TEMPLATE_ID)) return false;
    return true;
}

function contact_mail_mode(): string {
    if (emailjs_is_enabled()) return 'emailjs';
    if (mail_is_enabled()) return 'smtp';
    return 'none';
}

/** @return list<string> Human-readable issues (Persian) */
function mail_config_diagnosis(): array {
    $issues = [];

    if (!is_readable(__DIR__ . '/mail_secrets.php')) {
        $issues[] = 'فایل includes/mail_secrets.php وجود ندارد — از mail_secrets.example.php کپی کنید.';
        return $issues;
    }

    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        $issues[] = 'MAIL_ENABLED روی false است — در mail_secrets.php آن را true کنید.';
    }

    if (!class_exists(PHPMailer::class)) {
        $issues[] = 'PHPMailer نصب نیست — در پوشه پروژه: composer install';
    }

    $user = defined('MAIL_SMTP_USER') ? trim(MAIL_SMTP_USER) : '';
    $pass = defined('MAIL_SMTP_PASS') ? trim(MAIL_SMTP_PASS) : '';

    if ($user === '' || mail_is_placeholder($user)) {
        $issues[] = 'MAIL_SMTP_USER هنوز تنظیم نشده (الان: your@gmail.com) — ایمیل Gmail واقعی بگذارید.';
    }
    if ($pass === '' || mail_is_placeholder($pass)) {
        $issues[] = 'MAIL_SMTP_PASS هنوز تنظیم نشده — App Password گmail را وارد کنید (نه رمز اصلی).';
    }

    $from = defined('MAIL_FROM_EMAIL') ? trim(MAIL_FROM_EMAIL) : '';
    if ($from !== '' && $user !== '' && !mail_is_placeholder($user) && $from !== $user && str_contains(MAIL_SMTP_HOST ?? '', 'gmail')) {
        $issues[] = 'برای Gmail، MAIL_FROM_EMAIL باید دقیقاً همان MAIL_SMTP_USER باشد.';
    }

    if (empty($issues) && !mail_is_enabled() && (defined('MAIL_ENABLED') && MAIL_ENABLED)) {
        $issues[] = 'تنظیمات ناقص است — مقادیر placeholder را با اطلاعات واقعی عوض کنید.';
    }

    return $issues;
}

function last_mail_error(): string {
    return $GLOBALS['_last_mail_error'] ?? '';
}

function set_last_mail_error(string $msg): void {
    $GLOBALS['_last_mail_error'] = $msg;
}

function emailjs_public_config(): array {
    if (!emailjs_is_enabled()) {
        return ['enabled' => false];
    }
    return [
        'enabled'    => true,
        'publicKey'  => EMAILJS_PUBLIC_KEY,
        'serviceId'  => EMAILJS_SERVICE_ID,
        'templateId' => EMAILJS_CONTACT_TEMPLATE_ID,
    ];
}

function mail_admin_recipients(): array {
    $emails = [];
    if (defined('MAIL_ADMIN_TO') && MAIL_ADMIN_TO !== '') {
        $emails[] = MAIL_ADMIN_TO;
    }
    if (defined('ADMIN_EMAIL') && ADMIN_EMAIL !== '' && !in_array(ADMIN_EMAIL, $emails, true)) {
        $emails[] = ADMIN_EMAIL;
    }
    try {
        $rows = DB::fetchAll('SELECT email FROM users WHERE role = "admin" AND is_active = 1');
        foreach ($rows as $r) {
            if (!empty($r['email']) && !in_array($r['email'], $emails, true)) {
                $emails[] = $r['email'];
            }
        }
    } catch (Throwable) {}
    return $emails;
}

/**
 * Send an HTML email via PHPMailer SMTP.
 *
 * @param array{reply_to?:string,reply_name?:string,alt_body?:string} $opts
 */
function send_mail(string $to, string $subject, string $bodyHtml, array $opts = []): bool {
    set_last_mail_error('');

    if (!mail_is_enabled()) {
        set_last_mail_error('تنظیمات SMTP در mail_secrets.php فعال یا کامل نیست.');
        return false;
    }
    if (!class_exists(PHPMailer::class)) {
        $msg = 'PHPMailer نصب نیست. دستور composer install را اجرا کنید.';
        set_last_mail_error($msg);
        error_log($msg);
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet  = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = MAIL_SMTP_HOST;
        $mail->Port       = (int)MAIL_SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_SMTP_USER;
        $mail->Password   = MAIL_SMTP_PASS;
        $secure           = defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : 'tls';
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        if (defined('MAIL_SMTP_DEBUG') && MAIL_SMTP_DEBUG) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = static function (string $str, int $level) {
                error_log("PHPMailer [$level]: $str");
            };
        }

        $fromEmail = defined('MAIL_FROM_EMAIL') && MAIL_FROM_EMAIL !== ''
            ? MAIL_FROM_EMAIL
            : MAIL_SMTP_USER;
        $fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME;
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        if (!empty($opts['reply_to'])) {
            $mail->addReplyTo($opts['reply_to'], $opts['reply_name'] ?? '');
        } elseif (defined('MAIL_REPLY_TO') && MAIL_REPLY_TO !== '') {
            $mail->addReplyTo(MAIL_REPLY_TO);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = mail_wrap_html($subject, $bodyHtml);
        $mail->AltBody = $opts['alt_body'] ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml));

        $mail->send();
        return true;
    } catch (MailException $e) {
        set_last_mail_error($e->getMessage());
        error_log('send_mail failed: ' . $e->getMessage());
        return false;
    } catch (Throwable $e) {
        set_last_mail_error($e->getMessage());
        error_log('send_mail error: ' . $e->getMessage());
        return false;
    }
}

function send_mail_to_admins(string $subject, string $bodyHtml, string $link = ''): void {
    if ($link !== '') {
        $bodyHtml .= '<p style="margin-top:16px"><a href="' . h($link) . '">مشاهده در پنل</a></p>';
    }
    foreach (mail_admin_recipients() as $email) {
        send_mail($email, APP_NAME . ' — ' . $subject, $bodyHtml);
    }
}

function send_mail_to_user(string $email, string $subject, string $bodyHtml, string $link = ''): void {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    if ($link !== '') {
        $bodyHtml .= '<p style="margin-top:16px"><a href="' . h($link) . '">مشاهده</a></p>';
    }
    send_mail($email, APP_NAME . ' — ' . $subject, $bodyHtml);
}

function mail_wrap_html(string $title, string $content): string {
    $app = h(APP_NAME);
    $body = nl2br($content);
    if (str_contains($content, '<')) {
        $body = $content;
    }
    return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta charset="UTF-8"></head>
<body style="font-family:Tahoma,Arial,sans-serif;background:#f4f6f8;padding:24px;margin:0">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;border:1px solid #e2e8f0">
    <p style="margin:0 0 16px;font-weight:700;color:#0a2540">{$app}</p>
    <h2 style="margin:0 0 16px;font-size:18px;color:#0a2540">{$title}</h2>
    <div style="color:#334155;line-height:1.7;font-size:15px">{$body}</div>
    <p style="margin:24px 0 0;font-size:12px;color:#94a3b8">این ایمیل از {$app} ارسال شده است.</p>
  </div>
</body>
</html>
HTML;
}

function log_contact_notification(string $name, string $email, string $subject, string $message): void {
    $body = "From: {$name} <{$email}>\n\n{$message}";
    $admins = DB::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1 LIMIT 5');
    if ($admins) {
        foreach ($admins as $a) {
            DB::insert('notifications', [
                'user_id' => (int)$a['id'],
                'type'    => 'contact',
                'title'   => mb_strimwidth('تماس: ' . $subject, 0, 200),
                'body'    => $body,
                'is_read' => 0,
            ]);
        }
        return;
    }
    DB::insert('notifications', [
        'user_id' => 1,
        'type'    => 'contact',
        'title'   => mb_strimwidth('تماس: ' . $subject, 0, 200),
        'body'    => $body,
        'is_read' => 0,
    ]);
}

function handle_contact_submission(string $name, string $email, string $subject, string $message): array {
    $name    = clean($name);
    $email   = trim($email);
    $subject = clean($subject);
    $message = clean($message);
    $errors  = [];

    if (mb_strlen($name) < 2)                          $errors['name']    = 'لطفاً نام خود را وارد کنید.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors['email']   = 'لطفاً یک ایمیل معتبر وارد کنید.';
    if (mb_strlen($subject) < 3)                       $errors['subject'] = 'لطفاً موضوع را وارد کنید.';
    if (mb_strlen($message) < 10)                      $errors['message'] = 'پیام باید حداقل ۱۰ کاراکتر باشد.';

    if ($errors) {
        return ['errors' => $errors];
    }

    log_contact_notification($name, $email, $subject, $message);

    $mode = contact_mail_mode();
    if ($mode === 'none') {
        return [
            'ok'         => true,
            'mail_sent'  => false,
            'mail_error' => 'تنظیمات ایمیل انجام نشده. فایل includes/mail_secrets.php را از روی mail_secrets.example.php بسازید.',
        ];
    }

    if ($mode === 'emailjs') {
        return ['ok' => true, 'mail_sent' => true, 'via' => 'emailjs'];
    }

    $html = '<p><strong>نام:</strong> ' . h($name) . '</p>'
          . '<p><strong>ایمیل:</strong> ' . h($email) . '</p>'
          . '<p><strong>موضوع:</strong> ' . h($subject) . '</p>'
          . '<p><strong>پیام:</strong></p><p>' . nl2br(h($message)) . '</p>';

    $recipients = mail_admin_recipients();
    if (empty($recipients)) {
        return [
            'ok'         => true,
            'mail_sent'  => false,
            'mail_error' => 'هیچ ایمیل مدیر (MAIL_ADMIN_TO / ADMIN_EMAIL) تنظیم نشده.',
        ];
    }

    $mailSent = false;
    $lastErr  = '';
    foreach ($recipients as $adminEmail) {
        if (send_mail($adminEmail, APP_NAME . ' — تماس: ' . $subject, $html, [
            'reply_to'   => $email,
            'reply_name' => $name,
        ])) {
            $mailSent = true;
        } else {
            $lastErr = last_mail_error();
        }
    }

    if (!$mailSent) {
        return [
            'ok'         => true,
            'mail_sent'  => false,
            'mail_error' => $lastErr ?: 'ارسال ایمیل ناموفق بود.',
        ];
    }

    return ['ok' => true, 'mail_sent' => true, 'via' => 'smtp'];
}
