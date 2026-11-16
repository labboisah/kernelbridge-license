# Unified Installation Activation — API v1

This document records the currently implemented KernelBridge installation activation contract. KernelBridge remains the source of truth for product licensing mode, license status, seats, and entitlements.

## Common response envelope

Successful responses use:

```json
{
  "success": true,
  "data": {},
  "meta": {
    "request_id": "request-correlation-id"
  }
}
```

Errors use:

```json
{
  "success": false,
  "error": {
    "code": "stable_reason_code",
    "message": "Safe user-facing explanation.",
    "details": {}
  },
  "meta": {
    "request_id": "request-correlation-id"
  }
}
```

Clients must preserve and expose the stable error code without logging activation codes, credentials, signing keys, or license keys.

## Signed licensing-mode discovery

`GET /api/v1/licensing/products/{product_code}/policy`

This public, rate-limited endpoint does not require a product API credential. It returns the active product's effective licensing mode as an Ed25519-signed payload using the same pre-provisioned signing key trust as installation entitlements:

```json
{
  "payload_json": "{\"version\":1,\"policy_type\":\"licensing_mode\",\"issued_at\":\"...\",\"product_code\":\"MEDIFLOW\",\"licensing_mode\":\"platform_account\",\"licensing_policy_revision\":3,\"licensing_policy_effective_at\":\"...\"}",
  "signature": "base64-ed25519-signature",
  "algorithm": "ed25519",
  "key_id": "signing-key-id"
}
```

Clients must verify the exact `payload_json` bytes against a pre-provisioned trusted key, then validate the product code, policy type, mode, and positive revision before showing activation paths. The endpoint does not establish key trust. Unified policy lookup and code exchange require no product machine token. KernelBridge rechecks current mode and activation-code provenance when issuing/redeeming codes; UI mode selection is not an authorization boundary.

## Direct installation activation

`POST /api/v1/installations/activate`

This endpoint is rate-limited and uses the public activation code, not a product machine token.

Request:

```json
{
  "activation_code": "KBI-...",
  "product_code": "MEDIFLOW",
  "installation_uuid": "7efce9ae-2456-4b9d-9601-882f37ba9401",
  "device_name": "Optional display name"
}
```

The client application licensing package creates or loads a stable installation UUID before the first request. Same-code/same-installation retries recover the same credential; replay by a different installation is rejected. Persist returned state securely before discarding the code.

Success `201` for first activation and `200` for an idempotent retry:

```json
{
  "installation_uuid": "stable-installation-uuid",
  "license_uuid": "public-license-uuid",
  "product_code": "MEDIFLOW",
  "credential_type": "bearer",
  "credential": "installation-uuid.secret",
  "expires_at": null,
  "signed_entitlements": {
    "payload_json": "{\"version\":1}",
    "signature": "base64-ed25519-signature",
    "algorithm": "ed25519",
    "key_id": "signing-key-id"
  },
  "created": true
}
```

The example signed payload above is abbreviated and is not a valid signature vector. Real conformance fixtures must use test keys and verify signatures over the exact UTF-8 bytes of `payload_json`.

Expected stable failure codes include `activation_code_invalid`, `activation_code_expired`, `activation_code_revoked`, `activation_code_used`, `activation_code_installation_mismatch`, `product_mismatch`, `installation_bound`, `installation_inactive`, `seat_limit_reached`, `license_inactive`, and `signing_not_configured`.

## Installation verification and deactivation

`POST /api/v1/installations/verify`

`POST /api/v1/installations/deactivate`

Send the installation credential as a bearer token in the `Authorization` header. Verification returns the current signed entitlement snapshot. Deactivation is idempotent and immediately releases the installation seat. Rejected verification codes distinguish invalid credentials, inactive/revoked/deactivated/expired installations, and inactive licenses.

The signed entitlement snapshot currently carries product code, licensing mode and policy revision, installation/license/subscription public UUIDs, status and expiry, entitlements, entitlement revision, and entitlement digest. Verify the signature over the exact `payload_json` bytes before parsing or trusting any claims. Never treat a client-selected licensing mode as policy.

When `license_status` is `grace`, `grace_expires_at` is the authoritative access cutoff even if `expires_at` (the subscription end) is already in the past. A `grace` snapshot without `grace_expires_at` is invalid for client access. For other usable statuses, `expires_at` bounds access when present. Client offline grace must never extend beyond the applicable signed cutoff.

### Ed25519 public-key trust and rotation

Responses identify their signing key with `key_id`; clients therefore need a trusted public-key map keyed by `key_id`, provisioned in trusted deployment configuration (not copied from the server's private signing key). For KernelBridge-managed signing, run `php artisan product-integration:generate-signing-key` on the KernelBridge server: when a signing key is already configured, the command emits the public-key map for that active key; when none is configured, it emits a new key and clearly marks it inactive until configured. Do not generate a different key just to obtain a public key. Laravel reads the JSON map from `KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS` and expects base64-encoded raw 32-byte Ed25519 public keys; TypeScript accepts either these raw base64 keys or PEM-encoded Ed25519 public keys. Unknown key IDs and malformed keys must fail closed.

For rotation, add the new public key to a client release before KernelBridge starts signing with the new key. Keep the old public key available for the supported package upgrade/rollback window; then retire it only after KernelBridge confirms old snapshots and client versions are no longer in scope. Never silently accept a signature under an unknown key and never download a key over an unauthenticated channel. Public-key distribution is a deployment prerequisite; the signed licensing-policy endpoint is not a key-discovery mechanism.

## Platform-account linking

These are trusted product-backend endpoints. Require the product's API credential with the exact `licensing:account-link` scope. Never expose this credential to an activation UI, desktop bundle, or browser.

- `POST /api/v1/platform/account-links/requests` — link a product-platform `business_uuid` to either a `license_uuid` from the platform's trusted account/license association or an `activation_code` submitted by the authenticated customer and forwarded only by the trusted product-platform backend. A valid request creates an active link immediately; no separate KernelBridge owner approval is required. Never accept a client-supplied `license_uuid` as proof of ownership. The activation code is resolved server-to-server and is not returned or stored by the product platform.
- `GET /api/v1/platform/account-links/{link}` — poll the caller product's link status.
- `POST /api/v1/platform/account-links/{link}/activation-codes` — request a one-time code for an active account link, bound to `installation_uuid` and optional `device_name`.

Code issuance requires an active account link and the account-link API credential with `licensing:account-link` scope, held only by the trusted product-platform backend. Product-platform sign-in alone is not proof of a license: the backend must validate a submitted activation code or resolve the license from its trusted account mapping. Older pending links remain supported and can be completed when the platform repeats the link request. Do not proxy these calls through an unauthenticated route or expose the credential or returned activation code in browser output, logs, analytics, or support diagnostics. Forward a returned code only to the authenticated client application flow that requested activation, and bind the request to that application's persisted installation UUID.

The client application exchanges this short-lived code at `/api/v1/installations/activate`; it does not call the platform account-link endpoints or receive the platform integration credential. KernelBridge records the installation during this exchange, so there is no separate device-registration call. `installation_already_active` means the backend should verify the existing installation rather than issue another activation code. Repeating code issuance may replace an outstanding code for the same link/installation.

Platform-side sequence:

1. Authenticate the customer with the product platform and resolve the trusted customer-account-to-license mapping, or collect a valid KernelBridge activation code.
2. Request an account link using that `license_uuid` or activation code and the stable product-platform `business_uuid`; a valid request links immediately.
3. Request an installation code for the client application's stable installation UUID.
4. Deliver the short-lived code to that authenticated client app and let its client SDK perform code exchange and persist the returned installation credential.

These are adapter operations, not a local licensing-mode selector. KernelBridge's configured mode and account-link state govern whether the calls can succeed.

### Licensing and device-management ownership

KernelBridge owns activation-code issuance/redemption, the license-bound installation record and credential, seat accounting, entitlements, renewal/expiry, and licensing revocation for all products. For platform-account activations, the product platform owns its activated-account workflow and its business/branch/server/app-device inventory; it authorizes the device and brokers activation to KernelBridge. KernelBridge may also display and manage the associated licensing installation in its customer portal, but it does not replace the platform's device records. For direct products and direct installations in Hybrid mode, KernelBridge manages installed-client device information through its customer portal. Renewal and entitlement state remain KernelBridge-controlled in either model.

When both systems represent the same platform-enabled device, correlate them with stable public identifiers and keep domain ownership separate: product business/device metadata belongs to the platform; license, installation credential, seat, entitlement, and expiry belong to KernelBridge.

## Licensing mode and UI

The target client-package experience follows KernelBridge's effective product mode:

- `direct`: enter a KernelBridge installation activation code.
- `platform_account`: use the product's existing sign-in and trusted server-side broker to link a valid license without a separate KernelBridge owner-approval step.
- `hybrid`: show both flows only when KernelBridge configured hybrid mode.

The React package `@kernelbridge/activation-ui-react` provides a KernelBridge-branded activation screen for direct, platform-account, and hybrid modes. The host should supply the effective mode from a verified `LicenseActivationService.getLicensingPolicy()` result and provide secure callbacks for the relevant flows. The UI does not fetch policy itself, authenticate product-platform users, call trusted account-link endpoints, or accept a platform integration credential. Platform-account callbacks must use the authenticated product backend and pass the short-lived code directly into the client SDK without returning it to the UI. A product cannot use the package UI to alter licensing policy or white-label KernelBridge.

## SDK implementation status

The Laravel and TypeScript client packages in this working tree implement installation-code exchange, bearer verification/deactivation, Ed25519 snapshot verification, credential persistence, and legacy-state compatibility. The TypeScript suite covers the client lifecycle and shared signature fixture. Laravel has focused package tests for signature validation, bound credential persistence, unknown keys, and grace handling; full HTTP/controller lifecycle and upgrade-migration coverage remain incomplete. The separate platform adapters implement account-link requests, polling, and code issuance; they do not authenticate product-platform users, resolve account-to-license mapping, or expose application routes.

Real public-key provisioning/rotation in product deployments, broader Laravel integration coverage, clean artifact installation tests, and product staging/rollback validation remain incomplete. See `../docs/SDK_IMPROVEMENT_PLAN.md`; do not infer production readiness from the implementation alone.
