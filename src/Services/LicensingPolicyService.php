<?php

namespace KernelBridge\LicensingClient\Services;

use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\KernelBridgeLicenseClient;

final readonly class LicensingPolicyService
{
    public function __construct(private KernelBridgeLicenseClient $client) {}

    /** @return array{product_code: string, licensing_mode: string, licensing_policy_revision: int, licensing_policy_effective_at: ?string} */
    public function current(): array
    {
        app(DeploymentProfile::class)->assertReady();
        $signed = $this->client->licensingPolicy();
        if (($signed['algorithm'] ?? null) !== 'ed25519'
            || ! is_string($signed['payload_json'] ?? null)
            || ! is_string($signed['signature'] ?? null)
            || ! is_string($signed['key_id'] ?? null)) {
            throw new KernelBridgeApiException('KernelBridge returned an incomplete signed licensing policy.', 'invalid_licensing_policy', 502);
        }

        $keys = config('kernelbridge-licensing.entitlement_public_keys', []);
        $encodedKey = is_array($keys) ? ($keys[$signed['key_id']] ?? null) : null;
        $publicKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        $signature = base64_decode($signed['signature'], true);
        if (! function_exists('sodium_crypto_sign_verify_detached')
            || $publicKey === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || $signature === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, $signed['payload_json'], $publicKey)) {
            throw new KernelBridgeApiException('KernelBridge licensing policy signature is invalid or its key is not trusted.', 'invalid_licensing_policy', 502);
        }

        try {
            $policy = json_decode($signed['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new KernelBridgeApiException('KernelBridge licensing policy payload is malformed.', 'invalid_licensing_policy', 502);
        }

        if (! is_array($policy)) {
            throw new KernelBridgeApiException('KernelBridge licensing policy payload is malformed.', 'invalid_licensing_policy', 502);
        }

        $mode = $policy['licensing_mode'] ?? null;
        $effectiveAt = $policy['licensing_policy_effective_at'] ?? null;
        $issuedAt = $policy['issued_at'] ?? null;
        if (($policy['version'] ?? null) !== 1
            || ($policy['policy_type'] ?? null) !== 'licensing_mode'
            || strtoupper((string) ($policy['product_code'] ?? '')) !== strtoupper((string) config('kernelbridge-licensing.product_code'))
            || ! in_array($mode, ['legacy', 'direct', 'platform_account', 'hybrid'], true)
            || ! is_int($policy['licensing_policy_revision'] ?? null)
            || $policy['licensing_policy_revision'] < 1
            || ! is_string($issuedAt)
            || trim($issuedAt) === ''
            || strtotime($issuedAt) === false
            || ($effectiveAt !== null && ! is_string($effectiveAt))) {
            throw new KernelBridgeApiException('KernelBridge licensing policy is not valid for this product.', 'invalid_licensing_policy', 502);
        }

        return [
            'product_code' => strtoupper($policy['product_code']),
            'licensing_mode' => $mode,
            'licensing_policy_revision' => $policy['licensing_policy_revision'],
            'licensing_policy_effective_at' => $effectiveAt,
        ];
    }
}
