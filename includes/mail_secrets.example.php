<?php

// ─── PHPMailer (SMTP) ─────────────────────────────────────────────────────
// Copy to mail_secrets.php and fill in real values. Never commit mail_secrets.php.

if (!defined('MAIL_ENABLED')) define('MAIL_ENABLED', true);

if (!defined('MAIL_SMTP_HOST')) define('MAIL_SMTP_HOST', 'smtp.example.com');
if (!defined('MAIL_SMTP_PORT')) define('MAIL_SMTP_PORT', 587);
if (!defined('MAIL_SMTP_SECURE')) define('MAIL_SMTP_SECURE', 'tls');
if (!defined('MAIL_SMTP_DEBUG')) define('MAIL_SMTP_DEBUG', false);

if (!defined('MAIL_SMTP_USER')) define('MAIL_SMTP_USER', 'your-email@example.com');
if (!defined('MAIL_SMTP_PASS')) define('MAIL_SMTP_PASS', 'your-app-password-here');
if (!defined('MAIL_FROM_EMAIL')) define('MAIL_FROM_EMAIL', 'your-email@example.com');

if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'Swapin');
if (!defined('MAIL_REPLY_TO')) define('MAIL_REPLY_TO', 'info@swaapin.ir');
if (!defined('MAIL_ADMIN_TO')) define('MAIL_ADMIN_TO', 'admin@example.com');

// ─── EmailJS (غیر فعاله فعلا) ───────────────────────
if (!defined('EMAILJS_ENABLED')) define('EMAILJS_ENABLED', false);
if (!defined('EMAILJS_PUBLIC_KEY')) define('EMAILJS_PUBLIC_KEY', '');
if (!defined('EMAILJS_SERVICE_ID')) define('EMAILJS_SERVICE_ID', '');
if (!defined('EMAILJS_CONTEMPLATE_ID')) define('EMAILJS_CONTEMPLATE_ID', '');
