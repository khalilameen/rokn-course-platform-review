# Feature Specification: Bilingual Static Pages

**Feature Branch**: `002-static-pages`
**Created**: 2026-04-05
**Status**: Draft
**Input**: User description: "Create bilingual static pages (about us, contact us, privacy policy, terms of use) with same landing page theming, responsive design."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - View About Us Page (Priority: P1)

A visitor navigates to the About Us page from the landing page or directly via URL. The page displays information about the Rokn platform — its mission, vision, and what it offers (short video courses, practical tasks, certifications for career-ready skills). The page uses the same visual theming as the landing page (blue gradient brand, same navbar with language toggle, same footer). Content displays in Arabic by default with the ability to switch to English.

**Why this priority**: About Us is the most common informational page visitors look for to understand and trust the platform. It establishes credibility.

**Independent Test**: Navigate to `/about` and verify the page renders with Rokn branding, Arabic content, language toggle, and responsive layout.

**Acceptance Scenarios**:

1. **Given** a visitor navigates to `/about`, **When** the page loads, **Then** the About Us content is displayed in Arabic with the same navbar, footer, and brand theming as the landing page.
2. **Given** a visitor is on the About Us page in Arabic, **When** they click the language toggle, **Then** the content switches to English and the layout changes to LTR.
3. **Given** a visitor accesses the About Us page on a mobile device, **When** the page loads, **Then** all content is readable and properly laid out without horizontal scrolling.

---

### User Story 2 - View Contact Us Page (Priority: P1)

A visitor navigates to the Contact Us page to find contact information for the Rokn team. The page displays contact data only (email, phone number, social media links) — no contact form. Contact information is pulled from the settings and design settings (already managed via admin dashboard).

**Why this priority**: Contact information is essential for user trust and support. It has a direct business impact.

**Independent Test**: Navigate to `/contact` and verify contact data (email, phone, social media) displays correctly from settings.

**Acceptance Scenarios**:

1. **Given** a visitor navigates to `/contact`, **When** the page loads, **Then** the contact information (email, phone) from settings is displayed with the same brand theming.
2. **Given** the settings contain social media URLs, **When** the Contact Us page loads, **Then** social media links are displayed with appropriate icons.
3. **Given** a setting field (e.g., phone) is empty, **When** the page loads, **Then** that field is not shown (no empty or broken elements).

---

### User Story 3 - View Privacy Policy Page (Priority: P2)

A visitor navigates to the Privacy Policy page to read the platform's privacy practices. The page displays the privacy policy content stored in the design settings (`policy_content_ar` / `policy_content_en`). The content is rendered as rich text (HTML).

**Why this priority**: Privacy policy is a legal requirement for app stores and user trust. Less frequently visited than About Us or Contact but mandatory.

**Independent Test**: Navigate to `/privacy-policy` and verify the policy content from design settings renders correctly in both languages.

**Acceptance Scenarios**:

1. **Given** a visitor navigates to `/privacy-policy`, **When** the page loads, **Then** the privacy policy content from design settings is displayed with brand theming.
2. **Given** the design settings contain HTML in `policy_content_ar`, **When** the page loads in Arabic, **Then** the HTML renders correctly as formatted text (headings, paragraphs, lists).
3. **Given** no policy content exists in design settings, **When** the page loads, **Then** a default placeholder message is shown (e.g., "Privacy policy content will be available soon").

---

### User Story 4 - View Terms of Use Page (Priority: P2)

A visitor navigates to the Terms of Use page to read the platform's terms and conditions. The content is managed via translation files (static text) since there is no dedicated database field for terms of use.

**Why this priority**: Terms of use is a legal requirement. Similar to privacy policy in importance but less frequently accessed.

**Independent Test**: Navigate to `/terms` and verify the terms content displays correctly in both languages with brand theming.

**Acceptance Scenarios**:

1. **Given** a visitor navigates to `/terms`, **When** the page loads, **Then** the terms of use content is displayed with brand theming.
2. **Given** the visitor switches to English, **When** the page refreshes, **Then** the terms content switches to English and layout changes to LTR.

---

### Edge Cases

- What happens when a visitor accesses a static page URL that does not exist (e.g., `/about-usx`)? Standard 404 handling applies — not part of this feature.
- What happens when privacy policy HTML content contains malicious scripts? The rendered content MUST be sanitized to prevent XSS.
- What happens when all contact fields are empty in settings? The Contact Us page MUST still render without errors, showing the page structure with a message that contact information is being updated.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide four static pages accessible at `/about`, `/contact`, `/privacy-policy`, and `/terms`.
- **FR-002**: All four pages MUST use the same visual theming as the landing page (same navbar with logo and language toggle, same footer with social media links, same blue gradient brand colors).
- **FR-003**: All four pages MUST support bilingual content (Arabic default, English via language toggle) with RTL/LTR switching.
- **FR-004**: All four pages MUST be fully responsive across desktop, tablet, and mobile (320px minimum width).
- **FR-005**: All four pages MUST be publicly accessible without authentication.
- **FR-006**: The Contact Us page MUST display contact information (email, phone, social media) from settings — no contact form.
- **FR-007**: The Privacy Policy page MUST render the policy content from design settings (`policy_content_ar` / `policy_content_en`) as formatted HTML.
- **FR-008**: The Privacy Policy content MUST be sanitized before rendering to prevent XSS.
- **FR-009**: The About Us page MUST describe the Rokn platform's mission and offerings using content from translation files.
- **FR-010**: The Terms of Use page MUST display terms content from translation files.
- **FR-011**: The language preference selected on any static page MUST persist when navigating to other pages (shared session).
- **FR-012**: Each page MUST have appropriate SEO meta tags (title, description) in the active language.
- **FR-013**: The landing page navbar MUST include navigation links to these static pages.

### Key Entities

- **Setting**: Existing model providing email, phone, site name, SEO fields for the static pages.
- **DesignSetting**: Existing model providing privacy policy content (`policy_content_ar`/`policy_content_en`), social media URLs, brand colors, and logo.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All four static pages load within 2 seconds of navigation.
- **SC-002**: Language switching works consistently across all pages with a single click, maintaining the same session-based persistence.
- **SC-003**: 100% of page content is readable on screens 320px wide and above without horizontal scrolling.
- **SC-004**: Contact information displayed on the Contact Us page matches the values in the admin settings dashboard without code changes.
- **SC-005**: Privacy policy content from design settings renders correctly as formatted text with no raw HTML visible to visitors.

## Assumptions

- The four pages share the same layout (navbar + content area + footer) already established by the landing page.
- Contact Us is informational only — no contact form, no email sending, no form submission.
- Privacy policy content is managed via the existing admin dashboard (`policy_content_ar`/`policy_content_en` in `design_settings`).
- Terms of use content is managed via translation files (static text), not database-driven, since no dedicated field exists in settings.
- About Us content is managed via translation files (static text describing Rokn's mission and offerings).
- The existing session-based language switching from the landing page (`?lang=ar`/`?lang=en`) is reused across all static pages.
- Pages will be linked from the landing page navbar/footer for discoverability.
- The brand identity (blue gradient, Rokn logo) from the landing page applies to all static pages.
