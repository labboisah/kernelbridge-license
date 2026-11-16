import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createSign, generateKeyPairSync, sign as cryptoSign } from 'node:crypto';
import { test } from 'node:test';
import Database from 'better-sqlite3';
import { DeploymentProfileService, Ed25519EntitlementVerifier, KernelBridgeUnavailableError, LicenseAccessError, LicenseActivationService, LicenseCacheService, LicenseGuard, LicenseVerificationService, type DeploymentProfileStore, type Entitlement, type LicenseStore, type SecretVault, KernelBridgeLicenseClient, licensingOptionsFromEnv, PlatformAccountLinkClient, RsaSha256EntitlementVerifier, SqliteLicenseStore, type SqliteDatabase } from '../src/index.js';

class MemoryStore implements LicenseStore {
  value: string | null = null;
  async load(): Promise<string | null> { return this.value; }
  async save(_productCode: string, value: string): Promise<void> { this.value = value; }
  async remove(): Promise<void> { this.value = null; }
}
class TestVault implements SecretVault {
  isAvailable(): boolean { return true; }
  async encrypt(value: string): Promise<string> { return Buffer.from(value).toString('base64'); }
  async decrypt(value: string): Promise<string> { return Buffer.from(value, 'base64').toString(); }
}
class MemoryProfileStore implements DeploymentProfileStore {
  value: string | null = null;
  async load(): Promise<string | null> { return this.value; }
  async save(_productCode: string, value: string): Promise<void> { this.value = value; }
}

const active = { license_uuid: 'license-uuid', activation_uuid: 'activation-uuid', subscription_uuid: 'subscription-uuid', status: 'active' as const, expires_at: '2030-01-01T00:00:00.000Z' };
const machine = 'a'.repeat(64);
const options = { apiUrl: 'https://kernelbridge.test/api/v1', productCode: 'ALIGNEX', apiToken: 'test-token', cacheSigningKey: 'test-cache-signing-key', appKey: 'desktop-app-key', machineIdentityProvider: { getFingerprint: async () => machine }, now: () => new Date('2027-01-01T00:00:00.000Z') };

function successFetch(requests: Request[], signed?: { signed_payload: string; signature: string; signature_algorithm: string; key_id: string }): typeof fetch {
  return async (input, init) => {
    const request = new Request(input, init); requests.push(request); const path = new URL(request.url).pathname;
    const data = path.endsWith('/subscriptions/status') ? { status: 'active', ends_at: active.expires_at }
      : path.endsWith('/entitlements') ? { entitlements: [{ key: 'finance', enabled: true, limit: null }, { key: 'inventory', enabled: false, limit: null }, { key: 'max_users', enabled: true, limit: '25' }], ...signed }
        : path.endsWith('/licenses/deactivate') ? { license_uuid: active.license_uuid, activation_uuid: active.activation_uuid, status: 'deactivated' }
          : active;
    return new Response(JSON.stringify({ success: true, data }), { status: path.endsWith('/licenses/activate') ? 201 : 200, headers: { 'Content-Type': 'application/json' } });
  };
}

test('activates, encrypts local state, and answers entitlement decisions without per-request API calls', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) };
  const client = new KernelBridgeLicenseClient(configured); const cache = new LicenseCacheService(store, new TestVault(), configured);
  const state = await new LicenseActivationService(client, cache, configured).activate('KBT-LICENSE-SECRET', 'AlignEx desktop');
  assert.equal(state.status, 'active'); assert.equal(requests.length, 3); assert.ok(store.value); assert.ok(!store.value.includes('KBT-LICENSE-SECRET'));
  assert.equal(await cache.hasFeature('finance'), true); assert.equal(await cache.hasFeature('inventory'), false); assert.equal(await cache.limit('max_users'), '25'); assert.equal(requests.length, 3);
  assert.equal(requests[0]?.headers.get('Authorization'), 'Bearer test-token'); assert.equal(requests[0]?.headers.get('X-KernelBridge-Product'), 'ALIGNEX');
  assert.equal((await requests[0]!.clone().json() as { machine_fingerprint: string }).machine_fingerprint, machine);
});

test('machine identity is bound on activation, verification, and deactivation', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) };
  const client = new KernelBridgeLicenseClient(configured); const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(client, cache, configured).activate('KBT-LICENSE-SECRET');
  await new LicenseVerificationService(client, cache, configured).verify(true);
  await new LicenseVerificationService(client, cache, configured).deactivate();
  for (const request of requests.filter(item => /\/licenses\/(activate|verify|deactivate)$/.test(new URL(item.url).pathname))) {
    assert.equal((await request.clone().json() as { machine_fingerprint: string }).machine_fingerprint, machine);
  }
});

test('a copied cache fails closed on a different machine without invalidating the central license', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) };
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), new LicenseCacheService(store, new TestVault(), configured), configured).activate('KBT-LICENSE-SECRET');
  const otherMachine = { ...configured, machineIdentityProvider: { getFingerprint: async () => 'b'.repeat(64) } };
  const cache = new LicenseCacheService(store, new TestVault(), otherMachine);
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'device_mismatch');
  await assert.rejects(() => new LicenseVerificationService(new KernelBridgeLicenseClient(otherMachine), cache, otherMachine).verify(true), (error: unknown) => error instanceof Error && 'code' in error && error.code === 'device_mismatch');
  assert.equal((await cache.state()).status, 'active');
});

test('legacy state is migrated and rebound through one activation request', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');
  const legacy = JSON.parse(Buffer.from(store.value!, 'base64').toString()) as Record<string, unknown>;
  legacy.version = 1; delete legacy.machineFingerprint;
  store.value = Buffer.from(JSON.stringify(legacy)).toString('base64');

  assert.equal((await cache.state()).version, 3);
  await new LicenseVerificationService(new KernelBridgeLicenseClient(configured), cache, configured).verify(true);
  assert.equal(new URL(requests[3]!.url).pathname, '/api/v1/licenses/activate');
  assert.equal((await cache.state()).machineFingerprint, machine);
});

test('uses signed local grace during an outage and fails closed after grace', async () => {
  const store = new MemoryStore(); const good = { ...options, fetch: successFetch([]) }; const cache = new LicenseCacheService(store, new TestVault(), good);
  await new LicenseActivationService(new KernelBridgeLicenseClient(good), cache, good).activate('KBT-LICENSE-SECRET');
  const outage = { ...options, fetch: (async () => { throw new Error('offline'); }) as typeof fetch };
  assert.equal((await new LicenseVerificationService(new KernelBridgeLicenseClient(outage), cache, outage).verify(true)).status, 'active');
  const state = await cache.state(); state.offlineGraceExpiresAt = '2026-12-31T23:59:59.000Z'; await cache.save(state);
  await assert.rejects(() => new LicenseVerificationService(new KernelBridgeLicenseClient(outage), cache, outage).verify(true), KernelBridgeUnavailableError);
});

test('active, feature, and plan limit guards use cached decisions', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) }; const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET'); const guard = new LicenseGuard(cache);
  await guard.assertActive(); await guard.assertFeature('finance'); await guard.assertLimit('max_users', 25);
  await assert.rejects(() => guard.assertFeature('inventory'), (error: unknown) => error instanceof LicenseAccessError && error.code === 'feature_unavailable');
  await assert.rejects(() => guard.assertLimit('max_users', 26), (error: unknown) => error instanceof LicenseAccessError && error.code === 'limit_exceeded');
});
test('guard decision asks host app to show activation before activation', async () => {
  const cache = new LicenseCacheService(new MemoryStore(), new TestVault(), options);
  const guard = new LicenseGuard(cache);
  const decision = await guard.checkActive();

  assert.equal(decision.allowed, false);
  assert.equal(decision.reason, 'license_not_activated');
  assert.equal(decision.message, 'Activate this application before continuing.');
  assert.equal(await guard.shouldShowActivation(), true);
});

test('sensitive configuration changes require reactivation', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) }; const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');

  assert.equal(await cache.hasUsableLicense(), true);

  const tampered = { ...configured, apiToken: 'tampered-token' };
  const tamperedCache = new LicenseCacheService(store, new TestVault(), tampered);

  assert.equal(await tamperedCache.hasUsableLicense(), false);
  assert.equal(await tamperedCache.activationRequirementReason(), 'configuration_tampered');
  assert.equal(await tamperedCache.activationRequirementMessage(), 'KernelBridge licensing configuration changed. Re-activation is required.');
  await assert.rejects(() => new LicenseGuard(tamperedCache).assertActive(), (error: unknown) => error instanceof LicenseAccessError && error.code === 'configuration_tampered');
});

test('signed entitlement claims are verified and cached entitlement edits fail closed', async () => {
  const entitlements: Entitlement[] = [{ key: 'finance', enabled: true, limit: null }, { key: 'inventory', enabled: false, limit: null }, { key: 'max_users', enabled: true, limit: '25' }];
  const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  const signed_payload = JSON.stringify({ product_code: 'ALIGNEX', license_uuid: active.license_uuid, subscription_uuid: active.subscription_uuid, status: 'active', expires_at: active.expires_at, entitlements });
  const signer = createSign('RSA-SHA256'); signer.update(signed_payload); signer.end();
  const signed = { signed_payload, signature: signer.sign(privateKey, 'base64'), signature_algorithm: 'RSA-SHA256', key_id: 'key-2026' };
  const requests: Request[] = []; const store = new MemoryStore();
  const configured = { ...options, requireSignedEntitlements: true, signatureVerifier: new RsaSha256EntitlementVerifier({ 'key-2026': publicKey.export({ type: 'spki', format: 'pem' }).toString() }), fetch: successFetch(requests, signed) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');
  assert.equal(await cache.hasFeature('finance'), true);
  const state = await cache.state(); state.entitlements.finance!.enabled = false; await cache.save(state);
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'local_license_tampered');
});

test('strict signature mode rejects unsigned entitlement responses', async () => {
  const configured = { ...options, requireSignedEntitlements: true, signatureVerifier: new RsaSha256EntitlementVerifier('unused'), fetch: successFetch([]) };
  const cache = new LicenseCacheService(new MemoryStore(), new TestVault(), configured);
  await assert.rejects(() => new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET'), (error: unknown) => error instanceof Error && 'code' in error && error.code === 'invalid_entitlement_signature');
});

test('deployment profiles initialize explicitly and require confirmed reprovisioning', async () => {
  const store = new MemoryProfileStore(); const vault = new TestVault();
  const configured = { ...options, deploymentProfileMode: 'strict' as const };
  const profiles = new DeploymentProfileService(store, vault, configured);
  assert.equal((await profiles.status()).initialized, false);
  await assert.rejects(() => profiles.assertReady(), /Initialize the KernelBridge deployment profile/);
  await profiles.initializeProfile();
  await profiles.assertReady();
  await assert.rejects(() => profiles.initializeProfile(), /already exists/);
  await assert.rejects(() => profiles.reprovisionProfile(false), /explicit confirmation/);
  const downgraded = new DeploymentProfileService(store, vault, { ...configured, deploymentProfileMode: 'compatibility' });
  await assert.rejects(() => downgraded.assertReady(), /cannot be downgraded/);

  const changed = new DeploymentProfileService(store, vault, { ...configured, apiUrl: 'https://changed.kernelbridge.test/api/v1' });
  await assert.rejects(() => changed.assertReady(), /configuration changed/);
  const reprovisioned = await changed.reprovisionProfile(true);
  assert.equal(reprovisioned.matches, true);
  assert.ok(reprovisioned.reprovisionedAt);
});

test('deployment profile corruption is reported and only confirmed reprovisioning repairs it', async () => {
  const store = new MemoryProfileStore(); const profiles = new DeploymentProfileService(store, new TestVault(), { ...options, deploymentProfileMode: 'strict' });
  await profiles.initializeProfile();
  store.value = Buffer.from('{broken-profile').toString('base64');
  await assert.rejects(() => profiles.assertReady(), (error: unknown) => error instanceof Error && 'code' in error && error.code === 'configuration_tampered');
  await assert.rejects(() => profiles.reprovisionProfile(false), /explicit confirmation/);
  assert.equal((await profiles.reprovisionProfile(true)).matches, true);
});

test('strict deployment profile mode blocks activation until explicit initialization', async () => {
  const requests: Request[] = []; const configured = { ...options, deploymentProfileMode: 'strict' as const, fetch: successFetch(requests) };
  const vault = new TestVault(); const profileService = new DeploymentProfileService(new MemoryProfileStore(), vault, configured);
  const cache = new LicenseCacheService(new MemoryStore(), vault, configured, profileService);
  const activation = new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured);
  await assert.rejects(() => activation.activate('KBT-LICENSE-SECRET'), /Initialize the KernelBridge deployment profile/);
  assert.equal(requests.length, 0);
  await profileService.initializeProfile();
  await activation.activate('KBT-LICENSE-SECRET');
  assert.equal(requests.length, 3);
});

test('corrupt local state reports local tampering instead of an unactivated state', async () => {
  const store = new MemoryStore(); store.value = Buffer.from('{invalid-json').toString('base64');
  const cache = new LicenseCacheService(store, new TestVault(), options);
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'local_license_tampered');
});

test('malformed decrypted local fields are rejected as tampered state', async () => {
  const store = new MemoryStore(); const configured = { ...options, fetch: successFetch([]) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');
  const state = JSON.parse(Buffer.from(store.value!, 'base64').toString()) as Record<string, unknown>;
  state.status = 'administrator';
  store.value = Buffer.from(JSON.stringify(state)).toString('base64');
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'local_license_tampered');
});
test('renewal notice levels warn at three two and one months', async () => {
  const store = new MemoryStore();
  const configured = { ...options, now: () => new Date('2029-10-02T00:00:00.000Z'), fetch: successFetch([]) };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');

  assert.equal(await cache.renewalNoticeLevel(), '3_months');
  assert.match(await cache.renewalNoticeMessage() ?? '', /about 3 months/);

  const state = await cache.state();
  state.expiresAt = '2029-12-01T00:00:00.000Z';
  state.offlineGraceExpiresAt = state.expiresAt;
  await cache.save(state);
  assert.equal(await cache.renewalNoticeLevel(), '2_months');

  state.expiresAt = '2029-11-01T00:00:00.000Z';
  state.offlineGraceExpiresAt = state.expiresAt;
  await cache.save(state);
  assert.equal(await cache.renewalNoticeLevel(), '1_month');
  assert.equal((await new LicenseGuard(cache).renewalNotice())?.level, '1_month');
});

test('renewal notice thresholds are configurable', async () => {
  const store = new MemoryStore();
  const configured = {
    ...options,
    now: () => new Date('2027-01-01T00:00:00.000Z'),
    renewalNoticeThresholdMonths: [6, 4],
    fetch: successFetch([]),
  };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activate('KBT-LICENSE-SECRET');

  const state = await cache.state();
  state.expiresAt = '2027-07-01T00:00:00.000Z';
  state.offlineGraceExpiresAt = state.expiresAt;
  await cache.save(state);

  assert.equal(await cache.renewalNoticeLevel(), '6_months');
});

test('deactivation is sent centrally and invalidates local access', async () => {
  const requests: Request[] = []; const store = new MemoryStore(); const configured = { ...options, fetch: successFetch(requests) }; const cache = new LicenseCacheService(store, new TestVault(), configured);
  const client = new KernelBridgeLicenseClient(configured); await new LicenseActivationService(client, cache, configured).activate('KBT-LICENSE-SECRET');
  await new LicenseVerificationService(client, cache, configured).deactivate(); assert.equal((await cache.state()).status, 'deactivated'); assert.equal(await cache.hasUsableLicense(), false); assert.equal(requests.length, 4);
});

test('SQLite adapter persists, updates, and removes the encrypted envelope', async () => {
  const database = new Database(':memory:'); const store = new SqliteLicenseStore(database as unknown as SqliteDatabase);
  await store.save('ALIGNEX', 'encrypted-one'); assert.equal(await store.load('ALIGNEX'), 'encrypted-one');
  await store.save('ALIGNEX', 'encrypted-two'); assert.equal(await store.load('ALIGNEX'), 'encrypted-two');
  await store.remove('ALIGNEX'); assert.equal(await store.load('ALIGNEX'), null); database.close();
});

test('loads the documented KernelBridge environment configuration', () => {
  const configured = licensingOptionsFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.com/api/v1', KERNELBRIDGE_PRODUCT_CODE: 'ALIGNEX', KERNELBRIDGE_OFFLINE_GRACE_MS: '3600000', KERNELBRIDGE_CACHE_SIGNING_KEY: 'cache-key', KERNELBRIDGE_APP_KEY: 'app-key', KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS: '{"key-1":"trusted-key"}' });
  assert.equal(configured.productCode, 'ALIGNEX'); assert.equal(configured.apiToken, undefined); assert.equal(configured.offlineGraceMs, 3_600_000); assert.equal(configured.cacheSigningKey, 'cache-key'); assert.equal(configured.appKey, 'app-key'); assert.equal(configured.deploymentProfileMode, 'compatibility'); assert.deepEqual(configured.ed25519PublicKeys, { 'key-1': 'trusted-key' });
  assert.equal(licensingOptionsFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.com/api/v1', KERNELBRIDGE_PRODUCT_CODE: 'ALIGNEX', KERNELBRIDGE_API_TOKEN: 'legacy-token' }).apiToken, 'legacy-token');
  assert.throws(() => licensingOptionsFromEnv({ KERNELBRIDGE_API_URL: 'https://kernelbridge.com/api/v1', KERNELBRIDGE_PRODUCT_CODE: 'ALIGNEX', KERNELBRIDGE_API_TOKEN: 'installation-token', KERNELBRIDGE_DEPLOYMENT_PROFILE_MODE: 'unknown' }), /KERNELBRIDGE_DEPLOYMENT_PROFILE_MODE/);
  assert.throws(() => licensingOptionsFromEnv({}), /KERNELBRIDGE_API_URL/);
});

test('platform backend requests account links and installation-bound activation codes with its server credential', async () => {
  const requests: Request[] = [];
  const client = new PlatformAccountLinkClient({
    apiUrl: 'https://kernelbridge.test/api/v1',
    productCode: 'MEDIFLOW',
    getCredential: () => 'server-only-integration-token',
    fetch: async (input, init) => {
      const request = new Request(input, init);
      requests.push(request);
      const path = new URL(request.url).pathname;
      const data = path.endsWith('/requests')
        ? { uuid: 'link-uuid', license_uuid: 'license-uuid', product_code: 'MEDIFLOW', business_uuid: 'business-uuid', status: 'pending', license_status: 'active', subscription_uuid: 'subscription-uuid', expires_at: null, grace_expires_at: null, entitlement_revision: 1, entitlements: [], requested_at: null, approved_at: null, rejected_at: null, revoked_at: null }
        : path.endsWith('/activation-codes')
          ? { link_uuid: 'link-uuid', installation_uuid: 'installation-uuid', activation_code: 'KB-ONE-TIME-SECRET', expires_at: '2027-01-01T00:10:00.000Z' }
          : request.method === 'DELETE'
            ? { uuid: 'link-uuid', license_uuid: 'license-uuid', business_uuid: 'business-uuid', status: 'revoked', installations_revoked: 1, activation_codes_revoked: 1 }
          : { uuid: 'link-uuid', license_uuid: 'license-uuid', product_code: 'MEDIFLOW', business_uuid: 'business-uuid', status: 'approved', requested_at: null, approved_at: null, rejected_at: null, revoked_at: null };
      return new Response(JSON.stringify({ success: true, data }), { status: path.endsWith('/requests') ? 202 : 200 });
    },
  });

  const requested = await client.requestLink('license-uuid', 'business-uuid');
  const status = await client.getLinkStatus(requested.uuid);
  const code = await client.issueInstallationCode(status.uuid, 'installation-uuid', 'MediFlow client');
  const unlinked = await client.unlink(status.uuid);

  assert.equal(requests.length, 4);
  for (const request of requests) {
    assert.equal(request.headers.get('Authorization'), 'Bearer server-only-integration-token');
    assert.equal(request.headers.get('X-KernelBridge-Product'), 'MEDIFLOW');
  }
  assert.deepEqual(await requests[0]!.clone().json(), { license_uuid: 'license-uuid', business_uuid: 'business-uuid' });
  assert.deepEqual(await requests[2]!.clone().json(), { installation_uuid: 'installation-uuid', device_name: 'MediFlow client' });
  assert.equal(requests[3]!.method, 'DELETE');
  assert.equal(new URL(requests[3]!.url).pathname, '/api/v1/platform/account-links/link-uuid');
  assert.equal(unlinked.status, 'revoked');
  assert.equal(unlinked.installations_revoked, 1);
  assert.equal(code.activation_code, 'KB-ONE-TIME-SECRET');
});

test('platform backend can bootstrap an account link from an activation code without persisting it', async () => {
  let requestBody: Record<string, string> | undefined;
  const client = new PlatformAccountLinkClient({
    apiUrl: 'https://kernelbridge.test/api/v1',
    productCode: 'ALIGNEX',
    getCredential: () => 'server-only-integration-token',
    fetch: async (input, init) => {
      const request = new Request(input, init);
      requestBody = await request.json() as Record<string, string>;
      return new Response(JSON.stringify({
        success: true,
        data: {
          uuid: 'link-uuid',
          license_uuid: 'license-uuid',
          product_code: 'ALIGNEX',
          business_uuid: 'business-uuid',
          status: 'pending',
          license_status: 'active',
          subscription_uuid: 'subscription-uuid',
          expires_at: null,
          grace_expires_at: null,
          entitlement_revision: 2,
          entitlements: [],
          requested_at: null,
          approved_at: null,
          rejected_at: null,
          revoked_at: null,
        },
      }), { status: 202 });
    },
  });

  const link = await client.requestLinkWithActivationCode('KB-SHORT-LIVED', 'business-uuid');
  assert.equal(link.status, 'pending');
  assert.deepEqual(requestBody, { activation_code: 'KB-SHORT-LIVED', business_uuid: 'business-uuid' });
});

test('platform adapter surfaces approval and authorization errors without fallback', async () => {
  const client = new PlatformAccountLinkClient({
    apiUrl: 'https://kernelbridge.test/api/v1',
    productCode: 'MEDIFLOW',
    getCredential: () => 'server-only-integration-token',
    fetch: async () => new Response(JSON.stringify({
      success: false,
      error: { code: 'platform_account_not_approved', message: 'The platform account link is not approved.' },
    }), { status: 403 }),
  });
  await assert.rejects(
    () => client.issueInstallationCode('link-uuid', 'installation-uuid'),
    (error: unknown) => error instanceof Error && 'code' in error && error.code === 'platform_account_not_approved',
  );
});

test('exchanges an installation code without product credentials and verifies Ed25519 claims', async () => {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const requests: Request[] = [];
  const store = new MemoryStore();
  const signedClaims = {
    version: 1,
    issued_at: '2027-01-01T00:00:00.000Z',
    product_code: 'ALIGNEX',
    licensing_mode: 'direct',
    licensing_policy_revision: 1,
    licensing_policy_effective_at: null,
    installation_uuid: 'installation-test-uuid',
    platform_account_link_uuid: null,
    business_uuid: null,
    license_uuid: 'license-test-uuid',
    license_status: 'active',
    subscription_uuid: 'subscription-test-uuid',
    plan: { uuid: 'plan-test-uuid', name: 'Test' },
    expires_at: '2030-01-01T00:00:00.000Z',
    grace_expires_at: null,
    entitlements: [{ key: 'finance', name: 'Finance', enabled: true, limit: 25 }],
    entitlement_revision: 7,
    entitlement_digest: 'test-digest',
    entitlement_framework: {
      schema_version: 1,
      source: 'direct',
      catalog_revision: 1,
      source_revision: 1,
      grants: {
        'finance.view': { type: 'capability', enabled: true },
        'finance.disabled': { type: 'capability', enabled: false },
        'branches.max': { type: 'limit', state: 'finite', value: 5, unit: 'branches', scope: 'business' },
      },
    },
  };
  const payload = JSON.stringify(signedClaims);
  let signedEntitlements = {
    payload_json: payload,
    signature: cryptoSign(null, Buffer.from(payload), privateKey).toString('base64'),
    algorithm: 'ed25519' as const,
    key_id: 'test-key-1',
  };
  const policyPayload = JSON.stringify({
    version: 1,
    policy_type: 'licensing_mode',
    issued_at: '2027-01-01T00:00:00.000Z',
    product_code: 'ALIGNEX',
    licensing_mode: 'direct',
    licensing_policy_revision: 2,
    licensing_policy_effective_at: null,
  });
  const signedPolicy = {
    payload_json: policyPayload,
    signature: cryptoSign(null, Buffer.from(policyPayload), privateKey).toString('base64'),
    algorithm: 'ed25519' as const,
    key_id: 'test-key-1',
  };
  let currentTime = options.now();
  const configured = {
    apiUrl: options.apiUrl,
    productCode: options.productCode,
    cacheSigningKey: options.cacheSigningKey,
    appKey: options.appKey,
    now: () => currentTime,
    ed25519PublicKeys: { 'test-key-1': Buffer.from(publicKey.export({ type: 'spki', format: 'der' })).subarray(-32).toString('base64') },
    fetch: async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = new Request(input, init);
      const path = new URL(request.url).pathname;
      if (path.endsWith('/policy')) {
        assert.equal(request.headers.has('Authorization'), false);
      } else {
        requests.push(request);
      }
      const data = path.endsWith('/licensing/products/ALIGNEX/policy')
        ? signedPolicy
        : path.endsWith('/installations/activate')
        ? {
          installation_uuid: 'installation-test-uuid',
          license_uuid: 'license-test-uuid',
          product_code: 'ALIGNEX',
          credential_type: 'bearer',
          credential: 'installation-test-uuid.secret',
          expires_at: null,
          signed_entitlements: signedEntitlements,
          created: true,
        }
        : signedEntitlements;
      return new Response(JSON.stringify({ success: true, data }), { status: 201 });
    },
  };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  const client = new KernelBridgeLicenseClient(configured);
  const activation = new LicenseActivationService(client, cache, configured);
  const initialState = await cache.state();
  initialState.installationIdentifier = 'installation-test-uuid';
  await cache.save(initialState);
  const state = await activation.activateWithCode('  KB-ONE-TIME-CODE  ', 'Test device');

  assert.equal(state.credentialKind, 'installation_bearer');
  assert.equal(state.encryptedInstallationCredential, 'installation-test-uuid.secret');
  assert.equal(state.entitlementRevision, 7);
  assert.equal(JSON.parse(state.signedEntitlementPayload!).entitlement_framework.source, 'direct');
  assert.equal(await cache.hasFeature('finance'), false);
  assert.equal(await cache.hasFeature('finance.view'), true);
  assert.equal(await cache.hasFeature('finance.disabled'), false);
  assert.equal(await cache.entitlementDecision('finance.disabled').then(result => result.status), 'disabled');
  assert.equal(await cache.entitlementDecision('finance').then(result => result.status), 'absent');
  assert.equal(await cache.hasFeature('branches.max'), true);
  assert.deepEqual(await cache.limitGrant('branches.max'), {
    status: 'finite',
    value: 5,
    unit: 'branches',
    scope: 'business',
    period: null,
  });
  assert.equal(await cache.limit('branches.max'), 5);
  assert.equal(await cache.hasFeature('missing.feature'), false);
  assert.equal(await cache.entitlementDecision('missing.feature').then(result => result.status), 'absent');
  assert.equal(requests.length, 1);
  assert.equal(requests[0]?.headers.has('Authorization'), false);
  assert.equal((await requests[0]!.clone().json() as { activation_code: string }).activation_code, 'KB-ONE-TIME-CODE');

  const cachedState = JSON.parse(Buffer.from(store.value!, 'base64').toString()) as Record<string, unknown>;
  cachedState.version = 1;
  store.value = Buffer.from(JSON.stringify(cachedState)).toString('base64');
  assert.equal((await cache.state()).version, 3);
  assert.equal(await cache.hasFeature('finance.view'), true);

  const offlineOptions = {
    ...configured,
    fetch: (async () => { throw new Error('offline'); }) as typeof fetch,
  };
  const offlineCache = new LicenseCacheService(store, new TestVault(), offlineOptions);
  await new LicenseVerificationService(new KernelBridgeLicenseClient(offlineOptions), offlineCache, offlineOptions).verify(true);
  assert.equal(await offlineCache.hasFeature('finance.view'), true);

  const staleClock = new Date(options.now().getTime() + 73 * 60 * 60_000);
  const staleCache = new LicenseCacheService(store, new TestVault(), { ...configured, now: () => staleClock });
  const legacyCachedState = await staleCache.state();
  legacyCachedState.lastSuccessfulVerification = staleClock.toISOString();
  legacyCachedState.offlineGraceExpiresAt = new Date(staleClock.getTime() + 72 * 60 * 60_000).toISOString();
  await staleCache.save(legacyCachedState);
  assert.equal(await staleCache.hasUsableLicense(), false);
  assert.equal((await staleCache.entitlementDecision('finance.view')).status, 'license_unavailable');
  await assert.rejects(
    () => new LicenseVerificationService(new KernelBridgeLicenseClient({ ...configured, now: () => staleClock }), staleCache, { ...configured, now: () => staleClock }).verify(true),
    (error: unknown) => error instanceof Error && 'code' in error && error.code === 'stale_entitlement_snapshot',
  );

  const revokedPayload = JSON.stringify({ ...signedClaims, issued_at: '2027-01-01T00:01:00.000Z', license_status: 'revoked' });
  currentTime = new Date('2027-01-01T00:01:00.000Z');
  signedEntitlements = {
    payload_json: revokedPayload,
    signature: cryptoSign(null, Buffer.from(revokedPayload), privateKey).toString('base64'),
    algorithm: 'ed25519',
    key_id: 'test-key-1',
  };
  await new LicenseVerificationService(client, cache, configured).verify(true);
  assert.equal(await cache.hasFeature('finance.view'), false);
  assert.equal((await cache.entitlementDecision('finance.view')).status, 'license_unavailable');

  const renewedPayload = JSON.stringify({
    ...signedClaims,
    issued_at: '2027-01-01T00:02:00.000Z',
    entitlement_framework: {
      ...signedClaims.entitlement_framework,
      source_revision: 2,
      grants: { 'finance.renewed': { type: 'capability', enabled: true } },
    },
  });
  currentTime = new Date('2027-01-01T00:02:00.000Z');
  signedEntitlements = {
    payload_json: renewedPayload,
    signature: cryptoSign(null, Buffer.from(renewedPayload), privateKey).toString('base64'),
    algorithm: 'ed25519',
    key_id: 'test-key-1',
  };
  await new LicenseVerificationService(client, cache, configured).verify(true);
  assert.equal(await cache.hasFeature('finance.view'), false);
  assert.equal(await cache.hasFeature('finance.renewed'), true);

  await new LicenseVerificationService(client, cache, configured).verify(true);
  assert.equal(requests[1]?.headers.get('Authorization'), 'Bearer installation-test-uuid.secret');

  const mismatchedFrameworkPayload = JSON.stringify({
    ...signedClaims,
    entitlement_framework: {
      schema_version: 1,
      source: 'product_platform',
      catalog_revision: 1,
      source_revision: 1,
      grants: {},
    },
  });
  signedEntitlements = {
    ...signedEntitlements,
    payload_json: mismatchedFrameworkPayload,
    signature: cryptoSign(null, Buffer.from(mismatchedFrameworkPayload), privateKey).toString('base64'),
  };
  await assert.rejects(
    () => new LicenseVerificationService(client, cache, configured).verify(true),
    (error: unknown) => error instanceof Error && 'code' in error && error.code === 'invalid_entitlement_signature',
  );

  await new LicenseVerificationService(client, cache, configured).deactivate();
  const deactivateRequest = requests[requests.length - 1]!;
  assert.equal(new URL(deactivateRequest.url).pathname, '/api/v1/installations/deactivate');
  assert.deepEqual(await deactivateRequest.clone().json(), {});
});

test('rejects installation-code activation when the signed policy is legacy', async () => {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const policyPayload = JSON.stringify({
    version: 1,
    policy_type: 'licensing_mode',
    issued_at: '2027-01-01T00:00:00.000Z',
    product_code: 'ALIGNEX',
    licensing_mode: 'legacy',
    licensing_policy_revision: 1,
    licensing_policy_effective_at: null,
  });
  const signedPolicy = {
    payload_json: policyPayload,
    signature: cryptoSign(null, Buffer.from(policyPayload), privateKey).toString('base64'),
    algorithm: 'ed25519' as const,
    key_id: 'legacy-key',
  };
  const requests: Request[] = [];
  const configured = {
    ...options,
    ed25519PublicKeys: { 'legacy-key': publicKey.export({ type: 'spki', format: 'pem' }).toString() },
    fetch: async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = new Request(input, init);
      requests.push(request);
      return new Response(JSON.stringify({ success: true, data: signedPolicy }), { status: 200 });
    },
  };
  const cache = new LicenseCacheService(new MemoryStore(), new TestVault(), configured);
  const activation = new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured);

  await assert.rejects(
    () => activation.activateWithCode('KB-LEGACY-CODE'),
    (error: unknown) => error instanceof Error && 'code' in error && error.code === 'licensing_mode_mismatch',
  );
  assert.equal(requests.length, 1);
  assert.equal(new URL(requests[0]!.url).pathname, '/api/v1/licensing/products/ALIGNEX/policy');
  assert.equal(requests[0]?.headers.has('Authorization'), false);
});

test('installation grace snapshots remain usable after subscription expiry but stop at grace expiry', async () => {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  let currentTime = new Date('2027-01-01T00:00:00.000Z');
  const store = new MemoryStore();
  const claims = {
    version: 1,
    issued_at: '2027-01-01T00:00:00.000Z',
    product_code: 'ALIGNEX',
    licensing_mode: 'direct',
    licensing_policy_revision: 1,
    licensing_policy_effective_at: null,
    installation_uuid: 'grace-installation-uuid',
    platform_account_link_uuid: null,
    business_uuid: null,
    license_uuid: 'grace-license-uuid',
    license_status: 'grace',
    subscription_uuid: 'grace-subscription-uuid',
    plan: { uuid: 'grace-plan-uuid', name: 'Grace plan' },
    expires_at: '2026-12-31T23:59:59.000Z',
    grace_expires_at: '2027-01-03T00:00:00.000Z',
    entitlements: [{ key: 'feature.grace', name: 'Grace feature', enabled: true, limit: null }],
    entitlement_revision: 1,
    entitlement_digest: 'grace-test',
  };
  const payload = JSON.stringify(claims);
  const signedEntitlements = {
    payload_json: payload,
    signature: cryptoSign(null, Buffer.from(payload), privateKey).toString('base64'),
    algorithm: 'ed25519' as const,
    key_id: 'grace-key',
  };
  const policyPayload = JSON.stringify({
    version: 1,
    policy_type: 'licensing_mode',
    issued_at: '2027-01-01T00:00:00.000Z',
    product_code: 'ALIGNEX',
    licensing_mode: 'direct',
    licensing_policy_revision: 1,
    licensing_policy_effective_at: null,
  });
  const signedPolicy = {
    payload_json: policyPayload,
    signature: cryptoSign(null, Buffer.from(policyPayload), privateKey).toString('base64'),
    algorithm: 'ed25519' as const,
    key_id: 'grace-key',
  };
  const configured = {
    ...options,
    now: () => currentTime,
    ed25519PublicKeys: { 'grace-key': publicKey.export({ type: 'spki', format: 'pem' }).toString() },
    fetch: async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = new URL(new Request(input, init).url).pathname;
      return new Response(JSON.stringify({
      success: true,
      data: path.endsWith('/policy') ? signedPolicy : {
        installation_uuid: claims.installation_uuid,
        license_uuid: claims.license_uuid,
        product_code: claims.product_code,
        credential_type: 'bearer',
        credential: 'grace-installation-uuid.secret',
        expires_at: claims.expires_at,
        signed_entitlements: signedEntitlements,
        created: true,
      },
    }), { status: 201 });
    },
  };
  const cache = new LicenseCacheService(store, new TestVault(), configured);
  const state = await cache.state();
  state.installationIdentifier = claims.installation_uuid;
  await cache.save(state);

  await new LicenseActivationService(new KernelBridgeLicenseClient(configured), cache, configured).activateWithCode('KB-GRACE-CODE');

  assert.equal(await cache.hasUsableLicense(), true);
  assert.equal(await cache.hasFeature('feature.grace'), true);
  currentTime = new Date('2027-01-04T00:00:00.000Z');
  assert.equal(await cache.hasUsableLicense(), false);
  assert.equal(await cache.activationRequirementReason(), 'offline_grace_expired');
});

test('shared Ed25519 entitlement fixture verifies exact payload bytes and rejects tampering or unknown keys', () => {
  const fixture = JSON.parse(readFileSync(new URL('../../../../protocol/fixtures/ed25519-entitlement-v1.json', import.meta.url), 'utf8')) as {
    key_id: string;
    public_key_pem: string;
    payload_json: string;
    signature: string;
  };
  const verifier = new Ed25519EntitlementVerifier({ [fixture.key_id]: fixture.public_key_pem });

  assert.equal(verifier.verify(fixture.payload_json, fixture.signature, fixture.key_id), true);
  assert.equal(verifier.verify(`${fixture.payload_json} `, fixture.signature, fixture.key_id), false);
  assert.equal(verifier.verify(fixture.payload_json, fixture.signature, 'unknown-key'), false);
});

test('Ed25519 entitlement verification supports overlapping trusted key IDs during rotation', () => {
  const oldKeyPair = generateKeyPairSync('ed25519');
  const newKeyPair = generateKeyPairSync('ed25519');
  const payload = '{"version":1,"product_code":"ALIGNEX"}';
  const oldSignature = cryptoSign(null, Buffer.from(payload), oldKeyPair.privateKey).toString('base64');
  const newSignature = cryptoSign(null, Buffer.from(payload), newKeyPair.privateKey).toString('base64');
  const verifier = new Ed25519EntitlementVerifier({
    'old-key': oldKeyPair.publicKey.export({ type: 'spki', format: 'pem' }).toString(),
    'new-key': newKeyPair.publicKey.export({ type: 'spki', format: 'pem' }).toString(),
  });

  assert.equal(verifier.verify(payload, oldSignature, 'old-key'), true);
  assert.equal(verifier.verify(payload, newSignature, 'new-key'), true);
  assert.equal(verifier.verify(payload, oldSignature, 'unknown-key'), false);
});
