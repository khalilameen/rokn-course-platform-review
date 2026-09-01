# Mobile API compatibility

## Supported surface

- New mobile work targets `/api/v1`.
- `/api` mirrors the same routes for previously released APKs. It is a
  compatibility base, not a second product contract.
- Public responses use the standard `{status, success, data, message}`
  envelope. Clients must ignore additive response fields.
- `POST /api/v1/app/check-version` accepts optional
  `api_contract_version` and `capabilities`, and returns the current/minimum
  contract plus server capabilities. Older clients may omit both.
- `GET /api/v1/product-features` is additive. A client must use its own safe
  default for an unknown or absent capability.

## Change rules

Add fields and endpoints within contract version 1. Do not rename/remove a
field consumed by a released build, reuse an old field with a new meaning, or
make a previously optional request field required. A breaking change needs a
new contract version, a versioned route, and an active minimum-app policy
before the old path is retired.

The route table in `routes/api.php`, request validators, resources, and mobile
API adapters are the source of truth. Historical implementation reports and
sample payloads were removed because they described retired centers, tenant,
phone/password, raw Bunny URL, and test-payment flows.

## Release and database upgrade

- Ship forward-only database migrations. Never edit or delete migration
  history after it has run in any shared environment.
- Deploy code that tolerates an additive column/table before depending on it;
  run migrations once; then restart web, queue, and scheduler processes.
- Rebuild Laravel config, route, and view caches from the same release artifact.
  Do not call `env()` outside `config/*`.
- Mobile upgrades keep the application identifier and signing identity. The
  installer rejects downgrade and signer mismatch so Android replaces the app
  without clearing its secure session or local learning queues.
- Local migrations are copy-before-delete and journaled. A killed process can
  resume them; corrupt legacy bytes remain available rather than being silently
  discarded.

