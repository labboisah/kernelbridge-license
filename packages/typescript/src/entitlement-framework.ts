import type { EntitlementPeriod } from './types.js';

export type EntitlementSource = 'direct' | 'product_platform';
export type EntitlementValidationError =
  | 'malformed_json'
  | 'duplicate_key'
  | 'unsupported_schema'
  | 'product_mismatch'
  | 'license_mismatch'
  | 'account_link_mismatch'
  | 'invalid_source'
  | 'catalog_revision_mismatch'
  | 'stale_source_revision'
  | 'unknown_grant'
  | 'unknown_grant_type'
  | 'malformed_grant'
  | 'invalid_unit'
  | 'invalid_scope'
  | 'invalid_period';

export interface EntitlementCatalogValidationResult {
  valid: boolean;
  error: 'catalog_invalid' | 'unsupported_schema' | 'product_mismatch' | EntitlementValidationError | null;
  definitions?: Readonly<Record<string, EntitlementGrantDefinition>>;
}

export function validateEntitlementCatalog(
  catalog: unknown,
  expectedProductCode: string,
): EntitlementCatalogValidationResult {
  if (!isRecord(catalog)
    || !hasOnlyKeys(catalog, ['schema_version', 'product_code', 'catalog_revision', 'packages', 'plans'])
    || !Array.isArray(catalog.packages)
    || !Array.isArray(catalog.plans)) {
    return { valid: false, error: 'catalog_invalid' };
  }
  if (catalog.schema_version !== 1) return { valid: false, error: 'unsupported_schema' };
  if (typeof catalog.product_code !== 'string'
    || catalog.product_code.toUpperCase() !== expectedProductCode.toUpperCase()) {
    return { valid: false, error: 'product_mismatch' };
  }
  if (typeof catalog.catalog_revision !== 'number'
    || !Number.isInteger(catalog.catalog_revision)
    || catalog.catalog_revision < 1) {
    return { valid: false, error: 'catalog_invalid' };
  }

  const definitions: Record<string, EntitlementGrantDefinition> = {};
  const packageKeys = new Set<string>();
  const moduleKeys = new Set<string>();
  for (const packageDefinition of catalog.packages) {
    if (!isRecord(packageDefinition)
      || !hasOnlyKeys(packageDefinition, ['key', 'modules'])
      || !isIdentifier(packageDefinition.key)
      || packageKeys.has(packageDefinition.key)
      || !Array.isArray(packageDefinition.modules)) {
      return { valid: false, error: 'catalog_invalid' };
    }
    packageKeys.add(packageDefinition.key);
    for (const moduleDefinition of packageDefinition.modules) {
      if (!isRecord(moduleDefinition)
        || !hasOnlyKeys(moduleDefinition, ['key', 'capabilities'])
        || !isIdentifier(moduleDefinition.key)
        || moduleKeys.has(moduleDefinition.key)
        || !Array.isArray(moduleDefinition.capabilities)) {
        return { valid: false, error: 'catalog_invalid' };
      }
      moduleKeys.add(moduleDefinition.key);
      for (const capability of moduleDefinition.capabilities) {
        if (!isRecord(capability)
          || !hasOnlyKeys(capability, ['key', 'type', 'description', 'unit', 'scope', 'period'])
          || typeof capability.key !== 'string'
          || !/^[a-z][a-z0-9_.-]{0,127}$/.test(capability.key)
          || Object.hasOwn(definitions, capability.key)
          || (capability.description !== undefined && (typeof capability.description !== 'string' || capability.description.length > 500))) {
          return { valid: false, error: 'catalog_invalid' };
        }
        if (capability.type === 'capability') {
          if (capability.unit !== undefined || capability.scope !== undefined || capability.period !== undefined) {
            return { valid: false, error: 'catalog_invalid' };
          }
          definitions[capability.key] = { type: 'capability' };
          continue;
        }
        if (capability.type !== 'limit'
          || !isIdentifier(capability.unit)
          || !isIdentifier(capability.scope)
          || (capability.period !== undefined && !isPeriod(capability.period))) {
          return { valid: false, error: 'catalog_invalid' };
        }
        definitions[capability.key] = {
          type: 'limit',
          unit: capability.unit,
          scope: capability.scope,
          ...(isPeriod(capability.period) ? { period: capability.period } : {}),
        };
      }
    }
  }

  const planKeys = new Set<string>();
  for (const plan of catalog.plans) {
    if (!isRecord(plan)
      || !hasOnlyKeys(plan, ['key', 'grants'])
      || !isIdentifier(plan.key)
      || planKeys.has(plan.key)
      || !isRecord(plan.grants)) {
      return { valid: false, error: 'catalog_invalid' };
    }
    planKeys.add(plan.key);
    const result = validateEntitlementFramework(JSON.stringify({
      schema_version: 1,
      source: 'direct',
      catalog_revision: catalog.catalog_revision,
      source_revision: 1,
      grants: plan.grants,
    }), {
      expectedProductCode,
      claimedProductCode: expectedProductCode,
      expectedLicenseUuid: 'catalog',
      claimedLicenseUuid: 'catalog',
      expectedSource: 'direct',
      catalogRevision: catalog.catalog_revision,
      minimumSourceRevision: 1,
      catalog: definitions,
    });
    if (!result.valid) return { valid: false, error: result.error };
  }

  return { valid: true, error: null, definitions };
}

export interface EntitlementGrantDefinition {
  type: 'capability' | 'limit';
  unit?: string;
  scope?: string;
  period?: EntitlementPeriod;
}

export interface EntitlementValidationContext {
  expectedProductCode: string;
  claimedProductCode: string;
  expectedLicenseUuid: string;
  claimedLicenseUuid: string;
  expectedSource: EntitlementSource;
  catalogRevision: number;
  minimumSourceRevision: number;
  catalog: Readonly<Record<string, EntitlementGrantDefinition>>;
}

export interface PlatformEntitlementUpdateValidationContext {
  expectedProductCode: string;
  expectedLicenseUuid: string;
  expectedAccountLinkUuid: string;
  catalogRevision: number;
  currentSourceRevision: number;
  catalog: Readonly<Record<string, EntitlementGrantDefinition>>;
}

export interface EntitlementValidationResult {
  valid: boolean;
  error: EntitlementValidationError | null;
}

type JsonRecord = Record<string, unknown>;

export function validateSignedEntitlementFramework(
  framework: unknown,
  licensingMode: unknown,
  platformLinkUuid: unknown,
): EntitlementValidationError | null {
  if (!isRecord(framework)
    || !hasOnlyKeys(framework, ['schema_version', 'source', 'catalog_revision', 'source_revision', 'grants'])
    || framework.schema_version !== 1) return 'unsupported_schema';
  if (!Number.isInteger(framework.catalog_revision) || (framework.catalog_revision as number) < 1
    || !Number.isInteger(framework.source_revision) || (framework.source_revision as number) < 1
    || !isRecord(framework.grants)) return 'malformed_grant';

  const validPlatformLink = typeof platformLinkUuid === 'string'
    && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(platformLinkUuid);
  const expectedSource = licensingMode === 'direct' && platformLinkUuid === null
    ? 'direct'
    : licensingMode === 'platform_account' && validPlatformLink
      ? 'product_platform'
      : licensingMode === 'hybrid' && platformLinkUuid === null
        ? 'direct'
        : licensingMode === 'hybrid' && validPlatformLink
          ? 'product_platform'
          : null;
  if (expectedSource === null || framework.source !== expectedSource) return 'invalid_source';

  for (const [key, grant] of Object.entries(framework.grants)) {
    if (!/^[a-z][a-z0-9_.-]{0,127}$/.test(key)) return 'malformed_grant';
    const error = validateSnapshotGrant(grant);
    if (error) return error;
  }
  return null;
}

export function validateEntitlementFramework(
  payloadJson: string,
  context: EntitlementValidationContext,
): EntitlementValidationResult {
  const parsed = parseUniqueJson(payloadJson);
  if ('error' in parsed) return invalid(parsed.error);
  const decoded = parsed.value;
  if (!isRecord(decoded) || !hasOnlyKeys(decoded, ['schema_version', 'source', 'catalog_revision', 'source_revision', 'grants'])) {
    return invalid('malformed_grant');
  }
  if (decoded.schema_version !== 1) return invalid('unsupported_schema');
  if (context.expectedProductCode.toUpperCase() !== context.claimedProductCode.toUpperCase()) return invalid('product_mismatch');
  if (context.expectedLicenseUuid !== context.claimedLicenseUuid) return invalid('license_mismatch');
  if ((decoded.source !== 'direct' && decoded.source !== 'product_platform') || decoded.source !== context.expectedSource) {
    return invalid('invalid_source');
  }
  if (!Number.isInteger(decoded.catalog_revision) || decoded.catalog_revision !== context.catalogRevision) {
    return invalid('catalog_revision_mismatch');
  }
  if (!Number.isInteger(decoded.source_revision) || (decoded.source_revision as number) < context.minimumSourceRevision) {
    return invalid('stale_source_revision');
  }
  const grantsError = validateGrantSet(decoded.grants, context.catalog);
  if (grantsError) return invalid(grantsError);

  return { valid: true, error: null };
}

export function validatePlatformEntitlementUpdate(
  payloadJson: string,
  context: PlatformEntitlementUpdateValidationContext,
): EntitlementValidationResult {
  const parsed = parseUniqueJson(payloadJson);
  if ('error' in parsed) return invalid(parsed.error);
  const update = parsed.value;
  const allowedKeys = [
    'schema_version',
    'product_code',
    'license_uuid',
    'account_link_uuid',
    'catalog_revision',
    'source_revision',
    'idempotency_key',
    'grants',
  ];
  if (!isRecord(update) || !hasOnlyKeys(update, allowedKeys)) return invalid('malformed_grant');
  if (update.schema_version !== 1) return invalid('unsupported_schema');
  if (typeof update.product_code !== 'string'
    || update.product_code.toUpperCase() !== context.expectedProductCode.toUpperCase()) return invalid('product_mismatch');
  if (update.license_uuid !== context.expectedLicenseUuid) return invalid('license_mismatch');
  if (update.account_link_uuid !== context.expectedAccountLinkUuid) return invalid('account_link_mismatch');
  if (!Number.isInteger(update.catalog_revision) || update.catalog_revision !== context.catalogRevision) {
    return invalid('catalog_revision_mismatch');
  }
  if (!Number.isInteger(update.source_revision)) return invalid('malformed_grant');
  if ((update.source_revision as number) <= context.currentSourceRevision) return invalid('stale_source_revision');
  if (typeof update.idempotency_key !== 'string' || update.idempotency_key.trim() === '' || update.idempotency_key.length > 128) {
    return invalid('malformed_grant');
  }
  const grantsError = validateGrantSet(update.grants, context.catalog);
  if (grantsError) return invalid(grantsError);

  return { valid: true, error: null };
}

function validateGrantSet(
  grants: unknown,
  catalog: Readonly<Record<string, EntitlementGrantDefinition>>,
): EntitlementValidationError | null {
  if (!isRecord(grants)) return 'malformed_grant';
  for (const [key, grant] of Object.entries(grants)) {
    if (!/^[a-z][a-z0-9_.-]{0,127}$/.test(key)) return 'malformed_grant';
    const definition = catalog[key];
    if (!definition) return 'unknown_grant';
    const validation = validateGrant(grant, definition);
    if (validation) return validation;
  }
  return null;
}

function validateGrant(grant: unknown, definition: EntitlementGrantDefinition): EntitlementValidationError | null {
  if (!isRecord(grant) || typeof grant.type !== 'string') return 'malformed_grant';
  if (grant.type !== 'capability' && grant.type !== 'limit') return 'unknown_grant_type';

  if (grant.type === 'capability') {
    return definition.type === 'capability'
      && hasOnlyKeys(grant, ['type', 'enabled'])
      && typeof grant.enabled === 'boolean'
      ? null
      : 'malformed_grant';
  }

  if (definition.type !== 'limit') return 'malformed_grant';
  if (grant.state !== 'finite' && grant.state !== 'unlimited') return 'malformed_grant';
  const allowedKeys = grant.state === 'finite'
    ? ['type', 'state', 'value', 'unit', 'scope', 'period']
    : ['type', 'state', 'unit', 'scope', 'period'];
  if (!hasOnlyKeys(grant, allowedKeys)) return 'malformed_grant';
  if (grant.state === 'finite' && (typeof grant.value !== 'number' || !Number.isFinite(grant.value) || grant.value < 0)) {
    return 'malformed_grant';
  }
  if (typeof grant.unit !== 'string' || grant.unit !== definition.unit) return 'invalid_unit';
  if (typeof grant.scope !== 'string' || grant.scope !== definition.scope) return 'invalid_scope';

  const hasPeriod = Object.hasOwn(grant, 'period');
  if (definition.period) {
    if (!hasPeriod || !samePeriod(grant.period, definition.period)) return 'invalid_period';
    if (!isValidTimeZone(definition.period.time_zone)) return 'invalid_period';
  } else if (hasPeriod) {
    return 'invalid_period';
  }
  return null;
}

function samePeriod(value: unknown, expected: EntitlementPeriod): boolean {
  if (!isRecord(value)
    || !hasOnlyKeys(value, ['unit', 'reset', 'time_zone'])
    || !['day', 'week', 'month', 'year'].includes(String(value.unit))
    || value.reset !== 'calendar'
    || typeof value.time_zone !== 'string') return false;
  return value.unit === expected.unit && value.reset === expected.reset && value.time_zone === expected.time_zone;
}

function validateSnapshotGrant(grant: unknown): EntitlementValidationError | null {
  if (!isRecord(grant) || typeof grant.type !== 'string') return 'malformed_grant';
  if (grant.type === 'capability') {
    return hasOnlyKeys(grant, ['type', 'enabled']) && typeof grant.enabled === 'boolean'
      ? null
      : 'malformed_grant';
  }
  if (grant.type !== 'limit' || (grant.state !== 'finite' && grant.state !== 'unlimited')) return 'malformed_grant';
  const allowedKeys = grant.state === 'finite'
    ? ['type', 'state', 'value', 'unit', 'scope', 'period']
    : ['type', 'state', 'unit', 'scope', 'period'];
  if (!hasOnlyKeys(grant, allowedKeys)
    || typeof grant.unit !== 'string' || !/^[a-z][a-z0-9_.-]{0,63}$/.test(grant.unit)
    || typeof grant.scope !== 'string' || !/^[a-z][a-z0-9_.-]{0,63}$/.test(grant.scope)) return 'malformed_grant';
  if (grant.state === 'finite'
    && (typeof grant.value !== 'number' || !Number.isFinite(grant.value) || grant.value < 0)) return 'malformed_grant';
  if (!Object.hasOwn(grant, 'period')) return null;
  if (!isRecord(grant.period)
    || !hasOnlyKeys(grant.period, ['unit', 'reset', 'time_zone'])
    || !['day', 'week', 'month', 'year'].includes(String(grant.period.unit))
    || grant.period.reset !== 'calendar'
    || typeof grant.period.time_zone !== 'string'
    || !isValidTimeZone(grant.period.time_zone)) return 'invalid_period';
  return null;
}

function isValidTimeZone(timeZone: string): boolean {
  try {
    new Intl.DateTimeFormat('en-US', { timeZone });
    return true;
  } catch (error) {
    if (error instanceof RangeError) return false;
    throw error;
  }
}

function containsDuplicateObjectKey(source: string): boolean {
  const objectKeys: Set<string>[] = [];
  for (let index = 0; index < source.length; index += 1) {
    const character = source[index];
    if (character === '{') {
      objectKeys.push(new Set<string>());
      continue;
    }
    if (character === '}') {
      objectKeys.pop();
      continue;
    }
    if (character !== '"') continue;

    const start = index;
    let escaped = false;
    index += 1;
    for (; index < source.length; index += 1) {
      const current = source[index];
      if (escaped) {
        escaped = false;
      } else if (current === '\\') {
        escaped = true;
      } else if (current === '"') {
        break;
      }
    }
    const token = source.slice(start, index + 1);
    let lookahead = index + 1;
    while (/\s/.test(source[lookahead] ?? '')) lookahead += 1;
    if (source[lookahead] === ':' && objectKeys.length > 0) {
      let key: string;
      try {
        key = JSON.parse(token) as string;
      } catch (error) {
        if (error instanceof SyntaxError) return false;
        throw error;
      }
      const currentKeys = objectKeys[objectKeys.length - 1];
      if (currentKeys?.has(key)) return true;
      currentKeys?.add(key);
    }
  }
  return false;
}

function parseUniqueJson(
  payloadJson: string,
): { value: unknown } | { error: 'duplicate_key' | 'malformed_json' } {
  if (containsDuplicateObjectKey(payloadJson)) return { error: 'duplicate_key' };
  try {
    return { value: JSON.parse(payloadJson) as unknown };
  } catch (error) {
    if (error instanceof SyntaxError) return { error: 'malformed_json' };
    throw error;
  }
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isIdentifier(value: unknown): value is string {
  return typeof value === 'string' && /^[a-z][a-z0-9_.-]{0,63}$/.test(value);
}

function isPeriod(value: unknown): value is EntitlementPeriod {
  if (!isRecord(value)
    || !hasOnlyKeys(value, ['unit', 'reset', 'time_zone'])
    || !['day', 'week', 'month', 'year'].includes(String(value.unit))
    || value.reset !== 'calendar'
    || typeof value.time_zone !== 'string'
    || !isValidTimeZone(value.time_zone)) return false;
  return true;
}

function hasOnlyKeys(record: JsonRecord, allowed: readonly string[]): boolean {
  return Object.keys(record).every(key => allowed.includes(key));
}

function invalid(error: EntitlementValidationError): EntitlementValidationResult {
  return { valid: false, error };
}
