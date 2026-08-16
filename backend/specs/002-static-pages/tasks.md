# Tasks: Bilingual Static Pages

**Input**: Design documents from `/specs/002-static-pages/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md

**Tests**: Not explicitly requested in the feature specification. Test tasks are omitted.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3, US4)
- Include exact file paths in descriptions

## Path Conventions

- Laravel monolith: `app/`, `resources/`, `routes/`, `public/` at repository root

---

## Phase 1: Setup

**Purpose**: Create translation files for all four static pages

- [x] T001 [P] Create Arabic translation file for About Us page (mission, vision, feature descriptions) in `resources/lang/ar/about.php`
- [x] T002 [P] Create English translation file for About Us page in `resources/lang/en/about.php`
- [x] T003 [P] Create Arabic translation file for Contact Us page (labels, headings) in `resources/lang/ar/contact.php`
- [x] T004 [P] Create English translation file for Contact Us page in `resources/lang/en/contact.php`
- [x] T005 [P] Create Arabic translation file for Privacy Policy page (fallback text, page title) in `resources/lang/ar/privacy.php`
- [x] T006 [P] Create English translation file for Privacy Policy page in `resources/lang/en/privacy.php`
- [x] T007 [P] Create Arabic translation file for Terms of Use page (full terms content, page title) in `resources/lang/ar/terms.php`
- [x] T008 [P] Create English translation file for Terms of Use page in `resources/lang/en/terms.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared layout, controller, routes, and styles — MUST complete before any page view work

**CRITICAL**: No user story work can begin until this phase is complete

- [x] T009 Extract shared Blade layout from `resources/views/landing/index.blade.php` into `resources/views/layouts/landing.blade.php` with: `<html>` tag with locale/dir attributes, `<head>` with `@yield('title')` and `@yield('meta_description')`, navbar with logo + nav links + language toggle, `@yield('content')` for page body, footer with social media + contact info
- [x] T010 Refactor `resources/views/landing/index.blade.php` to `@extend('layouts.landing')` and move hero/features/skills/how-it-works sections into `@section('content')`
- [x] T011 Add navigation links to the shared layout navbar in `resources/views/layouts/landing.blade.php`: links to About (`/about`), Contact (`/contact`), Privacy Policy (`/privacy-policy`), Terms (`/terms`) using translation keys from `landing.php`
- [x] T012 Add nav link translation keys to `resources/lang/ar/landing.php` and `resources/lang/en/landing.php`: `nav_about`, `nav_contact`, `nav_privacy`, `nav_terms`
- [x] T013 Create `StaticPageController` in `app/Http/Controllers/StaticPageController.php` with locale handling (same pattern as `LandingPageController`), settings fetching, and 4 methods: `about()`, `contact()`, `privacy()`, `terms()` — each returning its respective view
- [x] T014 Add 4 routes to `routes/web.php`: `/about`, `/contact`, `/privacy-policy`, `/terms` pointing to `StaticPageController` methods
- [x] T015 Add static page content styles to `public/css/landing.css`: `.page-content` container with max-width, padding, typography for headings/paragraphs/lists, `.policy-content` wrapper for rendered HTML, contact info card styles

**Checkpoint**: Shared layout works, landing page still renders correctly, routes respond, controller returns views

---

## Phase 3: User Story 1 — About Us (Priority: P1)

**Goal**: Visitor sees About Us page with Rokn's mission, vision, and offerings at `/about`

**Independent Test**: Navigate to `/about` and verify branded page with Arabic content, language toggle, responsive layout

### Implementation for User Story 1

- [x] T016 [US1] Create `resources/views/static/about.blade.php` extending `layouts.landing` with: `@section('title')` and `@section('meta_description')` from translation keys, page heading, mission statement, platform description (short video courses, practical tasks, certifications), target audience (youth, students, fresh graduates), skill categories — all content from `@lang('about.*')` translation keys

**Checkpoint**: About Us page fully functional at `/about` in both languages

---

## Phase 4: User Story 2 — Contact Us (Priority: P1)

**Goal**: Visitor sees Contact Us page with email, phone, and social media links at `/contact`

**Independent Test**: Navigate to `/contact` and verify contact info from settings displays correctly

### Implementation for User Story 2

- [x] T017 [US2] Create `resources/views/static/contact.blade.php` extending `layouts.landing` with: page heading from `@lang('contact.*')`, email from `$setting->email` (conditionally shown), phone from `$setting->phone` (conditionally shown), social media links from `$designSetting` (same pattern as landing footer, with URL validation), fallback message when all contact fields are empty

**Checkpoint**: Contact Us page fully functional at `/contact` with dynamic data from settings

---

## Phase 5: User Story 3 — Privacy Policy (Priority: P2)

**Goal**: Visitor reads privacy policy content from design settings at `/privacy-policy`

**Independent Test**: Navigate to `/privacy-policy` and verify HTML content renders as formatted text

### Implementation for User Story 3

- [x] T018 [US3] Create `resources/views/static/privacy.blade.php` extending `layouts.landing` with: page heading, rendered HTML content from `$designSetting->{'policy_content_' . $locale}` via `{!! !!}` wrapped in `.policy-content` div, fallback placeholder message when no content exists in design settings

**Checkpoint**: Privacy Policy page renders HTML content correctly at `/privacy-policy`

---

## Phase 6: User Story 4 — Terms of Use (Priority: P2)

**Goal**: Visitor reads terms of use content at `/terms`

**Independent Test**: Navigate to `/terms` and verify terms content displays in both languages

### Implementation for User Story 4

- [x] T019 [US4] Create `resources/views/static/terms.blade.php` extending `layouts.landing` with: page heading from `@lang('terms.title')`, terms content sections from `@lang('terms.*')` translation keys (general terms, user responsibilities, intellectual property, limitation of liability, governing law)

**Checkpoint**: Terms of Use page fully functional at `/terms` in both languages

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Final validation and cleanup

- [x] T020 [P] Verify language switching persists across all pages: switch to English on landing page, navigate to `/about`, confirm English is active, navigate to `/contact`, confirm English persists
- [x] T021 [P] Verify edge cases: empty settings (pages render without errors), empty privacy policy content (fallback shown), empty contact fields (hidden gracefully)
- [x] T022 [P] Verify responsive layout on all 4 pages at 320px, 768px, and 1200px viewport widths — no horizontal scrolling, readable text, tappable links

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — all 8 translation files can run in parallel
- **Foundational (Phase 2)**: Depends on Phase 1 (translation keys exist). T009→T010 sequential (extract layout then refactor landing). T011→T012 sequential (add links then add translations). T013→T014 sequential (controller then routes). T015 parallel with others.
- **User Stories (Phases 3–6)**: All depend on Phase 2 completion. Each story is independent and can run in parallel.
- **Polish (Phase 7)**: Depends on all user stories complete. All tasks parallel.

### Parallel Opportunities

```bash
# Phase 1 — all 8 translation files in parallel:
Task: T001–T008

# Phase 2 — styles parallel with controller/routes:
Task: T015 "Content styles" (parallel with T013–T014)

# Phases 3–6 — all 4 page views in parallel:
Task: T016 "About Us view"
Task: T017 "Contact Us view"
Task: T018 "Privacy Policy view"
Task: T019 "Terms of Use view"

# Phase 7 — all polish tasks in parallel:
Task: T020–T022
```

---

## Implementation Strategy

### MVP First (About Us + Contact Us)

1. Complete Phase 1: Translation files
2. Complete Phase 2: Shared layout + controller + routes + styles
3. Complete Phase 3: About Us page
4. Complete Phase 4: Contact Us page
5. **STOP and VALIDATE**: All P1 stories functional
6. Deploy/demo if ready

### Incremental Delivery

1. Setup + Foundational → Shared layout works, routes respond
2. Add About Us → First static page live
3. Add Contact Us → Contact info accessible
4. Add Privacy Policy → Legal page live
5. Add Terms of Use → All pages complete
6. Polish → Cross-page validation

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story
- No new database tables or migrations
- No npm build needed — plain CSS in `public/css/landing.css`
- Shared layout extraction (T009–T010) is the most critical foundational task
- All 4 page views (T016–T019) can be built in parallel after Phase 2
