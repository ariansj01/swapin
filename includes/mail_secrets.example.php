<?php

// ─── PHPMailer (SMTP) ─────────────────────────────────────────────────────
define('MAIL_ENABLED', true);

define('MAIL_SMTP_HOST', 'smtp.gmail.com');
define('MAIL_SMTP_PORT', 587);
define('MAIL_SMTP_SECURE', 'tls');
define('MAIL_SMTP_DEBUG', false);

define('MAIL_SMTP_USER', 'ariansj.ir@gmail.com');
define('MAIL_SMTP_PASS', 'lcko iwfo uyxl ovce');
define('MAIL_FROM_EMAIL', 'ariansj.ir@gmail.com');

define('MAIL_FROM_NAME', 'Swapin');
define('MAIL_REPLY_TO', 'support@swapin.ir');
define('MAIL_ADMIN_TO', 'admin@kalabkala.com'); 

// ─── EmailJS (غیر فعاله فعلا) ───────────────────────
define('EMAILJS_ENABLED', false);
define('EMAILJS_PUBLIC_KEY', '');
define('EMAILJS_SERVICE_ID', '');
define('EMAILJS_CONTACT_TEMPLATE_ID', '');