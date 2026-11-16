<?php

namespace KernelBridge\LicensingClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeUnavailableException;
use KernelBridge\LicensingClient\Services\DeploymentProfile;
use KernelBridge\LicensingClient\Services\DeviceIdentity;

final class KernelBridgeLicenseClient
{
    public function licensingPolicy(): array
    {
        $apiUrl = rtrim((string) config('kernelbridge-licensing.api_url', 'https://kernelbridge.com/api/v1'), '/');
        $productCode = strtoupper(trim((string) config('kernelbridge-licensing.product_code', '')));
        if ($apiUrl === '' || $productCode === '') {
            throw new \LogicException('KernelBridge licensing policy requires a product code and API URL.');
        }

        try {
            $response = Http::baseUrl($apiUrl)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-KernelBridge-Product' => $productCode])
                ->connectTimeout((int) config('kernelbridge-licensing.connect_timeout_seconds', 3))
                ->timeout((int) config('kernelbridge-licensing.timeout_seconds', 10))
                ->get('/licensing/products/'.rawurlencode($productCode).'/policy');
        } catch (ConnectionException $exception) {
            throw new KernelBridgeUnavailableException('KernelBridge licensing policy is temporarily unavailable.', previous: $exception);
        }

        return $this->data($response);
    }

    public function exchangeActivationCode(string $activationCode, string $installationUuid, ?string $deviceName = null): array
    {
        return $this->installationRequest('post', '/installations/activate', [
            'activation_code' => $activationCode,
            'product_code' => strtoupper((string) config('kernelbridge-licensing.product_code')),
            'installation_uuid' => $installationUuid,
            'device_name' => $deviceName,
        ]);
    }

    public function verifyInstallation(string $credential): array
    {
        if ($credential === '') {
            throw new \InvalidArgumentException('An installation credential is required.');
        }

        return $this->installationRequest('post', '/installations/verify', [], $credential);
    }

    public function deactivateInstallation(string $credential): array
    {
        if ($credential === '') {
            throw new \InvalidArgumentException('An installation credential is required.');
        }

        return $this->installationRequest('post', '/installations/deactivate', [], $credential);
    }

    public function activate(string $licenseKey, string $installationIdentifier, ?string $deviceName = null, ?string $platformUrl = null): array
    {
        $deviceInfo = app(DeviceIdentity::class)->information();
        if ($platformUrl !== null) {
            $deviceInfo['platform_url'] = $platformUrl;
        }

        return $this->request('post', '/licenses/activate', array_filter([
            'license_key' => $licenseKey,
            'device_id' => $installationIdentifier,
            'device_name' => $deviceName,
            'machine_fingerprint' => app(DeviceIdentity::class)->fingerprint(),
            'device_info' => $deviceInfo,
        ]));
    }

    public function verify(string $licenseKey, string $installationIdentifier): array
    {
        return $this->request('post', '/licenses/verify', [
            'license_key' => $licenseKey,
            'device_id' => $installationIdentifier,
            'machine_fingerprint' => app(DeviceIdentity::class)->fingerprint(),
        ]);
    }

    public function deactivate(string $licenseKey, string $installationIdentifier): array
    {
        return $this->request('post', '/licenses/deactivate', [
            'license_key' => $licenseKey,
            'device_id' => $installationIdentifier,
            'machine_fingerprint' => app(DeviceIdentity::class)->fingerprint(),
        ]);
    }

    public function getEntitlements(string $subscriptionIdentifier): array
    {
        return $this->request('get', '/entitlements', ['subscription_uuid' => $subscriptionIdentifier]);
    }

    public function getSubscription(string $subscriptionIdentifier): array
    {
        return $this->request('get', '/subscriptions/status', ['subscription_uuid' => $subscriptionIdentifier]);
    }

    private function request(string $method, string $path, array $data): array
    {
        try {
            $response = $method === 'get'
                ? $this->http()->get($path, $data)
                : $this->http()->send(strtoupper($method), $path, ['json' => $data]);
        } catch (ConnectionException $exception) {
            Log::warning('KernelBridge licensing connection failed', [
                'host' => parse_url((string) config('kernelbridge-licensing.api_url'), PHP_URL_HOST),
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
            throw new KernelBridgeUnavailableException('KernelBridge licensing is temporarily unavailable.', previous: $exception);
        }

        return $this->data($response);
    }

    private function installationRequest(string $method, string $path, array $data, ?string $credential = null): array
    {
        $apiUrl = rtrim((string) config('kernelbridge-licensing.api_url', 'https://kernelbridge.com/api/v1'), '/');
        $productCode = strtoupper(trim((string) config('kernelbridge-licensing.product_code', '')));
        if ($apiUrl === '' || $productCode === '') {
            throw new \LogicException('KernelBridge installation licensing requires a product code and API URL.');
        }

        try {
            $request = Http::baseUrl($apiUrl)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->withHeaders(['X-KernelBridge-Product' => $productCode])
                ->connectTimeout((int) config('kernelbridge-licensing.connect_timeout_seconds', 3))
                ->timeout((int) config('kernelbridge-licensing.timeout_seconds', 10));

            if ($credential !== null) {
                $request = $request->withToken($credential);
            }

            $response = $request->send(strtoupper($method), $path, ['json' => $data]);
        } catch (ConnectionException $exception) {
            throw new KernelBridgeUnavailableException('KernelBridge licensing is temporarily unavailable.', previous: $exception);
        }

        return $this->data($response);
    }

    private function http(): PendingRequest
    {
        $profile = app(DeploymentProfile::class);
        $profile->assertReady();
        $profile->assertConfigurationComplete(requireApiToken: true);

        $url = rtrim($profile->apiUrl(), '/');
        $token = $profile->apiToken();
        $code = $profile->productCode();

        return Http::baseUrl($url)->withoutRedirecting()->withToken($token)->acceptJson()->asJson()
            ->withHeaders(['X-KernelBridge-Product' => $code])
            ->connectTimeout((int) config('kernelbridge-licensing.connect_timeout_seconds', 3))
            ->timeout((int) config('kernelbridge-licensing.timeout_seconds', 10));
    }

    private function data(Response $response): array
    {
        if ($response->serverError()) {
            throw new KernelBridgeUnavailableException('KernelBridge licensing is temporarily unavailable.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new KernelBridgeUnavailableException('KernelBridge returned an unreadable licensing response.');
        }
        if (! $response->successful() || ($body['success'] ?? false) !== true || ! is_array($body['data'] ?? null)) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : [];
            throw new KernelBridgeApiException(
                (string) ($error['message'] ?? 'KernelBridge rejected the licensing request.'),
                (string) ($error['code'] ?? 'license_rejected'),
                $response->status(),
            );
        }

        return $body['data'];
    }
}
