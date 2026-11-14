<?php

require_once __DIR__ . '/../config/db.php';

class PayvesselKyc
{
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;

   
    public const MIN_NAME_MATCH_PERCENT = 90.0;
    public const REQUIRE_BIRTHDAY_MATCH = true;

    public function __construct()
    {
        $this->apiKey    = PAYVESSEL_API_KEY;
        $this->apiSecret = PAYVESSEL_API_SECRET;
        $this->baseUrl   = defined('PAYVESSEL_SANDBOX') && PAYVESSEL_SANDBOX
            ? 'https://sandbox.payvessel.com/kyc'
            : 'https://api.payvessel.com/kyc';
    }

  
    public function verifyBvnBasic(
        string $bvn,
        string $firstName,
        string $middleName,
        string $lastName,
        string $gender,
        string $birthday,
        string $phoneNumber
    ): array {
        $payload = [
            'bvn'          => $bvn,
            'first_name'   => $firstName,
            'middle_name'  => $middleName !== '' ? $middleName : $firstName,
            'last_name'    => $lastName,
            'gender'       => strtoupper($gender) === 'FEMALE' ? 'FEMALE' : 'MALE',
            'birthday'     => $birthday, // YYYY-MM-DD
            'phone_number' => $phoneNumber,
        ];

        $ch = curl_init($this->baseUrl . '/api/v1/merchant/bvn/basic');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'api-key: ' . $this->apiKey,
                'api-secret: ' . $this->apiSecret,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw       = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("Payvessel BVN cURL error: $curlError");
            return $this->emptyResult(false, 'Verification service is unreachable. Please try again shortly.');
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Payvessel BVN non-JSON response ($httpCode): $raw");
            return $this->emptyResult(false, 'Verification service returned an unexpected response.');
        }

        if ($httpCode === 402) {
            error_log("Payvessel BVN 402 — insufficient wallet balance: $raw");
            $result = $this->emptyResult(false, $decoded['message'] ?? 'Verification wallet balance is insufficient.');
            $result['insufficient_wallet'] = true;
            return $result;
        }

        if ($httpCode >= 400) {
            error_log("Payvessel BVN error $httpCode — body: $raw");
        }

        $success = (bool) ($decoded['success'] ?? false);
        $data    = $decoded['data'] ?? null;

        if (!$success || !$data) {
            // Includes the documented "BVN not found" case — still charged,
            // still counts as a used attempt.
            return $this->emptyResult(true, $decoded['message'] ?? 'BVN could not be verified.');
        }

        $namePct = isset($data['names_match_percentage']) ? (float) $data['names_match_percentage'] : 0.0;
        $nameOk  = strtoupper((string)($data['name_match_rlt'] ?? '')) === 'MATCH' && $namePct >= self::MIN_NAME_MATCH_PERCENT;
        $dobOk   = !self::REQUIRE_BIRTHDAY_MATCH || strtoupper((string)($data['birthday_match_rlt'] ?? '')) === 'MATCH';

        return [
            'success'                 => true,
            'found'                   => true,
            'message'                 => $decoded['message'] ?? null,
            'name_match_rlt'          => $data['name_match_rlt'] ?? null,
            'names_match_percentage'  => $namePct,
            'birthday_match_rlt'      => $data['birthday_match_rlt'] ?? null,
            'gender_match_rlt'        => $data['gender_match_rlt'] ?? null,
            'phone_number_match_rlt'  => $data['phone_number_match_rlt'] ?? null,
            'passed'                  => $nameOk && $dobOk,
            'insufficient_wallet'     => false,
            'raw'                     => $decoded,
        ];
    }

    private function emptyResult(bool $found, string $message): array
    {
        return [
            'success'                 => $found, // request completed, just no/failed match
            'found'                   => $found,
            'message'                 => $message,
            'name_match_rlt'          => null,
            'names_match_percentage'  => null,
            'birthday_match_rlt'      => null,
            'gender_match_rlt'        => null,
            'phone_number_match_rlt'  => null,
            'passed'                  => false,
            'insufficient_wallet'     => false,
            'raw'                     => [],
        ];
    }
}