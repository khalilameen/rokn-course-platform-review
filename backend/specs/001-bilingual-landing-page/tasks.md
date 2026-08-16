# Tasks: Bilingual Landing Page

**Input**: Design documents from `/specs/001-bilingual-landing-page/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md

**Tests**: Not explicitly requested in the feature specification. Test tasks are omitted.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- Laravel monolith: `app/`, `resources/`, `routes/`, `public/` at repository root

---

## Phase 1: Setup

**Purpose**: Create project files and directory structure for the landing page feature

- [x] T001 [P] Create Arabic translation file with landing page marketing copy (hero text, feature descriptions, skill categories, CTA labels) in `resources/lang/ar/landing.php`
- [x] T002 [P] Create English translation file with landing page marketing copy (hero text, feature descriptions, skill categories, CTA labels) in `resources/lang/en/landing.php`
- [x] T003 [P] Create landing page SCSS partial with base variables, brand color CSS custom properties, and import statement in `resources/sass/_landing.scss` and add `@import 'landing'` to `resources/sass/app.scss`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Controller and route — MUST be complete before any view work can render

**CRITICAL**: No user story work can begin until this phase is complete

- [x] T004 Create `LandingPageController` in `app/Http/Controllers/LandingPageController.php` with `index()` method that: reads `?lang` query param, stores locale in session, sets `app()->setLocale()`, fetches `Setting::first()` and `DesignSetting::getDefaultSettings()`, returns `view('landing.index')` with settings, designSetting, and locale data
- [x] T005 Replace the current placeholder `/` route in `routes/web.php` (remove `echo "ElMobde3.com"; exit();`) with `Route::get('/', [LandingPageController::class, 'index'])->name('landing')`

**Checkpoint**: Controller returns a view at `/` with settings data — user story implementation can now begin

---

## Phase 3: User Story 1 — View Landing Page in Arabic (Priority: P1) MVP

**Goal**: Visitor opens `/` and sees a complete Arabic landing page with hero section, features, skill categories, and app store download buttons

**Independent Test**: Navigate to `/` and verify all sections render in Arabic with correct app store links from settings

### Implementation for User Story 1

- [x] T006 [US1] Create the landing page Blade layout in `resources/views/landing/index.blade.php` with: `<html lang="" dir="">` attributes driven by locale, `<head>` with SEO meta tags from Setting (`seo_meta_title_ar/en`, `seo_meta_description_ar/en`), Bootstrap 4 CSS, and compiled `app.css` link
- [x] T007 [US1] Build the header/navbar section in `resources/views/landing/index.blade.php` with: Rokn logo from `public/images/logo.png` (fallback to `DesignSetting.logo_url`), platform name from `DesignSetting` (`name_ar`/`name_en` based on locale), and language toggle placeholder link
- [x] T008 [US1] Build the hero section in `resources/views/landing/index.blade.php` with: blue gradient background matching brand identity, slogans from `DesignSetting` (`slogan_1_ar`/`slogan_1_en`, `slogan_2_ar`/`slogan_2_en`), hero description from `@lang('landing.hero_description')`, and app store download buttons
- [x] T009 [US1] Build the app store download buttons section: render Google Play button linked to `Setting.android_app_url` and App Store button linked to `Setting.ios_app_url`, conditionally hidden via `@if(filter_var($url, FILTER_VALIDATE_URL))` when URL is empty/null/invalid
- [x] T010 [US1] Build the features section in `resources/views/landing/index.blade.php` with: 3-4 feature cards highlighting short video courses, practical tasks, certifications, and career readiness — text from `@lang('landing.feature_*')` translation keys
- [x] T011 [US1] Build the skill categories section in `resources/views/landing/index.blade.php` displaying skill tags (graphic design, content writing, marketing, sales, etc.) from `@lang('landing.skills')` translation array
- [x] T012 [US1] Build the "How Platform Works" section (conditionally shown via `DesignSetting.show_how_platform_works`) with title from `how_platform_works_title_ar/en` and optional video embed from `how_platform_works_video_link`
- [x] T013 [US1] Build the footer section with: social media links from `DesignSetting` (`facebook_url`, `instagram_url`, `tiktok_url`, `youtube_url`, `whatsapp_url`, `telegram_url`) — each conditionally rendered only if URL is valid, contact info from `Setting` (`email`, `phone`), and CTA text from `DesignSetting.slogan_3_ar/en`
- [x] T014 [US1] Add landing page hero and section styles in `resources/sass/_landing.scss`: blue gradient hero background (`#7EC8E3` to `#1976B5`), section spacing, feature card styles, skill tag badges, footer styling, and RTL layout overrides using `[dir="rtl"]` selectors
- [x] T015 [US1] Compile assets by running `npm run dev` and verify the landing page renders correctly at `/` in Arabic with all sections visible

**Checkpoint**: User Story 1 is fully functional — landing page renders in Arabic with all content sections and app store buttons

---

## Phase 4: User Story 2 — Switch Language to English (Priority: P2)

**Goal**: Visitor can toggle between Arabic and English with a single click; layout direction switches RTL/LTR and language persists across refreshes

**Independent Test**: Click language toggle on the landing page, verify all text switches language and direction, refresh and confirm persistence

### Implementation for User Story 2

- [x] T016 [US2] Implement the language toggle link in the header/navbar (created in T007): render a link/button showing "English" when locale is `ar` and "العربية" when locale is `en`, linking to `/?lang=en` or `/?lang=ar` respectively
- [x] T017 [US2] Update all Blade sections (T006–T013) to use locale-aware field access: `$designSetting->{'name_' . $locale}`, `$designSetting->{'slogan_1_' . $locale}`, `$setting->{'site_name_' . $locale}`, `$setting->{'seo_meta_title_' . $locale}`, etc. — ensuring both Arabic and English content renders correctly based on session locale
- [x] T018 [US2] Verify that the `<html lang="" dir="">` attributes switch correctly: `lang="ar" dir="rtl"` for Arabic, `lang="en" dir="ltr"` for English, and that RTL CSS overrides in `_landing.scss` apply properly (text alignment, flex direction, margin/padding mirroring)

**Checkpoint**: Language toggle works, all text switches, RTL/LTR adapts, and preference persists via session

---

## Phase 5: User Story 3 — Responsive Mobile Experience (Priority: P2)

**Goal**: Landing page is fully usable on mobile devices (320px+) with tappable buttons, readable text, and no horizontal scrolling

**Independent Test**: Open landing page at 320px viewport width and verify all content is accessible without horizontal scroll

### Implementation for User Story 3

- [x] T019 [US3] Add responsive styles in `public/css/landing.css`: mobile-first media queries for hero section (stacked layout, scaled font sizes, full-width CTA buttons), feature cards (single column on mobile, grid on tablet+), and skill tags (wrap on small screens)
- [x] T020 [US3] Add responsive styles for header/navbar: collapsible or simplified navigation on mobile, ensure language toggle is accessible on small screens, logo scales appropriately
- [x] T021 [US3] Add responsive styles for footer: stack columns vertically on mobile, ensure social media icons have adequate tap targets (minimum 44x44px), and contact info is readable
- [x] T022 [US3] Verify no horizontal scrolling at 320px, 375px, 768px, and 1200px viewport widths; fix any overflow issues in `resources/sass/_landing.scss`

**Checkpoint**: All user stories are independently functional — landing page works on all screen sizes in both languages

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Final cleanup and production readiness

- [x] T023 [P] N/A — plain CSS served directly, no build step needed to compile minified production assets and verify the landing page still renders correctly
- [x] T024 [P] Verify edge cases: no settings record in DB (page renders without errors), empty app store URLs (buttons hidden), malformed URLs (not rendered as clickable)
- [x] T025 Remove or rename the old `resources/views/welcome.blade.php` if no other route references it

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — T001, T002, T003 can all run in parallel
- **Foundational (Phase 2)**: T004 depends on T001+T002 (translations exist). T005 depends on T004 (controller exists). BLOCKS all user stories.
- **User Story 1 (Phase 3)**: Depends on Phase 2 completion. T006–T013 are sequential (each builds on prior sections). T014 can run in parallel with T006–T013. T015 depends on all prior tasks.
- **User Story 2 (Phase 4)**: Depends on Phase 3 (view exists to add toggle to). T016–T018 are sequential.
- **User Story 3 (Phase 5)**: Depends on Phase 3 (view exists to make responsive). Can run in parallel with Phase 4. T019–T021 can run in parallel. T022 depends on T019–T021.
- **Polish (Phase 6)**: Depends on all user stories complete. T023–T025 can run in parallel.

### Parallel Opportunities

```bash
# Phase 1 — all three setup tasks in parallel:
Task: T001 "Arabic translation file"
Task: T002 "English translation file"
Task: T003 "SCSS partial + import"

# Phase 3 — styles can be written while building view sections:
Task: T014 "Landing page styles" (parallel with T006–T013)

# Phase 5 — responsive styles for different sections in parallel:
Task: T019 "Hero + features responsive"
Task: T020 "Header responsive"
Task: T021 "Footer responsive"

# Phase 6 — polish tasks in parallel:
Task: T023 "Production build"
Task: T024 "Edge case verification"
Task: T025 "Clean up old welcome view"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (translation files + SCSS)
2. Complete Phase 2: Controller + route
3. Complete Phase 3: Full Arabic landing page with all sections
4. **STOP and VALIDATE**: Verify page renders at `/` in Arabic with app store buttons
5. Deploy/demo if ready — this alone replaces the placeholder

### Incremental Delivery

1. Setup + Foundational → Route works, returns view
2. Add User Story 1 → Full Arabic page (MVP!)
3. Add User Story 2 → Bilingual toggle works
4. Add User Story 3 → Mobile responsive
5. Polish → Production-ready

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- No new database tables or migrations required
- No test tasks generated (not requested in spec)
- All dynamic content comes from existing Setting + DesignSetting models
- Static marketing copy managed via Laravel translation files
- Commit after each phase completion
