# Research: Bilingual Landing Page

**Date**: 2026-04-05
**Branch**: `001-bilingual-landing-page`

## Research Tasks & Findings

### 1. Language Switching Approach

**Decision**: Session/cookie-based locale with query parameter toggle (`?lang=en` / `?lang=ar`).

**Rationale**: The existing `ApplyLocale` middleware reads locale from URL segments, but the root route `/` has no segment. Using a query parameter or session-based approach is simpler — no URL prefix needed for a single-page landing. The controller sets `app()->setLocale()` based on a session value, and the language toggle links pass `?lang=en` or `?lang=ar`.

**Alternatives considered**:
- URL prefix (`/ar/`, `/en/`): Requires route changes and impacts SEO. Overkill for a single page.
- JavaScript-only switching: Violates the "core content without JS" requirement.
- Separate Blade views per language: Duplicates the template, increasing maintenance.

### 2. Dynamic Content Sources

**Decision**: Use both `Setting` and `DesignSetting` models to populate the landing page.

**Rationale**:
- `Setting`: Provides `android_app_url`, `ios_app_url`, `site_name_ar`, `site_name_en`, `seo_meta_title_*`, `seo_meta_description_*`.
- `DesignSetting`: Provides slogans (3 per language), brand colors, social media URLs, logo, backgrounds, "how platform works" section.
- Translation files (`resources/lang/{locale}/landing.php`): Provide static marketing copy (hero text, feature descriptions, skill categories) that doesn't change per deployment.

**Alternatives considered**:
- All content in translation files only: Loses the admin-configurable slogans and social media from DesignSetting.
- All content in database only: Makes simple text changes require admin dashboard access.
- Hybrid approach (chosen): Best of both — admin-configurable dynamic content + developer-managed static copy.

### 3. CSS & Styling Strategy

**Decision**: Bootstrap 4 (already installed) + custom SCSS partial (`_landing.scss`) imported into `app.scss`. Use Bootstrap's RTL utilities and `[dir="rtl"]` CSS selectors for Arabic layout.

**Rationale**: Bootstrap 4 is already a project dependency. Adding a custom SCSS partial keeps styles modular. Bootstrap 4 doesn't have built-in RTL, so we use `[dir="rtl"]` attribute selectors for directional overrides (text alignment, flexbox direction, margins/padding).

**Alternatives considered**:
- Tailwind CSS: Would require adding a new dependency (violates Constitution V).
- Inline styles: Unmaintainable for responsive + RTL requirements.
- Separate RTL stylesheet: Duplicates styles. CSS logical properties + `[dir]` selectors are cleaner.

### 4. Responsive Strategy

**Decision**: Bootstrap 4 grid system with custom breakpoint overrides in `_landing.scss`. Mobile-first approach.

**Rationale**: Bootstrap 4's grid handles 576px/768px/992px/1200px breakpoints. The 320px minimum requires minor adjustments (padding, font sizes) below Bootstrap's default `sm` breakpoint.

### 5. SEO Considerations

**Decision**: Use `<html lang="ar" dir="rtl">` / `<html lang="en" dir="ltr">` based on selected locale. Populate `<meta>` tags from Setting's SEO fields.

**Rationale**: Proper `lang` and `dir` attributes improve accessibility and SEO. The Setting model already has `seo_meta_title_ar/en` and `seo_meta_description_ar/en` fields.

## Summary

No NEEDS CLARIFICATION items remain. All technical decisions use existing project infrastructure:
- Existing Bootstrap 4 + Laravel Mix for frontend
- Existing Setting + DesignSetting models for dynamic data
- Session-based locale switching (no URL restructuring)
- New translation files for static copy
- Single Blade view with RTL/LTR toggling via `dir` attribute
