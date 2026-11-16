# KernelBridge Laravel Licensing Client

> **Migration status:** The working tree implements installation-code exchange, installation-bearer verification/deactivation, signed licensing-policy discovery, and mode-aware activation UX alongside the legacy flow. It is not yet a production release: full HTTP/controller and upgrade-migration coverage, immutable artifact validation, and product staging/rollback validation remain release gates.

This package is the shared Laravel licensing boundary for product applications such as AlignEx, EduNexa, MediFlow, and future client apps. It keeps a signed, encrypted local license snapshot on the product server and treats KernelBridge as the source of truth for license status, activation limits, entitlements, and feature policy.

## Why this package exists

The package is designed to prevent silent license drift and environment-based fallback.

Key rules:

- Local configuration is treated as bootstrap input, not as a trusted runtime authority.
- A persisted deployment profile stored in the product database records the expected deployment configuration.
- Sensitive system settings are fingerprinted and compared on activation and verification.
- A mismatch is fail-closed: the package blocks access until the operator intentionally re-provisions the deployment profile.

This detects configuration drift and prevents silent fallback. It is not hardware attestation or protection against a hostile administrator who controls the application files and database; the product server remains customer-controlled.

## Installation

The monorepo package is currently for local development and testing only; an upgraded immutable production artifact has not been released. Requirements and Composer path installation steps are documented in the repository [Package Installation Guide](../../docs/INSTALLATION.md). Publish tags and migrations listed there apply to the imported legacy package baseline and do not imply that unified activation is implemented.

## Default behavior

After installation the package auto-registers:

- the `/license` activation UI
- activation, verification, and deactivation routes
- middleware aliases for activation and feature/limit checks
- the scheduled verification job
- a signed local state store for license status and entitlements

Visit `/license` in the client app to activate or verify the installation. Before activation, the page fetches and verifies KernelBridge's signed effective licensing mode. Direct and Hybrid modes expose code activation; Platform Account mode links to the trusted product account flow, configured with `KERNELBRIDGE_PLATFORM_ACTIVATION_URL`; Legacy mode exposes legacy-key activation. Optional legacy-key migration remains available in Direct and Hybrid modes when enabled. If policy retrieval or signature verification fails, activation is disabled. Code exchange itself remains protected by KernelBridge's server-side issuance rules.

When a license or its verification grace period expires, protected browser routes redirect to the package's code-free `/license/renewal` screen. It offers configured KernelBridge links for renewal, an installation code, and an activation code, plus a link back to `/license` for entering a code. To enable the access gate, set `KERNELBRIDGE_PROTECT_WEB_ROUTES=true` and exclude public routes such as login, health checks, and onboarding with `KERNELBRIDGE_PROTECT_EXCEPT`. API requests remain `402 Payment Required`.

Configure the KernelBridge customer-portal destinations with `KERNELBRIDGE_RENEWAL_URL`, `KERNELBRIDGE_INSTALLATION_CODE_URL`, and `KERNELBRIDGE_ACTIVATION_CODE_URL`. Each must be an HTTPS URL; all default to the KernelBridge customer license page.

## Product-platform account-link adapter

This adapter is a separate, server-side integration surface. The client activation flow exchanges the resulting one-time code but the product still owns the authenticated account-to-license mapping and delivery of that code. The adapter requires application-level authorization and security controls.

For the full platform/client trust boundary and activation sequence, see the repository's [Product Platform Integration guide](../../docs/PRODUCT_PLATFORM_INTEGRATION.md).

The optional `KernelBridge\LicensingClient\Platform\PlatformAccountLinkClient` is for the trusted **product-platform backend**, not a client application, browser, or activation page. A valid link request creates an active KernelBridge account link immediately; the client then requests a one-time installation-bound activation code. It does not choose licensing mode, authenticate the product-platform customer, or expose routes in your application.

The package provides two distinct KernelBridge-branded Blade views: `kernelbridge-licensing::installation` for platform-account linking and `kernelbridge-licensing::activation` for client installation-code activation. The platform-account screen uses the `kernelbridge-licensing::layouts.installation` layout. A product can render the linking view from its authenticated Livewire page and keep account-to-license lookup and server actions in its own component:

```php
return view('kernelbridge-licensing::installation', [
    'productName' => 'MarketLens',
    'businessName' => $business->name,
    'license' => $business->kernelbridgeLicense()->first(),
])->layout('kernelbridge-licensing::layouts.installation');
```

The host Livewire component provides the `activationCode` property and `activate`, `refreshAccountLinkStatus`, `requestAccountLinkAgain`, and `unlinkAccount` actions. Reauthorize and throttle each action, including Livewire updates, and expose failures through the `activationCode` or `license` error bags. Revoked links allow a new activation code; unlink must revoke the remote link and update the host's local access records. Pass `licenseActive` as a boolean when the host can determine whether the linked license is active; an approved link alone does not guarantee an active license. Laravel asset publishing copies the shared logo and stylesheet to `public/vendor/kernelbridge-licensing`; to publish manually, run `php artisan vendor:publish --tag=kernelbridge-licensing-assets --force`.

When the host's linking action receives an approved account link and an active license, it can redirect the customer to its business account. Configure the destination route name with `KERNELBRIDGE_LICENSE_REDIRECT_AFTER_PLATFORM_LINK`; the host component should apply it after activation, status refresh, or link retry. Leave it unset when the host needs to provide custom post-link behavior.

Provide the `PlatformCredentialProvider` binding from your server-side secret manager. The credential must have KernelBridge's `licensing:account-link` scope; do not put it in client code or return it to a customer:

```php
use Illuminate\Support\Facades\App;
use KernelBridge\LicensingClient\Platform\PlatformAccountLinkClient;
use KernelBridge\LicensingClient\Platform\PlatformCredentialProvider;

App::bind(PlatformCredentialProvider::class, ProductPlatformSecretProvider::class);

$links = app(PlatformAccountLinkClient::class);
$link = $links->requestLink($trustedLicenseUuid, $authenticatedBusinessUuid);
$status = $links->status($link['uuid']);

if ($status['status'] === 'approved') {
    $code = $links->issueInstallationCode(
        $status['uuid'],
        $clientApplicationInstallationUuid,
        'MediFlow client',
    );
}

// When an authenticated customer switches licenses, revoke this link before requesting another.
$revoked = $links->unlink($status['uuid']);
// Unlinking revokes active installations and unused installation codes for the account link.
```

Resolve the license from the product platform's authenticated account/license mapping; never accept an arbitrary `license_uuid` from the client. Require customer authentication, authorization, CSRF protection for browser sessions, and appropriate throttling on any product-owned endpoints that call this adapter. Deliver the short-lived activation code only to the requesting authenticated client flow, and never log it. The client application then exchanges that code through its own client licensing SDK. KernelBridge creates the installation during code exchange; no separate device-registration call is needed.

## Entitlement catalog validation

The package provides `EntitlementCatalogValidator` for the shared `product → package → module → capability` catalog. It validates stable keys, product and catalog revisions, capability/limit definitions, and plan grants, returning flattened stable-key definitions for server-side use. Validation does not publish catalogs or assign customer grants; catalog publishing and Direct plan mapping belong in trusted KernelBridge/product-platform administration.

```php
use KernelBridge\LicensingClient\Services\EntitlementCatalogValidator;

$result = app(EntitlementCatalogValidator::class)->validate($catalog, 'MEDIFLOW');
if (! $result['valid']) {
    throw new InvalidArgumentException("Invalid entitlement catalog: {$result['error']}");
}
```

Treat a validation failure as an error and do not activate or apply grants from the invalid catalog.

## Environment values

```dotenv
KERNELBRIDGE_API_URL=https://kernelbridge.com/api/v1
KERNELBRIDGE_PRODUCT_CODE=ALIGNEX
KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS='{"kb-1":"replace-with-the-base64-raw-ed25519-public-key"}'
KERNELBRIDGE_PLATFORM_ACTIVATION_URL=https://your-product.example/account/licensing
KERNELBRIDGE_LICENSE_REDIRECT_AFTER_PLATFORM_LINK=platform.dashboard
KERNELBRIDGE_API_CONNECT_TIMEOUT=3
KERNELBRIDGE_API_TIMEOUT=10
KERNELBRIDGE_VERIFICATION_INTERVAL=15
KERNELBRIDGE_OFFLINE_GRACE_HOURS=72
KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-an-independent-long-random-secret
KERNELBRIDGE_PROTECT_WEB_ROUTES=true
KERNELBRIDGE_PROTECT_EXCEPT=login,register,password/*
KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true
KERNELBRIDGE_RENEWAL_NOTICE_MONTHS=3,2,1
KERNELBRIDGE_STRICT_DEPLOYMENT_PROFILE=true
```

`KERNELBRIDGE_API_TOKEN` is optional and is only needed for controlled legacy license-key API operations. Unified policy discovery and installation-code exchange do not send a product token. The optional platform-account adapter uses a separate scoped credential supplied by the platform's server-side secret provider.

Important notes:

- Keep any legacy product token separate for each environment and product. The unified installation-code exchange does not send a product token.
- Every client installation must have the trusted public-key map configured before code exchange; the SDK intentionally fails closed if the signing `key_id` is missing or untrusted.
- Never commit tokens or signing keys.
- Rotate API credentials when a deployment changes, a product is retired, or a token is suspected to be exposed.
- `KERNELBRIDGE_STRICT_DEPLOYMENT_PROFILE` should remain enabled in production.
- Provision `KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS` with the exact JSON map emitted by KernelBridge's `php artisan product-integration:generate-signing-key` command. It identifies the active signer and key ID. The public key is not secret, but must be delivered through trusted deployment configuration; never trust a key fetched from an unauthenticated endpoint.

## Route protection

Two supported patterns exist:

### Protect the whole app

```dotenv
KERNELBRIDGE_PROTECT_WEB_ROUTES=true
KERNELBRIDGE_PROTECT_EXCEPT=login,register,password/*
```

This redirects unauthenticated or unlicensed browser requests to `/license` until activation succeeds.

### Protect only selected routes

```php
Route::middleware('kernelbridge.subscription.active')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/reports', ReportsController::class);
});
```

This is useful when the client app needs custom route-level enforcement instead of a global redirect.

## Deployment profile and fail-closed validation

The package records a deployment profile that includes the product code, deployment mode, API URL, hashes of the machine token and signing key, and a SHA-256 fingerprint of the active licensing configuration. License entitlements remain separately signed in the local license cache.

During activation and verification, the package checks:

- the current config matches the persisted deployment profile
- required values are present and not empty
- the local signed state is valid
- the runtime config still matches the persisted deployment fingerprint

If the config changes after activation, the runtime fails closed with `configuration_tampered` and asks for re-activation. The DB profile is a consistency baseline, not a cryptographic trust anchor against an operator who can modify both the application and its database.

With `KERNELBRIDGE_STRICT_DEPLOYMENT_PROFILE=true` (the default), initialize the profile explicitly after configuring the environment and running the package migrations:

```bash
php artisan kernelbridge:initialize-profile
```

Activation, verification, deactivation, and protected access remain blocked until the profile is initialized. Set strict profile enforcement to `false` only for compatibility deployments; an existing profile is still checked against the current configuration.

## Intentional re-provisioning

When a deployment genuinely changes, the operator can intentionally rotate the trusted deployment record.

### Artisan command

```bash
php artisan kernelbridge:reprovision-profile
```

The command asks for confirmation before replacing an existing profile, with the prompt defaulting to no. Add `--force` only for a controlled, non-interactive deployment operation. The command first validates the required client settings and then persists the current deployment fingerprint. It is CLI-only by default so profile replacement is not exposed to unauthenticated web requests.

Client apps that require a web-based control must implement their own authenticated, administrator-authorized action and must not expose the package profile update directly to public users.

## Activation and verification

```php
use KernelBridge\LicensingClient\Services\LicenseActivationService;
use KernelBridge\LicensingClient\Services\LicenseVerificationService;

// Preferred unified flow: pass the short-lived code to the client application.
$state = app(LicenseActivationService::class)->activateWithCode($activationCode, 'AlignEx production');
$state = app(LicenseVerificationService::class)->verify();
$features = app(LicenseVerificationService::class)->getEntitlements();
app(LicenseVerificationService::class)->deactivate();
```

The legacy `activate($licenseKey)` method remains for controlled migration and compatibility. Do not expose a reusable product license key to an untrusted browser or desktop client. Configure `kernelbridge-licensing.entitlement_public_keys` with trusted base64-encoded raw 32-byte Ed25519 public keys, indexed by KernelBridge `key_id`; unknown keys fail closed. Key distribution and rotation must be completed before enabling unified activation in production.

Version-1 signed installation claims may include the optional `entitlement_framework` extension. The package verifies the exact signed payload bytes first, then rejects malformed or source-mismatched extensions; snapshots without the extension remain supported. With the extension present, `hasFeature`, `entitlementDecision`, and `limitGrant` use its typed grants as the authority; a missing structured grant does not fall back to the flat list. Without the extension, existing flat-entitlement behavior is preserved. `limitGrant` returns finite/unlimited states with unit, scope, and period metadata, while the existing `limit` method retains its scalar return contract.

The verified signed payload remains the cache source, so existing encrypted caches migrate without a schema change. Snapshot freshness is bounded from signed `issued_at` by the configured offline-grace duration and signed license/grace expiry; snapshots more than five minutes in the future or older than the freshness window fail closed, and older responses cannot replace newer cached snapshots. Feature decisions are local-cache reads and do not call KernelBridge per request. Keep old and new trusted key IDs configured during signing-key rotation.

The package encrypts installation credentials and signed server snapshots at rest and signs its normalized local entitlement cache. It clears stale credentials when switching between unified and legacy credential types. The local cache also records:

- credential type and installation/license/subscription identifiers
- activation and verification metadata
- signed entitlement payload
- expiry timestamps and offline grace dates
- a hash of the effective configuration and deployment fingerprint

## License status and tamper handling

The package fails closed when any of these are true:

- no license exists locally
- the license was revoked, rejected, deactivated, expired, or exceeded offline grace
- the signed entitlement payload was changed or cannot be decrypted
- sensitive licensing configuration changed after activation
- the deployment profile does not match the active runtime config

Protected web routes redirect to `/license`, and JSON/API requests return `402 Payment Required` until activation or re-provisioning resolves the problem.

## Local state and offline operation

Normal product requests read the local signed snapshot and do not require a live KernelBridge call. The scheduled verification job refreshes the local state on a fixed interval.

If a temporary outage occurs, the last valid signed snapshot remains usable only until the earlier of:

- the subscription expiry for active/trial licenses, or the signed grace expiry while status is `grace`
- offline grace expiry
- explicit rejection or deactivation
- signature failure or entitlement tampering

This is intentional: the package remains usable during short outages, but it does not silently fall back to an unsafe local policy.

## Release tags and updates

Tag package releases in GitHub using the package version, for example `1.2.0`.

Client apps can then update via:

```bash
composer update kernelbridge/licensing-client-laravel
php artisan optimize:clear
```

If a custom view exists at `resources/views/vendor/kernelbridge-licensing/activation.blade.php`, merge any desired branding changes manually. Published views override the package view and are not replaced automatically.

## Operational guidance

- Treat KernelBridge as the licensing source of truth.
- Back up the product database but do not rely on local config as a trust anchor.
- Protect `APP_KEY` and `KERNELBRIDGE_CACHE_SIGNING_KEY`.
- Monitor failing verification jobs and API connectivity.
- Do not expose the local entitlement payload or signed state to public controllers.

## Package summary

The package is intentionally strict by default:

- it validates the active deployment
- it signs and verifies local state
- it blocks silent fallback
- it requires an explicit deployment rotation for real runtime changes

That makes it safe for production products while still supporting a clear, auditable re-provision path when the deployment must intentionally change.
- Check entitlements again inside sensitive service methods; route middleware is a convenience boundary, not the only authorization control.

## Machine binding

The legacy reusable-key flow sends a SHA-256 machine fingerprint with activation, verification and deactivation. The unified installation-code flow instead binds the issued bearer credential and signed entitlement snapshot to the stable installation UUID. It does not claim hardware attestation. Existing version-1/legacy caches remain compatible; verify and test migrations before upgrading a product.

The platform persists installation identity and credential state server-side. Do not expose bearer credentials or raw signed entitlement payloads through public controllers, logs, or diagnostics.
