# KernelBridge TypeScript Licensing Client

> **Migration status:** The working tree implements signed licensing-policy discovery, installation-code exchange, installation-bearer verification/deactivation, Ed25519 entitlement verification, and version-3 state migration alongside legacy compatibility. The separate React package provides the branded activation UI. This is not yet a production release: trusted signing-key provisioning, product integration, release artifact validation, staging/rollback validation, and stronger clock-rollback protection remain gates.

Framework-neutral licensing for Electron main processes and Node.js desktop services. It stores local snapshots in SQLite behind a host-provided secret vault.

## Security boundary

Run this package only in Electron's **main process**. Never import it into a renderer or expose license keys, API tokens, encrypted state, or raw entitlements over IPC. Expose narrow decisions such as `hasFeature('finance')` and `limit('max_users')`.

The optional `@kernelbridge/licensing-client-typescript/platform` export is a separate **server-only product-platform adapter**. It links valid licenses to product accounts and requests installation-bound activation codes. Import it only in a trusted Node.js backend. It is not safe to bundle into Electron renderers, browser code, or client applications, and it does not replace the client SDK's activation/code-exchange flow.

React hosts can use the separate [`@kernelbridge/activation-ui-react`](../react/README.md) package for the KernelBridge-branded activation screen. Keep the platform adapter and client SDK in their trusted runtime; the UI accepts host callbacks and does not receive platform credentials or activation codes.

```ts
import { PlatformAccountLinkClient } from '@kernelbridge/licensing-client-typescript/platform';

const links = new PlatformAccountLinkClient({
  apiUrl: 'https://kernelbridge.com/api/v1',
  productCode: 'MEDIFLOW',
  getCredential: () => serverSecretStore.get('kernelbridge-account-link-credential'),
});

// Resolve these IDs from the authenticated product-platform account/license mapping.
const link = await links.requestLink(trustedLicenseUuid, authenticatedBusinessUuid);
if (link.status === 'approved') {
  const activation = await links.issueInstallationCode(link.uuid, clientInstallationUuid);
  // Deliver activation.activation_code only to the authenticated client flow; never log it.
}

// When the authenticated customer switches licenses, revoke the existing link first.
const revoked = await links.unlink(link.uuid);
// Existing installations and unused installation codes for that link are revoked.
```

The backend credential must have the `licensing:account-link` scope and stay in server-side secret storage. Protect product-owned endpoints with authentication, authorization, CSRF protection where applicable, and throttling. Do not accept a license UUID or installation UUID from an untrusted caller without validating its association. KernelBridge performs installation registration when the client exchanges the returned code; do not create a parallel device-registration call.

See the [Product Platform Integration guide](../../docs/PRODUCT_PLATFORM_INTEGRATION.md) for the full sequence and current implementation boundary.

A permanent product integration credential can be extracted from a distributed desktop binary. Public Electron releases must never embed a product-platform integration credential or product machine token in source, asar files, renderer environment variables, or SQLite. The unified flow exchanges a short-lived installation-bound code without a product API credential, then stores the installation-scoped bearer credential in protected main-process storage.

## Installation

The monorepo package is currently for local development and testing only; an upgraded immutable npm release has not been published. Build and install steps, runtime requirements, and package selection are documented in the repository [Package Installation Guide](../../docs/INSTALLATION.md).

The imported client baseline uses `node-machine-id` to obtain a hashed OS machine identifier. Hosts may inject a `MachineIdentityProvider` when their deployment requires a platform-specific source.

## Electron main-process setup

```ts
import Database from 'better-sqlite3';
import { safeStorage } from 'electron';
import {
  DeploymentProfileService, ElectronSafeStorageVault, KernelBridgeLicenseClient,
  LicenseActivationService, LicenseCacheService, LicenseGuard,
  LicenseVerificationService, PeriodicLicenseVerifier, SqliteDeploymentProfileStore, SqliteLicenseStore,
} from '@kernelbridge/licensing-client-typescript';

const options = {
  apiUrl: process.env.KERNELBRIDGE_API_URL!,
  productCode: 'ALIGNEX',
  ed25519PublicKeys: trustedEntitlementKeys,
  tokenProvider: installationCredentials,
  tokenProviderFingerprint: process.env.KERNELBRIDGE_TOKEN_PROVIDER_FINGERPRINT!,
  cacheSigningKey: process.env.KERNELBRIDGE_CACHE_SIGNING_KEY!,
  verificationIntervalMs: 15 * 60_000,
  offlineGraceMs: 72 * 60 * 60_000,
  deploymentProfileMode: 'strict',
};
const database = new Database('/private/app-data/license.sqlite');
const store = new SqliteLicenseStore(database);
const vault = new ElectronSafeStorageVault(safeStorage);
const profiles = new DeploymentProfileService(new SqliteDeploymentProfileStore(database), vault, options);
const cache = new LicenseCacheService(store, vault, options, profiles);
const api = new KernelBridgeLicenseClient(options);
export const activation = new LicenseActivationService(api, cache, options);
export const verification = new LicenseVerificationService(api, cache, options);
export const license = new LicenseGuard(cache);
const verifier = new PeriodicLicenseVerifier(() => verification.verify(true), options.verificationIntervalMs, console.error);
verifier.start();
```

Initialize the deployment profile once through trusted installation/provisioning tooling, after reviewing the active API URL, product, credential scope, and signing-key configuration:

```ts
await profiles.initializeProfile();
```

Do not initialize or reprovision automatically during application startup, activation, or verification. In strict mode, activation and verification require an initialized matching profile. Existing installations can temporarily use `compatibility` mode while an operator initializes profiles; profile mismatches still fail closed. `disabled` mode permits an installation with no profile, but it does not bypass an existing profile. Lowering an existing strict profile requires confirmed reprovisioning.

For controlled installations, `licensingOptionsFromEnv()` loads the compatible environment names:

```dotenv
KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS='{"kb-1":"replace-with-base64-raw-ed25519-public-key"}'
KERNELBRIDGE_API_TIMEOUT_MS=10000
KERNELBRIDGE_VERIFICATION_INTERVAL_MS=900000
KERNELBRIDGE_OFFLINE_GRACE_MS=259200000
KERNELBRIDGE_REQUIRE_SIGNED_ENTITLEMENTS=false
KERNELBRIDGE_DEPLOYMENT_PROFILE_MODE=compatibility
```

No `KERNELBRIDGE_API_TOKEN` is required for signed policy discovery, unified activation-code exchange, or installation-bearer verification. Set a token only for the legacy license-key API. Prefer a runtime `tokenProvider` backed by an installation credential for distributable applications; environment variables are not a secure secret boundary inside a packaged Electron application.
When using `tokenProvider`, provide a stable `tokenProviderFingerprint` that changes when its credential is rotated, and a `cacheSigningKey`. Neither value should be exposed to a renderer. Configure the profile mode as `strict` after explicitly initializing existing installations.
For the unified code-exchange path, `ed25519PublicKeys` is required. The client fetches the signed effective licensing mode and verifies it before exchanging a code; neither request sends `apiToken` or a product integration credential. The legacy `activate(licenseKey)` method remains only for controlled compatibility/migration.

For unified activation, configure the trusted Ed25519 public keys delivered with the application build. The public keys are indexed by KernelBridge `key_id`; accept either the raw base64-encoded public-key map printed by KernelBridge or PEM public keys. Do not fetch or trust keys from an unauthenticated endpoint: the public policy endpoint is signed with these pre-provisioned keys and cannot establish their trust.

Version-1 signed installation claims may include the optional `entitlement_framework` extension. The SDK verifies the exact signed payload bytes first, then rejects malformed or source-mismatched extensions; snapshots without the extension remain supported. With the extension present, `hasFeature`, `entitlementDecision`, and `limitGrant` use its typed grants as the authority; missing structured grants do not fall back to the flat list. Without the extension, existing flat-entitlement behavior is preserved. `limitGrant` returns finite/unlimited states with unit, scope, and period metadata, while `limit` retains its existing scalar return contract.

The encrypted signed payload remains the cache source, so prior cache versions migrate without a database change. Snapshot freshness is bounded from signed `issued_at` by `offlineGraceMs` and signed license/grace expiry; snapshots more than five minutes in the future or older than the freshness window fail closed, and older responses cannot replace newer cached snapshots. Feature decisions are local-cache reads and do not call KernelBridge per request. Keep old and new trusted key IDs configured during signing-key rotation.

Activation and protocol methods:

```ts
await activation.activateWithCode(activationCode, 'Front desk PC');
await verification.verify();
await verification.deactivate();
const entitlements = await verification.getEntitlements();

await license.assertActive();
await license.assertFeature('finance');
await license.assertLimit('max_users', currentUserCount + 1);
```

Invoke `verify(true)` on application startup and periodically in the main process. Normal navigation reads the encrypted local state and makes no network request. Stop the periodic verifier during application shutdown.


## Activation gating

The TypeScript package cannot automatically protect Electron windows or Node routes the way the Laravel package can push middleware into a web route group. Instead, call `LicenseGuard` from the Electron main process or from server-side Node route middleware before opening protected screens or running protected operations.

```ts
const decision = await license.checkActive();
if (!decision.allowed) {
  showActivationWindow(decision.message);
  return;
}

await openMainApplicationWindow();
```

`checkActive()` returns `{ allowed, reason, message }`. `assertActive()` throws `LicenseAccessError` with the same reason and message. After activation, the package stores a signed fingerprint of `apiUrl`, `productCode`, credential hash or provider fingerprint, `appKey`, `cacheSigningKey`, and any `extraConfigFingerprintValues`. If those values change, `checkActive()` returns `configuration_tampered` and the app should show the activation UI again.

Deployment profiles are stored in a separate encrypted `kernelbridge_deployment_profiles` SQLite table. `profiles.status()` returns only redacted metadata. To intentionally accept a reviewed configuration change, call `profiles.reprovisionProfile(true)` from trusted main-process or deployment tooling, then reactivate the license if its configuration fingerprint no longer matches. The confirmation argument is mandatory; never expose this operation through renderer IPC or an unauthenticated route.

## Entitlement catalog validation

The package exports `validateEntitlementCatalog()` for validating the shared `product → package → module → capability` catalog before using it in a trusted integration. It checks catalog/product revisions, stable-key uniqueness, capability and limit definitions, and plan grants, then returns a flattened stable-key definition map. The hierarchy is an authoring and validation structure; runtime authorization continues to use stable grant keys. Catalog validation does not publish a catalog or assign a customer's grants.

```ts
import { validateEntitlementCatalog } from '@kernelbridge/licensing-client-typescript';

const result = validateEntitlementCatalog(catalog, 'MEDIFLOW');
if (!result.valid) {
  throw new Error(`Invalid entitlement catalog: ${result.error}`);
}
```

Keep catalog publishing and Direct plan mapping in trusted server-side administration. Do not let a client application choose or publish its effective grants.


## Renewal notices

Use `LicenseGuard.renewalNotice()` or `LicenseCacheService.renewalNotice()` to notify users before the current license expires. The default thresholds are 3, 2, and 1 month before expiry.

```ts
const notice = await license.renewalNotice();
if (notice) {
  showRenewalBanner(notice.message);
}
```

Warnings do not block access. Continue to use `checkActive()` or `assertActive()` for the activation gate.

## Local SQLite state

`SqliteLicenseStore` creates only `kernelbridge_license_state`. The entire JSON state—including the license key—is encrypted before SQLite receives it. The record includes product/installation/license/subscription/activation identifiers, a machine fingerprint, last successful verification, expiry, offline grace expiry, feature/limit snapshot, optional server signature, a signed configuration fingerprint, and last rejection code. Version 1 state is normalized to version 2; because it lacks a machine fingerprint, the next successful verification reactivates the existing installation once to bind it.

Electron `safeStorage` must report encryption availability. The package refuses to save when it is unavailable. Protect the application data directory with normal OS permissions and exclude the licensing database from diagnostics uploaded to support.

## Offline behavior

Network and HTTP 5xx failures may use the last encrypted snapshot until the earlier of central license expiry and offline grace expiry. Explicit API rejection marks the local state invalid. Deactivation, expiry, changed sensitive configuration, missing activation, and corrupted local state fail closed. The package does not trust a renderer-provided status or total.

For signed `grace` status, the signed `grace_expires_at` bounds access instead of the already elapsed subscription `expires_at`. Active and trial statuses remain bounded by `expires_at`, when present. Every installation snapshot is also bounded by the configured offline-grace window measured from signed `issued_at`; a stale or future-dated snapshot fails closed.

For stronger clock-rollback protection, production applications should persist the greatest signed server time and compare it with a monotonic session clock. The optional RSA-SHA256 verifier remains for the legacy response shape; it is not a substitute for the unified Ed25519 verifier. Production enablement still requires a trusted key-provisioning/rotation process and application-owned activation UI that follows KernelBridge mode.

Machine binding sends a domain-separated SHA-256 fingerprint of the OS machine identity on activation, verification, and deactivation. The raw OS identifier is never sent or persisted. This supports server-side device-bound license policy and rejects copied local caches. It is not hardware attestation: customers who control the machine or application may inspect or patch the client, and cloned OS identities may share identifiers.

## IPC example

Expose decisions, not objects:

```ts
ipcMain.handle('license:feature', (_event, key: string) => cache.hasFeature(key));
ipcMain.handle('license:limit', (_event, key: string) => cache.limit(key));
```

Validate allowed feature keys in the IPC handler and use Electron context isolation. Repeat checks inside sensitive main-process business operations; hiding a renderer button is not authorization.
