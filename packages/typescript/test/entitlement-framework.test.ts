import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
  validateEntitlementCatalog,
  validateEntitlementFramework,
  validatePlatformEntitlementUpdate,
  validateSignedEntitlementFramework,
  type EntitlementGrantDefinition,
  type EntitlementValidationContext,
  type EntitlementValidationResult,
  type PlatformEntitlementUpdateValidationContext,
} from '../src/entitlement-framework.js';

interface FixtureContext {
  expected_product_code: string;
  claimed_product_code: string;
  expected_license_uuid: string;
  claimed_license_uuid: string;
  expected_source: 'direct' | 'product_platform';
  catalog_revision: number;
  minimum_source_revision: number;
  catalog: Record<string, EntitlementGrantDefinition>;
}

interface FixtureCase {
  id: string;
  payload_json: string;
  context?: Partial<FixtureContext>;
  expected: EntitlementValidationResult;
}

interface Fixture {
  format_version: number;
  context: FixtureContext;
  cases: FixtureCase[];
  update_context: Omit<FixtureContext, 'claimed_product_code' | 'claimed_license_uuid' | 'expected_source' | 'minimum_source_revision'> & {
    expected_account_link_uuid: string;
    current_source_revision: number;
  };
  update_cases: FixtureCase[];
}

test('entitlement framework validator matches shared conformance fixtures', async t => {
  const fixture = JSON.parse(readFileSync(new URL('../../../../protocol/fixtures/entitlement-framework-v1.json', import.meta.url), 'utf8')) as Fixture;
  assert.equal(fixture.format_version, 1);

  const { catalog, ...baseContext } = fixture.context;
  for (const fixtureCase of fixture.cases) {
    await t.test(fixtureCase.id, () => {
      const { catalog: caseCatalog, ...caseContext } = fixtureCase.context ?? {};
      const mergedContext = { ...baseContext, ...caseContext };
      const context: EntitlementValidationContext = {
        expectedProductCode: mergedContext.expected_product_code,
        claimedProductCode: mergedContext.claimed_product_code,
        expectedLicenseUuid: mergedContext.expected_license_uuid,
        claimedLicenseUuid: mergedContext.claimed_license_uuid,
        expectedSource: mergedContext.expected_source,
        catalogRevision: mergedContext.catalog_revision,
        minimumSourceRevision: mergedContext.minimum_source_revision,
        catalog: caseCatalog ?? catalog,
      };
      assert.deepEqual(validateEntitlementFramework(fixtureCase.payload_json, context), fixtureCase.expected);
    });
  }

  const updateContext: PlatformEntitlementUpdateValidationContext = {
    expectedProductCode: fixture.update_context.expected_product_code,
    expectedLicenseUuid: fixture.update_context.expected_license_uuid,
    expectedAccountLinkUuid: fixture.update_context.expected_account_link_uuid,
    catalogRevision: fixture.update_context.catalog_revision,
    currentSourceRevision: fixture.update_context.current_source_revision,
    catalog: fixture.update_context.catalog,
  };
  for (const fixtureCase of fixture.update_cases) {
    await t.test(fixtureCase.id, () => {
      assert.deepEqual(validatePlatformEntitlementUpdate(fixtureCase.payload_json, updateContext), fixtureCase.expected);
    });
  }
});

test('signed snapshot framework validation binds source and validates grant shape', () => {
  const valid = {
    schema_version: 1,
    source: 'direct',
    catalog_revision: 2,
    source_revision: 4,
    grants: {
      'records.view': { type: 'capability', enabled: true },
      'branches.max': {
        type: 'limit',
        state: 'finite',
        value: 5,
        unit: 'branches',
        scope: 'business',
        period: { unit: 'month', reset: 'calendar', time_zone: 'Africa/Accra' },
      },
    },
  };
  assert.equal(validateSignedEntitlementFramework(valid, 'direct', null), null);
  assert.equal(validateSignedEntitlementFramework(valid, 'hybrid', null), null);
  assert.equal(validateSignedEntitlementFramework({
    schema_version: 1,
    source: 'direct',
    catalog_revision: 1,
    source_revision: 1,
    grants: {},
  }, 'direct', null), null);
  assert.equal(validateSignedEntitlementFramework(valid, 'platform_account', '11111111-1111-4111-8111-111111111111'), 'invalid_source');

  assert.equal(validateSignedEntitlementFramework({
    ...valid,
    source: 'product_platform',
  }, 'hybrid', null), 'invalid_source');
  assert.equal(validateSignedEntitlementFramework({
    ...valid,
    grants: { 'records.view': { type: 'capability', enabled: 'yes' } },
  }, 'direct', null), 'malformed_grant');
  assert.equal(validateSignedEntitlementFramework({
    ...valid,
    schema_version: 2,
  }, 'direct', null), 'unsupported_schema');
});

test('entitlement catalog validates plan-package-module-capability hierarchy and returns runtime definitions', () => {
  const catalog = {
    schema_version: 1,
    product_code: 'MEDIFLOW',
    catalog_revision: 3,
    packages: [{
      key: 'clinical',
      modules: [{
        key: 'pharmacy',
        capabilities: [
          { key: 'mediflow.module.pharmacy', type: 'capability', description: 'Pharmacy access' },
          { key: 'mediflow.branches.max', type: 'limit', unit: 'branches', scope: 'business' },
        ],
      }],
    }],
    plans: [{
      key: 'professional',
      grants: {
        'mediflow.module.pharmacy': { type: 'capability', enabled: true },
        'mediflow.branches.max': { type: 'limit', state: 'finite', value: 3, unit: 'branches', scope: 'business' },
      },
    }],
  };
  const result = validateEntitlementCatalog(catalog, 'MEDIFLOW');
  assert.equal(result.valid, true);
  assert.equal(result.error, null);
  assert.deepEqual(result.definitions, {
    'mediflow.module.pharmacy': { type: 'capability' },
    'mediflow.branches.max': { type: 'limit', unit: 'branches', scope: 'business' },
  });
});

test('entitlement catalog rejects duplicate keys, unknown plan grants, and product mismatch', () => {
  const base = {
    schema_version: 1,
    product_code: 'MEDIFLOW',
    catalog_revision: 1,
    packages: [{ key: 'clinical', modules: [{ key: 'pharmacy', capabilities: [{ key: 'mediflow.module.pharmacy', type: 'capability' }] }] }],
    plans: [{ key: 'basic', grants: {} }],
  };
  assert.equal(validateEntitlementCatalog(base, 'EDUNEXA').error, 'product_mismatch');
  assert.equal(validateEntitlementCatalog({
    ...base,
    packages: [...base.packages, ...base.packages],
  }, 'MEDIFLOW').error, 'catalog_invalid');
  assert.equal(validateEntitlementCatalog({
    ...base,
    plans: [{ key: 'basic', grants: { 'unknown.grant': { type: 'capability', enabled: true } } }],
  }, 'MEDIFLOW').error, 'unknown_grant');
});
