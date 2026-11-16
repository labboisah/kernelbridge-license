# Product Platform Integration

This guide explains how a product platform (for example, a customer-account portal) works with a client application (for example, MediFlow) and KernelBridge licensing.

## Trust roles

| Component | Owns | Must not do |
|---|---|---|
| KernelBridge | Product licensing mode, activation-code issuance/redemption, license-bound installation records and credentials, seats, entitlements, renewal/expiry, account-link state, and licensing revocation | Replace product-platform identity, business/device inventory, or operational records |
| Product platform backend | Customer authentication, activated-account workflow, tenant/business membership, Business Server and business-app device inventory, trusted account-to-license mapping, device authorization, and server-side KernelBridge integration | Choose KernelBridge licensing policy, extend a KernelBridge license, or expose its integration credential to clients |
| Client application | Stable installation identity, code exchange, protected installation-credential storage, and local entitlement checks | Receive the platform integration credential or trust sign-in alone as proof of a KernelBridge license |

These are separate security roles even if the platform and client app share a codebase or deployment.

## Platform Account activation sequence

1. **Authenticate the customer.** The customer signs in using the product platform's existing authentication system.
2. **Resolve the account and license on the backend.** The platform backend uses its own trusted records to map the authenticated business/customer account to the correct KernelBridge license. Do not trust a `license_uuid` supplied by an unauthenticated or unauthorized client.
3. **Request the account link.** The backend calls `POST /api/v1/platform/account-links/requests` with the trusted `license_uuid` and the stable product-platform `business_uuid`. The product integration credential must have `licensing:account-link` scope.
4. **Use the returned account link.** A valid activation code or a license selected from the platform's trusted account-to-license mapping creates an active account link immediately. No separate KernelBridge owner approval is required; the platform integration credential must remain server-side.
5. **Create or retrieve a stable client installation ID.** The client licensing package persists this UUID before requesting a code. The product backend must bind activation to this client installation.
6. **Request the installation code.** The backend calls `POST /api/v1/platform/account-links/{link}/activation-codes` with the link UUID and installation UUID. KernelBridge rejects issuance unless the link is active and the effective product mode allows platform-account licensing.
7. **Deliver the short-lived code to that client flow.** The host platform returns the code only to the authenticated, authorized client activation request that owns that installation. Do not log, cache in browser storage, or expose it in URLs or analytics.
8. **Exchange the code in the client app.** The client licensing package calls `POST /api/v1/installations/activate`. KernelBridge creates or recognizes the installation and returns an installation-scoped bearer credential and signed entitlement snapshot.
9. **Persist and verify client state.** The client package saves the credential in protected runtime storage, verifies the signed snapshot, and uses the installation credential for later verification. It does not retain the platform credential.

KernelBridge registers the installation during code exchange. No separate device-registration operation is required. Retrying code exchange after a lost response must use the same installation UUID.

For platform-account activations, the product platform is the source of truth for its business, branch, and app-device records and decides which authorized device should be activated. KernelBridge remains the source of truth for the corresponding license-bound installation, credential, seat, entitlement, renewal, expiry, and revocation state. KernelBridge's customer portal may also show and manage that licensing-level installation; this does not transfer ownership of the platform's device inventory. In Hybrid mode, direct installations follow the direct-product management boundary while platform-linked installations follow this platform-account boundary. Use stable public identifiers to correlate the records and avoid copying platform-domain metadata into KernelBridge.

For products without a platform account, KernelBridge's customer portal is the management surface for the installed client's device information and licensing installation. For both types, renewal and entitlement changes remain KernelBridge-controlled.

## Direct and Hybrid modes

- **Direct:** The customer enters a KernelBridge installation code in the client application's activation experience. The product platform adapter is not required.
- **Platform Account:** The authenticated product-platform backend requests an active link and brokers the installation-bound code described above.
- **Hybrid:** The client may present both approved journeys only if KernelBridge configured the product for Hybrid.

The package and product platform do not provide a local mode selector. Only KernelBridge can set or change the effective product licensing mode.

## Server-side adapter use

The repository includes:

- Laravel services in `packages/laravel/src/Platform/`.
- A TypeScript server-only subpath, `@kernelbridge/licensing-client-typescript/platform`.
- A separate React activation screen, `@kernelbridge/activation-ui-react`, which receives host callbacks and never contains the platform credential.

The server adapter exposes link request, status polling, and installation-code issuance. It does not authenticate product-platform users, decide the account-to-license mapping, or expose product routes. The React UI renders the approved activation experience but does not call the server adapter or exchange codes itself. The host platform/application must connect the UI callbacks to authenticated backend routes or a trusted Electron main-process bridge.

The React UI's `completeActivation(linkId)` callback must request the installation-bound code through the trusted backend and pass it directly to the client SDK for exchange. It must not return the code to the React component or renderer. Resolve the UI mode from trusted product configuration; only render hybrid mode when KernelBridge configured it.

Supply the integration credential from server-side secret storage (Laravel's `PlatformCredentialProvider` or TypeScript's `getCredential` callback). Never place it in a client bundle, renderer, browser response, or customer-facing `.env` file. Protect host routes with authentication, authorization, CSRF protection for browser sessions, and appropriate throttling. Avoid logging integration credentials, activation codes, or installation credentials.

## Current implementation boundary

The account-link endpoints are implemented by KernelBridge, and the repository's Laravel and TypeScript server adapters call those endpoints. The TypeScript adapter has automated tests for requests, credential headers, installation binding, and authorization errors; Laravel adapter behavior still needs package-level automated integration tests.

The client packages in this repository remain imported legacy baselines. Their client activation, installation credential persistence, Ed25519 verification, and mode-aware UI have not yet been upgraded to the unified installation-activation protocol. This integration guide describes the target flow and implemented platform-adapter calls; it is not a claim that end-to-end client activation is production-ready.

See [the protocol contract](../protocol/v1-installation-activation.md) for request/response semantics and [the SDK improvement plan](SDK_IMPROVEMENT_PLAN.md) for remaining implementation gates.
