<?php

// ─── IranPayamak (Pattern SMS) ────────────────────────────────────────────
// Copy to sms_secrets.php and fill in real values. Never commit sms_secrets.php.

define('SMS_ENABLED', true);

define('SMS_IRANPAYAMAK_API_KEY', 'XmuNKUha9fZhz365DH2BjkKIoNyNXhaHX0dqxq2pQW24vXbKXQ');
define('SMS_IRANPAYAMAK_PATTERN_CODE', 'mMR4lJx2Jq');
define('SMS_IRANPAYAMAK_LINE_NUMBER', '50002178584000');
define('SMS_NUMBER_FORMAT', 'english');

// این نگاشت باید با متغیرهای پترن شما در پنل ایران‌پیامک یکی باشد.
define('SMS_OTP_ATTRIBUTE_MAP', [
    'code' => '{code}',
    'minutes' => '{minutes}',
]);
