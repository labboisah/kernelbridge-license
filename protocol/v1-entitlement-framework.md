# Entitlement Framework v1

This contract is additive. It does not replace the existing installation `entitlements` array or change activation, license lifecycle, renewal, expiry, or revocation behavior. The optional `entitlement_framework` extension in a signed installation claim uses the schema in [`schemas/entitlement-framework-v1.schema.json`](./schemas/entitlement-framework-v1.schema.json).

## Authority and binding

- `source` is `direct` or `product_platform`. Hybrid installations bind to one of these sources through the authorized activation/link path; a payload cannot change that binding.
- `product_code` and `license_uuid` remain in the signed outer installation claims. Validators receive those values from the verified claims and compare them with the configured product and installation/license association.
- Product servers publish catalogs to `POST /api/v1/platform/entitlement-catalogs` with the exact `licensing:entitlement-catalog:write` scope, then submit grants to `POST /api/v1/platform/entitlement-grants` with the exact `licensing:entitlement-grants:write` scope.
- Platform update requests use [`schemas/entitlement-update-v1.schema.json`](./schemas/entitlement-update-v1.schema.json). The authenticated server credential determines the product and operation scope; request identifiers are checked against that identity and KernelBridge's license/account-link association.
- Catalog definitions use [`schemas/entitlement-catalog-v1.schema.json`](./schemas/entitlement-catalog-v1.schema.json). Product-specific plan names and package/module labels are metadata; grant keys are the authorization contract.

## Grants

Capability grants have exactly `type: "capability"` and a boolean `enabled`. Limit grants have `type: "limit"`, `state: "finite"` with a non-negative numeric `value`, or `state: "unlimited"` with no `value`. Every limit carries catalog-matching `unit` and `scope`. A missing grant is not a grant.

A non-periodic limit omits `period`. A time-windowed limit uses a calendar `period` with a unit of `day`, `week`, `month`, or `year`, `reset: "calendar"`, and an explicit IANA `time_zone`. Runtime validators reject time zones that are not recognized by the runtime. Products own usage counts and must atomically enforce them; the grant itself does not contain usage.

## Validation and revision rules

The Laravel and TypeScript validators consume the same raw JSON fixtures and receive trusted context from their caller: expected and claimed product/license identities, the activation-bound source, the current catalog revision, a minimum acceptable source revision, and the registered grant definitions. They reject duplicate JSON object keys before decoding, as ordinary JSON decoders otherwise silently discard duplicates.

Validation is fail-closed and does not confer authority, verify signatures, authenticate platform credentials, or persist revisions. Those remain responsibilities of the relevant KernelBridge/product server boundary. In particular, platform update authorization must also validate the credential scope and server-side license/account-link association. A source revision lower than the caller's minimum is rejected; idempotent retry and same-revision conflict handling are performed by the update service and are not inferred by this isolated payload validator.

Stable error codes shared by both validators are `malformed_json`, `duplicate_key`, `unsupported_schema`, `product_mismatch`, `license_mismatch`, `account_link_mismatch`, `invalid_source`, `catalog_revision_mismatch`, `stale_source_revision`, `unknown_grant`, `unknown_grant_type`, `malformed_grant`, `invalid_unit`, `invalid_scope`, and `invalid_period`.

The schema and fixtures are conformance inputs only. They are intentionally not wired into the existing activation or entitlement resolvers in this phase.

## Signed installation snapshot compatibility

The framework extension is optional inside the existing version-1 signed installation claims. KernelBridge emits it only when `ENTITLEMENT_FRAMEWORK_SNAPSHOT_ENABLED=true` and a valid, current source grant resolves for an active license; the existing flat `entitlements` remain present and authoritative for old clients. The extension carries only `schema_version`, `source`, `catalog_revision`, `source_revision`, and typed `grants`. It is covered by the same Ed25519 signature as the exact `payload_json` bytes.

Product SDKs must verify the configured trusted signing key and exact payload bytes before parsing the extension. When the extension is present, validate its schema and ensure its source matches the outer licensing mode and platform-link binding; reject malformed, unsupported, or mismatched extensions. Absence remains valid for legacy snapshots. Never accept a public key supplied by the snapshot itself.

### SDK cache and decision behavior

SDKs cache the verified signed payload and answer product feature/limit queries locally; they must not make a KernelBridge request for each product request. When `entitlement_framework` is present, its grants are authoritative: a missing key is absent/denied and must not fall back to a matching flat entitlement. When the extension is absent, existing flat-entitlement APIs retain their prior behavior. Expose explicit decision outcomes for granted, disabled, absent, license-unavailable, malformed, and unsupported states. Typed limit results preserve finite/unlimited state and unit/scope/period metadata; legacy scalar limit helpers remain compatible.

Offline access is bounded by the earlier of the signed license/grace expiry and signed `issued_at` plus the configured offline-grace duration. Re-verification with an older snapshot must not replace a newer cached snapshot or restart that duration. Snapshots issued more than five minutes in the future or older than the freshness window fail closed; an outage may continue using a still-fresh verified cache only within the existing offline policy.
