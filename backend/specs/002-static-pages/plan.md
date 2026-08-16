# Implementation Plan: Bilingual Static Pages

**Branch**: `002-static-pages` | **Date**: 2026-04-05 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/002-static-pages/spec.md`

## Summary

Add four bilingual static pages (About Us, Contact Us, Privacy Policy, Terms of Use) sharing the same visual theming, navbar, footer, and language switching as the existing landing page. Pages use a shared Blade layout extracted from the landing page. Content comes from translation files (About Us, Terms), database settings (Contact Us), and design settings (Privacy Policy).

## Technical Context

**Language/Version**: PHP 8.x (Laravel 9)
**Primary Dependencies**: Plain HTML/CSS (landing.css), Blade templating
**Storage**: MySQL (existing `settings` and `design_settings` tables — read only)
**Testing**: PHPUnit (`php artisan test`, SQLite in-memory)
**Target Platform**: Web (server-rendered Blade views)
**Project Type**: Web application (Laravel monolith)
**Performance Goals**: Page load under 2 seconds
**Constraints**: 320px+ responsive, no JavaScript required for core content, vanilla HTML/CSS only
**Scale/Scope**: 4 static pages, 1 shared layout, 1 controller, translation files

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|-----------|--------|-------|
| I. Single-Tenant Architecture | PASS | No tenant_id, no tenant middleware. |
| II. Thin Controllers, Fat Services | PASS | Controller only fetches settings and returns views. No business logic. |
| III. Consistent API Contract | N/A | Web views, not API endpoints. |
| IV. Code Quality Standards | PASS | New PHP files use `declare(strict_types=1)`, PSR-12, type hints. |
| V. Simplicity & Incremental Cleanup | PASS | Reuses existing models, existing CSS, existing locale logic. Extracts shared layout from landing page. No new dependencies. |

## Project Structure

### Documentation (this feature)

```text
specs/002-static-pages/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit.tasks command)
```

### Source Code (repository root)

```text
app/
└── Http/
    └── Controllers/
        └── StaticPageController.php           # New controller (4 actions)
resources/
├── lang/
│   ├── ar/
│   │   ├── landing.php                        # Modified: add nav link labels
│   │   ├── about.php                          # New: About Us content
│   │   ├── contact.php                        # New: Contact Us labels
│   │   ├── privacy.php                        # New: Privacy Policy fallback
│   │   └── terms.php                          # New: Terms of Use content
│   └── en/
│       ├── landing.php                        # Modified: add nav link labels
│       ├── about.php                          # New: About Us content
│       ├── contact.php                        # New: Contact Us labels
│       ├── privacy.php                        # New: Privacy Policy fallback
│       └── terms.php                          # New: Terms of Use content
├── views/
│   ├── layouts/
│   │   └── landing.blade.php                  # New: shared layout extracted from landing page
│   ├── landing/
│   │   └── index.blade.php                    # Modified: use shared layout
│   └── static/
│       ├── about.blade.php                    # New
│       ├── contact.blade.php                  # New
│       ├── privacy.blade.php                  # New
│       └── terms.blade.php                    # New
routes/
└── web.php                                    # Modified: add 4 new routes
public/
└── css/
    └── landing.css                            # Modified: add static page content styles
```

**Structure Decision**: Extend the existing landing page architecture. Extract the shared navbar/footer into a Blade layout (`layouts/landing.blade.php`) that all 5 pages (landing + 4 static) inherit. One controller with 4 methods. Translation files for static content.

## Complexity Tracking

> No violations found. All gates pass.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |
