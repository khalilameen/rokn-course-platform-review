# Android staging-release smoke

This suite runs against a signed **staging** APK on an emulator or sacrificial
test device. It is intentionally not a mocked Jest replacement. Keep a single
reusable staging learner and two non-production courses: one enrolled course
with a playable lesson/project, and one paid course whose Kashier test checkout
can be opened and cancelled. The runner completes flows 01-05 and 07 before it
creates a forced-update lease for flow 06. That lease must target the installed
version, expire server-side within 15 minutes, and be released immediately by
the runner's `finally` teardown.

Install Maestro 1.39.0, install Android platform-tools, then provide protected
environment variables. The release workflow downloads that exact GitHub release
and verifies its published SHA-256 before execution. Values are
selectors/assertions, so the secrets never enter the repository:

```powershell
$env:ANDROID_SERIAL = 'emulator-5554'
$env:ROKN_SMOKE_APK = 'C:\secure\Rokn-staging.apk'
$env:ROKN_SMOKE_APK_VERSION_CODE = '23'
$env:ROKN_SMOKE_RUN_ID = 'local-smoke-20260816-01'
$env:ROKN_SMOKE_EMAIL = '<STAGING_SMOKE_EMAIL>'
$env:ROKN_SMOKE_PASSWORD = '<PASSWORD_FROM_SECRET_MANAGER>'
$env:ROKN_SMOKE_COURSE_TITLE = 'كورس الاختبار المسجل'
$env:ROKN_SMOKE_PAID_COURSE_TITLE = 'كورس الدفع التجريبي'
$env:ROKN_SMOKE_CHECKOUT_READY_TEXT = 'Kashier'
$env:ROKN_SMOKE_OFFLINE_NOTICE_TEXT = 'أنت غير متصل بالإنترنت'
$env:ROKN_SMOKE_QUEUED_PROJECT_TEXT = 'سنرسل مشروعك تلقائياً'
$env:ROKN_SMOKE_FORCED_UPDATE_TEXT = 'تحديث مطلوب'
$env:ROKN_SMOKE_DELETE_ACCOUNT_TEXT = 'تأكيد حذف الحساب'
$env:ROKN_SMOKE_API_BASE = 'https://staging.rokn.app/api/'
$env:ROKN_SMOKE_COURSE_ID = '64'
$env:ROKN_SMOKE_LESSON_ID = '641'
$env:ROKN_SMOKE_PROJECT_ID = '684'
npm run e2e:android:staging
```

Before the APK is installed, the protected CI job runs
`verify-staging-api-contract.js`. The staging fixtures above must identify a
published three-plan course, a playable lesson, and a project. The gate requires
`health/launch-ready`, enabled checkout/playback/project-upload flags, the exact
basic/guided/mentor plan contract, and an authentication boundary (HTTP 401,
not 404) on the new wallet, learning, upgrade, reward, playback, and project
routes. This prevents an old backend deployment from approving a new client.

`run-android-staging-smoke.js` restores Wi-Fi and mobile data in `finally`.
Run it only on an emulator or a dedicated test device. The command exits `2`
when a secret/fixture is absent; such a run is explicitly **not** smoke-tested.
The CI workflow accepts the same values only through an environment protected by
GitHub environment reviewers. Archive the Maestro output beside the signed APK,
its `.json` provenance sidecar, source maps, and R8 mapping.

The login screen uses social OAuth rather than an in-app password form. Create
a staging-only OAuth test account and configure these additional selector
variables in the protected environment: `ROKN_SMOKE_AUTH_PROVIDER_LABEL`,
`ROKN_SMOKE_OAUTH_EMAIL_FIELD`, `ROKN_SMOKE_OAUTH_PASSWORD_FIELD`,
`ROKN_SMOKE_LEARN_CTA`, `ROKN_SMOKE_CHECKOUT_CTA`,
`ROKN_SMOKE_PROJECT_SUBMIT_CTA`, `ROKN_SMOKE_PROFILE_TAB`,
`ROKN_SMOKE_EDIT_ACCOUNT_CTA`, and `ROKN_SMOKE_DELETE_ACCOUNT_CTA`. The OAuth
field labels intentionally remain configuration because the provider may
localize them. A provider that cannot complete a deterministic staging login
cannot be used as evidence for the authentication release gate.

## Immutable candidate gate

The protected CI job treats the APK URL as transport only. A dispatch must also
provide the exact lowercase SHA-256 and `versionCode`; the protected environment
supplies the adjacent provenance-sidecar URL and the approved release signer
SHA-256. Before installation, `verify-artifact-provenance.js` verifies the APK
bytes, manifest application id/versionCode, signer certificate, production
profile/channel/API base, and the full Git commit selected by the workflow.
Missing pins or Android inspection tools fail the job closed.

The protected `mobile-staging-smoke` environment needs these candidate values:

- Secrets: `ROKN_SMOKE_APK_URL`, `ROKN_SMOKE_APK_PROVENANCE_URL`, and
  `ROKN_SMOKE_APK_SIGNER_SHA256`.
- Dispatch inputs: `staging_apk_sha256` and `staging_apk_version_code`.

The downloaded filename remains `Rokn-direct.apk`, matching the production
sidecar. Never update a candidate URL without dispatching its new immutable
pins; a changed byte, signer, profile, API base, version, or commit is rejected.

## Forced-update fixture lease

The protected environment also supplies
`ROKN_SMOKE_FORCED_UPDATE_FIXTURE_URL` and `ROKN_SMOKE_FIXTURE_TOKEN`. The URL
must be credential-free HTTPS and accept authenticated JSON `POST` requests.
Activation receives `action=activate`, the application id, run id, installed
`versionCode`, and `ttlSeconds=900`. It must return JSON containing:

```json
{
  "active": true,
  "applicationId": "com.rokn",
  "versionCode": 23,
  "runId": "<the supplied run id>",
  "leaseId": "<opaque lease id>",
  "expiresAt": "<ISO-8601 time no more than 15 minutes ahead>"
}
```

Teardown is a second authenticated `POST` with `action=deactivate` and that
lease id. It must return `{"released":true,"leaseId":"<same id>"}`. Both calls
use run-scoped idempotency keys, reject redirects, and time out after 15 seconds.
The server-side TTL is mandatory: it is the final cleanup boundary if a hosted
runner is terminated before JavaScript `finally` can execute. Missing fixture
configuration fails only after core flows 01-05 and 07 have run, so the forced
policy can neither pre-empt those flows nor remain enabled without a bounded
lease.
