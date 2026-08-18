<?php

namespace Marvel\Otp\Gateways;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Marvel\Otp\OtpInterface;
use Marvel\Otp\Result;

class RedsmsGateway implements OtpInterface
{
    /**
     * @var string
     */
    private $login;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $sender;

    /**
     * @var string
     */
    private $smsTemplate;

    /**
     * RedsmsGateway constructor.
     */
    public function __construct()
    {
        $this->login = config('services.redsms.login');
        $this->apiKey = config('services.redsms.api_key');
        $this->baseUrl = config('services.redsms.base_url', 'https://cp.redsms.ru/api');
        $this->sender = config('services.redsms.sender');
        $this->smsTemplate = config('services.redsms.sms_template', 'Ваш код подтверждения: {code}');
        
        // Проверяем наличие обязательных параметров
        if (empty($this->login) || empty($this->apiKey)) {
            throw new Exception('REDSMS configuration is incomplete: login and api_key are required');
        }
        
        if (empty($this->sender)) {
            Log::warning('REDSMS sender is not configured. SMS may fail to send.');
        }
    }

    /**
     * Generate authorization parameters for REDSMS API
     *
     * @return array
     */
    private function getAuthParams(): array
    {
        $ts = (string) time();
        $secret = md5($ts . $this->apiKey);

        return [
            'login' => $this->login,
            'ts' => $ts,
            'secret' => $secret,
        ];
    }

    /**
     * Send HTTP request to REDSMS API
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array
     */
    private function sendRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $authParams = $this->getAuthParams();

        try {
            $response = Http::timeout(10)
                ->retry(3, 1000)
                ->acceptJson()
                ->withHeaders($authParams)
                ->{strtolower($method)}($url, $data);

            $responseData = $response->json();

            // Log request (with response data for debugging)
            Log::info('REDSMS API Request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $responseData, // Полный ответ для отладки
            ]);

            if (!$response->successful()) {
                $errorMessage = $responseData['error'] ?? $responseData['message'] ?? 'Unknown error';
                throw new Exception("REDSMS API error: {$errorMessage}");
            }

            return $responseData;
        } catch (Exception $e) {
            Log::error('REDSMS API Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate random OTP code
     *
     * @param int $length
     * @return string
     */
    private function generateOtpCode(int $length = 6): string
    {
        return str_pad((string) rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Start a phone verification process
     *
     * @param $phone_number
     * @return Result
     */
    public function startVerification($phone_number)
    {
        try {
            // Format phone number (remove + if present, add 7 for Russian numbers if needed)
            $phone = $this->formatPhoneNumber($phone_number);

            // Wait Call: пользователь сам звонит на номер из replacedFrom.
            $data = [
                'route' => 'wcall',
                'to' => $phone,
            ];

            $response = $this->sendRequest('POST', '/message', $data);

            // REDSMS API возвращает UUID в items[0].uuid
            // Формат ответа: { "items": [{ "uuid": "xxx", ... }], "success": true }
            $uuid = null;
            
            if (isset($response['items']) && is_array($response['items']) && count($response['items']) > 0) {
                $uuid = $response['items'][0]['uuid'] ?? $response['items'][0]['id'] ?? null;
            } else {
                // Fallback для других форматов ответа
                $uuid = $response['uuid'] ?? $response['id'] ?? null;
            }
            
            if (!$uuid) {
                // Логируем полный ответ для отладки
                Log::warning('REDSMS: UUID not found in response', [
                    'response' => $response,
                    'phone' => $phone,
                ]);
                throw new Exception('UUID not received from REDSMS API response');
            }

            $callTo = $response['items'][0]['replacedFrom'] ?? null;
            if (!$callTo) {
                throw new Exception('Wait Call number was not received from REDSMS API response');
            }
            
            // Store verification context temporarily (expires in 5 minutes)
            cache()->put("redsms_otp_{$uuid}", [
                'code' => null,
                'phone' => $phone,
                'channel' => 'wcall',
                'call_to' => $callTo,
                'created_at' => now(),
            ], now()->addMinutes(5));
            
            Log::info('REDSMS OTP sent and cached', [
                'uuid' => $uuid,
                'phone' => $phone,
                'cache_key' => "redsms_otp_{$uuid}",
            ]);

            return new Result($uuid);
        } catch (Exception $exception) {
            return new Result(["Verification failed to start: {$exception->getMessage()}"]);
        }
    }

    /**
     * Check verification code
     *
     * @param $id
     * @param $code
     * @param $phone_number
     * @return Result
     */
    public function checkVerification($id, $code, $phone_number)
    {
        try {
            // Get stored OTP data
            $otpData = cache()->get("redsms_otp_{$id}");
            
            if (!$otpData) {
                return new Result(['Verification check failed: OTP code expired or invalid.']);
            }

            if (($otpData['channel'] ?? null) === 'wcall') {
                $status = $this->getStatus((string) $id);
                $current = $status['items'][0]['status'] ?? $status['status'] ?? null;
                if ($current === 'wcall_delivered') {
                    cache()->forget("redsms_otp_{$id}");
                    return new Result('success');
                }
                return new Result(['Verification check failed: Waiting for customer call.']);
            }

            // Backward compatibility for already issued SMS OTPs.
            if ($otpData['code'] === $code && $otpData['phone'] === $this->formatPhoneNumber($phone_number)) {
                // Remove OTP from cache after successful verification
                cache()->forget("redsms_otp_{$id}");
                return new Result('success');
            }

            return new Result(['Verification check failed: Invalid code.']);
        } catch (Exception $exception) {
            return new Result(["Verification check failed: {$exception->getMessage()}"]);
        }
    }

    /**
     * Send SMS message
     *
     * @param $phone_number
     * @param $messageBody
     * @return Result
     */
    public function sendSms($phone_number, $messageBody): Result
    {
        try {
            $phone = $this->formatPhoneNumber($phone_number);
            
            $data = [
                'route' => 'sms',
                'to' => $phone,
                'text' => $messageBody,
            ];

            // Отправитель обязателен для REDSMS
            if (empty($this->sender)) {
                throw new Exception('REDSMS sender is not configured. Please set REDSMS_SENDER in .env file');
            }
            
            $data['from'] = $this->sender;

            $response = $this->sendRequest('POST', '/message', $data);
            
            // REDSMS API возвращает UUID в items[0].uuid
            $messageId = null;
            if (isset($response['items']) && is_array($response['items']) && count($response['items']) > 0) {
                $messageId = $response['items'][0]['uuid'] ?? $response['items'][0]['id'] ?? null;
            } else {
                $messageId = $response['uuid'] ?? $response['id'] ?? null;
            }
            
            if (!$messageId) {
                Log::warning('REDSMS: Message ID not found in response', ['response' => $response]);
                throw new Exception('Message ID not received from REDSMS API');
            }
            
            return new Result($messageId);
        } catch (Exception $exception) {
            return new Result(["Message failed to send: {$exception->getMessage()}"]);
        }
    }

    /**
     * Format phone number for REDSMS
     * 
     * @param string $phoneNumber
     * @return string
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phoneNumber);
        
        // If starts with 8, replace with 7 (Russian format)
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7' . substr($phone, 1);
        }
        
        // If doesn't start with country code, assume Russian (7)
        if (strlen($phone) === 10) {
            $phone = '7' . $phone;
        }
        
        return $phone;
    }

    /**
     * Get message status by UUID
     *
     * @param string $uuid
     * @return array|null
     */
    public function getStatus(string $uuid): ?array
    {
        try {
            $response = $this->sendRequest('GET', "/message/{$uuid}");
            return $response;
        } catch (Exception $exception) {
            Log::error('REDSMS getStatus error', [
                'uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    public function getVerificationData(string $uuid): ?array
    {
        $data = cache()->get("redsms_otp_{$uuid}");
        return is_array($data) ? $data : null;
    }
}






