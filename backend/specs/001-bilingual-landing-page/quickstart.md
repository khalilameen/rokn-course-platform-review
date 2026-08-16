# Quickstart: Bilingual Landing Page

**Branch**: `001-bilingual-landing-page`

## Prerequisites

- PHP 8.x with Laravel 9
- Composer dependencies installed (`composer install`)
- Node.js + npm (`npm install`)
- MySQL database with `settings` and `design_settings` tables migrated

## Setup

1. **Switch to feature branch**:
   ```bash
   git checkout 001-bilingual-landing-page
   ```

2. **Install dependencies** (if not already done):
   ```bash
   composer install
   npm install
   ```

3. **Compile assets**:
   ```bash
   npm run dev
   ```

4. **Run the dev server**:
   ```bash
   php artisan serve
   ```

5. **Visit the landing page**:
   - Arabic (default): `http://localhost:8000/`
   - English: `http://localhost:8000/?lang=en`
   - Switch back to Arabic: `http://localhost:8000/?lang=ar`

## Configure App Store Links

In the admin dashboard (`/dashboard/settings`), set:
- `android_app_url` — Google Play Store link
- `ios_app_url` — Apple App Store link

These will appear as download buttons on the landing page.

## Configure Branding

In the admin dashboard (`/dashboard/design-settings`), set:
- Platform name (Arabic + English)
- Slogans (up to 3 per language)
- Brand colors
- Social media URLs
- Logo and background images

## Development

- **Watch mode** for CSS/JS changes:
  ```bash
  npm run watch
  ```

- **Landing page view**: `resources/views/landing/index.blade.php`
- **Translations**: `resources/lang/ar/landing.php` and `resources/lang/en/landing.php`
- **Styles**: `resources/sass/_landing.scss`
- **Controller**: `app/Http/Controllers/LandingPageController.php`

## Testing

```bash
php artisan test --filter LandingPage
```

Verifies:
- Landing page loads at `/` with 200 status
- Language switching works via `?lang=en` / `?lang=ar`
- App store buttons render when URLs are configured
- App store buttons hidden when URLs are empty
- Page renders without errors when no settings exist
