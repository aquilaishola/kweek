<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/currency.php';

/**
 * A payout call Bachs definitely refused (or that we never got as far as
 * sending). Nothing was created on their side, so it is safe to give the
 * user their money back.
 */
class BachsPayoutRejected extends RuntimeException {
    public string $errorCode;
    public function __construct(string $message, string $errorCode = '') {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

/**
 * A payout call whose outcome we can't be sure of (network error, 5xx,
 * request still in flight). The payout MAY exist, so do NOT refund. Leave
 * it as "processing" and let the payout.paid / payout.failed webhook (or
 * a manual check) settle it.
 */
class BachsPayoutUncertain extends RuntimeException {}

class Nomba {

    private string $secretKey;
    private string $baseUrl;

    public function __construct() {
        $this->secretKey = BACHS_SECRET_KEY;
        $this->baseUrl   = BACHS_BASE_URL;
    }

    private function request(string $method, string $path, array $body = [], array $extraHeaders = []): array {
        $url = $this->baseUrl . $path;
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->secretKey,
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'GET' && !empty($body)) {
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($body));
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("Bachs cURL error: $err");

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Bachs non-JSON response ($code): $raw");
        }

        if ($code >= 400) {
            $msg = $decoded['detail'] ?? $decoded['error']['message'] ?? 'Unknown error';
            error_log("Bachs error $code on $method $path — $msg — body: $raw");
        }

        $decoded['_http_code'] = $code;
        return $decoded;
    }

    public function createCheckout(array $data): array {
        $redirect = $data['redirectUrl'] ?? (SITE_URL . '/receipt/' . urlencode($data['orderRef'] ?? ''));
        $cancel   = $data['cancelUrl'] ?? $redirect;

        // The currency travels with the order. If the caller doesn't say, we
        // look it up from the order itself, so every path that creates a
        // checkout (including the "confirm order" flow in orders.php) charges
        // in the currency the buyer was quoted without needing its own change.
        // Anything we don't recognise falls back to naira.
        $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
        if ($currency === null && !empty($data['orderRef'])) {
            try {
                $cur = db()->prepare("SELECT currency FROM orders WHERE order_ref = ? LIMIT 1");
                $cur->execute([$data['orderRef']]);
                $currency = strtoupper((string) $cur->fetchColumn());
            } catch (Throwable $e) {
                error_log('createCheckout: could not read order currency: ' . $e->getMessage());
            }
        }
        if (!in_array($currency, knownCurrencies(), true)) {
            $currency = 'NGN';
        }

        $payload = [
            'pricing' => [
                'currency' => $currency,
                'amount'   => number_format((float) ($data['amount'] ?? 0), 2, '.', ''),
            ],
            'customer' => [
                'email' => $data['customerEmail'] ?? 'noreply@kweek.ng',
                'name'  => $data['customerName'] ?? 'Customer',
            ],
            'reference'   => $data['orderRef'] ?? null,
            'success_url' => $redirect,
            'cancel_url'  => $cancel,
            'metadata'    => ['order_ref' => $data['orderRef'] ?? null],
        ];

        // Keep dollar and cedi checkouts in their own currency. Without this the
        // gateway could offer a naira method and charge a converted amount, which
        // would no longer match what we quoted. (See paymentMethodsFor().)
        $methods = paymentMethodsFor($currency);
        if (!empty($methods)) {
            $payload['payment_method_types'] = $methods;
        }

        $result = $this->request('POST', '/v1/checkout-sessions', $payload);

        return [
            'status' => $result['status'] ?? null,
            'data'   => [
                'paymentLink'  => $result['checkout_url'] ?? null,
                'checkoutLink' => $result['checkout_url'] ?? null,
                'url'          => $result['checkout_url'] ?? null,
                'id'           => $result['checkout_id'] ?? null,
                'orderId'      => $result['checkout_id'] ?? null,
            ],
            'raw' => $result,
        ];
    }

    public function verifyTransaction(string $reference, string $type = 'orderReference'): array {
        $checkoutId = $reference;

        if ($type === 'orderReference') {
            $stmt = db()->prepare("SELECT nomba_checkout_id FROM orders WHERE order_ref = ? LIMIT 1");
            $stmt->execute([$reference]);
            $stored = $stmt->fetchColumn();
            if (!$stored) {
                return ['status' => null, 'data' => ['status' => null], 'error' => 'No stored payment_id for this order_ref'];
            }
            $checkoutId = $stored;
        }

        $result = $this->request('GET', '/v1/checkout-sessions/' . urlencode($checkoutId));

        $sessionStatus = strtoupper((string) ($result['status'] ?? ''));
        $chargeStatus  = strtoupper((string) ($result['charge']['status'] ?? ''));

        if ($chargeStatus === 'SUCCEEDED') {
            $normalizedStatus = 'SUCCESS';
        } elseif (in_array($sessionStatus, ['EXPIRED', 'CANCELLED'], true) || in_array($chargeStatus, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
            $normalizedStatus = 'FAILED';
        } else {
            $normalizedStatus = 'PENDING';
        }

        return [
            'status' => $result['status'] ?? null,
            'data'   => [
                'status'    => $normalizedStatus,
                'amount'    => $result['charge']['amount'] ?? $result['amount'] ?? null,
                // Which currency was actually charged, when the gateway says.
                // payment.php compares it with what we quoted; null means "unknown".
                'currency'  => strtoupper((string) ($result['charge']['currency'] ?? $result['pricing']['currency'] ?? $result['currency'] ?? '')) ?: null,
                'account'   => $result['customer_email'] ?? null,
                'paymentId' => $result['charge']['charge_id'] ?? $checkoutId,
            ],
            'raw' => $result,
        ];
    }

    public function getCheckoutStatus(string $id, string $idType = 'ORDER_REFERENCE'): array {
        $type = ($idType === 'ORDER_REFERENCE') ? 'orderReference' : 'paymentId';
        return $this->verifyTransaction($id, $type);
    }

    public function createVirtualAccount(array $customer): array {
        throw new RuntimeException("createVirtualAccount() has no Bachs equivalent — Bachs collects via hosted/overlay checkout, not merchant-created virtual accounts.");
    }

    public function getVirtualAccount(string $accountId): array {
        throw new RuntimeException("getVirtualAccount() has no Bachs equivalent.");
    }

    // ── PAYOUTS (withdrawals) ─────────────────────────────────────
    //
    // Bachs' payout flow is two calls:
    //   1. POST /v1/payouts/destinations  (bank code + account number; the
    //      account is resolved at the bank and comes back approved)
    //   2. POST /v1/payouts               (destination id + amount)
    // The old POST /v1/payouts/withdrawals endpoint no longer accepts POST,
    // which is what produced the 405s.

    /**
     * Get the Bachs destination id (pd_...) for a user's payout account,
     * creating it the first time (or when the user's bank details change).
     * The id is cached on the users row when the columns exist; if they
     * don't (migration not run yet) it just creates a destination each time.
     *
     * Nothing has been sent when this runs, so any failure here is a
     * definite "not paid" and is thrown as BachsPayoutRejected.
     */
    private function getOrCreateDestination(int $userId, string $bankCode, string $accountNumber): string {
        $key = md5('NGN|' . $bankCode . '|' . $accountNumber);

        if ($userId > 0) {
            try {
                $stmt = db()->prepare("SELECT payout_destination_id, payout_destination_key FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if ($row && !empty($row['payout_destination_id']) && ($row['payout_destination_key'] ?? '') === $key) {
                    return $row['payout_destination_id'];
                }
            } catch (Throwable $e) {
                error_log('Bachs destination cache read skipped (run the users migration): ' . $e->getMessage());
            }
        }

        try {
            $result = $this->request('POST', '/v1/payouts/destinations', [
                'currency'       => 'NGN',
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
            ]);
        } catch (RuntimeException $e) {
            throw new BachsPayoutRejected('Could not register payout destination: ' . $e->getMessage());
        }

        $code = (int) ($result['_http_code'] ?? 0);
        if ($code >= 400 || empty($result['id'])) {
            throw new BachsPayoutRejected(
                'Destination rejected: ' . ($result['detail'] ?? "HTTP $code"),
                (string) ($result['error_code'] ?? '')
            );
        }
        if (empty($result['is_usable'])) {
            throw new BachsPayoutRejected(
                'Destination not usable (status: ' . ($result['status'] ?? 'unknown') . ')',
                'DESTINATION_NOT_USABLE'
            );
        }

        $destinationId = (string) $result['id'];

        if ($userId > 0) {
            try {
                db()->prepare("UPDATE users SET payout_destination_id = ?, payout_destination_key = ? WHERE id = ?")
                    ->execute([$destinationId, $key, $userId]);
            } catch (Throwable $e) {
                error_log('Bachs destination cache write skipped (run the users migration): ' . $e->getMessage());
            }
        }

        return $destinationId;
    }

    /**
     * Send money to a bank account. `amount` is what the recipient gets;
     * Bachs adds its own fee on top and takes it from your Bachs balance.
     *
     * Required: amount, bankCode, accountNumber, reference (also used as the
     * Idempotency-Key, so a retry can never pay twice). Optional: userId
     * (lets us reuse the saved destination).
     *
     * @throws BachsPayoutRejected  nothing was sent, safe to refund
     * @throws BachsPayoutUncertain may have been sent, do NOT refund
     */
    public function transfer(array $data): array {
        $reference = (string) ($data['reference'] ?? '');

        $destinationId = $this->getOrCreateDestination(
            (int) ($data['userId'] ?? 0),
            (string) $data['bankCode'],
            (string) $data['accountNumber']
        );

        $headers = $reference !== '' ? ['Idempotency-Key: ' . $reference] : [];

        try {
            $result = $this->request('POST', '/v1/payouts', [
                'destination' => $destinationId,
                'amount'      => number_format((float) $data['amount'], 2, '.', ''),
                'reference'   => $reference !== '' ? $reference : null,
            ], $headers);
        } catch (RuntimeException $e) {
            // Timeout / dropped connection / garbage response: it may have gone through.
            throw new BachsPayoutUncertain('Payout request outcome unknown: ' . $e->getMessage());
        }

        $code = (int) ($result['_http_code'] ?? 0);

        // 5xx, or "same key still in flight": not proof it failed.
        if ($code >= 500 || $code === 409) {
            throw new BachsPayoutUncertain("Payout request returned HTTP $code: " . ($result['detail'] ?? ''));
        }
        // Any other error: Bachs refused it, nothing was created.
        if ($code >= 400 || empty($result['id'])) {
            throw new BachsPayoutRejected(
                'Payout rejected: ' . ($result['detail'] ?? "HTTP $code"),
                (string) ($result['error_code'] ?? '')
            );
        }

        return [
            'status'  => $result['status'] ?? null,
            'message' => null,
            'data'    => [
                'id'                   => $result['id'],
                'transactionReference' => $result['id'],
                // Payouts settle asynchronously: this is normally "PENDING".
                // Real completion arrives via payout.paid / payout.failed.
                'status'               => strtoupper((string) ($result['status'] ?? 'PENDING')),
                // Bachs' own fee (charged on top, from your balance). Not the user's withdrawal fee.
                'fee'                  => $result['fee'] ?? null,
                'totalDebited'         => $result['total_debited'] ?? null,
                'settlementAmount'     => $result['amount'] ?? null,
            ],
            'raw' => $result,
        ];
    }

    /** Look up a payout by its Bachs id (pay_...). */
    public function getPayout(string $payoutId): array {
        $result = $this->request('GET', '/v1/payouts/' . urlencode($payoutId));
        return [
            'status' => $result['status'] ?? null,
            'data'   => [
                'id'            => $result['id'] ?? $payoutId,
                'status'        => strtoupper((string) ($result['status'] ?? '')),
                'failureReason' => $result['failure_reason'] ?? null,
                'reference'     => $result['reference'] ?? null,
            ],
            'raw' => $result,
        ];
    }

    public function getTransferStatus(string $payoutId): array {
        return $this->getPayout($payoutId);
    }

    // ── BANK LIST ─────────────────────────────────────────────────
    private const FALLBACK_BANKS = [
        ['bankCode' => '044', 'bankName' => 'Access Bank'],
        ['bankCode' => '023', 'bankName' => 'Citibank'],
        ['bankCode' => '050', 'bankName' => 'EcoBank'],
        ['bankCode' => '011', 'bankName' => 'First Bank'],
        ['bankCode' => '214', 'bankName' => 'First City Monument Bank'],
        ['bankCode' => '058', 'bankName' => 'Guaranty Trust Bank'],
        ['bankCode' => '030', 'bankName' => 'Heritage Bank'],
        ['bankCode' => '301', 'bankName' => 'Jaiz Bank'],
        ['bankCode' => '082', 'bankName' => 'Keystone Bank'],
        ['bankCode' => '526', 'bankName' => 'Kuda Bank'],
        ['bankCode' => '076', 'bankName' => 'Polaris Bank'],
        ['bankCode' => '039', 'bankName' => 'Stanbic IBTC'],
        ['bankCode' => '232', 'bankName' => 'Sterling Bank'],
        ['bankCode' => '032', 'bankName' => 'Union Bank'],
        ['bankCode' => '033', 'bankName' => 'United Bank for Africa'],
        ['bankCode' => '215', 'bankName' => 'Unity Bank'],
        ['bankCode' => '035', 'bankName' => 'Wema Bank'],
        ['bankCode' => '057', 'bankName' => 'Zenith Bank'],
        ['bankCode' => '999992', 'bankName' => 'OPay'],
        ['bankCode' => '999991', 'bankName' => 'PalmPay'],
        ['bankCode' => '000017', 'bankName' => 'Moniepoint'],
    ];

    public function getBanks(): array {
        // New cache key so a list cached from the old endpoint isn't reused.
        if (!empty($_SESSION['bachs_bank_list_v2']) && !empty($_SESSION['bachs_bank_list_v2_exp']) && time() < $_SESSION['bachs_bank_list_v2_exp']) {
            return ['data' => $_SESSION['bachs_bank_list_v2']];
        }

        try {
            $result = $this->request('GET', '/v1/reference/banks', ['country' => 'NG']);
        } catch (Throwable $e) {
            error_log('Bachs getBanks() request failed: ' . $e->getMessage());
            return ['data' => self::FALLBACK_BANKS];
        }

        $raw = $result['banks'] ?? $result['data'] ?? [];
        if (empty($raw) || !is_array($raw)) {
            error_log('Bachs getBanks() returned no usable bank list — raw response: ' . json_encode($result));
            return ['data' => self::FALLBACK_BANKS];
        }

        // Bachs returns {name, code}. Emit both spellings so every page that
        // reads either bankName/bankCode or name/code keeps working.
        $banks = [];
        foreach ($raw as $b) {
            $code = $b['code'] ?? $b['bankCode'] ?? null;
            $name = $b['name'] ?? $b['bankName'] ?? null;
            if ($code === null || $name === null) continue;
            $banks[] = ['bankCode' => (string) $code, 'bankName' => $name, 'code' => (string) $code, 'name' => $name];
        }
        if (empty($banks)) {
            return ['data' => self::FALLBACK_BANKS];
        }

        $_SESSION['bachs_bank_list_v2']     = $banks;
        $_SESSION['bachs_bank_list_v2_exp'] = time() + 86400;

        return ['data' => $banks];
    }

    public function resolveAccount(string $accountNumber, string $bankCode): array {
        $result  = $this->request('POST', '/v1/misc/bank-accounts/resolve', [
            'bank_code'      => $bankCode,
            'account_number' => $accountNumber,
        ]);
        $payload = $result['data'] ?? $result;

        return [
            'status' => $result['status'] ?? true,
            'data'   => [
                'account_name' => $payload['account_name'] ?? null,
                'accountName'  => $payload['account_name'] ?? null,
            ],
            'raw' => $result,
        ];
    }

    public static function verifyWebhookSignature(string $rawPayload, string $signature, string $timestamp = ''): bool {
        if ($timestamp === '' || $signature === '') return false;
        if (abs(time() - (int) $timestamp) > 300) return false; // stale delivery

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, BACHS_WEBHOOK_SECRET);
        return hash_equals($expected, $signature);
    }

    // ── TRANSACTION QUERY ─────────────────────────────────────────
    public function getTransaction(string $txnRef): array {
        return $this->verifyTransaction($txnRef, 'paymentId');
    }
}