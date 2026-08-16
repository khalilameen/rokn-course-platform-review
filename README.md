# Rokn Course Platform — Review Snapshot

This public repository is a clean review snapshot of the Rokn learning platform. It contains the current mobile application and the Laravel backend/admin dashboard without the historical Git repositories, production credentials, runtime data, dependency folders, or build outputs.

## Structure

- `mobile/` — React Native / Expo application for Android and iOS.
- `backend/` — Laravel API, commerce and learning domains, operations, and the admin dashboard.

## Review status

The snapshot is intended for engineering review, not direct production deployment.

- Mobile: TypeScript, ESLint, accessibility audit, 42 Jest suites / 230 tests, and 46 release-script tests passed before publication.
- Backend: the last full PHPUnit run passed 318 tests / 1,809 assertions; the later admin/public-asset verification passed 128 tests / 2,016 assertions.
- Current files pass the repository secret scanners. The old source repositories are deliberately not included because their historical commits contained credentials that must be rotated independently.
- iOS remains release-blocked until `Podfile.lock` is regenerated on macOS with the pinned modern Ruby/Bundler/CocoaPods toolchain.
- Production promotion still requires staging API parity, payment reconciliation evidence, a database restore drill, and operator approval.

## Local setup

### Mobile

```bash
cd mobile
npm ci
npm run verify:config
npm run typecheck
npm run lint:release
npm run test:release
```

### Backend

```bash
cd backend
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan test
```

Use only development or staging credentials. Never copy production secrets into this repository.

## Rights

Public visibility is provided for source review. No additional license or permission is granted beyond licenses explicitly included with third-party components.
