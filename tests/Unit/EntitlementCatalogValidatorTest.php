<?php

namespace KernelBridge\LicensingClient\Tests\Unit;

use KernelBridge\LicensingClient\Services\EntitlementCatalogValidator;
use KernelBridge\LicensingClient\Services\EntitlementFrameworkValidator;
use PHPUnit\Framework\TestCase;

final class EntitlementCatalogValidatorTest extends TestCase
{
    public function test_it_validates_the_plan_package_module_capability_hierarchy(): void
    {
        $catalog = [
            'schema_version' => 1,
            'product_code' => 'MEDIFLOW',
            'catalog_revision' => 3,
            'packages' => [[
                'key' => 'clinical',
                'modules' => [[
                    'key' => 'pharmacy',
                    'capabilities' => [
                        ['key' => 'mediflow.module.pharmacy', 'type' => 'capability', 'description' => 'Pharmacy access'],
                        ['key' => 'mediflow.branches.max', 'type' => 'limit', 'unit' => 'branches', 'scope' => 'business'],
                    ],
                ]],
            ]],
            'plans' => [[
                'key' => 'professional',
                'grants' => [
                    'mediflow.module.pharmacy' => ['type' => 'capability', 'enabled' => true],
                    'mediflow.branches.max' => [
                        'type' => 'limit',
                        'state' => 'finite',
                        'value' => 3,
                        'unit' => 'branches',
                        'scope' => 'business',
                    ],
                ],
            ]],
        ];

        $result = (new EntitlementCatalogValidator(new EntitlementFrameworkValidator))->validate($catalog, 'MEDIFLOW');

        self::assertTrue($result['valid']);
        self::assertNull($result['error']);
        self::assertSame([
            'mediflow.module.pharmacy' => ['type' => 'capability'],
            'mediflow.branches.max' => ['type' => 'limit', 'unit' => 'branches', 'scope' => 'business'],
        ], $result['definitions']);
    }

    public function test_it_rejects_mismatched_products_duplicate_hierarchy_keys_and_unknown_plan_grants(): void
    {
        $validator = new EntitlementCatalogValidator(new EntitlementFrameworkValidator);
        $catalog = [
            'schema_version' => 1,
            'product_code' => 'MEDIFLOW',
            'catalog_revision' => 1,
            'packages' => [[
                'key' => 'clinical',
                'modules' => [[
                    'key' => 'pharmacy',
                    'capabilities' => [['key' => 'mediflow.module.pharmacy', 'type' => 'capability']],
                ]],
            ]],
            'plans' => [['key' => 'basic', 'grants' => []]],
        ];

        self::assertSame('product_mismatch', $validator->validate($catalog, 'EDUNEXA')['error']);
        self::assertSame('catalog_invalid', $validator->validate([
            ...$catalog,
            'packages' => [...$catalog['packages'], ...$catalog['packages']],
        ], 'MEDIFLOW')['error']);
        self::assertSame('unknown_grant', $validator->validate([
            ...$catalog,
            'plans' => [['key' => 'basic', 'grants' => [
                'unknown.grant' => ['type' => 'capability', 'enabled' => true],
            ]]],
        ], 'MEDIFLOW')['error']);
    }
}
