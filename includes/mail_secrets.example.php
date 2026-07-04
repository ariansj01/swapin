<?php

// ─── PHPMailer (SMTP) ─────────────────────────────────────────────────────
// Copy to mail_secrets.php and fill in real values. Never commit mail_secrets.php.

define('MAIL_ENABLED', true);

define('MAIL_SMTP_HOST', 'smtp.example.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_SECURE', 'tls');
define('MAIL_SMTP_DEBUG', false);

define('MAIL_SMTP_USER', 'your-email@example.com');
define('MAIL_SMTP_PASS', 'your-app-password-here');
define('MAIL_FROM_EMAIL', 'your-email@example.com');

define('MAIL_FROM_NAME', 'Swapin');
define('MAIL_REPLY_TO', 'info@swaapin.ir');
define('MAIL_ADMIN_TO', 'admin@example.com');

// ─── EmailJS (غیر فعاله فعلا) ───────────────────────
define('EMAILJS_ENABLED', false);
define('EMAILJS_PUBLIC_KEY', '');
define('EMAILJS_SERVICE_ID', '');
define('EMAILJS_CONTACT_TEMPLATE_ID', '');
