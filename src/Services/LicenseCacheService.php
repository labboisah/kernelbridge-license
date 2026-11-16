<?php

namespace KernelBridge\LicensingClient\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Models\LicenseState;
use Throwable;

final class LicenseCacheService
{
    public function state(): LicenseState
    {
        $profile = app(DeploymentProfile::class);
        $productCode = $profile->productCode();
        if ($productCode === '') {
            throw new \LogicException('KERNELBRIDGE_PRODUCT_CODE is required.');
        }

        return LicenseState::query()->firstOrCreate(
            ['product_code' => $productCode],
            ['installation_identifier' => (string) Str::uuid(), 'status' => 'unlicensed'],
        );
    }

    /**
     * Verify the exact server-signed bytes before storing any claims or credentials.
     *
     * @param  array{payload_json: string, signature: string, algorithm: string, key_id: string}  $signed
     */
    public function storeInstallationSnapshot(array $signed, ?string $credential = null): LicenseState
    {
        return DB::transaction(function () use ($signed, $credential): LicenseState {
            $state = LicenseState::query()->lockForUpdate()->findOrFail($this->state()->id);
            $claims = $this->verifyInstallationSnapshot($signed, $state);
            $verifiedAt = now();
            $expiresAt = $claims['expires_at'] === null ? null : Carbon::parse($claims['expires_at']);
            $graceExpiry = $claims['grace_expires_at'] === null ? null : Carbon::parse($claims['grace_expires_at']);
            $issuedAt = Carbon::parse($claims['issued_at']);
            if ($issuedAt->gt($verifiedAt->copy()->addMinutes(5))) {
                throw new KernelBridgeApiException('KernelBridge signed an entitlement snapshot with a future issue time.', 'invalid_entitlement_signature', 502);
            }
            $offlineGrace = $issuedAt->copy()->addHours(max(1, (int) config('kernelbridge-licensing.offline_grace_hours', 72)));
            if (! $offlineGrace->isFuture()) {
                throw new KernelBridgeApiException('KernelBridge returned a stale signed entitlement snapshot.', 'stale_entitlement_snapshot', 502);
            }
            $previousIssuedAt = data_get($state->entitlement_payload, 'effective_at');
            if (is_string($previousIssuedAt)
                && strtotime($previousIssuedAt) !== false
                && Carbon::parse($previousIssuedAt)->gt($issuedAt)) {
                throw new KernelBridgeApiException('KernelBridge returned an older signed entitlement snapshot.', 'stale_entitlement_snapshot', 502);
            }
            if ($claims['license_status'] === 'grace' && ! $graceExpiry) {
                throw new KernelBridgeApiException('KernelBridge grace entitlement has no grace expiry.', 'invalid_entitlement_signature', 502);
            }
            $accessExpiry = $claims['license_status'] === 'grace' ? $graceExpiry : $expiresAt;
            if ($accessExpiry && $offlineGrace->gt($accessExpiry)) {
                $offlineGrace = $accessExpiry->copy();
            }

            $features = collect($claims['entitlements'])
                ->mapWithKeys(fn (array $item): array => [$item['key'] => ['enabled' => $item['enabled'], 'limit' => $item['limit']]])
                ->all();
            $payload = [
                'version' => 3,
                'revision' => (string) $claims['entitlement_revision'],
                'effective_at' => $claims['issued_at'],
                'product_code' => $state->product_code,
                'license_identifier' => $claims['license_uuid'],
                'subscription_identifier' => $claims['subscription_uuid'],
                'features' => $features,
                'verified_at' => $verifiedAt->toISOString(),
                'offline_grace_expires_at' => $offlineGrace->toISOString(),
            ];

            $state->forceFill([
                'credential_kind' => 'installation_bearer',
                'encrypted_installation_credential' => $credential ?? $state->encrypted_installation_credential,
                'encrypted_license_key' => null,
                'activation_identifier' => null,
                'license_identifier' => $claims['license_uuid'],
                'subscription_identifier' => $claims['subscription_uuid'],
                'status' => $claims['license_status'],
                'last_successful_verification_at' => $verifiedAt,
                'expires_at' => $expiresAt,
                'offline_grace_expires_at' => $offlineGrace,
                'entitlement_payload' => $payload,
                'entitlement_signature' => $this->sign($payload),
                'server_signed_entitlements' => $signed,
                'entitlement_revision' => $claims['entitlement_revision'],
                'config_fingerprint' => $this->configFingerprint(),
                'config_fingerprinted_at' => $verifiedAt,
                'last_error_code' => null,
            ])->save();

            return $state->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function verifyInstallationSnapshot(array $signed, LicenseState $state): array
    {
        if (($signed['algorithm'] ?? null) !== 'ed25519'
            || ! is_string($signed['payload_json'] ?? null)
            || ! is_string($signed['signature'] ?? null)
            || ! is_string($signed['key_id'] ?? null)) {
            throw new KernelBridgeApiException('KernelBridge returned an incomplete signed entitlement.', 'invalid_entitlement_signature', 502);
        }

        $publicKeys = config('kernelbridge-licensing.entitlement_public_keys', []);
        $encodedKey = is_array($publicKeys) && array_key_exists($signed['key_id'], $publicKeys) ? $publicKeys[$signed['key_id']] : null;
        $publicKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        $signature = base64_decode($signed['signature'], true);
        if (! function_exists('sodium_crypto_sign_verify_detached')
            || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $signed['payload_json'], $publicKey)) {
            throw new KernelBridgeApiException('KernelBridge entitlement signature is invalid or its key is not trusted.', 'invalid_entitlement_signature', 502);
        }

        try {
            $claims = json_decode($signed['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $objectClaims = json_decode($signed['payload_json'], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new KernelBridgeApiException('KernelBridge signed entitlement payload is malformed.', 'invalid_entitlement_signature', 502);
        }

        $allowedStatuses = ['active', 'trial', 'grace', 'expired', 'suspended', 'cancelled', 'revoked', 'invalid'];
        if (! is_array($claims)
            || ($claims['version'] ?? null) !== 1
            || strtoupper((string) ($claims['product_code'] ?? '')) !== strtoupper($state->product_code)
            || ($claims['installation_uuid'] ?? null) !== $state->installation_identifier
            || ! is_string($claims['license_uuid'] ?? null)
            || (filled($state->license_identifier) && ! hash_equals((string) $state->license_identifier, $claims['license_uuid']))
            || ! is_string($claims['subscription_uuid'] ?? null)
            || ! in_array($claims['license_status'] ?? null, $allowedStatuses, true)
            || ! is_int($claims['entitlement_revision'] ?? null)
            || ! is_array($claims['entitlements'] ?? null)
            || ! array_key_exists('expires_at', $claims)
            || ! array_key_exists('grace_expires_at', $claims)
            || ($claims['expires_at'] !== null && (! is_string($claims['expires_at']) || strtotime($claims['expires_at']) === false))
            || ($claims['grace_expires_at'] !== null && (! is_string($claims['grace_expires_at']) || strtotime($claims['grace_expires_at']) === false))
            || ! is_string($claims['issued_at'] ?? null)
            || strtotime($claims['issued_at']) === false) {
            throw new KernelBridgeApiException('KernelBridge signed entitlement claims are invalid or not bound to this installation.', 'invalid_entitlement_signature', 502);
        }

        if (array_key_exists('entitlement_framework', $claims)
            && ! app(SignedEntitlementFrameworkValidator::class)->isValid(
                $objectClaims->entitlement_framework ?? null,
                $claims['licensing_mode'] ?? null,
                $claims['platform_account_link_uuid'] ?? null,
            )) {
            throw new KernelBridgeApiException('KernelBridge signed entitlement framework is malformed or not bound to this installation source.', 'invalid_entitlement_signature', 502);
        }

        $entitlementKeys = [];
        foreach ($claims['entitlements'] as $item) {
            if (! is_array($item)
                || ! is_string($item['key'] ?? null)
                || trim($item['key']) === ''
                || in_array($item['key'], $entitlementKeys, true)
                || ! is_bool($item['enabled'] ?? null)
                || ! array_key_exists('limit', $item)
                || ($item['limit'] !== null && ! is_string($item['limit']) && ! is_int($item['limit']) && ! is_float($item['limit']))) {
                throw new KernelBridgeApiException('KernelBridge signed entitlement list is malformed.', 'invalid_entitlement_signature', 502);
            }
            $entitlementKeys[] = $item['key'];
        }

        return $claims;
    }

    /**
     * @return array{present: bool, status: 'valid'|'malformed'|'unsupported', grants: array<string, mixed>}
     */
    private function frameworkSnapshot(LicenseState $state): array
    {
        if ($state->credential_kind !== 'installation_bearer' || ! is_array($state->server_signed_entitlements)) {
            return ['present' => false, 'status' => 'valid', 'grants' => []];
        }
        $payloadJson = $state->server_signed_entitlements['payload_json'] ?? null;
        if (! is_string($payloadJson)) {
            return ['present' => false, 'status' => 'malformed', 'grants' => []];
        }
        try {
            $claims = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            $objectClaims = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['present' => false, 'status' => 'malformed', 'grants' => []];
        }
        if (! is_array($claims) || ! array_key_exists('entitlement_framework', $claims)) {
            return ['present' => false, 'status' => 'valid', 'grants' => []];
        }

        $framework = $claims['entitlement_framework'];
        if (! is_array($framework)) {
            return ['present' => true, 'status' => 'malformed', 'grants' => []];
        }
        if (($framework['schema_version'] ?? null) !== 1) {
            return ['present' => true, 'status' => 'unsupported', 'grants' => []];
        }
        if (! is_array($framework['grants'] ?? null)
            || ! is_object($objectClaims)
            || ! app(SignedEntitlementFrameworkValidator::class)->isValid(
                $objectClaims->entitlement_framework ?? null,
                $claims['licensing_mode'] ?? null,
                $claims['platform_account_link_uuid'] ?? null,
            )) {
            return ['present' => true, 'status' => 'malformed', 'grants' => []];
        }

        return ['present' => true, 'status' => 'valid', 'grants' => $framework['grants']];
    }

    private function snapshotFreshnessIsValid(LicenseState $state): bool
    {
        try {
            $claims = json_decode($state->server_signed_entitlements['payload_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($claims) || ! is_string($claims['issued_at'] ?? null) || strtotime($claims['issued_at']) === false) {
                return false;
            }
            $issuedAt = Carbon::parse($claims['issued_at']);
            if ($issuedAt->gt(now()->copy()->addMinutes(5))) {
                return false;
            }
            $freshUntil = $issuedAt->copy()->addHours(max(1, (int) config('kernelbridge-licensing.offline_grace_hours', 72)));
            $accessExpiry = $claims['license_status'] === 'grace'
                ? ($claims['grace_expires_at'] ?? null)
                : ($claims['expires_at'] ?? null);
            if ($accessExpiry !== null) {
                if (! is_string($accessExpiry) || strtotime($accessExpiry) === false) {
                    return false;
                }
                $freshUntil = $freshUntil->min(Carbon::parse($accessExpiry));
            }

            return now()->lt($freshUntil);
        } catch (\JsonException) {
            return false;
        }
    }

    public function storeVerified(string $licenseKey, array $license, array $subscription, array $entitlements): LicenseState
    {
        return DB::transaction(function () use ($licenseKey, $license, $subscription, $entitlements): LicenseState {
            $state = LicenseState::query()->lockForUpdate()->findOrFail($this->state()->id);
            $verifiedAt = now();
            $expiresAt = $this->expiry($license, $subscription);
            $grace = $verifiedAt->copy()->addHours(max(1, (int) config('kernelbridge-licensing.offline_grace_hours', 72)));
            $status = $subscription['status'] ?? $license['status'] ?? 'inactive';
            $accessExpiry = $status === 'grace'
                ? (isset($subscription['grace_ends_at']) ? Carbon::parse($subscription['grace_ends_at']) : null)
                : $expiresAt;
            if ($accessExpiry && $grace->gt($accessExpiry)) {
                $grace = $accessExpiry->copy();
            } elseif ($status === 'grace' && ! $accessExpiry) {
                $grace = $verifiedAt->copy();
            }
            $payload = [
                'version' => 2,
                'revision' => $this->revisionFor($license, $subscription, $entitlements, $verifiedAt),
                'effective_at' => $verifiedAt->toISOString(),
                'machine_fingerprint' => app(DeviceIdentity::class)->fingerprint(),
                'product_code' => $state->product_code,
                'license_identifier' => $license['license_uuid'],
                'subscription_identifier' => $license['subscription_uuid'],
                'subscription' => $subscription,
                'configuration' => $license['configuration'] ?? $subscription['configuration'] ?? [],
                'features' => collect($entitlements['entitlements'] ?? [])->mapWithKeys(fn (array $item): array => [(string) $item['key'] => ['enabled' => (bool) ($item['enabled'] ?? false), 'limit' => $item['limit'] ?? null]])->all(),
                'verified_at' => $verifiedAt->toISOString(),
                'offline_grace_expires_at' => $grace->toISOString(),
            ];
            $state->update([
                'credential_kind' => 'legacy_license_key',
                'encrypted_installation_credential' => null,
                'server_signed_entitlements' => null,
                'entitlement_revision' => null,
                'license_identifier' => $license['license_uuid'],
                'subscription_identifier' => $license['subscription_uuid'],
                'activation_identifier' => $license['activation_uuid'] ?? $state->activation_identifier,
                'encrypted_license_key' => $licenseKey,
                'status' => $status,
                'last_successful_verification_at' => $verifiedAt,
                'expires_at' => $expiresAt,
                'offline_grace_expires_at' => $grace,
                'entitlement_payload' => $payload,
                'entitlement_signature' => $this->sign($payload),
                'config_fingerprint' => $this->configFingerprint(),
                'config_fingerprinted_at' => $verifiedAt,
                'last_error_code' => null,
            ]);

            return $state->refresh();
        });
    }

    public function markRejected(string $code): void
    {
        $this->state()->update(['status' => 'invalid', 'last_error_code' => $code]);
    }

    public function markDeactivated(): void
    {
        $this->state()->update(['status' => 'deactivated', 'offline_grace_expires_at' => now(), 'last_error_code' => null]);
    }

    public function reset(): void
    {
        $state = $this->state();
        $state->delete();
    }

    public function hasUsableLicense(): bool
    {
        try {
            app(DeploymentProfile::class)->assertReady();
            $state = $this->state();

            return in_array($state->status, ['active', 'trial', 'grace'], true)
                && ($state->credential_kind === 'installation_bearer' ? filled($state->encrypted_installation_credential) : filled($state->encrypted_license_key))
                && $state->last_successful_verification_at !== null
                && ($state->status === 'grace' || $state->expires_at === null || $state->expires_at->isFuture())
                && $state->offline_grace_expires_at?->isFuture()
                && ($state->credential_kind !== 'installation_bearer' || $this->snapshotFreshnessIsValid($state))
                && $this->configFingerprintIsValid($state)
                && $this->signatureIsValid($state)
                && ($state->credential_kind === 'installation_bearer' || $this->machineMatches($state));
        } catch (Throwable) {
            return false;
        }
    }

    public function hasFeature(string $feature): bool
    {
        return $this->entitlementDecision($feature)['status'] === 'granted';
    }

    public function limit(string $feature): int|float|string|null
    {
        $limit = $this->limitGrant($feature);

        return match ($limit['status']) {
            'finite' => $limit['value'],
            'unlimited' => null,
            default => 0,
        };
    }

    /**
     * @return array{status: 'granted'|'disabled'|'absent'|'license_unavailable'|'malformed'|'unsupported', source: 'framework'|'legacy'|null, grant?: array<string, mixed>}
     */
    public function entitlementDecision(string $key): array
    {
        try {
            $state = $this->state();
        } catch (Throwable) {
            return ['status' => 'malformed', 'source' => null];
        }
        $framework = $this->frameworkSnapshot($state);
        if (! $this->hasUsableLicense()) {
            if ($framework['status'] === 'unsupported') {
                return ['status' => 'unsupported', 'source' => 'framework'];
            }
            if ($framework['status'] === 'malformed') {
                return ['status' => 'malformed', 'source' => 'framework'];
            }

            return ['status' => 'license_unavailable', 'source' => $framework['present'] ? 'framework' : 'legacy'];
        }

        if ($framework['present']) {
            if ($framework['status'] !== 'valid') {
                return ['status' => $framework['status'], 'source' => 'framework'];
            }
            $grant = $framework['grants'][$key] ?? null;
            if (! is_array($grant)) {
                return ['status' => 'absent', 'source' => 'framework'];
            }
            if ($grant['type'] === 'capability' && $grant['enabled'] !== true) {
                return ['status' => 'disabled', 'source' => 'framework', 'grant' => $grant];
            }

            return ['status' => 'granted', 'source' => 'framework', 'grant' => $grant];
        }

        $legacy = $state->entitlement_payload['features'][$key] ?? null;
        if (! is_array($legacy)) {
            return ['status' => 'absent', 'source' => 'legacy'];
        }
        if (($legacy['enabled'] ?? false) !== true) {
            return ['status' => 'disabled', 'source' => 'legacy'];
        }

        return ['status' => 'granted', 'source' => 'legacy'];
    }

    /**
     * @return array{status: 'finite'|'unlimited'|'disabled'|'absent'|'license_unavailable'|'malformed'|'unsupported'|'not_a_limit', value?: int|float|string, unit?: string|null, scope?: string|null, period?: array<string, mixed>|null}
     */
    public function limitGrant(string $key): array
    {
        $decision = $this->entitlementDecision($key);
        if ($decision['status'] !== 'granted') {
            return ['status' => $decision['status']];
        }

        if ($decision['source'] === 'framework') {
            $grant = $decision['grant'];
            if (($grant['type'] ?? null) !== 'limit') {
                return ['status' => 'not_a_limit'];
            }
            $result = [
                'status' => $grant['state'],
                'unit' => $grant['unit'],
                'scope' => $grant['scope'],
            ];
            if (array_key_exists('period', $grant)) {
                $result['period'] = $grant['period'];
            }
            if ($grant['state'] === 'finite') {
                $result['value'] = $grant['value'];
            }

            return $result;
        }

        $legacy = $this->state()->entitlement_payload['features'][$key] ?? [];
        $value = $legacy['limit'] ?? null;
        if ($value === null || $value === '' || $value === 'unlimited') {
            return ['status' => 'unlimited', 'unit' => null, 'scope' => null, 'period' => null];
        }
        if (is_numeric($value)) {
            return ['status' => 'finite', 'value' => $value, 'unit' => null, 'scope' => null, 'period' => null];
        }

        return ['status' => 'malformed'];
    }

    public function status(): array
    {
        $state = $this->state();
        $payload = is_array($state->entitlement_payload) ? $state->entitlement_payload : [];
        $active = $this->hasUsableLicense();
        $reason = $active ? 'active' : $this->activationRequirementReason();

        return [
            'active' => $active,
            'status' => $state->status ?? 'unlicensed',
            'reason' => $reason,
            'message' => $active ? 'License is active and verified.' : $this->activationRequirementMessage(),
            'product_code' => $state->product_code,
            'package' => $payload['primary_package'] ?? data_get($payload, 'subscription.primary_package') ?? null,
            'packages' => $payload['selected_packages'] ?? data_get($payload, 'subscription.selected_packages') ?? [],
            'features' => $payload['features'] ?? [],
            'expires_at' => $state->expires_at?->toIso8601String(),
            'last_verification_at' => $state->last_successful_verification_at?->toIso8601String(),
            'offline_grace_expires_at' => $state->offline_grace_expires_at?->toIso8601String(),
            'effective_at' => $payload['effective_at'] ?? null,
            'revision' => $payload['revision'] ?? null,
            'renewal_notice' => $this->renewalNotice(),
            'last_error_code' => $state->last_error_code,
        ];
    }

    public function renewalNoticeLevel(): ?string
    {
        try {
            if (! (bool) config('kernelbridge-licensing.renewal_notice.enabled', true) || ! $this->hasUsableLicense()) {
                return null;
            }

            $state = $this->state();
            if (! $state->expires_at?->isFuture()) {
                return null;
            }

            $thresholds = array_values(array_unique(array_filter(
                (array) config('kernelbridge-licensing.renewal_notice.threshold_months', [3, 2, 1]),
                static fn (mixed $months): bool => is_numeric($months) && (float) $months > 0 && (float) $months === (float) (int) $months,
            )));
            if ($thresholds === []) {
                $thresholds = [3, 2, 1];
            }
            $thresholds = array_map('intval', $thresholds);
            sort($thresholds, SORT_NUMERIC);

            foreach ($thresholds as $months) {
                $level = $months === 1 ? '1_month' : $months.'_months';
                if (now()->copy()->addMonthsNoOverflow($months)->gte($state->expires_at)) {
                    return $level;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    public function renewalNoticeMessage(): ?string
    {
        $level = $this->renewalNoticeLevel();
        if ($level === null) {
            return null;
        }

        $state = $this->state();
        $months = (int) str_replace('_months', '', str_replace('_month', '', $level));
        $monthText = $months === 1 ? '1 month' : $months.' months';

        return sprintf('Your KernelBridge license expires in about %s on %s. Renew to avoid service interruption.', $monthText, $state->expires_at?->toFormattedDateString());
    }

    /** @return array{level: string, message: string, expires_at: string}|null */
    public function renewalNotice(): ?array
    {
        $level = $this->renewalNoticeLevel();
        $message = $this->renewalNoticeMessage();
        $expiresAt = $this->state()->expires_at;

        if ($level === null || $message === null || $expiresAt === null) {
            return null;
        }

        return ['level' => $level, 'message' => $message, 'expires_at' => $expiresAt->toDateString()];
    }

    public function signatureIsValid(?LicenseState $state = null): bool
    {
        try {
            $state ??= $this->state();
            if (! is_array($state->entitlement_payload) || ! $state->entitlement_signature) {
                return false;
            }

            if (! hash_equals($state->entitlement_signature, $this->sign($state->entitlement_payload))) {
                return false;
            }
            if ($state->credential_kind === 'installation_bearer') {
                $this->verifyInstallationSnapshot($state->server_signed_entitlements ?? [], $state);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function configFingerprintIsValid(?LicenseState $state = null): bool
    {
        try {
            $state ??= $this->state();
            if ((! $state->encrypted_license_key && $state->credential_kind !== 'installation_bearer') || ! $state->config_fingerprint) {
                return ! $state->encrypted_license_key && $state->credential_kind !== 'installation_bearer';
            }

            return hash_equals($state->config_fingerprint, $this->configFingerprint());
        } catch (Throwable) {
            return false;
        }
    }

    public function activationRequirementReason(): string
    {
        try {
            $state = $this->state();

            if (! $state->encrypted_license_key && ! $state->encrypted_installation_credential) {
                return 'license_not_activated';
            }
            app(DeploymentProfile::class)->assertReady();
            if (! $this->configFingerprintIsValid($state)) {
                return 'configuration_tampered';
            }
            if ($state->credential_kind !== 'installation_bearer' && $state->entitlement_payload && ! $this->machineMatches($state)) {
                return 'device_mismatch';
            }
            if (! $this->signatureIsValid($state)) {
                return 'local_license_tampered';
            }
            if ($state->status === 'deactivated') {
                return 'license_deactivated';
            }
            if ($state->status === 'invalid') {
                return $state->last_error_code ?: 'license_invalid';
            }
            if ($state->status !== 'grace' && $state->expires_at?->isPast()) {
                return 'license_expired';
            }
            if (! $state->offline_grace_expires_at?->isFuture()) {
                return 'offline_grace_expired';
            }
            if (! in_array($state->status, ['active', 'trial', 'grace'], true)) {
                return 'license_inactive';
            }

            return 'license_required';
        } catch (Throwable $exception) {
            if ($exception instanceof DeploymentProfileException) {
                return $exception->reason;
            }

            return $exception instanceof \LogicException ? 'configuration_incomplete' : 'license_required';
        }
    }

    public function requiresRenewal(): bool
    {
        $reason = $this->activationRequirementReason();
        if (! in_array($reason, ['license_expired', 'offline_grace_expired', 'license_inactive'], true)) {
            return false;
        }

        $status = $this->state()->status;

        return $status === 'expired'
            || ($reason === 'license_expired' && in_array($status, ['active', 'trial', 'grace'], true))
            || ($reason === 'offline_grace_expired' && in_array($status, ['active', 'trial', 'grace'], true));
    }

    public function activationRequirementMessage(): string
    {
        return match ($this->activationRequirementReason()) {
            'activation_limit_reached' => 'This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.',
            'device_mismatch' => 'This cached licence does not belong to this computer. Connect to KernelBridge and activate this installation.',
            'configuration_tampered' => 'KernelBridge licensing configuration changed. Re-activation is required.',
            'configuration_incomplete' => 'KernelBridge licensing configuration is incomplete.',
            'deployment_profile_missing' => 'Initialize the KernelBridge deployment profile before continuing.',
            'license_deactivated' => 'This installation has been deactivated. Re-activation is required.',
            'license_expired' => 'This license has expired. Renew or activate a valid license.',
            'license_not_activated' => 'Activate this application before continuing.',
            'license_revoked' => 'This license was revoked by KernelBridge. Activate a valid license.',
            'local_license_tampered' => 'The local license cache was changed or cannot be trusted. Re-activation is required.',
            'offline_grace_expired' => 'Offline grace has expired. Reconnect to KernelBridge and verify the license.',
            default => 'An active KernelBridge subscription is required.',
        };
    }

    public function machineMatches(?LicenseState $state = null): bool
    {
        try {
            $state ??= $this->state();
            $expected = $state->entitlement_payload['machine_fingerprint'] ?? null;

            return is_string($expected) && hash_equals($expected, app(DeviceIdentity::class)->fingerprint());
        } catch (Throwable) {
            return false;
        }
    }

    private function configFingerprint(): string
    {
        $profile = app(DeploymentProfile::class);
        $payload = [
            'mode' => $profile->mode(),
            'deployment_fingerprint' => $profile->fingerprint(),
            'api_url' => $profile->apiUrl(),
            'product_code' => $profile->productCode(),
            'api_token_hash' => hash('sha256', $profile->apiToken()),
            'app_key_hash' => hash('sha256', (string) config('app.key')),
            'signature_key_hash' => hash('sha256', $profile->signatureKey()),
        ];

        return $this->sign($payload);
    }

    private function expiry(array $license, array $subscription): ?Carbon
    {
        $value = $license['expires_at'] ?? $subscription['grace_ends_at'] ?? $subscription['ends_at'] ?? null;
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value);
    }

    private function revisionFor(array $license, array $subscription, array $entitlements, Carbon $verifiedAt): string
    {
        $seed = [
            'license_uuid' => $license['license_uuid'] ?? null,
            'subscription_uuid' => $license['subscription_uuid'] ?? null,
            'status' => $subscription['status'] ?? $license['status'] ?? 'inactive',
            'expires_at' => $this->expiry($license, $subscription)?->toISOString(),
            'verified_at' => $verifiedAt->toISOString(),
            'configuration' => $license['configuration'] ?? $subscription['configuration'] ?? [],
            'entitlements' => collect($entitlements['entitlements'] ?? [])->map(fn (array $item): array => [
                'key' => (string) ($item['key'] ?? ''),
                'enabled' => (bool) ($item['enabled'] ?? false),
                'limit' => $item['limit'] ?? null,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($this->canonical($seed), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function sign(array $payload): string
    {
        $key = (string) config('kernelbridge-licensing.signature_key');
        if ($key === '') {
            throw new \LogicException('KERNELBRIDGE_CACHE_SIGNING_KEY or APP_KEY is required.');
        }

        return hash_hmac('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
    }

    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
