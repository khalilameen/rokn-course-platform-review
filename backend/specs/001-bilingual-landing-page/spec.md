# Feature Specification: Bilingual Landing Page

**Feature Branch**: `001-bilingual-landing-page`
**Created**: 2026-04-05
**Status**: Draft
**Input**: User description: "Create a bilingual (Arabic/English) landing page for Rokn e-learning app at the main index route, displaying app store links from the settings table."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - View Landing Page in Arabic (Priority: P1)

A visitor opens the Rokn website root URL and sees an attractive landing page presenting Rokn as an e-learning platform. The page highlights that Rokn offers courses via short videos/reels with practical tasks and certifications, targeting youth, university students, and fresh graduates seeking employable skills (graphic design, content writing, marketing, sales, etc.). The page loads in Arabic by default as the primary audience language, with full RTL layout. Prominent app download buttons link to the Google Play and App Store URLs configured in the settings table.

**Why this priority**: The landing page is the first impression for all visitors. Without it, the site shows a blank placeholder. Arabic-first serves the primary target audience. App download CTAs are the main conversion goal.

**Independent Test**: Navigate to the root URL `/` and verify the full landing page renders in Arabic with all content sections visible and app store buttons linking correctly.

**Acceptance Scenarios**:

1. **Given** a visitor opens the root URL `/`, **When** the page loads, **Then** the landing page displays in Arabic with RTL layout, a hero section, feature highlights, skill categories, and app download links.
2. **Given** the settings table contains `android_app_url` and `ios_app_url` values, **When** the landing page loads, **Then** the Google Play and App Store download buttons are visible and point to the correct URLs.
3. **Given** the settings table has empty or null `ios_app_url`, **When** the landing page loads, **Then** only the Google Play button is displayed; the App Store button is hidden.
4. **Given** neither app URL is set in settings, **When** the landing page loads, **Then** the download buttons section is hidden entirely but the rest of the page renders normally.

---

### User Story 2 - Switch Language to English (Priority: P2)

A visitor viewing the landing page can switch from Arabic to English using a visible language toggle. When switching, all text content updates to English and the layout direction changes from RTL to LTR. The language preference persists across page refreshes.

**Why this priority**: Bilingual support broadens audience reach and is essential for non-Arabic-speaking visitors. It depends on the landing page existing first (US1).

**Independent Test**: Load the page in Arabic, click the English language toggle, and verify all visible text switches to English, layout changes to LTR, and refreshing the page keeps English selected.

**Acceptance Scenarios**:

1. **Given** the page is displayed in Arabic (RTL), **When** the visitor clicks the English language toggle, **Then** all text switches to English and the layout changes to LTR.
2. **Given** the page is displayed in English (LTR), **When** the visitor clicks the Arabic language toggle, **Then** all text switches to Arabic and the layout changes to RTL.
3. **Given** a visitor has switched to English, **When** they refresh the page, **Then** the page reloads in English (language choice persists).

---

### User Story 3 - Responsive Mobile Experience (Priority: P2)

A visitor accessing the landing page from a mobile phone sees a properly adapted layout. Content stacks vertically, the hero section scales to fit the screen, app download buttons are tappable, and the language toggle is accessible. The page is usable on screens as small as 320px wide.

**Why this priority**: Most of the target audience (youth, students) will likely access the page from mobile devices. A broken mobile experience directly hurts app download conversions.

**Independent Test**: Open the landing page on a mobile device or at 320px viewport width and verify all content is readable, buttons are tappable, and no horizontal scrolling occurs.

**Acceptance Scenarios**:

1. **Given** a visitor opens the page on a 320px-wide screen, **When** the page loads, **Then** all content is visible without horizontal scrolling and buttons are tappable.
2. **Given** a visitor opens the page on a tablet (768px), **When** the page loads, **Then** the layout adapts appropriately between mobile and desktop views.

---

### Edge Cases

- What happens when the settings table has no record at all? The page MUST still render without errors, with download buttons hidden.
- What happens if a visitor's browser does not support JavaScript? Core content (text, images, links) MUST be visible without JavaScript.
- What happens if app store URLs contain malformed data? Links MUST only render as clickable when they contain valid URLs.
- What happens with very long Arabic or English text content? Layout MUST not break with text overflow.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The root URL (`/`) MUST serve the Rokn landing page, replacing the current placeholder.
- **FR-002**: The landing page MUST display in Arabic by default with full RTL layout support.
- **FR-003**: The landing page MUST provide a visible language toggle to switch between Arabic and English.
- **FR-004**: When the language is switched, all page text, layout direction (RTL/LTR), and UI elements MUST update to the selected language.
- **FR-005**: The selected language MUST persist across page refreshes.
- **FR-006**: The landing page MUST display app download buttons (Google Play, App Store) with URLs dynamically loaded from the `settings` table columns `android_app_url` and `ios_app_url`.
- **FR-007**: If an app store URL is empty or null in settings, the corresponding download button MUST be hidden.
- **FR-008**: The landing page MUST include a hero section communicating Rokn's value proposition: short video courses with practical tasks and certifications for career-ready skills.
- **FR-009**: The landing page MUST highlight key skill categories (graphic design, content writing, marketing, sales, and similar).
- **FR-010**: The landing page MUST be fully responsive across desktop, tablet, and mobile screen sizes (320px minimum).
- **FR-011**: The landing page MUST load without requiring authentication (publicly accessible).

### Key Entities

- **Setting**: Existing model providing `android_app_url`, `ios_app_url`, `site_name_ar`, `site_name_en` for dynamic content on the landing page.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Visitors see the complete landing page within 3 seconds of navigating to the root URL.
- **SC-002**: Visitors can switch between Arabic and English with a single click, with the full page updating in under 1 second.
- **SC-003**: 100% of landing page content is readable and navigable on screens 320px wide and above without horizontal scrolling.
- **SC-004**: App store download links match the values configured in the admin settings dashboard with zero code changes required to update them.
- **SC-005**: The landing page is accessible without login from any device.

## Assumptions

- The primary audience is Arabic-speaking; Arabic is the default language.
- The existing `settings` table and admin dashboard already support managing `android_app_url` and `ios_app_url` — no admin-side changes are needed.
- The landing page replaces the current placeholder at `/` (the `echo "ElMobde3.com"; exit();` code).
- Static landing page content (marketing copy, feature descriptions) will be hardcoded in translation files or view templates, not managed via a CMS.
- The landing page is purely informational with app download CTAs — no user login or registration functionality.
- The existing asset pipeline (Laravel Mix) will be used for CSS/JS.
- No analytics or tracking integration is required for this version.
- The brand identity uses a blue gradient palette (light teal to deep blue) with white, as reflected in the app logo at `public/images/logo.png` — a stylized "R" with an embedded play button. The landing page design MUST align with this brand identity.
