<?php
require_once __DIR__ . '/sep_secrets.php';

class SEPPayment {
    private const TOKEN_URL = 'https://sep.shaparak.ir/onlinepg/onlinepg';
    private const PAYMENT_URL = 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
    private const VERIFY_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';
    private const REVERSE_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction';
    
    // Terminal ID
    public const TERMINAL_ID = SEP_TERMINAL_ID;

    public static function getToken(int $amount, string $resNum, string $redirectUrl, ?string $cellNumber = null): ?array {
        $data = [
            'Action' => 'Token',
            'TerminalId' => self::TERMINAL_ID,
            'Amount' => $amount,
            'ResNum' => $resNum,
            'RedirectUrl' => $redirectUrl,
        ];

        if ($cellNumber) {
            $data['CellNumber'] = $cellNumber;
        }

        $response = self::sendRequest(self::TOKEN_URL, $data);

        if ($response && isset($response['status']) && $response['status'] == 1) {
            return ['token' => $response['token']];
        }

        return null;
    }

    public static function verifyTransaction(string $refNum): ?array {
        $data = [
            'RefNum' => $refNum,
            'TerminalNumber' => (int)self::TERMINAL_ID,
        ];

        $response = self::sendRequest(self::VERIFY_URL, $data);

        if ($response && isset($response['Success']) && $response['Success'] === true) {
            return [
                'success' => true,
                'data' => $response['TransactionDetail'] ?? $response,
                'result_code' => $response['ResultCode'] ?? null,
                'result_desc' => $response['ResultDescription'] ?? null,
            ];
        }

        return null;
    }

    public static function reverseTransaction(string $refNum): ?array {
        $data = [
            'RefNum' => $refNum,
            'TerminalNumber' => (int)self::TERMINAL_ID,
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function generateResNum(): string {
        return uniqid('SWP_', true) . '_' . time();
    }
}
