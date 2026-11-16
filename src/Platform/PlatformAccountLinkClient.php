<?php

namespace KernelBridge\LicensingClient\Platform;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeUnavailableException;

final class PlatformAccountLinkClient
{
    public function __construct(private readonly PlatformCredentialProvider $credentials) {}

    /** @return array<string, mixed> */
    public function requestLink(string $licenseUuid, string $businessUuid, ?string $apiUrl = null): array
    {
        return $this->request('post', '/platform/account-links/requests', [
            'license_uuid' => $licenseUuid,
            'business_uuid' => $businessUuid,
        ], $apiUrl);
    }

    /** @return array<string, mixed> */
    public function requestLinkWithActivationCode(string $activationCode, string $businessUuid, ?string $apiUrl = null): array
    {
        return $this->request('post', '/platform/account-links/requests', [
            'activation_code' => $activationCode,
            'business_uuid' => $businessUuid,
        ], $apiUrl);
    }

    /** @return array<string, mixed> */
    public function status(string $linkUuid, ?string $apiUrl = null): array
    {
        return $this->request('get', '/platform/account-links/'.rawurlencode($linkUuid), apiUrl: $apiUrl);
    }

    /** @return array{uuid: string, license_uuid: string, business_uuid: string, status: string, installations_revoked: int, activation_codes_revoked: int} */
    public function unlink(string $linkUuid, ?string $apiUrl = null): array
    {
        return $this->request('delete', '/platform/account-links/'.rawurlencode($linkUuid), apiUrl: $apiUrl);
    }

    /** @return array{link_uuid: string, installation_uuid: string, activation_code: string, expires_at: string} */
    public function issueInstallationCode(string $linkUuid, string $installationUuid, ?string $deviceName = null, ?string $apiUrl = null): array
    {
        return $this->request('post', '/platform/account-links/'.rawurlencode($linkUuid).'/activation-codes', array_filter([
            'installation_uuid' => $installationUuid,
            'device_name' => $deviceName,
        ], static fn (?string $value): bool => $value !== null), $apiUrl);
    }

    /** @return array<string, mixed> */
    public function issueInstallationGrant(
        string $licenseUuid,
        string $businessUuid,
        string $branchUuid,
        string $deviceUuid,
        string $installationUuid,
        ?string $apiUrl = null,
    ): array {
        return $this->request('post', '/licenses/installation-grants', [
            'license_uuid' => $licenseUuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => $branchUuid,
            'device_uuid' => $deviceUuid,
            'installation_uuid' => $installationUuid,
        ], $apiUrl);
    }

    /** @return array<string, mixed> */
    public function verifyLicense(string $licenseUuid, string $businessUuid, string $productCode, ?string $apiUrl = null): array
    {
        return $this->request('post', '/licenses/verify', [
            'license_uuid' => $licenseUuid,
            'business_uuid' => $businessUuid,
            'product_code' => $productCode,
        ], $apiUrl);
    }

    /** @return array<string, mixed> */
    public function subscriptionStatus(string $subscriptionUuid, ?string $apiUrl = null): array
    {
        return $this->request('get', '/subscriptions/status', ['subscription_uuid' => $subscriptionUuid], $apiUrl);
    }

    /** @return array<string, mixed> */
    public function entitlements(string $subscriptionUuid, ?string $apiUrl = null): array
    {
        return $this->request('get', '/entitlements', ['subscription_uuid' => $subscriptionUuid], $apiUrl);
    }

    private function request(string $method, string $path, array $data = [], ?string $apiUrl = null): array
    {
        try {
            $response = match ($method) {
                'get' => $this->http($apiUrl)->get($path, $data),
                'delete' => $this->http($apiUrl)->delete($path, $data),
                default => $this->http($apiUrl)->post($path, $data),
            };
        } catch (ConnectionException $exception) {
            throw new KernelBridgeUnavailableException('KernelBridge platform licensing is temporarily unavailable.', previous: $exception);
        }

        return $this->data($response);
    }

    private function http(?string $apiUrl = null): PendingRequest
    {
        $apiUrl = rtrim((string) ($apiUrl ?: config('kernelbridge-licensing.api_url', 'https://kernelbridge.com/api/v1')), '/');
        $productCode = strtoupper(trim((string) config('kernelbridge-licensing.product_code', '')));
        $credential = $this->credentials->credential();
        $scheme = strtolower((string) parse_url($apiUrl, PHP_URL_SCHEME));
        $allowsLocalHttp = $scheme === 'http' && app()->environment(['local', 'testing']);

        if ($apiUrl === '' || $productCode === '' || $credential === '' || ($scheme !== 'https' && ! $allowsLocalHttp)) {
            throw new \LogicException('KernelBridge platform integration is not configured with product identity and a server credential provider.');
        }

        return Http::baseUrl($apiUrl)
            ->withoutRedirecting()
            ->withToken($credential)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-KernelBridge-Product' => $productCode])
            ->connectTimeout((int) config('kernelbridge-licensing.connect_timeout_seconds', 3))
            ->timeout((int) config('kernelbridge-licensing.timeout_seconds', 10));
    }

    private function data(Response $response): array
    {
        if ($response->serverError()) {
            throw new KernelBridgeUnavailableException('KernelBridge platform licensing is temporarily unavailable.');
        }

        $envelope = $response->json();
        if (! is_array($envelope)) {
            throw new KernelBridgeUnavailableException('KernelBridge returned an unreadable platform licensing response.');
        }
        if (! $response->successful() || ($envelope['success'] ?? false) !== true || ! is_array($envelope['data'] ?? null)) {
            $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
            throw new KernelBridgeApiException(
                (string) ($error['message'] ?? 'KernelBridge rejected the platform licensing request.'),
                (string) ($error['code'] ?? 'platform_account_link_rejected'),
                $response->status(),
            );
        }

        return $envelope['data'];
    }
}
