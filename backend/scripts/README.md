# Backend scripts

## Public guest load journey

`load-public-journey.mjs` models the read-only journey used by a guest in the
mobile app: settings, sign-in methods, classifications, paths, catalogue,
dynamic course details, and coin packages. It uses Node's built-in `fetch` and
adds no package dependency.

The command is a dry-run unless `--execute` is present. A dry-run prints the
complete profile without making a network request:

```bash
node scripts/load-public-journey.mjs
```

For a one-user local smoke journey:

```bash
node scripts/load-public-journey.mjs --execute --profile=smoke \
  --base-url=http://127.0.0.1:8000/api/v1
```

The `target-1000` profile ramps through 100, 300, 600, and 1,000 concurrent
guest journeys, holds 1,000 users for five minutes, then cools down. Each user
waits a random 3–8 seconds between requests. The default gates are overall and
per-endpoint p95 <= 800 ms, p99 <= 1,800 ms, and error rate <= 1%. A failed gate
exits with code 2. The JSON report defaults to
`storage/app/load-tests/public-journey.json`; change it with `--report=...`.

Remote targets are denied by default. An approved staging run requires an exact
origin allow-list and deliberate acknowledgement:

```bash
$env:LOAD_TEST_ALLOW_REMOTE = 'I_UNDERSTAND'
$env:LOAD_TEST_ALLOWED_ORIGIN = 'https://staging.example.com'
node scripts/load-public-journey.mjs --execute --profile=target-1000 `
  --base-url=https://staging.example.com/api/v1
```

The path may include `/api/v1`, but `LOAD_TEST_ALLOWED_ORIGIN` is only the
scheme and host. `rokn.app` and its subdomains have a second hard guard and also
require `LOAD_TEST_ALLOW_PRODUCTION=ROKN_PRODUCTION_LOAD_APPROVED`. Set it only
inside an approved load window after confirming provider, CDN, database, cache,
worker, and alerting capacity. Never use this public-read profile as proof that
payments, AI, uploads, or authenticated learning writes can sustain the same
load; those need isolated staging fixtures and their own provider-safe tests.

Thresholds can be adjusted for an explicitly agreed environment with
`LOAD_TEST_P95_MS`, `LOAD_TEST_P99_MS`, and
`LOAD_TEST_MAX_ERROR_RATE` (a fraction such as `0.01`). Request timeout defaults
to eight seconds and can be changed with `--request-timeout-ms=...`.
