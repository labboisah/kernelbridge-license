# KernelBridge Licensing SDKs

This repository is the shared home for KernelBridge licensing clients, trusted product-platform adapters, and shared protocol documentation.

> **Development status:** The Laravel and TypeScript client packages now implement unified installation activation and signed entitlement verification in the working tree; the optional platform adapters and React activation UI are also present. The packages are not yet production releases. Trusted key provisioning, product integration/staging, clean artifact installation, and rollback validation remain release gates.

## Architecture

KernelBridge, a product platform, and a client application have distinct responsibilities:

- **KernelBridge** is authoritative for product licensing mode, license status, account links, activation-code issuance, installation seats, signed entitlements, and revocation.
- **The product platform** authenticates its own customers and resolves the trusted customer-account-to-license relationship. Its backend may use the optional platform adapter to link a valid license and request an installation-bound activation code.
- **The client application** (for example, MediFlow) uses its runtime licensing package to create a stable installation ID, exchange a short-lived activation code, store its own installation credential securely, and enforce entitlements. React applications may use the separate `@kernelbridge/activation-ui-react` package for the branded activation screen.

The platform and client roles may run in one product suite, but their credentials and APIs must remain separated. The platform integration credential is server-only and must never reach a browser, renderer, or client application. The client receives only a short-lived code for its installation and, after exchange, an installation-scoped credential.

```text
Customer -> Product platform sign-in
         -> Platform backend resolves account/license mapping
         -> Customer submits a valid KernelBridge activation code
         -> Trusted platform backend links the business account
         -> Platform backend requests installation-bound code
         -> Client app exchanges code with KernelBridge
         -> KernelBridge registers installation and returns its credential
```

KernelBridge creates the installation during activation-code exchange; a separate device-registration call is not required. Direct activation uses a code entered in the client application's activation screen. Platform Account mode uses the product platform's authenticated account flow. Hybrid may expose both only when configured by KernelBridge. The React UI displays only the mode supplied by trusted host configuration; it does not offer a local licensing-mode override.

## Repository layout

- `packages/laravel/` — Laravel client package and the optional Laravel server-side platform adapter.
- `packages/typescript/` — TypeScript client SDK for trusted Electron main processes/Node.js and the optional `@kernelbridge/licensing-client-typescript/platform` server adapter.
- `packages/react/` — optional KernelBridge-branded React activation screen with host-provided callbacks.
- `protocol/` — consumer-facing API contract and shared conformance fixture location.
- `shared/branding/` — canonical KernelBridge activation-screen assets.
- `docs/PRODUCT_PLATFORM_INTEGRATION.md` — product-platform/client-application flow and trust boundary.
- `docs/INSTALLATION.md` — package selection and local development installation steps.
- `docs/` — repository migration, implementation, and release guidance.

The platform adapters implement the existing account-link endpoints:

- `POST /api/v1/platform/account-links/requests`
- `GET /api/v1/platform/account-links/{link}`
- `POST /api/v1/platform/account-links/{link}/activation-codes`

They require a product integration credential with `licensing:account-link` scope. The host platform remains responsible for authentication, authorization, CSRF protection where applicable, throttling, and trusted account/license mapping. See [the protocol contract](protocol/v1-installation-activation.md) for details.

## Package status

The Laravel and TypeScript clients retain legacy compatibility while supporting the unified installation-code exchange, installation bearer lifecycle, Ed25519 snapshot validation, and backwards-compatible local-state migration. The separate React UI supplies direct-code and platform-account activation screens; product teams still connect its callbacks to their trusted account and client services.

## Development

Run commands from the package directories:

```powershell
Set-Location packages\laravel
composer validate
```

```powershell
Set-Location packages\typescript
npm ci
npm run typecheck
npm test
```

Protocol changes should be covered by shared fixtures and each supported package's tests. The TypeScript platform-adapter tests exercise request paths, server credential headers, installation binding, and surfaced authorization errors.

## Security and configuration

New client integrations are intended to avoid customer-managed licensing `.env` values, generated environment files, product machine tokens, or manual endpoint setup. That target is not yet implemented in the imported client packages.

Platform integration credentials must be injected into server-side adapters from trusted secret storage. Do not hard-code them, send them to client applications, include them in logs, or return them in browser responses. Never log activation codes or installation credentials. KernelBridge—not a product installer, customer, or local config—sets the effective licensing mode.

## Activation experience and branding

The React UI package provides the KernelBridge-branded activation screen for direct, platform-account, and hybrid flows. It requires the host to supply the effective mode from trusted configuration and secure callbacks for activation; it does not authenticate product-platform users, call account-link APIs, or expose platform credentials. Package UI must not white-label KernelBridge or let a local installer/customer change licensing mode.

See [the SDK improvement plan](docs/SDK_IMPROVEMENT_PLAN.md) for migration and release gates.
