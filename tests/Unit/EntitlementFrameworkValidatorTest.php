<?php

namespace KernelBridge\LicensingClient\Tests\Unit;

use KernelBridge\LicensingClient\Services\EntitlementFrameworkValidator;
use PHPUnit\Framework\TestCase;

final class EntitlementFrameworkValidatorTest extends TestCase
{
    public function test_it_matches_the_shared_entitlement_framework_conformance_fixtures(): void
    {
        $fixturePath = dirname(__DIR__, 4)
            . DIRECTORY_SEPARATOR . 'protocol'
            . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'entitlement-framework-v1.json';
        $fixture = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $fixture['format_version']);
        $baseContext = $fixture['context'];
        unset($baseContext['catalog']);
        $catalog = $fixture['context']['catalog'];
        $validator = new EntitlementFrameworkValidator;

        foreach ($fixture['cases'] as $case) {
            $context = array_replace($baseContext, $case['context'] ?? []);
            $context['catalog'] = $case['context']['catalog'] ?? $catalog;
            $result = $validator->validate($case['payload_json'], $context);

            self::assertSame($case['expected']['valid'], $result['valid'], $case['id']);
            self::assertSame($case['expected']['error'], $result['error'], $case['id']);
        }

        foreach ($fixture['update_cases'] as $case) {
            $result = $validator->validatePlatformUpdate($case['payload_json'], $fixture['update_context']);

            self::assertSame($case['expected']['valid'], $result['valid'], $case['id']);
            self::assertSame($case['expected']['error'], $result['error'], $case['id']);
        }
    }
}
