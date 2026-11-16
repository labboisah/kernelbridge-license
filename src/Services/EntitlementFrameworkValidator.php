<?php

namespace KernelBridge\LicensingClient\Services;

use DateTimeZone;
use JsonException;

final class EntitlementFrameworkValidator
{
    /**
     * @param  array{
     *     expected_product_code: string,
     *     claimed_product_code: string,
     *     expected_license_uuid: string,
     *     claimed_license_uuid: string,
     *     expected_source: string,
     *     catalog_revision: int,
     *     minimum_source_revision: int,
     *     catalog: array<string, array{type: string, unit?: string, scope?: string, period?: array{unit: string, reset: string, time_zone: string}}>
     * }  $context
     * @return array{valid: bool, error: string|null}
     */
    public function validate(string $payloadJson, array $context): array
    {
        if ($this->containsDuplicateObjectKey($payloadJson)) {
            return $this->invalid('duplicate_key');
        }

        try {
            $framework = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalid('malformed_json');
        }

        if (! is_object($framework) || ! $this->hasOnlyProperties($framework, ['schema_version', 'source', 'catalog_revision', 'source_revision', 'grants'])) {
            return $this->invalid('malformed_grant');
        }
        if (($framework->schema_version ?? null) !== 1) {
            return $this->invalid('unsupported_schema');
        }
        if (strtoupper($context['expected_product_code']) !== strtoupper($context['claimed_product_code'])) {
            return $this->invalid('product_mismatch');
        }
        if ($context['expected_license_uuid'] !== $context['claimed_license_uuid']) {
            return $this->invalid('license_mismatch');
        }
        if (! in_array($framework->source ?? null, ['direct', 'product_platform'], true)
            || $framework->source !== $context['expected_source']) {
            return $this->invalid('invalid_source');
        }
        if (! is_int($framework->catalog_revision ?? null)
            || $framework->catalog_revision !== $context['catalog_revision']) {
            return $this->invalid('catalog_revision_mismatch');
        }
        if (! is_int($framework->source_revision ?? null)
            || $framework->source_revision < $context['minimum_source_revision']) {
            return $this->invalid('stale_source_revision');
        }
        if (! is_object($framework->grants ?? null)) {
            return $this->invalid('malformed_grant');
        }

        $error = $this->validateGrantSet($framework->grants, $context['catalog']);
        if ($error !== null) {
            return $this->invalid($error);
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * @param  array{
     *     expected_product_code: string,
     *     expected_license_uuid: string,
     *     expected_account_link_uuid: string,
     *     catalog_revision: int,
     *     current_source_revision: int,
     *     catalog: array<string, array{type: string, unit?: string, scope?: string, period?: array{unit: string, reset: string, time_zone: string}}>
     * }  $context
     * @return array{valid: bool, error: string|null}
     */
    public function validatePlatformUpdate(string $payloadJson, array $context): array
    {
        if ($this->containsDuplicateObjectKey($payloadJson)) {
            return $this->invalid('duplicate_key');
        }

        try {
            $update = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalid('malformed_json');
        }

        if (! is_object($update)
            || ! $this->hasOnlyProperties($update, [
                'schema_version',
                'product_code',
                'license_uuid',
                'account_link_uuid',
                'catalog_revision',
                'source_revision',
                'idempotency_key',
                'grants',
            ])) {
            return $this->invalid('malformed_grant');
        }
        if (($update->schema_version ?? null) !== 1) {
            return $this->invalid('unsupported_schema');
        }
        if (! is_string($update->product_code ?? null)
            || strtoupper($update->product_code) !== strtoupper($context['expected_product_code'])) {
            return $this->invalid('product_mismatch');
        }
        if (($update->license_uuid ?? null) !== $context['expected_license_uuid']) {
            return $this->invalid('license_mismatch');
        }
        if (($update->account_link_uuid ?? null) !== $context['expected_account_link_uuid']) {
            return $this->invalid('account_link_mismatch');
        }
        if (! is_int($update->catalog_revision ?? null)
            || $update->catalog_revision !== $context['catalog_revision']) {
            return $this->invalid('catalog_revision_mismatch');
        }
        if (! is_int($update->source_revision ?? null)) {
            return $this->invalid('malformed_grant');
        }
        if ($update->source_revision <= $context['current_source_revision']) {
            return $this->invalid('stale_source_revision');
        }
        if (! is_string($update->idempotency_key ?? null)
            || trim($update->idempotency_key) === ''
            || strlen($update->idempotency_key) > 128) {
            return $this->invalid('malformed_grant');
        }

        $error = $this->validateGrantSet($update->grants ?? null, $context['catalog']);
        if ($error !== null) {
            return $this->invalid($error);
        }

        return ['valid' => true, 'error' => null];
    }

    private function validateGrantSet(mixed $grants, array $catalog): ?string
    {
        if (! is_object($grants)) {
            return 'malformed_grant';
        }
        foreach (get_object_vars($grants) as $key => $grant) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key)) {
                return 'malformed_grant';
            }
            $definition = $catalog[$key] ?? null;
            if (! is_array($definition)) {
                return 'unknown_grant';
            }
            $error = $this->validateGrant($grant, $definition);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function validateGrant(mixed $grant, array $definition): ?string
    {
        if (! is_object($grant) || ! is_string($grant->type ?? null)) {
            return 'malformed_grant';
        }
        if (! in_array($grant->type, ['capability', 'limit'], true)) {
            return 'unknown_grant_type';
        }

        if ($grant->type === 'capability') {
            return ($definition['type'] ?? null) === 'capability'
                && $this->hasOnlyProperties($grant, ['type', 'enabled'])
                && is_bool($grant->enabled ?? null)
                    ? null
                    : 'malformed_grant';
        }

        if (($definition['type'] ?? null) !== 'limit'
            || ! in_array($grant->state ?? null, ['finite', 'unlimited'], true)) {
            return 'malformed_grant';
        }
        $allowed = $grant->state === 'finite'
            ? ['type', 'state', 'value', 'unit', 'scope', 'period']
            : ['type', 'state', 'unit', 'scope', 'period'];
        if (! $this->hasOnlyProperties($grant, $allowed)) {
            return 'malformed_grant';
        }
        if ($grant->state === 'finite'
            && (! is_int($grant->value ?? null) && ! is_float($grant->value ?? null)
                || ! is_finite((float) $grant->value) || $grant->value < 0)) {
            return 'malformed_grant';
        }
        if (! is_string($grant->unit ?? null) || $grant->unit !== ($definition['unit'] ?? null)) {
            return 'invalid_unit';
        }
        if (! is_string($grant->scope ?? null) || $grant->scope !== ($definition['scope'] ?? null)) {
            return 'invalid_scope';
        }

        $hasPeriod = property_exists($grant, 'period');
        if (isset($definition['period'])) {
            if (! $hasPeriod || ! $this->samePeriod($grant->period, $definition['period'])) {
                return 'invalid_period';
            }
            if (! $this->isValidTimeZone($definition['period']['time_zone'])) {
                return 'invalid_period';
            }
        } elseif ($hasPeriod) {
            return 'invalid_period';
        }

        return null;
    }

    private function samePeriod(mixed $period, array $expected): bool
    {
        if (! is_object($period)
            || ! $this->hasOnlyProperties($period, ['unit', 'reset', 'time_zone'])
            || ! in_array($period->unit ?? null, ['day', 'week', 'month', 'year'], true)
            || ($period->reset ?? null) !== 'calendar'
            || ! is_string($period->time_zone ?? null)) {
            return false;
        }

        return $period->unit === ($expected['unit'] ?? null)
            && $period->reset === ($expected['reset'] ?? null)
            && $period->time_zone === ($expected['time_zone'] ?? null);
    }

    private function isValidTimeZone(string $timeZone): bool
    {
        return in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    private function hasOnlyProperties(object $value, array $allowed): bool
    {
        foreach (array_keys(get_object_vars($value)) as $key) {
            if (! in_array($key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function containsDuplicateObjectKey(string $json): bool
    {
        $length = strlen($json);
        $objectKeys = [];
        for ($index = 0; $index < $length; $index++) {
            if ($json[$index] === '{') {
                $objectKeys[] = [];
                continue;
            }
            if ($json[$index] === '}') {
                array_pop($objectKeys);
                continue;
            }
            if ($json[$index] !== '"') {
                continue;
            }

            $start = $index;
            $escaped = false;
            for ($index++; $index < $length; $index++) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($json[$index] === '\\') {
                    $escaped = true;
                } elseif ($json[$index] === '"') {
                    break;
                }
            }
            $token = substr($json, $start, $index - $start + 1);
            $lookahead = $index + 1;
            while ($lookahead < $length && ctype_space($json[$lookahead])) {
                $lookahead++;
            }
            if (($json[$lookahead] ?? null) !== ':' || $objectKeys === []) {
                continue;
            }

            try {
                $key = json_decode($token, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            $stackIndex = array_key_last($objectKeys);
            if (array_key_exists($key, $objectKeys[$stackIndex])) {
                return true;
            }
            $objectKeys[$stackIndex][$key] = true;
        }

        return false;
    }

    /** @return array{valid: false, error: string} */
    private function invalid(string $error): array
    {
        return ['valid' => false, 'error' => $error];
    }
}
