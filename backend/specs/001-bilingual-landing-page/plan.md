# Implementation Plan: Bilingual Landing Page

**Branch**: `001-bilingual-landing-page` | **Date**: 2026-04-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/001-bilingual-landing-page/spec.md`

## Summary

Create a bilingual (Arabic/English) landing page for the Rokn e-learning app that replaces the current placeholder at `/`. The page presents Rokn's value proposition (short video courses with practical tasks and certifications for career-ready skills), displays app store download links from the `settings` table, and supports RTL/LTR switching via a language toggle. Dynamic content (slogans, social media, branding) is pulled from the existing `DesignSetting` model.

## Technical Context

**Language/Version**: PHP 8.x (Laravel 9)
**Primary Dependencies**: Bootstrap 4, Laravel Mix (Webpack), jQuery 3.2, Blade templating
**Storage**: MySQL (existing `settings` and `design_settings` tables)
**Testing**: PHPUnit (`php artisan test`, SQLite in-memory)
**Target Platform**: Web (server-rendered Blade views)
**Project Type**: Web application (Laravel monolith)
**Performance Goals**: Page load under 3 seconds, language switch under 1 second
**Constraints**: Must work on 320px+ screens, no JavaScript required for core content
**Scale/Scope**: Single page with 2 language variants, no new database tables

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| I. Single-Tenant Architecture | PASS | No tenant_id in new code. Landing page controller does not use tenant middleware. |
| II. Thin Controllers, Fat Services | PASS | Controller only fetches settings and returns view. No business logic in controller. |
| III. Consistent API Contract | N/A | This is a web view, not an API endpoint. No JSON envelope needed. |
| IV. Code Quality Standards | PASS | New PHP files will use `declare(strict_types=1)`, PSR-12, type hints. |
| V. Simplicity & Incremental Cleanup | PASS | Reuses existing models (Setting, DesignSetting), existing locale middleware, existing Bootstrap 4. No new dependencies. No speculative abstractions. |

## Project Structure

### Documentation (this feature)

```text
specs/001-bilingual-landing-page/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit.tasks command)
```

### Source Code (repository root)

```text
app/
├── Http/
│   └── Controllers/
│       └── LandingPageController.php      # New controller
resources/
├── lang/
│   ├── ar/
│   │   └── landing.php                    # New Arabic translations
│   └── en/
│       └── landing.php                    # New English translations
├── views/
│   └── landing/
│       └── index.blade.php                # New landing page view
├── sass/
│   └── _landing.scss                      # New landing page styles
routes/
└── web.php                                # Modified: replace `/` route
public/
└── images/
    └── logo.png                           # Existing logo
```

**Structure Decision**: Standard Laravel MVC — a single controller, one Blade view with inline language switching, translation files for bilingual content, and a SCSS partial compiled via Mix. No new models or migrations required.

## Complexity Tracking

> No violations found. All gates pass.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |
