# Rokn production runtime

This backend is prepared for the first production load, but capacity is an operational property: do not estimate a 1.6M-person spike from download count alone. Load-test the home, course-details, progress, wallet and sign-in routes against a staging copy before a large campaign.

## Required production topology

- PHP 8.4.24 or newer within the PHP 8.4 release line, with OPcache; managed MySQL 8.0.17 or newer (8.0.43 is the
  release-tested target), and Redis shared by every app instance. The plan
  snapshot integrity constraints use enforced JSON schema checks unavailable
  on older MySQL releases.
- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
- `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- Start from `.env.production.example`; it contains variable names only and no
  usable credentials. Set `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`,
  `REDIS_DB`, and `REDIS_CACHE_DB`, and point every web/worker node at the same
  Redis service.
- Run one scheduler process (`php artisan schedule:work`) and isolated workers
  for `default`, `notifications`, `ai-feedback`, and `webhooks`. Never let slow provider
  calls occupy the default queue. A baseline AI worker command is
  `php artisan queue:work redis --queue=ai-feedback --sleep=1 --tries=3 --timeout=60 --backoff=20`;
  run webhook deliveries separately with
  `php artisan queue:work redis --queue=webhooks --sleep=1 --tries=5 --timeout=90 --backoff=10`;
  the Redis `retry_after` must remain greater than the worker timeout (currently
  120 > 90). Start with at least two workers for `default` and `notifications`;
  scale `ai-feedback` and `webhooks` separately within their provider budgets.
  Keep `QUEUE_HEARTBEAT_REQUIRED_QUEUES=default,notifications,ai-feedback,webhooks`
  aligned with this topology; `/api/health/launch-ready` requires a recent
  heartbeat executed by a worker on every listed queue.
- Serve every reel and thumbnail from Bunny CDN. Never proxy video bytes through Laravel.
- Put only the actual reverse-proxy IPs or narrow CIDRs in `TRUSTED_PROXIES`,
  and allow only those sources to reach the origin at the firewall. Never use
  `*`, `0.0.0.0/0` or `::/0`; forwarded client IP/proto affects throttling,
  secure URLs and audit evidence.
- Set `PROJECT_SUBMISSION_DISK` and `CERTIFICATE_DISK` to a private shared
  filesystem disk before running more than one app node (for example `s3`,
  configured by `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
  `AWS_DEFAULT_REGION`, and `AWS_BUCKET`). Each submission records the disk
  used at upload time, so later changes do not orphan existing files. Legacy
  `local`/`public` consumers read `SHARED_STORAGE_PATH` and
  `SHARED_PUBLIC_STORAGE_PATH`; mount those durable paths identically on every
  node.
- Keep `ROKN_SEED_DEMO=false` and
  `ROKN_ALLOW_PRODUCTION_DEMO_SEED=false`. Production demo seeding requires an
  operator to deliberately enable both flags.

## Deploy order

1. Take a database snapshot and enable a short write-maintenance window for the first wallet/index migration.
2. Install production dependencies with an optimized autoloader.
3. Run `php artisan rokn:preflight --configuration-only --connectivity`. Do not continue while it reports a missing, placeholder, local-only, or unreachable dependency.
4. Run `php artisan migrate --force` once.
5. Privatize legacy learner assets before serving traffic:
   - Run `php artisan attachments:privatize` to audit module attachments.
   - Run `php artisan attachments:privatize --execute --delete-public`. The command copies each file, verifies the private copy exists with the same byte size, updates the database, and only then removes its public source.
   - Run `php artisan attachments:privatize` again; it must report no legacy public module attachments.
   - Run `php artisan security:quarantine-profile-svg`, then `php artisan security:quarantine-profile-svg --execute`, then the audit command again; it must report zero local SVG profile images.
   - Resolve every duplicate Bunny object key reported for portfolio images or lesson thumbnails by re-uploading each affected record. A shared key means deleting one record could delete another record's media.
   - Run `php artisan rokn:preflight --connectivity` again. This final gate fails while either legacy exposure remains; never bypass it during a release.
6. Do not run `db:seed` on production. A disposable showcase environment may
   enable both demo flags for a one-time seed, then must disable them before it
   serves traffic.
7. Run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`.
8. Restart PHP and queue workers (`php artisan queue:restart`).
9. Keep the scheduler active on exactly one node. Distributed scheduler locks require the shared Redis cache.
   The scheduler releases abandoned AI reservations every minute; disabling it
   can leave learner allowances reserved after a killed worker.

The August 5 migrations add the hot-path indexes, collapse duplicate section progress before enforcing uniqueness, classify unknown legacy wallet balances as reward coins, and add immutable paid/reward attribution to course orders. Do not manually classify legacy coins as purchased revenue.

## Minimum monitoring and scaling signals

- Alert on API p95 latency, 5xx rate, MySQL connections/slow queries, Redis
  errors, failed jobs, and disk/object-storage errors. Track oldest-job age per
  queue, not as one aggregate: page immediately when `default` or
  `notifications` age exceeds 60 seconds, when `webhooks` exceeds two minutes,
  or when `ai-feedback` exceeds five minutes. Alert if any `ai_usage_events` reservation remains reserved past
  `reservation_expires_at` for more than two scheduler cycles.
- Alert on Kashier callback failures separately; captured payments and coin credits are idempotent and must be replayed rather than edited in SQL.
- Alert on OpenRouter 402/429/5xx and Bunny signing/CDN failures. AI failure must not interrupt reel playback or project progression.
- Scale stateless HTTP nodes behind a load balancer only after sessions/cache and learner files are shared. Add read replicas only after measuring; wallet, progress, purchases and project transitions must always use the primary database.
- Cache home rails briefly and purge/update caches after dashboard changes. Keep CDN cache hit ratio high during campaigns.

## Smoke checks after deploy

Verify social sign-in, the two free preview reels, reward-first course purchase, purchased-coin package callback, wallet breakdown/history, project pending-to-pass transition, course completion, certificate/QR portfolio link, support WhatsApp link, notification inbox and push queue. Check one duplicate payment callback and one repeated purchase request to confirm idempotency.

## Backup and restore evidence

- Database and object-storage backups are infrastructure operations; the Rokn
  dashboard reports evidence but never starts a restore.
- Enable automated encrypted snapshots at the database/storage provider, with
  retention appropriate to the business and a copy outside the primary failure
  domain.
- After verifying the newest snapshot, set `BACKUP_PROVIDER` and
  `BACKUP_LAST_VERIFIED_AT` to its provider name and ISO-8601 verification time.
- At least every 90 days, restore into an isolated environment, run migrations,
  open a sample course, issue a signed video manifest, inspect a private
  attachment, and reconcile wallet/order totals. Never restore over production
  as a test.
- Only after that isolated drill succeeds set `RESTORE_DRILL_VERIFIED_AT` to its
  ISO-8601 completion time. The Product Operations screen turns the readiness
  indicator green only while both pieces of evidence remain current.

## Media integrity routine

- `php artisan media:reconcile --dry-run` inspects published courses without
  changing media-state rows.
- The scheduler runs `media:reconcile` once daily with distributed locks. It
  checks Bunny metadata, signed HLS readiness, duration, quality ladder,
  thumbnails and stored attachments in bounded batches.
- Findings set operational attention/quarantine metadata only. The command never
  deletes, replaces, unpublishes or exposes a signed playback URL.
