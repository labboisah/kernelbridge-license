export type LicenseStatus = 'unlicensed' | 'pending' | 'trial' | 'active' | 'grace' | 'expired' | 'suspended' | 'cancelled' | 'revoked' | 'invalid' | 'deactivated';

export interface Entitlement { key: string; enabled: boolean; limit: number | string | null }
export interface EntitlementMap { [key: string]: { enabled: boolean; limit: number | string | null } }
export type RenewalNoticeLevel = '3_months' | '2_months' | '1_month';
export interface RenewalNotice { level: RenewalNoticeLevel; message: string; expiresAt: string }

export interface LicenseResponse {
  license_uuid: string;
  activation_uuid?: string;
  subscription_uuid: string;
  status: LicenseStatus;
  expires_at?: string | null;
}
export interface DeactivationResponse { license_uuid: string; activation_uuid: string; status: 'deactivated' }

export interface SubscriptionResponse {
  uuid?: string;
  status: LicenseStatus;
  starts_at?: string | null;
  ends_at?: string | null;
  grace_ends_at?: string | null;
}

export interface EntitlementResponse {
  entitlements: Entitlement[];
  signed_payload?: string;
  signature?: string;
  signature_algorithm?: string;
  key_id?: string;
}

export interface SignedInstallationEntitlements {
  payload_json: string;
  signature: string;
  algorithm: 'ed25519';
  key_id: string;
}

export interface EntitlementFrameworkSnapshot {
  schema_version: 1;
  source: 'direct' | 'product_platform';
  catalog_revision: number;
  source_revision: number;
  grants: Record<string, EntitlementFrameworkGrant>;
}

export interface EntitlementCapabilityDefinition {
  key: string;
  type: 'capability' | 'limit';
  description?: string;
  unit?: string;
  scope?: string;
  period?: EntitlementPeriod;
}

export interface EntitlementModuleDefinition {
  key: string;
  capabilities: EntitlementCapabilityDefinition[];
}

export interface EntitlementPackageDefinition {
  key: string;
  modules: EntitlementModuleDefinition[];
}

export interface EntitlementPlanDefinition {
  key: string;
  grants: Record<string, EntitlementFrameworkGrant>;
}

export interface EntitlementCatalog {
  schema_version: 1;
  product_code: string;
  catalog_revision: number;
  packages: EntitlementPackageDefinition[];
  plans: EntitlementPlanDefinition[];
}

export interface EntitlementPeriod {
  unit: 'day' | 'week' | 'month' | 'year';
  reset: 'calendar';
  time_zone: string;
}

export type EntitlementFrameworkGrant =
  | { type: 'capability'; enabled: boolean }
  | { type: 'limit'; state: 'finite'; value: number; unit: string; scope: string; period?: EntitlementPeriod }
  | { type: 'limit'; state: 'unlimited'; unit: string; scope: string; period?: EntitlementPeriod };

export type EntitlementDecisionStatus = 'granted' | 'disabled' | 'absent' | 'license_unavailable' | 'malformed' | 'unsupported';
export interface EntitlementDecision {
  status: EntitlementDecisionStatus;
  source: 'framework' | 'legacy' | null;
  grant?: EntitlementFrameworkGrant;
}

export type EntitlementLimitResult =
  | { status: 'finite'; value: number | string; unit: string | null; scope: string | null; period: EntitlementPeriod | null }
  | { status: 'unlimited'; unit: string | null; scope: string | null; period: EntitlementPeriod | null }
  | { status: EntitlementDecisionStatus | 'not_a_limit' };

export type LicensingMode = 'legacy' | 'direct' | 'platform_account' | 'hybrid';

export interface LicensingPolicy {
  productCode: string;
  mode: LicensingMode;
  revision: number;
  effectiveAt: string | null;
}

export interface InstallationActivationResponse {
  installation_uuid: string;
  license_uuid: string;
  product_code: string;
  credential_type: 'bearer';
  credential: string;
  expires_at: string | null;
  signed_entitlements: SignedInstallationEntitlements;
  created: boolean;
}

export type CredentialKind = 'legacy_license_key' | 'installation_bearer' | null;

export interface LicenseState {
  version: 3;
  productCode: string;
  installationIdentifier: string;
  credentialKind: CredentialKind;
  encryptedInstallationCredential: string | null;
  machineFingerprint: string | null;
  licenseIdentifier: string | null;
  subscriptionIdentifier: string | null;
  activationIdentifier: string | null;
  status: LicenseStatus;
  lastSuccessfulVerification: string | null;
  expiresAt: string | null;
  offlineGraceExpiresAt: string | null;
  entitlements: EntitlementMap;
  signedEntitlementPayload: string | null;
  entitlementSignature: string | null;
  signatureAlgorithm: string | null;
  signatureKeyId: string | null;
  entitlementRevision: number | null;
  encryptedLicenseKey: string | null;
  configFingerprint: string | null;
  configFingerprintedAt: string | null;
  lastErrorCode: string | null;
}

export interface LicenseStore {
  load(productCode: string): Promise<string | null>;
  save(productCode: string, encryptedState: string): Promise<void>;
  remove(productCode: string): Promise<void>;
  loadInstallationCredential?(installationUuid: string): Promise<string | null>;
}

export interface SecretVault {
  isAvailable(): boolean;
  encrypt(value: string): Promise<string>;
  decrypt(value: string): Promise<string>;
}

export interface LicenseAccessDecision { allowed: boolean; reason: string; message: string }
export interface TokenProvider { getToken(): Promise<string> | string }
export interface EntitlementSignatureVerifier { verify(payload: string, signature: string, keyId?: string): boolean }
export interface MachineIdentityProvider { getFingerprint(): Promise<string> }
export type DeploymentProfileMode = 'disabled' | 'compatibility' | 'strict';
export interface DeploymentProfileStatus {
  mode: DeploymentProfileMode;
  initialized: boolean;
  matches: boolean;
  profileVersion: number | null;
  initializedAt: string | null;
  reprovisionedAt: string | null;
}

export interface LicensingClientOptions {
  apiUrl: string;
  productCode: string;
  apiToken?: string;
  tokenProvider?: TokenProvider;
  tokenProviderFingerprint?: string;
  timeoutMs?: number;
  verificationIntervalMs?: number;
  offlineGraceMs?: number;
  cacheSigningKey?: string;
  appKey?: string;
  extraConfigFingerprintValues?: Record<string, string | number | boolean | null | undefined>;
  renewalNoticeEnabled?: boolean;
  renewalNoticeThresholdMonths?: number[];
  requireSignedEntitlements?: boolean;
  signatureVerifier?: EntitlementSignatureVerifier;
  ed25519PublicKeys?: Readonly<Record<string, string>>;
  machineIdentityProvider?: MachineIdentityProvider;
  deploymentProfileMode?: DeploymentProfileMode;
  fetch?: typeof globalThis.fetch;
  now?: () => Date;
}
