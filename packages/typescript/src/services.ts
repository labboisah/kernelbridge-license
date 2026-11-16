import { KernelBridgeApiError, KernelBridgeUnavailableError } from './errors.js';
import { KernelBridgeLicenseClient } from './client.js';
import { LicenseCacheService } from './storage.js';
import { machineFingerprint } from './machine-identity.js';
import type { EntitlementMap, EntitlementResponse, InstallationActivationResponse, LicenseResponse, LicenseState, LicensingClientOptions, LicensingPolicy, SignedInstallationEntitlements, SubscriptionResponse } from './types.js';
import { Ed25519EntitlementVerifier } from './signature.js';
import { validateSignedEntitlementFramework } from './entitlement-framework.js';

export class LicenseActivationService {
  constructor(private readonly client: KernelBridgeLicenseClient, private readonly cache: LicenseCacheService, private readonly options: LicensingClientOptions) {}
  async activateWithCode(activationCode: string, deviceName?: string): Promise<LicenseState> {
    await this.cache.assertDeploymentProfileReady();
    const policy = await this.getLicensingPolicy();
    if (policy.mode === 'legacy') {
      throw new KernelBridgeApiError('This product is configured for legacy licensing and does not accept installation activation codes.', 'licensing_mode_mismatch', 403);
    }
    const state = await this.cache.state();
    if (!this.options.ed25519PublicKeys || Object.keys(this.options.ed25519PublicKeys).length === 0) {
      throw invalidEntitlementSignature('No trusted KernelBridge Ed25519 public keys are configured.');
    }

    const response = await this.client.exchangeActivationCode(activationCode.trim(), state.installationIdentifier, deviceName);
    if (response.product_code.toUpperCase() !== this.options.productCode.toUpperCase()
      || response.installation_uuid !== state.installationIdentifier
      || response.credential_type !== 'bearer'
      || !response.credential) {
      throw invalidEntitlementSignature('The activation response is not bound to this product installation.');
    }

    return persistInstallationSnapshot(this.cache, this.options, state, response, response.signed_entitlements);
  }

  async getLicensingPolicy(): Promise<LicensingPolicy> {
    const signed = await this.client.getLicensingPolicy();
    if (signed.algorithm !== 'ed25519' || !signed.key_id || !signed.payload_json || !signed.signature || !this.options.ed25519PublicKeys) {
      throw invalidEntitlementSignature('The signed KernelBridge licensing policy is incomplete or unsupported.');
    }

    const verifier = new Ed25519EntitlementVerifier(this.options.ed25519PublicKeys);
    if (!verifier.verify(signed.payload_json, signed.signature, signed.key_id)) {
      throw invalidEntitlementSignature('The KernelBridge licensing policy signature is invalid.');
    }

    let claims: {
      version?: unknown;
      policy_type?: unknown;
      issued_at?: unknown;
      product_code?: unknown;
      licensing_mode?: unknown;
      licensing_policy_revision?: unknown;
      licensing_policy_effective_at?: unknown;
    };
    try {
      claims = JSON.parse(signed.payload_json) as typeof claims;
    } catch (error) {
      throw invalidEntitlementSignature('The signed KernelBridge licensing policy payload is malformed.', error);
    }

    if (!claims || typeof claims !== 'object' || Array.isArray(claims)) {
      throw invalidEntitlementSignature('The signed KernelBridge licensing policy payload is malformed.');
    }

    const mode = claims.licensing_mode;
    const revision = claims.licensing_policy_revision;
    const productCode = claims.product_code;
    const effectiveAt = claims.licensing_policy_effective_at;
    if (claims.version !== 1
      || claims.policy_type !== 'licensing_mode'
      || typeof productCode !== 'string'
      || productCode !== this.options.productCode.toUpperCase()
      || typeof claims.issued_at !== 'string'
      || !Number.isFinite(Date.parse(claims.issued_at))
      || !isLicensingMode(mode)
      || typeof revision !== 'number'
      || !Number.isInteger(revision)
      || revision < 1
      || (effectiveAt !== null && typeof effectiveAt !== 'string')) {
      throw invalidEntitlementSignature('The signed KernelBridge licensing policy is not valid for this product.');
    }

    return {
      productCode,
      mode,
      revision,
      effectiveAt,
    };
  }

  async activate(licenseKey: string, deviceName?: string): Promise<LicenseState> {
    await this.cache.assertDeploymentProfileReady();
    const state = await this.cache.state(); const fingerprint = await machineFingerprint(this.options.machineIdentityProvider);
    const license = await this.client.activate(licenseKey, state.installationIdentifier, deviceName, fingerprint);
    return this.refresh(state, licenseKey, license, fingerprint);
  }
  private async refresh(state: LicenseState, licenseKey: string, license: LicenseResponse, fingerprint: string): Promise<LicenseState> {
    const subscription = await this.client.getSubscription(license.subscription_uuid); const entitlements = await this.client.getEntitlements(license.subscription_uuid);
    return persistVerified(this.cache, this.options, state, licenseKey, license, subscription, entitlements, fingerprint);
  }
}

export class LicenseVerificationService {
  constructor(private readonly client: KernelBridgeLicenseClient, private readonly cache: LicenseCacheService, private readonly options: LicensingClientOptions) {}

  async verifySignedInstallation(
    signed: SignedInstallationEntitlements,
    installationCredential: string,
    force = false,
  ): Promise<LicenseState> {
    await this.cache.assertDeploymentProfileReady();
    const state = await this.cache.state();
    if (state.credentialKind !== 'installation_bearer'
      || state.installationIdentifier === ''
      || (installationCredential && state.encryptedInstallationCredential !== installationCredential)) {
      if (state.credentialKind !== 'installation_bearer') {
        throw new KernelBridgeApiError('No KernelBridge installation has been activated.', 'license_not_activated', 422);
      }
      if (!installationCredential) {
        throw new KernelBridgeApiError('The installation credential is required.', 'license_not_activated', 422);
      }
    }

    if (!force && state.lastSuccessfulVerification
      && Date.parse(state.lastSuccessfulVerification) >= Date.now() - (this.options.verificationIntervalMs ?? 15 * 60_000)
      && await this.cache.hasUsableLicense()) {
      return state;
    }

    return persistSignedInstallationSnapshot(this.cache, this.options, state, signed);
  }

  async verify(force = false): Promise<LicenseState> {
    await this.cache.assertDeploymentProfileReady();
    const state = await this.cache.state(); const now = (this.options.now ?? (() => new Date()))().getTime();
    if (!force && state.lastSuccessfulVerification && now - Date.parse(state.lastSuccessfulVerification) < (this.options.verificationIntervalMs ?? 15 * 60_000) && await this.cache.hasUsableLicense()) return state;
    if (state.credentialKind === 'installation_bearer') {
      if (!state.encryptedInstallationCredential) throw new KernelBridgeApiError('No local installation credential has been activated.', 'license_not_activated', 422);
      try {
        const response = await this.client.verifyInstallation(state.encryptedInstallationCredential);
        return persistSignedInstallationSnapshot(this.cache, this.options, state, response);
      } catch (error) {
        if (error instanceof KernelBridgeApiError && error.code === 'stale_entitlement_snapshot') {
          if (await this.cache.hasUsableLicense()) return this.cache.state();
          throw error;
        }
        if (error instanceof KernelBridgeUnavailableError && await this.cache.hasUsableLicense()) return this.cache.state();
        if (error instanceof KernelBridgeApiError) {
          state.status = 'invalid';
          state.lastErrorCode = error.code;
          await this.cache.save(state);
        }
        throw error;
      }
    }
    if (!state.encryptedLicenseKey || !state.subscriptionIdentifier) throw new KernelBridgeApiError('No local KernelBridge license has been activated.', 'license_not_activated', 422);
    try {
      const fingerprint = await machineFingerprint(this.options.machineIdentityProvider);
      if (state.machineFingerprint && state.machineFingerprint !== fingerprint) throw new KernelBridgeApiError('This license cache belongs to a different computer.', 'device_mismatch', 403);
      const license = state.machineFingerprint
        ? await this.client.verify(state.encryptedLicenseKey, state.installationIdentifier, fingerprint)
        : await this.client.activate(state.encryptedLicenseKey, state.installationIdentifier, undefined, fingerprint);
      const subscription = await this.client.getSubscription(license.subscription_uuid); const entitlements = await this.client.getEntitlements(license.subscription_uuid);
      return persistVerified(this.cache, this.options, state, state.encryptedLicenseKey, license, subscription, entitlements, fingerprint);
    } catch (error) {
      if (error instanceof KernelBridgeUnavailableError && await this.cache.hasUsableLicense()) return this.cache.state();
      if (error instanceof KernelBridgeApiError && error.code !== 'device_mismatch') { state.status = 'invalid'; state.lastErrorCode = error.code; await this.cache.save(state); }
      throw error;
    }
  }
  async deactivate(): Promise<void> {
    await this.cache.assertDeploymentProfileReady();
    const state = await this.cache.state();
    if (state.credentialKind === 'installation_bearer' && state.encryptedInstallationCredential) {
      await this.client.deactivateInstallation(state.encryptedInstallationCredential);
    } else if (state.encryptedLicenseKey) {
      await this.client.deactivate(state.encryptedLicenseKey, state.installationIdentifier, await machineFingerprint(this.options.machineIdentityProvider));
    }
    state.status = 'deactivated';
    state.offlineGraceExpiresAt = new Date(0).toISOString();
    await this.cache.save(state);
  }
  async getEntitlements(force = false): Promise<EntitlementMap> { return (await this.verify(force)).entitlements; }
}

function isLicensingMode(value: unknown): value is LicensingPolicy['mode'] {
  return value === 'legacy' || value === 'direct' || value === 'platform_account' || value === 'hybrid';
}

function persistInstallationSnapshot(
  cache: LicenseCacheService,
  options: LicensingClientOptions,
  state: LicenseState,
  activation: InstallationActivationResponse,
  signed: SignedInstallationEntitlements,
): Promise<LicenseState> {
  state.credentialKind = 'installation_bearer';
  state.encryptedInstallationCredential = activation.credential;
  return persistSignedInstallationSnapshot(cache, options, state, signed);
}

async function persistSignedInstallationSnapshot(
  cache: LicenseCacheService,
  options: LicensingClientOptions,
  state: LicenseState,
  signed: SignedInstallationEntitlements,
): Promise<LicenseState> {
  if (signed.algorithm !== 'ed25519' || !signed.key_id || !signed.payload_json || !signed.signature || !options.ed25519PublicKeys) {
    throw invalidEntitlementSignature('The signed installation entitlement is incomplete or unsupported.');
  }

  const verifier = new Ed25519EntitlementVerifier(options.ed25519PublicKeys);
  if (!verifier.verify(signed.payload_json, signed.signature, signed.key_id)) {
    throw invalidEntitlementSignature('The installation entitlement signature is invalid.');
  }

  let claims: {
    product_code?: unknown;
    installation_uuid?: unknown;
    license_uuid?: unknown;
    subscription_uuid?: unknown;
    license_status?: unknown;
    expires_at?: unknown;
    grace_expires_at?: unknown;
    issued_at?: unknown;
    entitlement_revision?: unknown;
    entitlements?: unknown;
    licensing_mode?: unknown;
    platform_account_link_uuid?: unknown;
    entitlement_framework?: unknown;
  };
  try {
    claims = JSON.parse(signed.payload_json) as typeof claims;
  } catch (error) {
    throw invalidEntitlementSignature('The signed installation entitlement payload is malformed.', error);
  }

  const productCode = options.productCode.toUpperCase();
  if (!claims || typeof claims !== 'object' || Array.isArray(claims)
    || claims.product_code !== productCode
    || claims.installation_uuid !== state.installationIdentifier
    || typeof claims.license_uuid !== 'string'
    || (state.licenseIdentifier !== null && claims.license_uuid !== state.licenseIdentifier)
    || typeof claims.subscription_uuid !== 'string'
    || typeof claims.license_status !== 'string'
    || !Array.isArray(claims.entitlements)
    || !Number.isInteger(claims.entitlement_revision)
    || !['active', 'trial', 'grace', 'expired', 'suspended', 'cancelled', 'revoked', 'invalid'].includes(claims.license_status)) {
    throw invalidEntitlementSignature('The signed installation entitlement claims are invalid or not bound to this installation.');
  }
  if (Object.hasOwn(claims, 'entitlement_framework')
    && validateSignedEntitlementFramework(
      claims.entitlement_framework,
      claims.licensing_mode,
      claims.platform_account_link_uuid,
    ) !== null) {
    throw invalidEntitlementSignature('The signed entitlement framework is malformed or not bound to this installation source.');
  }

  let entitlements: EntitlementMap;
  try {
    entitlements = entitlementMap(claims.entitlements as EntitlementResponse['entitlements']);
  } catch (error) {
    throw invalidEntitlementSignature('The signed installation entitlement list is malformed.', error);
  }

  const now = (options.now ?? (() => new Date()))();
  const issuedAt = validIsoDate(claims.issued_at, 'issued_at');
  if (Date.parse(issuedAt) > now.getTime() + 5 * 60_000) {
    throw invalidEntitlementSignature('The signed installation entitlement has a future issue time.');
  }
  const offlineGrace = new Date(Date.parse(issuedAt) + (options.offlineGraceMs ?? 72 * 60 * 60_000));
  if (offlineGrace.getTime() <= now.getTime()) {
    throw new KernelBridgeApiError('KernelBridge returned a stale signed entitlement snapshot.', 'stale_entitlement_snapshot', 502);
  }
  if (state.signedEntitlementPayload) {
    try {
      const previousClaims = JSON.parse(state.signedEntitlementPayload) as { issued_at?: unknown };
      if (typeof previousClaims.issued_at === 'string' && Date.parse(previousClaims.issued_at) > Date.parse(issuedAt)) {
        throw new KernelBridgeApiError('KernelBridge returned an older signed entitlement snapshot.', 'stale_entitlement_snapshot', 502);
      }
    } catch (error) {
      if (error instanceof KernelBridgeApiError) throw error;
      throw invalidEntitlementSignature('The cached signed installation entitlement is malformed.', error);
    }
  }
  const expiry = claims.expires_at === null ? null : validIsoDate(claims.expires_at, 'expires_at');
  const graceExpiry = claims.grace_expires_at === null || claims.grace_expires_at === undefined
    ? null
    : validIsoDate(claims.grace_expires_at, 'grace_expires_at');
  if (claims.license_status === 'grace' && !graceExpiry) {
    throw invalidEntitlementSignature('A grace entitlement must include its grace expiry.');
  }
  const accessExpiry = claims.license_status === 'grace' ? graceExpiry : expiry;
  if (accessExpiry && offlineGrace.getTime() > Date.parse(accessExpiry)) offlineGrace.setTime(Date.parse(accessExpiry));

  state.version = 3;
  state.credentialKind = 'installation_bearer';
  state.licenseIdentifier = claims.license_uuid;
  state.subscriptionIdentifier = claims.subscription_uuid;
  state.status = claims.license_status as LicenseState['status'];
  state.lastSuccessfulVerification = now.toISOString();
  state.expiresAt = expiry;
  state.offlineGraceExpiresAt = offlineGrace.toISOString();
  state.entitlements = entitlements;
  state.signedEntitlementPayload = signed.payload_json;
  state.entitlementSignature = signed.signature;
  state.signatureAlgorithm = signed.algorithm;
  state.signatureKeyId = signed.key_id;
  state.entitlementRevision = claims.entitlement_revision as number;
  state.lastErrorCode = null;
  cache.stampConfigFingerprint(state, now);
  await cache.save(state);
  return state;
}

function validIsoDate(value: unknown, field: string): string {
  if (typeof value !== 'string' || !Number.isFinite(Date.parse(value))) {
    throw invalidEntitlementSignature(`The signed installation entitlement has an invalid ${field}.`);
  }
  return new Date(Date.parse(value)).toISOString();
}

async function persistVerified(cache: LicenseCacheService, options: LicensingClientOptions, state: LicenseState, licenseKey: string, license: LicenseResponse, subscription: SubscriptionResponse, response: EntitlementResponse, fingerprint: string): Promise<LicenseState> {
  const verifiedEntitlements = verifyEntitlements(options, response, license, subscription);
  const now = (options.now ?? (() => new Date()))(); const expiry = license.expires_at ?? subscription.grace_ends_at ?? subscription.ends_at ?? null;
  const status = subscription.status ?? license.status;
  const grace = new Date(now.getTime() + (options.offlineGraceMs ?? 72 * 60 * 60_000));
  const accessExpiry = status === 'grace' ? subscription.grace_ends_at ?? null : expiry;
  if (accessExpiry && grace.getTime() > Date.parse(accessExpiry)) grace.setTime(Date.parse(accessExpiry));
  if (status === 'grace' && !accessExpiry) grace.setTime(now.getTime());
  state.licenseIdentifier = license.license_uuid; state.subscriptionIdentifier = license.subscription_uuid; state.activationIdentifier = license.activation_uuid ?? state.activationIdentifier; state.machineFingerprint = fingerprint;
  state.status = status; state.lastSuccessfulVerification = now.toISOString(); state.expiresAt = expiry; state.offlineGraceExpiresAt = grace.toISOString(); state.encryptedLicenseKey = licenseKey; state.lastErrorCode = null;
  state.entitlements = verifiedEntitlements; state.signedEntitlementPayload = response.signed_payload ?? null; state.entitlementSignature = response.signature ?? null; state.signatureAlgorithm = response.signature_algorithm ?? null; state.signatureKeyId = response.key_id ?? null;
  cache.stampConfigFingerprint(state, now);
  await cache.save(state); return state;
}

function verifyEntitlements(options: LicensingClientOptions, response: EntitlementResponse, license: LicenseResponse, subscription: SubscriptionResponse): EntitlementMap {
  const hasSignedFields = response.signed_payload !== undefined || response.signature !== undefined || response.signature_algorithm !== undefined || response.key_id !== undefined;
  if (!hasSignedFields) {
    if (options.requireSignedEntitlements) throw invalidEntitlementSignature('The entitlement signature is required but missing.');
    return entitlementMap(response.entitlements);
  }
  if (!response.signed_payload || !response.signature || !response.key_id || response.signature_algorithm !== 'RSA-SHA256' || !options.signatureVerifier) {
    throw invalidEntitlementSignature('The entitlement signature is incomplete or cannot be verified.');
  }
  let claims: { product_code?: unknown; license_uuid?: unknown; subscription_uuid?: unknown; status?: unknown; expires_at?: unknown; entitlements?: unknown };
  try {
    if (!options.signatureVerifier.verify(response.signed_payload, response.signature, response.key_id)) throw new Error('Signature verification failed.');
    claims = JSON.parse(response.signed_payload) as typeof claims;
    if (!claims || typeof claims !== 'object' || Array.isArray(claims)) throw new Error('Signed claims must be an object.');
  } catch (error) {
    throw invalidEntitlementSignature('The entitlement signature is invalid.', error);
  }
  const expectedStatus = subscription.status ?? license.status;
  const expectedExpiry = license.expires_at ?? subscription.grace_ends_at ?? subscription.ends_at ?? null;
  if (claims.product_code !== options.productCode.toUpperCase()
    || claims.license_uuid !== license.license_uuid
    || claims.subscription_uuid !== license.subscription_uuid
    || claims.status !== expectedStatus
    || !Object.hasOwn(claims, 'expires_at')
    || !sameExpiry(claims.expires_at, expectedExpiry)
    || !Array.isArray(claims.entitlements)) {
    throw invalidEntitlementSignature('The signed entitlement claims do not match this license response.');
  }
  const signedMap = entitlementMap(claims.entitlements as EntitlementResponse['entitlements']);
  const responseMap = entitlementMap(response.entitlements);
  if (stableJson(signedMap) !== stableJson(responseMap)) throw invalidEntitlementSignature('The signed entitlement claims do not match the entitlement response.');
  return signedMap;
}

function entitlementMap(entitlements: EntitlementResponse['entitlements']): EntitlementMap {
  if (!Array.isArray(entitlements)) throw invalidEntitlementSignature('The entitlement response is malformed.');
  const result: EntitlementMap = {};
  for (const item of entitlements) {
    if (!item || typeof item.key !== 'string' || typeof item.enabled !== 'boolean' || (item.limit !== null && typeof item.limit !== 'string' && typeof item.limit !== 'number') || Object.hasOwn(result, item.key)) {
      throw invalidEntitlementSignature('The entitlement response is malformed.');
    }
    result[item.key] = { enabled: item.enabled, limit: item.limit };
  }
  return result;
}

function sameExpiry(actual: unknown, expected: string | null): boolean {
  if (actual === null || actual === undefined || actual === '') return expected === null;
  if (typeof actual !== 'string' || expected === null) return false;
  return Date.parse(actual) === Date.parse(expected);
}

function invalidEntitlementSignature(message: string, cause?: unknown): KernelBridgeApiError {
  return new KernelBridgeApiError(message, 'invalid_entitlement_signature', 422, cause === undefined ? undefined : { cause });
}

function stableJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(stableJson).join(',')}]`;
  if (value && typeof value === 'object') return `{${Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`).join(',')}}`;
  return JSON.stringify(value);
}
