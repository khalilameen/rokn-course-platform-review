# Mobile sign-in contract

Rokn does not offer student phone/password registration or OTP login. The
current app creates or restores a student account through Google, TikTok, or
Facebook OAuth with PKCE.

Canonical API base: `/api/v1`. The matching `/api` routes exist only for APKs
already released against the historical base path.

## Bootstrap

`GET /api/v1/auth-methods` returns the providers that are ready in the current
environment. A client must render only providers whose `available` value is
true; provider credentials never belong in the app bundle.

## Browser hand-off

1. Open `GET /api/v1/social-auth/{provider}/start` with the app callback and
   PKCE challenge.
2. Let the system browser complete the provider flow.
3. Send the returned one-time hand-off to
   `POST /api/v1/social-auth/complete` with the original verifier.
4. Persist the returned API token in platform secure storage and the sanitized
   profile separately.

The exact request validation and response envelope live in
`SocialOAuthController`, `SocialOAuthAttemptService`, and
`mobile/src/services/socialAuth.ts`. Those files are the source of truth.

## Retired compatibility routes

`POST /login`, `/register`, `/send-verification`, `/verify-phone`,
`/forgot-password`, and `/reset-password` intentionally return the stable
`otpDisabled` response. They exist so old APKs fail clearly rather than seeing
404 or accidentally reviving password/OTP authentication.

There is no centers, groups, governorate, tenant-header, parent-phone, or
course-code-as-password registration contract.

