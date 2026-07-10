<?php
/**
 * SMS helpers — IranPayamak pattern API for OTP delivery.
 */

function sms_is_placeholder(string $value, array $placeholders = []): bool {
    $value = trim($value);
    if ($value === '') return true;
    $defaults = ['YOUR_API_KEY', 'YOUR_PATTERN_CODE', 'YOUR_LINE_NUMBER', 'your-iranpayamak-api-key'];
    return in_array($value, array_merge($defaults, $placeholders), true);
}

function sms_is_enabled(): bool {
    // Debug log for checking SMS configuration status
    if (function_exists('swapin_debug_log')) {
        swapin_debug_log('sms_is_enabled_check', [
            'defined_SMS_ENABLED' => defined('SMS_ENABLED'),
            'SMS_ENABLED_value' => defined('SMS_ENABLED') ? (bool)SMS_ENABLED : 'N/A',
            'defined_SMS_IRANPAYAMAK_API_KEY' => defined('SMS_IRANPAYAMAK_API_KEY'),
            'SMS_IRANPAYAMAK_API_KEY_value_masked' => defined('SMS_IRANPAYAMAK_API_KEY') ? substr(SMS_IRANPAYAMAK_API_KEY, 0, 5) . '...' : 'N/A',
            'defined_SMS_IRANPAYAMAK_PATTERN_CODE' => defined('SMS_IRANPAYAMAK_PATTERN_CODE'),
            'SMS_IRANPAYAMAK_PATTERN_CODE_value' => defined('SMS_IRANPAYAMAK_PATTERN_CODE') ? SMS_IRANPAYAMAK_PATTERN_CODE : 'N/A',
            'defined_SMS_IRANPAYAMAK_LINE_NUMBER' => defined('SMS_IRANPAYAMAK_LINE_NUMBER'),
            'SMS_IRANPAYAMAK_LINE_NUMBER_value' => defined('SMS_IRANPAYAMAK_LINE_NUMBER') ? SMS_IRANPAYAMAK_LINE_NUMBER : 'N/A',
            'app_is_production_status' => function_exists('app_is_production') ? app_is_production() : 'N/A',
        ]);
    }

    if (!defined('SMS_ENABLED') || !SMS_ENABLED) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('sms_is_enabled_fail', ['reason' => 'SMS_ENABLED not defined or false']);
        }
        return false;
    }
    if (!defined('SMS_IRANPAYAMAK_API_KEY') || sms_is_placeholder((string) SMS_IRANPAYAMAK_API_KEY)) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('sms_is_enabled_fail', ['reason' => 'SMS_IRANPAYAMAK_API_KEY not defined or placeholder']);
        }
        return false;
    }
    if (!defined('SMS_IRANPAYAMAK_PATTERN_CODE') || sms_is_placeholder((string) SMS_IRANPAYAMAK_PATTERN_CODE)) {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('sms_is_enabled_fail', ['reason' => 'SMS_IRANPAYAMAK_PATTERN_CODE not defined or placeholder']);
        }
        return false;
    }
    if (!defined('SMS_IRANPAYAMAK_LINE_NUMBER') || trim((string) SMS_IRANPAYAMAK_LINE_NUMBER) === '') {
        if (function_exists('swapin_debug_log')) {
            swapin_debug_log('sms_is_enabled_fail', ['reason' => 'SMS_IRANPAYAMAK_LINE_NUMBER not defined or empty']);
        }
        return false;
    }
    if (function_exists('swapin_debug_log')) {
        swapin_debug_log('sms_is_enabled_success', ['reason' => 'All SMS checks passed']);
    }
    return true;
}

function last_sms_error(): string {
    return $GLOBALS['_last_sms_error'] ?? '';
}

function set_last_sms_error(string $msg): void {
    $GLOBALS['_last_sms_error'] = $msg;
}

function safe_sms_error(?string $detail): string {
    if (function_exists('app_is_production') && !app_is_production()) {
        return $detail ?: 'ارسال پیامک ناموفق بود.';
    }
    return 'ارسال کد یکبارمصرف ناموفق بود. لطفاً کمی بعد دوباره تلاش کنید.';
}

function sms_mask_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '' || strlen($digits) <= 4) {
        return '—';
    }
    return str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function sms_normalize_recipient(string $phone): string {
    $value = trim($phone);
    $digits = preg_replace('/\D+/', '', $value);

    if (str_starts_with($digits, '0098') && strlen($digits) >= 12) {
        return '0' . substr($digits, 4);
    }
    if (str_starts_with($digits, '98') && strlen($digits) >= 12) {
        return '0' . substr($digits, 2);
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '0' . $digits;
    }
    return $digits !== '' ? $digits : $value;
}

function sms_log(string $message, array $context = []): void {
    if (function_exists('swapin_debug_log')) {
        swapin_debug_log($message, $context);
        return;
    }

    $payload = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    error_log('[sms] ' . $message . $payload);
}

function sms_otp_attributes(string $code): array {
    $minutes = (string) max(1, (int) ceil((defined('OTP_EXPIRE') ? OTP_EXPIRE : 600) / 60));
    $map = (defined('SMS_OTP_ATTRIBUTE_MAP') && is_array(SMS_OTP_ATTRIBUTE_MAP))
        ? SMS_OTP_ATTRIBUTE_MAP
        : ['var1' => '{code}', 'var2' => '{minutes}'];

    $attributes = [];
    foreach ($map as $key => $template) {
        $attributes[(string) $key] = strtr((string) $template, [
            '{code}' => $code,
            '{minutes}' => $minutes,
            '{app_name}' => defined('APP_NAME') ? APP_NAME : 'Swapin',
        ]);
    }
    return $attributes;
}

function send_pattern_sms(string $phone, array $attributes, ?string $patternCode = null): bool {
    set_last_sms_error('');

    if (!sms_is_enabled()) {
        set_last_sms_error('تنظیمات پیامک در sms_secrets.php فعال یا کامل نیست.');
        return false;
    }
    if (!function_exists('curl_init')) {
        set_last_sms_error('افزونه cURL در PHP فعال نیست.');
        return false;
    }

    $recipient = sms_normalize_recipient($phone);
    $payload = [
        'code' => $patternCode ?: SMS_IRANPAYAMAK_PATTERN_CODE,
        'attributes' => $attributes,
        'recipient' => $recipient,
        'line_number' => (string) SMS_IRANPAYAMAK_LINE_NUMBER,
        'number_format' => defined('SMS_NUMBER_FORMAT') ? SMS_NUMBER_FORMAT : 'english',
    ];

    $ch = curl_init('https://api.iranpayamak.com/ws/v1/sms/pattern');
    if ($ch === false) {
        set_last_sms_error('آماده‌سازی اتصال پیامک ناموفق بود.');
        return false;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Api-Key: ' . SMS_IRANPAYAMAK_API_KEY,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        set_last_sms_error($curlErr !== '' ? $curlErr : 'خطای نامشخص در ارتباط با سرویس پیامک.');
        sms_log('sms-curl-failed', [
            'phone' => sms_mask_phone($phone),
            'error' => last_sms_error(),
        ]);
        return false;
    }

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded)
            ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'HTTP ' . $httpCode)
            : 'HTTP ' . $httpCode;
        set_last_sms_error('ارسال پیامک رد شد: ' . $message);
        sms_log('sms-http-failed', [
            'phone' => sms_mask_phone($phone),
            'status' => $httpCode,
            'response' => mb_strimwidth($response, 0, 300, '...'),
        ]);
        return false;
    }

    if (is_array($decoded)) {
        $hasErrors = !empty($decoded['errors']) || (!empty($decoded['success']) && $decoded['success'] === false);
        $status = $decoded['status'] ?? null;
        $statusFailed = is_numeric($status) && !in_array((int) $status, [1, 200, 201], true);

        if ($hasErrors || $statusFailed) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'ارسال پیامک ناموفق بود.');
            set_last_sms_error($message);
            sms_log('sms-api-failed', [
                'phone' => sms_mask_phone($phone),
                'status' => $status,
                'message' => $message,
            ]);
            return false;
        }
    }

    return true;
}

function send_otp_sms(string $phone, string $code): bool {
    return send_pattern_sms($phone, sms_otp_attributes($code));
}
