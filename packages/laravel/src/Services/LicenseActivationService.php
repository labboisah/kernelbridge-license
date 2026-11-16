<?php

namespace KernelBridge\LicensingClient\Services;

use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\KernelBridgeLicenseClient;
use KernelBridge\LicensingClient\Models\LicenseState;

final readonly class LicenseActivationService
{
    public function __construct(private KernelBridgeLicenseClient $client, private LicenseCacheService $cache) {}

    public function activateWithCode(string $activationCode, ?string $deviceName = null): LicenseState
    {
        app(DeploymentProfile::class)->assertReady();
        $state = $this->cache->state();
        $response = $this->client->exchangeActivationCode(
            trim($activationCode),
            $state->installation_identifier,
            $deviceName ?? config('app.name'),
        );

        if (($response['installation_uuid'] ?? null) !== $state->installation_identifier
            || strtoupper((string) ($response['product_code'] ?? '')) !== strtoupper($state->product_code)
            || ($response['credential_type'] ?? null) !== 'bearer'
            || ! is_string($response['credential'] ?? null)
            || trim($response['credential']) === ''
            || ! is_array($response['signed_entitlements'] ?? null)) {
            throw new KernelBridgeApiException('KernelBridge returned an activation response that is not bound to this installation.', 'invalid_activation_response', 502);
        }

        return $this->cache->storeInstallationSnapshot($response['signed_entitlements'], $response['credential']);
    }

    public function activate(string $licenseKey, ?string $deviceName = null, ?string $platformUrl = null): LicenseState
    {
        app(DeploymentProfile::class)->assertReady();

        $installation = $this->cache->state()->installation_identifier;
        $license = $this->client->activate($licenseKey, $installation, $deviceName ?? config('app.name'), $platformUrl);
        $subscription = $license['subscription'] ?? $this->client->getSubscription($license['subscription_uuid']);
        $entitlements = $license['entitlements'] ?? $this->client->getEntitlements($license['subscription_uuid']);

        return $this->cache->storeVerified($licenseKey, $license, $subscription, $entitlements);
    }
}
