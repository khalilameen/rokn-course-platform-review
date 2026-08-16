# Research: Bilingual Static Pages

**Date**: 2026-04-05
**Branch**: `002-static-pages`

## Research Tasks & Findings

### 1. Shared Layout Extraction

**Decision**: Extract the navbar and footer from `landing/index.blade.php` into a shared Blade layout at `layouts/landing.blade.php`. All pages (landing + 4 static) extend this layout.

**Rationale**: The spec requires all pages share the same theming. Duplicating the navbar/footer in each view violates DRY. Blade's `@extends` / `@yield` pattern is the standard Laravel approach.

**Alternatives considered**:
- Blade `@include` partials for navbar/footer: Works but layout approach is cleaner with `@extends` + `@section`.
- Duplicate HTML in each file: Unmaintainable — 5 copies of navbar/footer to keep in sync.

### 2. Controller Design

**Decision**: Single `StaticPageController` with 4 methods (`about`, `contact`, `privacy`, `terms`). Each method fetches settings + design settings and returns the appropriate view.

**Rationale**: All 4 pages need the same data (settings, design settings, locale). A single controller keeps related pages together. The `LandingPageController` stays separate since the landing page has unique hero/features sections.

**Alternatives considered**:
- One controller per page: Overkill for simple read-only pages.
- Merge into `LandingPageController`: Makes that controller responsible for too many views.

### 3. Privacy Policy HTML Rendering

**Decision**: Use `{!! !!}` Blade syntax for rendering privacy policy HTML from `policy_content_ar`/`policy_content_en`. The content is admin-entered via the dashboard (trusted source). Add a `.policy-content` CSS wrapper to scope the rendered HTML styles.

**Rationale**: The privacy policy content is entered by administrators through the existing dashboard. It's stored as HTML in the database. Using `{!! !!}` renders it as-is. Since it's from a trusted admin source (not user input), the XSS risk is minimal — but the content is already entered through a validated admin form.

**Alternatives considered**:
- `strip_tags()`: Would remove all formatting, defeating the purpose of rich text.
- HTML Purifier library: Adds a dependency. Overkill since content is admin-managed, not user-submitted.
- Markdown: Would require converting existing HTML content.

### 4. Navigation Links

**Decision**: Add nav links to the navbar (About, Contact, Privacy, Terms) as simple `<a>` tags. On mobile, show them in a compact list or keep them in the footer only.

**Rationale**: FR-013 requires navbar links to these pages. Keep desktop navbar with all links; on mobile, the footer already has these links so the navbar can stay minimal (logo + lang toggle only).

### 5. SEO Meta Tags

**Decision**: Each page defines its own `<title>` and `<meta description>` via Blade `@section` blocks in the shared layout. Translation files provide page-specific titles and descriptions.

**Rationale**: FR-012 requires per-page SEO meta tags. The shared layout defines default meta with `@yield('title')` and `@yield('meta_description')` that each page overrides.

## Summary

No NEEDS CLARIFICATION items. All decisions reuse existing infrastructure:
- Shared Blade layout extracted from landing page
- Same `landing.css` with additional content styles
- Same session-based locale switching
- Same Setting + DesignSetting models
- Translation files for static content
