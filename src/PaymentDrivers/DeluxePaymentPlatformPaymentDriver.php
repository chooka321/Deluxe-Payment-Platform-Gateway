<?php

namespace App\PaymentDrivers;

use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\SystemLog;
use App\Exceptions\PaymentFailed;
use App\Jobs\Util\SystemLogger;
use App\PaymentDrivers\BaseDriver;
use App\PaymentDrivers\DeluxePaymentPlatform\ACH;
use App\PaymentDrivers\DeluxePaymentPlatform\CreditCard;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeluxePaymentPlatformPaymentDriver extends BaseDriver
{
    const SANDBOX_BASE_URL     = 'https://sandbox.api.deluxe.com/dpp/v1/';
    const PRODUCTION_BASE_URL  = 'https://api.deluxe.com/dpp/v1/';
    const SANDBOX_TOKEN_URL    = 'https://sandbox.api.deluxe.com/secservices/oauth2/v2/token';
    const PRODUCTION_TOKEN_URL = 'https://api.deluxe.com/secservices/oauth2/v2/token';
    const SANDBOX_SDK_URL      = 'https://payments2.deluxe.com/embedded/javascripts/deluxe.js';
    const PRODUCTION_SDK_URL   = 'https://payments.deluxe.com/embedded/javascripts/deluxe.js';

    public function gatewayTypes(): array
    {
        return [
            GatewayType::CREDIT_CARD,
            GatewayType::BANK_TRANSFER,
        ];
    }

    public function setPaymentMethod($payment_method_id)
    {
        $this->payment_method = match ((int) $payment_method_id) {
            GatewayType::CREDIT_CARD   => new CreditCard($this),
            GatewayType::BANK_TRANSFER => new ACH($this),
            default                    => new CreditCard($this),
        };

        return $this;
    }

    public function init(): self
    {
        return $this;
    }

    // -----------------------------------------------------------------
    // Credential accessors
    // accessToken  = merchant GUID — goes in JWT payload + partnerToken header
    // secretKey    = JWT HS256 signing key
    // clientId     = OAuth2 client ID — for webhook subscription and refunds
    // clientSecret = OAuth2 client secret
    // -----------------------------------------------------------------

    private function credPrefix(): string
    {
        return $this->isTestMode() ? 'sandbox' : 'production';
    }

    public function getAccessToken(): string
    {
        return $this->company_gateway->getConfigField($this->credPrefix() . 'AccessToken') ?? '';
    }

    public function getSecretKey(): string
    {
        return $this->company_gateway->getConfigField($this->credPrefix() . 'SecretKey') ?? '';
    }

    public function getClientId(): string
    {
        return $this->company_gateway->getConfigField($this->credPrefix() . 'ClientId') ?? '';
    }

    public function getClientSecret(): string
    {
        return $this->company_gateway->getConfigField($this->credPrefix() . 'ClientSecret') ?? '';
    }

    public function isTestMode(): bool
    {
        return ! (bool) ($this->company_gateway->getConfigField('productionMode') ?? false);
    }

    public function sdkUrl(): string
    {
        return $this->isTestMode() ? self::SANDBOX_SDK_URL : self::PRODUCTION_SDK_URL;
    }

    // -----------------------------------------------------------------
    // JWT generation — signs the embedded payments payload with HS256.
    // Inline implementation: firebase/php-jwt v7 rejects HMAC keys shorter
    // than 256 bits per RFC 7518, but Deluxe's signing key is 128 bits
    // and their server accepts signatures made with it.
    // -----------------------------------------------------------------

    public function generateJwt(array $payload): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $segments = [
            $b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_UNESCAPED_SLASHES)),
            $b64(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), $this->getSecretKey(), true);
        $segments[] = $b64($signature);

        return implode('.', $segments);
    }

    // -----------------------------------------------------------------
    // OAuth2 bearer token — for webhook subscription + refunds API
    // Cached 50 min; Deluxe tokens expire after 55 min (3300s)
    // -----------------------------------------------------------------

    public function getBearerToken(): string
    {
        $cacheKey = 'deluxe_bearer_' . $this->company_gateway->id;

        return Cache::remember($cacheKey, 3000, fn () => $this->fetchOAuthToken());
    }

    private function fetchOAuthToken(): string
    {
        $tokenUrl  = $this->isTestMode() ? self::SANDBOX_TOKEN_URL : self::PRODUCTION_TOKEN_URL;
        $basicAuth = base64_encode($this->getClientId() . ':' . $this->getClientSecret());

        try {
            $response = (new GuzzleClient(['timeout' => 15, 'verify' => true]))->post($tokenUrl, [
                'headers'     => [
                    'Authorization' => 'Basic ' . $basicAuth,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                ],
                'form_params' => ['grant_type' => 'client_credentials'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new \RuntimeException('Deluxe OAuth2 error: ' . $e->getMessage());
        }

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Deluxe OAuth2 failed — check clientId and clientSecret');
        }

        return $data['access_token'];
    }

    // -----------------------------------------------------------------
    // Generic API helper — for webhook subscription and refunds
    // -----------------------------------------------------------------

    public function apiCall(string $method, string $endpoint, array $payload = []): array
    {
        $base    = $this->isTestMode() ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
        $url     = rtrim($base, '/') . '/' . ltrim($endpoint, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->getBearerToken(),
            'partnerToken'  => $this->getAccessToken(),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'requestId'     => Str::uuid()->toString(),
        ];

        try {
            $options = ['headers' => $headers];
            if (strtoupper($method) === 'POST' && ! empty($payload)) {
                $options['json'] = $payload;
            }

            $response = (new GuzzleClient(['timeout' => 30, 'verify' => true]))
                ->request(strtoupper($method), $url, $options);

            $decoded = json_decode($response->getBody()->getContents(), true);
            return is_array($decoded) ? $decoded : [];

        } catch (RequestException $e) {
            $body     = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            $decoded  = json_decode($body, true);
            return array_merge(is_array($decoded) ? $decoded : [], [
                '_error'      => $e->getMessage(),
                '_httpStatus' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Payment Link — creates a Deluxe-hosted payment page for the customer.
    // We redirect the customer to the returned paymentLinkURL; Deluxe webhooks
    // us with TRANSACTION on completion, with our customData echoed back.
    // -----------------------------------------------------------------

    public function createPaymentLink(array $payload): array
    {
        return $this->apiCall('POST', 'paymentlinks', $payload);
    }

    public function getPaymentLinkUrl(array $acceptPaymentMethod): string
    {
        $hash   = $this->payment_hash;
        $client = $this->client;
        $amount = round((float) $hash->data->amount_with_fee, 2);
        $invoiceNumbers = collect($hash->invoices())->pluck('invoice_number')->filter()->implode(', ');

        $payload = [
            'amount' => [
                'amount'   => $amount,
                'currency' => $hash->data->currency_code ?? 'USD',
            ],
            'acceptPaymentMethod' => $acceptPaymentMethod,
            'deliveryMethod'      => [
                'type'  => 'None',
                'email' => '',
                'sms'   => '',
            ],
            'paymentLinkExpiry'   => '1 DAYS',
            'firstName'           => $client->present()->first_name() ?: 'Customer',
            'lastName'            => $client->present()->last_name() ?: '-',
            'orderData'           => [
                'orderId'     => $hash->hash,
                'description' => $invoiceNumbers !== ''
                    ? 'Invoice ' . $invoiceNumbers
                    : 'Invoice payment',
            ],
            'customData' => [
                ['name' => 'PaymentHash', 'value' => $hash->hash],
            ],
        ];

        Log::info('Deluxe paymentlinks request', ['payload' => $payload]);

        $response = $this->createPaymentLink($payload);

        Log::info('Deluxe paymentlinks response', ['response' => $response]);

        $url = $response['paymentLinkURL']
            ?? $response['paymentLinkUrl']
            ?? $response['url']
            ?? null;

        if (! $url) {
            $msg = $response['errors']['message']
                ?? $response['message']
                ?? $response['_error']
                ?? 'Failed to create Deluxe payment link';
            Log::error('Deluxe paymentlinks failed', ['message' => $msg, 'response' => $response]);
            throw new PaymentFailed($msg, 500);
        }

        return $url;
    }

    // -----------------------------------------------------------------
    // Refund
    // -----------------------------------------------------------------

    public function refund(Payment $payment, $amount, $return_client_response = false)
    {
        $response = $this->apiCall('POST', 'refunds', [
            'paymentId' => $payment->transaction_reference,
            'amount'    => [
                'amount'   => number_format((float) $amount, 2, '.', ''),
                'currency' => $payment->currency->code ?? 'USD',
            ],
        ]);

        $success = ! empty($response['paymentId']) && empty($response['_error']);

        return [
            'transaction_reference' => $response['paymentId'] ?? null,
            'transaction_response'  => $response,
            'success'               => $success,
            'description'           => $success
                ? 'Refund processed'
                : ($response['errors']['message'] ?? $response['_error'] ?? 'Refund failed'),
            'code'                  => $success ? 200 : 500,
        ];
    }

    // -----------------------------------------------------------------
    // Stubs — embedded payments do not use stored tokens
    // -----------------------------------------------------------------

    public function detach(ClientGatewayToken $token) {}

    public function tokenBilling(ClientGatewayToken $cgt, PaymentHash $payment_hash) {}

    public function authorizeView(array $data)
    {
        return redirect()->back();
    }

    public function authorizeResponse($request)
    {
        return redirect()->back();
    }

    public function processPaymentView(array $data)
    {
        return $this->payment_method->paymentView($data);
    }

    public function processPaymentResponse($request)
    {
        return $this->payment_method->paymentResponse($request);
    }

    // -----------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------

    public function logError(string $message, string $context = ''): void
    {
        SystemLogger::dispatch(
            ['message' => $message, 'context' => $context],
            SystemLog::CATEGORY_GATEWAY_RESPONSE,
            SystemLog::EVENT_GATEWAY_ERROR,
            SystemLog::TYPE_STRIPE,
            $this->client,
            $this->client->company
        );
    }
}
