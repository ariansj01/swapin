<?php
/**
 * Mail configuration — copy to mail_secrets.php and fill in your values.
 * Never commit mail_secrets.php to version control.
 *
 * Gmail: https://myaccount.google.com/apppasswords
 * MAIL_FROM_EMAIL must match MAIL_SMTP_USER for Gmail.
 */

// ─── PHPMailer (SMTP) ─────────────────────────────────────────────────────
define('MAIL_ENABLED', true);  // ← true برای فعال‌سازی

define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_SECURE', 'tls'); // tls | ssl | ''
define('MAIL_SMTP_DEBUG', false);  // true = log in php_error_log (dev only)

// ↓↓↓ این سه خط را با اطلاعات واقعی Gmail خودت پر کن ↓↓↓
define('MAIL_SMTP_USER', '');           // مثال: ali@gmail.com
define('MAIL_SMTP_PASS', '');           // App Password 16 کاراکتری (نه رمز ورود!)
define('MAIL_FROM_EMAIL', '');          // همان MAIL_SMTP_USER

define('MAIL_FROM_NAME', 'Swapin');
define('MAIL_REPLY_TO', 'support@swapin.ir');
define('MAIL_ADMIN_TO', 'admin@kalabkala.com'); // ایمیلی که پیام تماس را می‌گیرد

// ─── EmailJS (optional — contact form from browser) ───────────────────────
define('EMAILJS_ENABLED', false);
define('EMAILJS_PUBLIC_KEY', '');
define('EMAILJS_SERVICE_ID', '');
define('EMAILJS_CONTACT_TEMPLATE_ID', '');

/*
 * EmailJS template variables:
 *   {{from_name}} {{from_email}} {{subject}} {{message}} {{reply_to}} {{to_name}}
 */
