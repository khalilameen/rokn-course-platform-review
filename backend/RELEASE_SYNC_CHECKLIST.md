# Rokn release synchronization checklist

The mobile app, API and dashboard share one production contract. A mobile APK
must not be promoted until the matching backend revision and migrations are
deployed.

## Source of truth

| Capability | Dashboard owner | API contract | Mobile consumer |
| --- | --- | --- | --- |
| Courses, modules, short lessons, previews and projects | Courses | `/courses`, `/courses/{id}`, progress/project endpoints | Home, course details, course player |
| Course price and unlock | Courses / Packages | wallet snapshot and `/courses/authorize` | Course purchase sheet |
| Paid versus reward coins | Packages / Settings | wallet ledger | Wallet details |
| Reward limits | Settings → wallet/support | `/economy-config`, `/rewards/daily`, watch history and completion events | Home and course player |
| College grants and promo access | Course codes | `/course-codes/redeem` | Course details |
| Saved folders | Learner data | `/saved-folders` | Player and saved content |
| Watch history and resume | Learner data | `/user/watch-history` | Player and My Corner |
| Portfolio and certificates | Learner data / Courses | portfolio and certificate endpoints | Profile; certificate QR opens public portfolio |
| Promotions | Notifications | student notifications | Home campaign and notifications |
| Support and coin rules | Settings | `/settings` | Settings and Wallet |

## Required deployment order

1. Back up the production database.
2. Deploy the backend revision.
3. Run `php artisan migrate --force`.
4. Run `php artisan optimize:clear && php artisan optimize`.
5. Restart queue workers with `php artisan queue:restart`.
6. Keep the scheduler running every minute; it finalizes delayed project reviews.
7. Verify Redis is used for cache and queues in production.
8. Confirm `/api/v1/economy-config` returns the values shown in dashboard settings.
9. Confirm a real social login receives the configured first-registration bonus once.
10. Confirm a Kashier package order appears as EGP revenue, while a course unlock appears only in coin-consumption reporting.
11. Promote the matching APK only after the checks above.

## Accounting invariants

- Kashier package orders are cash revenue in EGP.
- Course wallet orders are virtual coin consumption, never EGP revenue.
- Every course order stores immutable `total_coins`, `paid_coins` and `reward_coins`.
- Reward credit events have idempotency keys and bounded daily/rolling limits.
- A course can consume no more reward coins than the dashboard-configured course cap.

## Android release channels

- Stakeholder/phone test: `npm run apk` writes `app/artifacts/Rokn-test.apk`.
  This build may include the deterministic local demo and is debug-signed. It
  must never be uploaded to a store or distributed as a production release.
- Production direct distribution: `npm run apk:direct` writes
  `app/artifacts/Rokn-direct.apk`. It requires an explicit production
  `EXPO_PUBLIC_API_URL`, release keystore and matching backend deployment; the
  local demo is disabled.
- Google Play: `npm run aab:play` produces the Play App Bundle. It requires the
  Play billing/review configuration appropriate to the chosen commercial model.

Do not publish any build that points to a backend revision older than this
checklist. Archive the artifact metadata and SHA-256 digest with every release.
