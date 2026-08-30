# Rokn backend

Rokn is the single-tenant API and administration backend for the Rokn course
application. It runs on Laravel 12 and the PHP 8.4 release line and owns course access, learning
progress, projects, certificates, the paid/reward coin ledger, Kashier payments,
notifications, media operations and the administrator dashboard.

## Requirements

- PHP 8.4.24 or newer within the PHP 8.4 release line, with the extensions listed in `../.github/workflows/backend-ci.yml`
- Composer 2.10.3
- MySQL 8 and Redis for a production-like environment
- Node.js 22.23.2 and npm 10.9.3 for dashboard assets

## Local setup

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

Configure the database, Redis and only the integrations you need in `.env`.
Never commit runtime credentials. Production deployments must use a durable queue
worker and scheduler in addition to the web process.

```bash
php artisan queue:work --tries=3
php artisan schedule:work
```

## Architecture

- `app/Http/Controllers/API` exposes the mobile API.
- `app/Http/Controllers/Admin` and `resources/views/admin` implement the
  administrator dashboard.
- `app/Services` contains business rules and integration boundaries.
- `app/Http/Resources` maps models to stable response contracts.
- `database/migrations` contains the frozen reconciled baseline followed by
  forward-only migrations.

New API responses use this envelope while preserving documented legacy aliases
at compatibility boundaries:

```json
{
  "status": 200,
  "success": true,
  "data": {},
  "message": ""
}
```

Financial mutations must remain transactional and idempotent. Paid and reward
coins are separate provenance buckets; access plans, entitlements, reversals and
AI budgets must not be inferred by the client.

Kashier callbacks and user polling are backed by a scheduled provider
reconciliation. The scheduler runs `payments:reconcile-kashier` in bounded,
locked batches; mismatches and reversals are quarantined in the administrator
review queue and never grant funds from unverified evidence.

Kashier uses two server-only credentials with different purposes. The Payment
API key (`KASHIER_*_API_KEY`) signs checkout and webhook payloads; the Secret
API key (`KASHIER_*_SECRET_KEY`) authenticates order-status and reconciliation
requests to Kashier's API. Neither credential belongs in the mobile app.

## Verification

```bash
composer validate --strict --no-interaction
composer audit --locked --no-interaction
npm audit --audit-level=high
php artisan test
php scripts/verify-migration-upgrade.php
php scripts/verify-dr-runbook.php
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize:clear
```

CI additionally replays the schema on MySQL, checks Redis, compiles routes,
schedules and views, and runs the full test suite on an isolated SQLite database.

## Deployment and recovery

Run `php artisan rokn:preflight` with the real production environment before
promoting a release. Apply only forward migrations and retain the generated
artifact and migration evidence with the release record.

The restore procedure, safety checks and required evidence are documented in
[`docs/DISASTER_RECOVERY_RUNBOOK.md`](docs/DISASTER_RECOVERY_RUNBOOK.md). The
first cutover to the reconciled migration baseline requires a completed restore
drill; the disposable SQLite migration gate is not a substitute for restoring a
real MySQL backup.
