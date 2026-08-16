# Data Model: Bilingual Landing Page

**Date**: 2026-04-05
**Branch**: `001-bilingual-landing-page`

## Entities

No new database tables or migrations are required. This feature reads from two existing tables.

### Setting (existing — `settings` table)

| Field | Type | Used On Landing Page |
|-------|------|---------------------|
| `site_name_ar` | string | Yes — page title, header (Arabic) |
| `site_name_en` | string | Yes — page title, header (English) |
| `android_app_url` | text | Yes — Google Play download button |
| `ios_app_url` | text | Yes — App Store download button |
| `seo_meta_title_ar` | string | Yes — `<title>` tag (Arabic) |
| `seo_meta_title_en` | string | Yes — `<title>` tag (English) |
| `seo_meta_description_ar` | text | Yes — meta description (Arabic) |
| `seo_meta_description_en` | text | Yes — meta description (English) |
| `email` | string | Optional — footer contact |
| `phone` | string | Optional — footer contact |

### DesignSetting (existing — `design_settings` table)

| Field | Type | Used On Landing Page |
|-------|------|---------------------|
| `logo_url` | string | Yes — header/hero logo |
| `name_ar` / `name_en` | string | Yes — platform name |
| `slogan_1_ar` / `slogan_1_en` | string | Yes — hero tagline |
| `slogan_2_ar` / `slogan_2_en` | string | Yes — secondary tagline |
| `slogan_3_ar` / `slogan_3_en` | string | Yes — CTA text |
| `color_1` through `color_4` | string | Yes — brand colors for CSS variables |
| `facebook_url`, `youtube_url`, `instagram_url`, `tiktok_url`, `whatsapp_url`, `telegram_url` | string | Yes — footer social links |
| `show_how_platform_works` | boolean | Yes — toggle "How it works" section |
| `how_platform_works_title_ar` / `_en` | string | Yes — section title |
| `how_platform_works_video_link` | string | Yes — embedded video |
| `home_background_url` | string | Optional — hero background |

## Data Flow

```text
Browser GET /  →  LandingPageController@index
                     ├── Setting::first()
                     ├── DesignSetting::getDefaultSettings()
                     ├── Session locale (ar/en)
                     └── returns view('landing.index', compact(...))
```

## Validation Rules

No user input is accepted on the landing page. All data is read-only from the database. URL validation for app store links is handled in the admin `SettingsController@update` (already exists).

The view template validates display-side:
- App store URLs: only render as `<a href>` if the value passes `filter_var($url, FILTER_VALIDATE_URL)`.
- Social media URLs: same URL validation before rendering links.
- Missing DesignSetting record: `getDefaultSettings()` returns sensible defaults.
