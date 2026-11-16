# Changelog

## Unreleased

- Add installation-code exchange and installation-bearer verification/deactivation while retaining legacy methods.
- Verify Ed25519 signed-entitlement snapshots using trusted public keys indexed by `key_id`.
- Migrate local state to version 3 and preserve existing encrypted credentials during migration.
- Bound grace-status access by the signed grace expiry rather than the elapsed subscription expiry.
- Add a grace-period regression test and shared Ed25519 fixture verification.

