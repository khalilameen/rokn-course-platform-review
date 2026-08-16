# Data Model: Bilingual Static Pages

**Date**: 2026-04-05
**Branch**: `002-static-pages`

## Entities

No new database tables or migrations required. All data comes from existing tables and translation files.

### Data Sources Per Page

| Page | Primary Source | Fields Used |
|------|---------------|-------------|
| About Us | Translation files (`about.php`) | Mission text, vision, feature descriptions |
| Contact Us | `Setting` model | `email`, `phone`, `site_name_ar/en` |
| Contact Us | `DesignSetting` model | Social media URLs, `technical_contact`, `center_contacts` |
| Privacy Policy | `DesignSetting` model | `policy_content_ar`, `policy_content_en` |
| Terms of Use | Translation files (`terms.php`) | Full terms text |
| All pages | `Setting` model | `site_name_ar/en` (navbar), SEO fields |
| All pages | `DesignSetting` model | Logo, colors, social media (footer) |

## Data Flow

```text
Browser GET /about|/contact|/privacy-policy|/terms
  → StaticPageController@{method}
      ├── Session locale (ar/en)
      ├── Setting::first()
      ├── DesignSetting::getDefaultSettings()
      └── returns view('static.{page}', compact(...))
```

## Validation Rules

No user input accepted on any static page. All data is read-only.
- Privacy policy HTML: rendered via `{!! !!}` from admin-managed content.
- Contact info: conditionally displayed only when non-empty.
- Social media URLs: rendered only if `filter_var($url, FILTER_VALIDATE_URL)` passes.
