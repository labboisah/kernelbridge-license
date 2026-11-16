<?php

namespace KernelBridge\LicensingClient\Services;

use JsonException;

final readonly class EntitlementCatalogValidator
{
    public function __construct(private EntitlementFrameworkValidator $frameworkValidator) {}

    /**
     * Validate the shared plan -> package -> module -> capability catalog and
     * return the flattened stable-key definitions used by runtime grant checks.
     *
     * @param  array<string, mixed>  $catalog
     * @return array{valid: bool, error: string|null, definitions?: array<string, array<string, mixed>>}
     */
    public function validate(array $catalog, string $expectedProductCode): array
    {
        if (array_diff(array_keys($catalog), ['schema_version', 'product_code', 'catalog_revision', 'packages', 'plans']) !== []
            || ! is_string($catalog['product_code'] ?? null)
            || ! is_int($catalog['catalog_revision'] ?? null)
            || $catalog['catalog_revision'] < 1
            || ! is_array($catalog['packages'] ?? null)
            || ! is_array($catalog['plans'] ?? null)) {
            return $this->invalid('catalog_invalid');
        }
        if (($catalog['schema_version'] ?? null) !== 1) {
            return $this->invalid('unsupported_schema');
        }
        if (strtoupper($catalog['product_code']) !== strtoupper($expectedProductCode)) {
            return $this->invalid('product_mismatch');
        }

        $definitions = [];
        $packageKeys = [];
        $moduleKeys = [];
        foreach ($catalog['packages'] as $package) {
            if (! is_array($package)
                || array_diff(array_keys($package), ['key', 'modules']) !== []
                || ! $this->isIdentifier($package['key'] ?? null)
                || isset($packageKeys[$package['key']])
                || ! is_array($package['modules'] ?? null)) {
                return $this->invalid('catalog_invalid');
            }
            $packageKeys[$package['key']] = true;
            foreach ($package['modules'] as $module) {
                if (! is_array($module)
                    || array_diff(array_keys($module), ['key', 'capabilities']) !== []
                    || ! $this->isIdentifier($module['key'] ?? null)
                    || isset($moduleKeys[$module['key']])
                    || ! is_array($module['capabilities'] ?? null)) {
                    return $this->invalid('catalog_invalid');
                }
                $moduleKeys[$module['key']] = true;
                foreach ($module['capabilities'] as $capability) {
                    if (! is_array($capability)
                        || ! is_string($capability['key'] ?? null)
                        || ! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $capability['key'])
                        || isset($definitions[$capability['key']])
                        || (isset($capability['description']) && (! is_string($capability['description']) || strlen($capability['description']) > 500))) {
                        return $this->invalid('catalog_invalid');
                    }
                    if (($capability['type'] ?? null) === 'capability'
                        && array_diff(array_keys($capability), ['key', 'type', 'description']) === []) {
                        $definitions[$capability['key']] = ['type' => 'capability'];

                        continue;
                    }
                    if (($capability['type'] ?? null) !== 'limit'
                        || array_diff(array_keys($capability), ['key', 'type', 'unit', 'scope', 'period', 'description']) !== []
                        || ! $this->isIdentifier($capability['unit'] ?? null)
                        || ! $this->isIdentifier($capability['scope'] ?? null)) {
                        return $this->invalid('catalog_invalid');
                    }
                    $definition = [
                        'type' => 'limit',
                        'unit' => $capability['unit'],
                        'scope' => $capability['scope'],
                    ];
                    if (array_key_exists('period', $capability)) {
                        if (! is_array($capability['period'])
                            || array_diff(array_keys($capability['period']), ['unit', 'reset', 'time_zone']) !== []
                            || ! in_array($capability['period']['unit'] ?? null, ['day', 'week', 'month', 'year'], true)
                            || ($capability['period']['reset'] ?? null) !== 'calendar'
                            || ! is_string($capability['period']['time_zone'] ?? null)) {
                            return $this->invalid('catalog_invalid');
                        }
                        try {
                            new \DateTimeZone($capability['period']['time_zone']);
                        } catch (\DateInvalidTimeZoneException) {
                            return $this->invalid('catalog_invalid');
                        }
                        $definition['period'] = $capability['period'];
                    }
                    $definitions[$capability['key']] = $definition;
                }
            }
        }

        $planKeys = [];
        foreach ($catalog['plans'] as $plan) {
            if (! is_array($plan)
                || array_diff(array_keys($plan), ['key', 'grants']) !== []
                || ! $this->isIdentifier($plan['key'] ?? null)
                || isset($planKeys[$plan['key']])
                || ! is_array($plan['grants'] ?? null)) {
                return $this->invalid('catalog_invalid');
            }
            $planKeys[$plan['key']] = true;
            try {
                $framework = json_encode([
                    'schema_version' => 1,
                    'source' => 'direct',
                    'catalog_revision' => $catalog['catalog_revision'],
                    'source_revision' => 1,
                    'grants' => (object) $plan['grants'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                return $this->invalid('catalog_invalid');
            }
            $validation = $this->frameworkValidator->validate($framework, [
                'expected_product_code' => $expectedProductCode,
                'claimed_product_code' => $expectedProductCode,
                'expected_license_uuid' => 'catalog',
                'claimed_license_uuid' => 'catalog',
                'expected_source' => 'direct',
                'catalog_revision' => $catalog['catalog_revision'],
                'minimum_source_revision' => 1,
                'catalog' => $definitions,
            ]);
            if (! $validation['valid']) {
                return $this->invalid($validation['error'] ?? 'catalog_invalid');
            }
        }

        return ['valid' => true, 'error' => null, 'definitions' => $definitions];
    }

    private function isIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1;
    }

    /** @return array{valid: false, error: string} */
    private function invalid(string $error): array
    {
        return ['valid' => false, 'error' => $error];
    }
}
