# Quickstart: Bilingual Static Pages

**Branch**: `002-static-pages`

## Prerequisites

- Landing page feature (001) must be implemented (shared CSS and locale logic)
- PHP 8.x with Laravel 9
- MySQL database with `settings` and `design_settings` tables

## Setup

1. **Switch to feature branch**:
   ```bash
   git checkout 002-static-pages
   ```

2. **Run the dev server**:
   ```bash
   php artisan serve
   ```

3. **Visit the pages**:
   - About Us: `http://localhost:8000/about`
   - Contact Us: `http://localhost:8000/contact`
   - Privacy Policy: `http://localhost:8000/privacy-policy`
   - Terms of Use: `http://localhost:8000/terms`
   - Append `?lang=en` for English on any page

## Configure Content

- **Contact info**: Admin dashboard → Settings → email, phone
- **Privacy policy**: Admin dashboard → Design Settings → policy_content_ar/en
- **Social media**: Admin dashboard → Design Settings → social media URLs
- **About Us / Terms**: Edit translation files in `resources/lang/{ar,en}/`

## Development

- **Shared layout**: `resources/views/layouts/landing.blade.php`
- **Page views**: `resources/views/static/{about,contact,privacy,terms}.blade.php`
- **Controller**: `app/Http/Controllers/StaticPageController.php`
- **Styles**: `public/css/landing.css` (shared with landing page)
- **Translations**: `resources/lang/{ar,en}/{about,contact,privacy,terms}.php`

## Testing

```bash
php artisan test --filter StaticPage
```

Verifies:
- All 4 pages return 200 at their URLs
- Language switching works on each page
- Contact page shows data from settings
- Privacy page renders HTML content
- Pages render without errors when settings are empty
