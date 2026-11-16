# Shared Licensing Protocol

KernelBridge is the protocol authority. This directory publishes the consumer-facing contract and shared fixtures used by each runtime package. Do not define divergent request or entitlement schemas in individual SDKs.

## Documents

- `v1-installation-activation.md` — current `/api/v1` unified installation activation and platform-account link contract.
- `v1-entitlement-framework.md` — approved additive entitlement framework contract for Phase 2 conformance.
- `schemas/` — versioned catalog, signed framework extension, and platform update JSON Schemas.
- `fixtures/` — cross-runtime request/response, signature, and entitlement validation test vectors. The Ed25519 entitlement fixture uses a test-only key; no private key is stored in this repository.

## Change process

1. Change and test the KernelBridge server behavior.
2. Update this contract and fixtures in the same change or coordinated change.
3. Run all supported SDK package tests against the fixtures.
4. Document any capability requirement or backwards-compatibility constraint before releasing clients.

The previous reusable-key APIs remain a compatibility contract during migration; they are not the preferred contract for new clients.
