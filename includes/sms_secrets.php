<?php

// ─── IranPayamak (Pattern SMS) ────────────────────────────────────────────
// Copy to sms_secrets.php and fill in real values. Never commit sms_secrets.php.
define('SMS_ENABLED', true);


define('SMS_ENABLED_FROM_SECRETS', true); // اگر می‌خواهید پیامک فعال باشد، این را true کنید
define('SMS_IRANPAYAMAK_API_KEY_FROM_SECRETS', 'XmuNKUha9fZhz365DH2BjkKIoNyNXhaHX0dqxq2pQW24vXbKXQ');
define('SMS_IRANPAYAMAK_PATTERN_CODE_FROM_SECRETS', 'mMR4lJx2Jq');
define('SMS_IRANPAYAMAK_LINE_NUMBER_FROM_SECRETS', '50002178584000');
define('SMS_NUMBER_FORMAT_FROM_SECRETS', 'english');


define('SMS_IRANPAYAMAK_API_KEY', 'XmuNKUha9fZhz365DH2BjkKIoNyNXhaHX0dqxq2pQW24vXbKXQ');
define('SMS_IRANPAYAMAK_PATTERN_CODE', 'mMR4lJx2Jq');
define('SMS_IRANPAYAMAK_LINE_NUMBER', '50002178584000');
define('SMS_NUMBER_FORMAT', 'english');

define('SMS_OTP_ATTRIBUTE_MAP', [
    'code' => '{code}',
    'minutes' => '{minutes}',
]);
