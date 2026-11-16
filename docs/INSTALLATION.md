# Package Installation Guide

This guide covers the Laravel and TypeScript packages in this monorepo and clarifies which application receives each integration.

> **Release status:** Unified client flows and the React activation UI are implemented in the working tree but have not been released as immutable artifacts. Do not install these monorepo packages in production yet. Use the local instructions below for development, integration, and tests.

## Choose the package by runtime and trust role

| Your application | Package | Purpose |
|---|---|---|
| Laravel client application, such as MediFlow | `packages/laravel` | Client licensing integration with unified installation activation and legacy compatibility. |
| Trusted Laravel product-platform backend | `packages/laravel` | Optional server-side account-link adapter; platform credentials remain in server secret storage. |
| Electron/Node.js client application | `packages/typescript` main SDK | Unified client licensing integration. Use only in Electron's trusted main process or a trusted Node.js service. |
| Trusted Node.js product-platform backend | `@kernelbridge/licensing-client-typescript/platform` export | Optional server-side account-link adapter; never bundle it in a browser or Electron renderer. |
| React activation screen | `packages/react` (`@kernelbridge/activation-ui-react`) | KernelBridge-branded activation UI; connect its callbacks to trusted client and platform services. |

The client application and product platform are distinct security roles even if they are deployed together. Platform Account mode uses the product platform to authenticate its customer and map that account to a license. KernelBridge approves the link. The client application receives an installation-bound, short-lived activation code and exchanges it with KernelBridge. It must never receive the platform integration credential.

KernelBridge sets the effective licensing mode. Installing a package does not select or change that mode.

## Laravel package — local development

Requirements: PHP 8.3+ and Laravel 12, matching `packages/laravel/composer.json`.

Assuming the product and SDK repositories are sibling directories:

```text
C:\laragon\www\mediflow
C:\laragon\www\kernelbridge-license
```

From the Laravel product root, add the local Composer path repository and require the package:

```powershell
composer config repositories.kernelbridge path ../kernelbridge-license/packages/laravel
composer require kernelbridge/licensing-client-laravel:^1.2
```

Publish the legacy package resources and run its migrations:

```powershell
php artisan vendor:publish --tag=kernelbridge-licensing-config
php artisan vendor:publish --tag=kernelbridge-licensing-migrations
php artisan vendor:publish --tag=kernelbridge-licensing-assets
php artisan migrate
```

The Laravel package now supports unified direct installation-code activation and signed entitlement verification, but it remains a local-development integration until an immutable release and product staging/rollback validation are available. Direct activation does not send a product API token.

The package provides two KernelBridge-branded Blade views with distinct roles: `kernelbridge-licensing::installation` handles platform-account linking, and `kernelbridge-licensing::activation` handles client installation-code activation. The account-link view uses the standalone `kernelbridge-licensing::layouts.installation` layout. Product applications keep their authenticated account/license mapping and Livewire actions while rendering the shared screen. Publish the shared logo and stylesheet with `php artisan vendor:publish --tag=kernelbridge-licensing-assets --force`; projects may run this in their Composer autoload scripts so package installation and updates refresh the assets.

For unified Laravel and TypeScript clients, provision the trusted Ed25519 public-key map from `php artisan product-integration:generate-signing-key` on the KernelBridge server. Keep the map in trusted deployment/build configuration and include old and new IDs during a planned rotation. KernelBridge's public policy endpoint is signed by those keys; it does not provide key discovery or establish trust. The TypeScript environment loader accepts the JSON map in `KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS`. No product API token is needed for unified policy discovery or code exchange; configure a token only for legacy operations that require it. Never bypass signature verification to recover from a trust-configuration error.

### Optional Laravel product-platform adapter

Use the adapter only in the trusted platform backend. Bind `KernelBridge\LicensingClient\Platform\PlatformCredentialProvider` to a provider that reads the scoped credential from the platform's server-side secret manager. The integration credential requires the `licensing:account-link` scope.

The adapter exposes request-link, poll-status, and issue-installation-code operations. The host application still owns user authentication, authorization, CSRF protection, throttling, and trusted account-to-license mapping. Do not add a public unauthenticated proxy endpoint. Full guidance is in [Product Platform Integration](PRODUCT_PLATFORM_INTEGRATION.md).

## TypeScript package — local development

Requirements: Node.js 20+. The imported client SDK uses Electron `safeStorage` and SQLite adapters; install `better-sqlite3` in the client application if using `SqliteLicenseStore`.

Build the package from the monorepo first:

```powershell
Set-Location C:\laragon\www\kernelbridge-license\packages\typescript
npm ci
npm run build
npm test
```

Then, from a sibling TypeScript/Electron application:

```powershell
Set-Location C:\laragon\www\mediflow-desktop
npm install ..\kernelbridge-license\packages\typescript
```

The local package reference is for development only; keep the SDK and product working trees available together. The client supports unified installation-code activation and signed entitlements while retaining legacy compatibility. Configure the trusted Ed25519 public keys and deployment profile required by the client SDK before activation.

### Optional TypeScript product-platform adapter

Import `@kernelbridge/licensing-client-typescript/platform` only from a trusted Node.js backend. Keep the product integration credential in server-side secret storage and provide it through `getCredential`. The adapter requires product identity and a KernelBridge API URL; it does not authenticate platform users or determine their license association.

```ts
import { PlatformAccountLinkClient } from '@kernelbridge/licensing-client-typescript/platform';

const platformLicensing = new PlatformAccountLinkClient({
  apiUrl: 'https://kernelbridge.com/api/v1',
  productCode: 'MEDIFLOW',
  getCredential: () => serverSecretStore.get('kernelbridge-account-link-credential'),
});
```

Never import the platform subpath in a browser or Electron renderer. Protect the host application's adapter routes and never return or log platform credentials or activation codes.

## React activation UI — local development

The React UI is framework-specific and separate from the Node/Electron client SDK:

```powershell
Set-Location C:\laragon\www\kernelbridge-license\packages\react
npm ci
npm test
```

From a React product in a sibling checkout, install the local package and import its stylesheet:

```powershell
npm install ..\kernelbridge-license\packages\react
```

```ts
import '@kernelbridge/activation-ui-react/styles.css';
```

Supply the effective mode from a verified `LicenseActivationService.getLicensingPolicy()` result, not a local environment toggle. The UI accepts callbacks for direct-code activation and/or the platform-account approval flow; it does not authenticate product users or handle the platform integration credential. In Electron, keep policy verification and client SDK calls in the main process and connect callbacks through narrow, validated IPC handlers.

## Production installation

Production installation from a tagged Composer/npm artifact is not documented yet because this monorepo has not produced upgraded client releases. Before a production rollout, the applicable package must have:

- unified installation-code exchange and installation bearer lifecycle;
- secure, durable credential storage and backward-compatible migration;
- Ed25519 entitlement verification with documented public-key rotation;
- verified signed-policy discovery and mode-aware, KernelBridge-branded client activation UX;
- platform-to-client activation and failure/retry tests;
- clean artifact install, upgrade, rollback, and product staging validation.

Follow the [SDK improvement plan](SDK_IMPROVEMENT_PLAN.md) and [Product Platform Integration guide](PRODUCT_PLATFORM_INTEGRATION.md) for the remaining gates and architecture.
