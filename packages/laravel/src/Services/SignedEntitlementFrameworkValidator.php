<?php

namespace KernelBridge\LicensingClient\Services;

use DateTimeZone;
use stdClass;

final class SignedEntitlementFrameworkValidator
{
    public function isValid(mixed $framework, mixed $licensingMode, mixed $platformLinkUuid): bool
    {
        if (! $framework instanceof stdClass
            || ! $this->hasOnlyProperties($framework, ['schema_version', 'source', 'catalog_revision', 'source_revision', 'grants'])
            || $framework->schema_version !== 1
            || ! is_int($framework->catalog_revision) || $framework->catalog_revision < 1
            || ! is_int($framework->source_revision) || $framework->source_revision < 1
            || ! is_object($framework->grants)) {
            return false;
        }

        $validPlatformLink = is_string($platformLinkUuid)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $platformLinkUuid) === 1;
        $expectedSource = match (true) {
            $licensingMode === 'direct' && $platformLinkUuid === null => 'direct',
            $licensingMode === 'platform_account' && $validPlatformLink => 'product_platform',
            $licensingMode === 'hybrid' && $platformLinkUuid === null => 'direct',
            $licensingMode === 'hybrid' && $validPlatformLink => 'product_platform',
            default => null,
        };
        if ($expectedSource === null || $framework->source !== $expectedSource) {
            return false;
        }

        foreach (get_object_vars($framework->grants) as $key => $grant) {
            if (! preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $key)
                || ! $this->validGrant($grant)) {
                return false;
            }
        }

        return true;
    }

    private function validGrant(mixed $grant): bool
    {
        if (! $grant instanceof stdClass || ! is_string($grant->type ?? null)) {
            return false;
        }
        if ($grant->type === 'capability') {
            return $this->hasOnlyProperties($grant, ['type', 'enabled'])
                && is_bool($grant->enabled ?? null);
        }
        if ($grant->type !== 'limit'
            || ! in_array($grant->state ?? null, ['finite', 'unlimited'], true)) {
            return false;
        }

        $allowed = $grant->state === 'finite'
            ? ['type', 'state', 'value', 'unit', 'scope', 'period']
            : ['type', 'state', 'unit', 'scope', 'period'];
        if (! $this->hasOnlyProperties($grant, $allowed)
            || ! $this->isIdentifier($grant->unit ?? null)
            || ! $this->isIdentifier($grant->scope ?? null)) {
            return false;
        }
        if ($grant->state === 'finite'
            && ((! is_int($grant->value ?? null) && ! is_float($grant->value ?? null))
                || ! is_finite((float) $grant->value)
                || $grant->value < 0)) {
            return false;
        }
        if (! property_exists($grant, 'period')) {
            return true;
        }

        $period = $grant->period;
        if (! $period instanceof stdClass
            || ! $this->hasOnlyProperties($period, ['unit', 'reset', 'time_zone'])
            || ! in_array($period->unit ?? null, ['day', 'week', 'month', 'year'], true)
            || ($period->reset ?? null) !== 'calendar'
            || ! is_string($period->time_zone ?? null)) {
            return false;
        }

        return in_array($period->time_zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    private function isIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1;
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
}
