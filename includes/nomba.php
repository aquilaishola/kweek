<?php

require_once __DIR__ . '/../config/db.php';

class Nomba {

    private string $clientId;
    private string $privateKey;
    private string $accountId;
    private string $subAccountId;
    private string $baseUrl;
    private ?string $accessToken = null;
    private ?string $tokenEnv    = null;

    public function __construct() {
        if (NOMBA_ENV === 'live') {
            $this->clientId   = NOMBA_LIVE_CLIENT_ID;
            $this->privateKey = NOMBA_LIVE_PRIVATE_KEY;
            $this->baseUrl    = 'https://api.nomba.com';
        } else {
            $this->clientId   = NOMBA_TEST_CLIENT_ID;
            $this->privateKey = NOMBA_TEST_PRIVATE_KEY;
            $this->baseUrl    = 'https://sandbox.nomba.com';
        }
        $this->accountId    = NOMBA_ACCOUNT_ID;
        $this->subAccountId = NOMBA_SUB_ACCOUNT_ID;
    }

    // ── AUTHENTICATION ────────────────────────────────────────
    private function getToken(): string {
        $cacheKey = 'nomba_token_' . NOMBA_ENV;
        $expKey   = 'nomba_token_exp_' . NOMBA_ENV;

        if (
            !empty($_SESSION[$cacheKey]) &&
            !empty($_SESSION[$expKey]) &&
            time() < (int)$_SESSION[$expKey]
        ) {
            $this->accessToken = $_SESSION[$cacheKey];
            return $this->accessToken;
        }

        $ch = curl_init($this->baseUrl . '/v1/auth/token/issue');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'client_id'     => $this->clientId,
                'client_secret' => $this->privateKey,
                'grant_type'    => 'client_credentials',
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'accountId: ' . $this->accountId,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("Nomba cURL auth error: $err");

        $decoded = json_decode($raw, true);

        // Nomba returns access_token at top level OR inside data
        $token = $decoded['access_token']
            ?? $decoded['data']['access_token']
            ?? null;

        if (!$token) {
            // Log full response to help debug
            error_log('Nomba auth response: ' . $raw);
            throw new RuntimeException('Nomba auth failed: ' . $raw);
        }

        // Cache for 55 minutes (tokens last 1 hour)
        $expiresIn = (int)($decoded['expires_in'] ?? $decoded['data']['expires_in'] ?? 3600);
        $this->accessToken       = $token;
        $_SESSION[$cacheKey]     = $token;
        $_SESSION[$expKey]       = time() + min($expiresIn - 60, 3300);

        return $this->accessToken;
    }

    private function clearTokenCache(): void {
        $cacheKey = 'nomba_token_' . NOMBA_ENV;
        $expKey   = 'nomba_token_exp_' . NOMBA_ENV;
        unset($_SESSION[$cacheKey], $_SESSION[$expKey]);
        $this->accessToken = null;
    }

    // ── HTTP REQUEST ──────────────────────────────────────────
    private function request(
        string $method,
        string $endpoint,
        array  $body       = [],
        bool   $auth       = true,
        bool   $retry      = true   
    ): array {

        $url     = $this->baseUrl . $endpoint;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . $this->getToken();
            $headers[] = 'accountId: ' . $this->accountId;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'GET') {
            if (!empty($body)) $url .= '?' . http_build_query($body);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("Nomba cURL error: $err");

        // Auto-retry once on 401 with fresh token
        if ($code === 401 && $retry && $auth) {
            $this->clearTokenCache();
            return $this->request($method, $endpoint, $body, $auth, false);
        }

        if ($code === 403) {
            error_log("Nomba 403 on $method $endpoint — body: $raw");
            throw new RuntimeException("Nomba 403 Forbidden on $endpoint. Check accountId, credentials and environment. Raw: $raw");
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Nomba non-JSON response ($code): $raw");
        }

        return $decoded;
    }

    // ── CREATE CHECKOUT ─────────────────────────────────────────
    public function createCheckout(array $data): array {
        $redirectUrl = $data['redirectUrl']
            ?? SITE_URL . '/receipt/' . urlencode($data['orderRef']);

        $order = [
            'amount'          => number_format($data['amount'], 2, '.', ''), 
            'currency'        => 'NGN',
            'orderReference'  => $data['orderRef'],
            'callbackUrl'     => $redirectUrl,
            'customerEmail'   => $data['customerEmail'] ?? 'noreply@kweek.ng',
            'customerId'      => $data['customerId'] ?? $this->subAccountId,
            'accountId'       => $this->subAccountId,
            'orderMetaData'   => [
                'orderRef' => $data['orderRef'],
                'source'   => 'kweek',
            ],
        'allowedPaymentMethods' => ["Transfer"],
        ];

        $payload  = ['order' => $order];
        $endpoint = NOMBA_ENV === 'live' ? '/v1/checkout/order' : '/sandbox/checkout/order';

        error_log('Nomba checkout payload: ' . json_encode($payload));
        $result = $this->request('POST', $endpoint, $payload);
        error_log('Nomba checkout result: ' . json_encode($result));
        return $result;
    }

    public function verifyTransaction(string $reference, string $type = 'orderReference'): array {
        // $type: 'orderReference' or 'transactionRef'
        return $this->request('GET', '/v1/transactions/accounts/single', [
            $type => $reference,
        ]);
    }

    public function getCheckoutStatus(string $id, string $idType = 'ORDER_REFERENCE'): array {
        if (NOMBA_ENV !== 'live') {
            throw new RuntimeException('getCheckoutStatus() is production-only. Use verifyTransaction() in sandbox.');
        }
        return $this->request('GET', '/v1/checkout/transaction', [
            'idType' => $idType,
            'id'     => $id,
        ]);
    }

    // ── VIRTUAL ACCOUNTS ──────────────────────────────────────
    public function createVirtualAccount(array $customer): array {
        $endpoint = NOMBA_ENV === 'live' ? '/v1/accounts/virtual/customer' : '/accounts/virtual/customer';
        return $this->request('POST', $endpoint, [
            'accountReference' => 'KWK-' . $customer['userId'] . '-' . time(),
            'accountName'      => $customer['name'],
            'phoneNumber'      => $customer['phone'],
            'email'            => $customer['email'],
            'bvn'              => $customer['bvn'] ?? null,
        ]);
    }

    public function getVirtualAccount(string $accountId): array {
        $endpoint = NOMBA_ENV === 'live' ? "/v1/accounts/virtual/$accountId" : "/accounts/virtual/$accountId";
        return $this->request('GET', $endpoint);
    }

    // ── TRANSFERS 
   public function transfer(array $data): array {
    return $this->request('POST', '/v2/transfers/bank', [
        'amount'        => $data['amount'],
        'accountNumber' => $data['accountNumber'],
        'accountName'   => $data['accountName'],
        'bankCode'      => $data['bankCode'],
        'merchantTxRef' => $data['reference'],
        'senderName'    => 'Kweek',
        'narration'     => $data['narration'] ?? 'Kweek withdrawal',
    ]);
}

    public function getTransferStatus(string $reference): array {
        $endpoint = NOMBA_ENV === 'live' ? "/v1/transfers/$reference" : "/transfers/$reference";
        return $this->request('GET', $endpoint);
    }

    // ── BANK LIST ─────
    public function getBanks(): array {
        if (!empty($_SESSION['nomba_banks_cached']) && !empty($_SESSION['nomba_banks_exp']) && time() < $_SESSION['nomba_banks_exp']) {
            return ['data' => $_SESSION['nomba_banks_cached']];
        }
        $endpoint = NOMBA_ENV === 'live' ? '/v1/transfers/banks' : '/transfers/banks';
        $result = $this->request('GET', $endpoint);
        if (!empty($result['data'])) {
            $_SESSION['nomba_banks_cached'] = $result['data'];
            $_SESSION['nomba_banks_exp']    = time() + 86400;
        }
        return $result;
    }

    public function resolveAccount(string $accountNumber, string $bankCode): array {
        $endpoint = NOMBA_ENV === 'live' ? '/v1/transfers/bank/lookup' : '/transfers/bank/lookup';
        return $this->request('POST', $endpoint, [
            'accountNumber' => $accountNumber,
            'bankCode'      => $bankCode,
        ]);
         error_log('Nomba checkout payload: ' . json_encode($payload));
        error_log('Nomba checkout result: ' . json_encode($result));
    }

    public static function verifyWebhookSignature(string $rawPayload, string $signature, string $timestamp): bool {
        $payload = json_decode($rawPayload, true);
        if (!$payload) return false;

        $data        = $payload['data'] ?? [];
        $merchant    = $data['merchant'] ?? [];
        $transaction = $data['transaction'] ?? [];

        $eventType     = $payload['event_type'] ?? '';
        $requestId     = $payload['requestId'] ?? '';
        $userId        = $merchant['userId'] ?? '';
        $walletId      = $merchant['walletId'] ?? '';
        $transactionId = $transaction['transactionId'] ?? '';
        $transactionType = $transaction['type'] ?? '';
        $transactionTime = $transaction['time'] ?? '';
        $responseCode    = $transaction['responseCode'] ?? '';
        if ($responseCode === 'null') $responseCode = '';

        $hashingPayload = sprintf(
            "%s:%s:%s:%s:%s:%s:%s:%s:%s",
            $eventType, $requestId, $userId, $walletId,
            $transactionId, $transactionType, $transactionTime,
            $responseCode, $timestamp
        );

        $secret   = NOMBA_WEBHOOK_SECRET;
        $expected = base64_encode(hash_hmac('sha256', $hashingPayload, $secret, true));

        return hash_equals($expected, $signature);
    }

    // ── TRANSACTION QUERY ─────────────────────────────────────
    public function getTransaction(string $txnRef): array {
        $endpoint = NOMBA_ENV === 'live' ? "/v1/transactions/$txnRef" : "/transactions/$txnRef";
        return $this->request('GET', $endpoint);
    }
}