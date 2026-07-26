<?php
$sepSecrets = require __DIR__ . '/sep_secrets.php';

class SEPPayment {
    private const TOKEN_URL = 'https://sep.shaparak.ir/onlinepg/onlinepg';
    private const PAYMENT_URL = 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
    private const VERIFY_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';
    private const REVERSE_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction';

    private const AMOUNT_UNIT_IS_RIALS = true;

    private static function toRials(int $tomanAmount): int {
        return self::AMOUNT_UNIT_IS_RIALS ? (int)($tomanAmount * 10) : $tomanAmount;
    }

    public static function fromRials(int $rialAmount): int {
        return self::AMOUNT_UNIT_IS_RIALS ? (int)round($rialAmount / 10) : $rialAmount;
    }

    public static function getToken(int $amount, string $resNum, string $redirectUrl, ?string $cellNumber = null): ?array {
        $sepAmount = self::toRials($amount);
        $data = [
            'Action' => 'Token',
            'TerminalId' => self::terminalId(),
            'Amount' => $sepAmount,
            'ResNum' => $resNum,
            'RedirectUrl' => $redirectUrl,
        ];

        if ($cellNumber) {
            $cell = preg_replace('/[^0-9]/', '', (string)$cellNumber);
            if (str_starts_with($cell, '0')) {
                $cell = '98' . substr($cell, 1);
            } elseif (strlen($cell) === 10 && str_starts_with($cell, '9')) {
                $cell = '98' . $cell;
            }
            $data['CellNumber'] = $cell;
        }

        $response = self::sendRequest(self::TOKEN_URL, $data);
        $status = $response['status'] ?? null;
        $token  = $response['token'] ?? null;
        if ($response && $status == 1 && !empty($token)) {
            swapin_debug_log('sep_gettoken_ok', [
                'res_num' => $resNum,
                'amount_toman' => $amount,
                'amount_sep' => $sepAmount,
                'redirect_url' => $redirectUrl,
            ]);
            return ['token' => $token, 'sep_amount' => $sepAmount];
        }

        swapin_debug_log('sep_gettoken_fail', [
            'res_num' => $resNum,
            'amount_toman' => $amount,
            'amount_sep' => $sepAmount,
            'redirect_url' => $redirectUrl,
            'sep_status' => $status,
            'sep_errorCode' => $response['errorCode'] ?? null,
            'sep_errorMessage' => $response['errorMessage'] ?? null,
            'sep_description' => $response['description'] ?? null,
            'sep_resultCode' => $response['ResultCode'] ?? null,
            'sep_raw' => is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return null;
    }

    public static function verifyTransaction(string $refNum): ?array {
        $data = [
            'RefNum' => $refNum,
            'TerminalNumber' => (int)self::terminalId(),
        ];

        $response = self::sendRequest(self::VERIFY_URL, $data);
        $success  = $response['Success'] ?? null;

        if ($response && $success === true) {
            $txn = $response['TransactionDetail'] ?? $response;
            $org = (int)($txn['OrginalAmount'] ?? $txn['amount'] ?? 0);
            if ($org > 0) {
                $txn['OrginalAmount'] = $org;
                $txn['_sep_amount_rial'] = $org;
                $txn['_amount_toman'] = self::fromRials($org);
            }
            swapin_debug_log('sep_verify_ok', [
                'ref_num' => $refNum,
                'amount_rial' => $org,
                'amount_toman' => $txn['_amount_toman'] ?? null,
                'rrn' => $txn['RRN'] ?? null,
                'trace_no' => $txn['TraceNo'] ?? null,
                'result_code' => $response['ResultCode'] ?? null,
            ]);
            return [
                'success' => true,
                'data' => $txn,
                'result_code' => $response['ResultCode'] ?? null,
                'result_desc' => $response['ResultDescription'] ?? null,
            ];
        }

        swapin_debug_log('sep_verify_fail', [
            'ref_num' => $refNum,
            'success' => $success,
            'result_code' => $response['ResultCode'] ?? null,
            'result_desc' => $response['ResultDescription'] ?? null,
            'sep_raw' => is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return null;
    }

    public static function reverseTransaction(string $refNum): ?array {
        $data = [
            'RefNum' => $refNum,
            'TerminalNumber' => (int)self::terminalId(),
        ];

        $response = self::sendRequest(self::REVERSE_URL, $data);

        if ($response && isset($response['Success']) && $response['Success'] === true) {
            return [
                'success' => true,
                'data' => $response['TransactionDetail'] ?? $response,
                'result_code' => $response['ResultCode'] ?? null,
                'result_desc' => $response['ResultDescription'] ?? null,
            ];
        }

        swapin_debug_log('sep_reverse_fail', [
            'ref_num' => $refNum,
            'sep_raw' => is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE) : null,
        ]);

        return null;
    }

    public static function getPaymentForm(string $token): string {
        return '
            <form id="sepPaymentForm" method="post" action="'.self::PAYMENT_URL.'" style="display:none;">
                <input type="hidden" name="Token" value="'.htmlspecialchars($token).'">
            </form>
            <script>document.getElementById("sepPaymentForm").submit();</script>
        ';
    }

    private static function sendRequest(string $url, array $data): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($error || $errno !== 0 || $httpCode >= 400) {
            swapin_debug_log('sep_request_transport_error', [
                'url' => $url,
                'curl_errno' => $errno,
                'curl_error' => $error,
                'http_code' => $httpCode,
                'response_len' => strlen((string)$response),
                'response_preview' => mb_substr((string)$response, 0, 500),
            ]);
            return null;
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            swapin_debug_log('sep_request_invalid_json', [
                'url' => $url,
                'response_preview' => mb_substr((string)$response, 0, 500),
            ]);
            return null;
        }

        return $decoded;
    }

    public static function generateResNum(): string {
        return uniqid('SWP_', true) . '_' . time();
    }

    private static function terminalId(): string {
        global $sepSecrets;

        if (is_array($sepSecrets) && !empty($sepSecrets['terminal_id'])) {
            return (string)$sepSecrets['terminal_id'];
        }

        if (defined('SEP_TERMINAL_ID') && SEP_TERMINAL_ID !== '') {
            return (string) SEP_TERMINAL_ID;
        }

        throw new RuntimeException('SEP terminal ID is not configured.');
    }
}
