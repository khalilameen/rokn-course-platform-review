{{-- Dynamic Styles Component for Course Sections Pages --}}
@php
    // Color Roles:
    // color_1 = Primary Color (Brand identity, main buttons, key highlights) ~60%
    // color_2 = Secondary Color (Supporting elements, secondary buttons) ~30%
    // color_3 = Neutral Colors (Backgrounds, text, borders, structure) ~60-80%
    // color_4 = Accent Color (Notifications, highlights, attention elements) ~5%

    $colorPrimary = $designSettings->color_1 ?? '#2563eb';
    $colorSecondary = $designSettings->color_2 ?? '#16a34a';
    $colorNeutral = $designSettings->color_3 ?? '#f5f7fa';
    $colorAccent = $designSettings->color_4 ?? '#f97316';

    // Feedback colors (fixed, not from settings)
    $colorSuccess = '#10b981'; // Green
    $colorWarning = '#f59e0b'; // Yellow/Orange
    $colorError = '#ef4444';   // Red

    // Generate lighter and darker shades for better UI
    if (!function_exists('adjustBrightness')) {
        function adjustBrightness($hex, $percent) {
            $hex = str_replace('#', '', $hex);
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            $r = max(0, min(255, $r + ($r * $percent / 100)));
            $g = max(0, min(255, $g + ($g * $percent / 100)));
            $b = max(0, min(255, $b + ($b * $percent / 100)));

            return sprintf("#%02x%02x%02x", $r, $g, $b);
        }
    }

    if (!function_exists('hexToRgba')) {
        function hexToRgba($hex, $alpha = 1) {
            $hex = str_replace('#', '', $hex);
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return "rgba($r, $g, $b, $alpha)";
        }
    }

    // Generate variations for each color
    $colorPrimaryLight = adjustBrightness($colorPrimary, 30);
    $colorPrimaryDark = adjustBrightness($colorPrimary, -20);

    $colorSecondaryLight = adjustBrightness($colorSecondary, 30);
    $colorSecondaryDark = adjustBrightness($colorSecondary, -20);

    $colorNeutralLight = adjustBrightness($colorNeutral, 40);
    $colorNeutralDark = adjustBrightness($colorNeutral, -20);

    $colorAccentLight = adjustBrightness($colorAccent, 30);
    $colorAccentDark = adjustBrightness($colorAccent, -20);
@endphp

<style id="dynamic-theme-styles-course-sections">
:root {
    /* PRIMARY COLOR (Brand Identity - Main Buttons, Key Highlights) ~60% */
    --color-primary: {{ $colorPrimary }};
    --color-primary-light: {{ $colorPrimaryLight }};
    --color-primary-dark: {{ $colorPrimaryDark }};
    --color-primary-rgb: {{ hexToRgba($colorPrimary, 1) }};

    /* SECONDARY COLOR (Supporting Elements - Secondary Buttons) ~30% */
    --color-secondary: {{ $colorSecondary }};
    --color-secondary-light: {{ $colorSecondaryLight }};
    --color-secondary-dark: {{ $colorSecondaryDark }};
    --color-secondary-rgb: {{ hexToRgba($colorSecondary, 1) }};

    /* NEUTRAL COLOR (Structure - Backgrounds, Text, Borders) ~60-80% */
    --color-neutral: {{ $colorNeutral }};
    --color-neutral-light: {{ $colorNeutralLight }};
    --color-neutral-dark: {{ $colorNeutralDark }};

    /* ACCENT COLOR (Attention Elements - Notifications, Highlights) ~5% */
    --color-accent: {{ $colorAccent }};
    --color-accent-light: {{ $colorAccentLight }};
    --color-accent-dark: {{ $colorAccentDark }};
    --color-accent-rgb: {{ hexToRgba($colorAccent, 1) }};

    /* FEEDBACK COLORS (Fixed System States) */
    --color-success: {{ $colorSuccess }};
    --color-warning: {{ $colorWarning }};
    --color-error: {{ $colorError }};

    /* Light Mode Variables */
    --bg-primary: #ffffff;
    --bg-secondary: #f8f9fa;
    --bg-tertiary: #e9ecef;
    --text-primary: #2d3748;
    --text-secondary: #718096;
    --text-tertiary: #a0aec0;
    --border-color: #e2e8f0;
    --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.15);
}

/* Dark Mode Variables */
body.dark-mode {
    --bg-primary: #1a202c;
    --bg-secondary: #2d3748;
    --bg-tertiary: #4a5568;
    --text-primary: #f7fafc;
    --text-secondary: #e2e8f0;
    --text-tertiary: #cbd5e0;
    --border-color: #4a5568;
    --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.3);
    --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.5);
}

/* Apply theme colors */
body {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    transition: background-color 0.3s ease, color 0.3s ease;
}

/* Headers - Always use Primary Color with White Text */
.sections-management-header,
.create-section-header,
.edit-section-header,
.show-section-header {
    background: var(--color-primary) !important;
    color: #ffffff !important;
}

/* Header Titles and Subtitles - Always White */
.sections-management-header h1,
.sections-management-header h2,
.sections-management-header h3,
.sections-management-header h4,
.sections-management-header h5,
.sections-management-header h6,
.sections-management-header p,
.create-section-header h1,
.create-section-header h2,
.create-section-header h3,
.create-section-header h4,
.create-section-header h5,
.create-section-header h6,
.create-section-header p,
.edit-section-header h1,
.edit-section-header h2,
.edit-section-header h3,
.edit-section-header h4,
.edit-section-header h5,
.edit-section-header h6,
.edit-section-header p,
.show-section-header h1,
.show-section-header h2,
.show-section-header h3,
.show-section-header h4,
.show-section-header h5,
.show-section-header h6,
.show-section-header p {
    color: #ffffff !important;
}

/* Ensure white color in dark mode too */
body.dark-mode .sections-management-header,
body.dark-mode .create-section-header,
body.dark-mode .edit-section-header,
body.dark-mode .show-section-header,
body.dark-mode .sections-management-header h1,
body.dark-mode .sections-management-header h2,
body.dark-mode .sections-management-header h3,
body.dark-mode .sections-management-header h4,
body.dark-mode .sections-management-header h5,
body.dark-mode .sections-management-header h6,
body.dark-mode .sections-management-header p,
body.dark-mode .create-section-header h1,
body.dark-mode .create-section-header h2,
body.dark-mode .create-section-header h3,
body.dark-mode .create-section-header h4,
body.dark-mode .create-section-header h5,
body.dark-mode .create-section-header h6,
body.dark-mode .create-section-header p,
body.dark-mode .edit-section-header h1,
body.dark-mode .edit-section-header h2,
body.dark-mode .edit-section-header h3,
body.dark-mode .edit-section-header h4,
body.dark-mode .edit-section-header h5,
body.dark-mode .edit-section-header h6,
body.dark-mode .edit-section-header p,
body.dark-mode .show-section-header h1,
body.dark-mode .show-section-header h2,
body.dark-mode .show-section-header h3,
body.dark-mode .show-section-header h4,
body.dark-mode .show-section-header h5,
body.dark-mode .show-section-header h6,
body.dark-mode .show-section-header p {
    color: #ffffff !important;
}

/* Containers */
.sections-container,
.create-section-container,
.edit-section-container,
.show-section-container {
    background: var(--bg-primary) !important;
    box-shadow: var(--shadow-md) !important;
}

/* Course Info Banner */
.course-info-banner {
    background: rgba(255, 255, 255, 0.15) !important;
    backdrop-filter: blur(10px);
}

.course-meta-item {
    background: rgba(255, 255, 255, 0.1) !important;
}

/* Sections Header */
.sections-header {
    background: var(--bg-secondary) !important;
    border-bottom: 1px solid var(--border-color) !important;
}

.sections-title {
    color: var(--text-primary) !important;
}

.title-icon {
    background: var(--color-primary) !important;
}

/* Stat Items */
.stat-item {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.stat-number {
    color: var(--color-accent) !important;
}

.stat-label {
    color: var(--text-secondary) !important;
}

/* Section Cards */
.section-card {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
    box-shadow: var(--shadow-sm) !important;
}

.section-card::before {
    background: var(--color-primary) !important;
}

.section-card:hover {
    border-color: var(--color-primary) !important;
    box-shadow: var(--shadow-md) !important;
}

.section-title {
    color: var(--text-primary) !important;
}

.section-type-icon {
    background: var(--color-primary) !important;
}

/* Meta Badges */
.meta-badge {
    background: {{ hexToRgba($colorAccent, 0.15) }} !important;
    color: var(--color-accent-dark) !important;
}

body.dark-mode .meta-badge {
    background: {{ hexToRgba($colorAccent, 0.25) }} !important;
}

.badge-order {
    background: {{ hexToRgba($colorPrimary, 0.15) }} !important;
    color: var(--color-primary-dark) !important;
}

body.dark-mode .badge-order {
    background: {{ hexToRgba($colorPrimary, 0.25) }} !important;
}

.badge-type {
    background: {{ hexToRgba($colorSecondary, 0.15) }} !important;
    color: var(--color-secondary-dark) !important;
}

body.dark-mode .badge-type {
    background: {{ hexToRgba($colorSecondary, 0.25) }} !important;
}

/* Buttons - Following Centers Pattern */
/* PRIMARY BUTTONS - Detail/View Buttons */
.btn-view,
.btn-primary-group {
    background: var(--color-primary) !important;
    color: white !important;
}

.btn-view:hover,
.btn-primary-group:hover {
    background: var(--color-primary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorPrimary, 0.3) }} !important;
    color: white !important;
}

/* SECONDARY BUTTONS - Create/Edit Buttons (matching centers) */
.btn-modern,
.btn-edit,
.btn-add-new,
.btn-primary {
    background: var(--color-secondary) !important;
    color: white !important;
}

.btn-modern:hover,
.btn-edit:hover,
.btn-add-new:hover,
.btn-primary:hover {
    background: var(--color-secondary-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorSecondary, 0.3) }} !important;
    color: white !important;
    text-decoration: none !important;
}

/* NEUTRAL BUTTONS - Back/Cancel Buttons */
/* .btn-secondary,
.btn-back,
.btn-cancel-modern {
    background: var(--color-neutral) !important;
    color: white !important;
    border: none !important;
}

.btn-secondary:hover,
.btn-back:hover,
.btn-cancel-modern:hover {
    background: var(--color-neutral-dark) !important;
    box-shadow: 0 8px 20px {{ hexToRgba($colorNeutral, 0.3) }} !important;
    color: white !important;
    text-decoration: none !important;
} */

/* DANGER BUTTONS - Delete Actions */
.btn-delete {
    background: var(--color-error) !important;
    color: white !important;
}

.btn-delete:hover {
    background: #dc2626 !important;
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3) !important;
    color: white !important;
}

/* Card Action Buttons */
.btn-card {
    border: none !important;
}

/* Form Sections */
.form-section {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.form-section:hover,
.form-section.active {
    border-color: var(--color-secondary) !important;
}

.section-header .section-icon {
    background: var(--color-secondary) !important;
}

.section-title {
    color: var(--text-primary) !important;
}

/* Form Inputs */
.form-control,
.form-select,
.form-input-modern,
.form-textarea-modern {
    background: var(--bg-primary) !important;
    color: var(--text-primary) !important;
    border: 2px solid var(--border-color) !important;
}

.form-control:focus,
.form-select:focus,
.form-input-modern:focus,
.form-textarea-modern:focus {
    border-color: var(--color-secondary) !important;
    box-shadow: 0 0 0 3px {{ hexToRgba($colorSecondary, 0.1) }} !important;
}

.form-label,
.form-label-modern {
    color: var(--text-primary) !important;
}

/* Type Options */
.type-option {
    background: var(--bg-primary) !important;
    border: 2px solid var(--border-color) !important;
}

.type-option:hover,
.type-option.selected {
    border-color: var(--color-secondary) !important;
    background: {{ hexToRgba($colorSecondary, 0.05) }} !important;
}

body.dark-mode .type-option.selected {
    background: {{ hexToRgba($colorSecondary, 0.15) }} !important;
}

.type-name {
    color: var(--text-primary) !important;
}

.type-description {
    color: var(--text-secondary) !important;
}

/* Detail Sections */
.detail-section {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.detail-section:hover {
    border-color: var(--color-primary) !important;
}

.detail-header .detail-icon {
    background: var(--color-primary) !important;
}

.detail-title {
    color: var(--text-primary) !important;
}

.question-card {
    background: var(--bg-primary) !important;
    border: 1px solid var(--border-color) !important;
}
.detail-item {
    background: var(--bg-secondary) !important;
    border: 1px solid var(--border-color) !important;
}

.detail-label {
    color: var(--text-secondary) !important;
}

.detail-value {
    color: var(--text-primary) !important;
}

/* Section Hero */
.section-hero {
    background: var(--bg-secondary) !important;
    border-bottom: 3px solid var(--border-color) !important;
}

.section-type-badge {
    background: var(--color-primary) !important;
    box-shadow: 0 10px 30px {{ hexToRgba($colorPrimary, 0.3) }} !important;
}

.section-main-title {
    color: var(--text-primary) !important;
}

.badge-order {
    background: {{ hexToRgba($colorPrimary, 0.15) }} !important;
    color: var(--color-primary-dark) !important;
}

.badge-type-name {
    background: {{ hexToRgba($colorSecondary, 0.15) }} !important;
    color: var(--color-secondary-dark) !important;
}

.badge-date {
    background: {{ hexToRgba($colorAccent, 0.15) }} !important;
    color: var(--color-accent-dark) !important;
}

/* Content Display */
.content-display {
    background: var(--bg-primary) !important;
    border: 1px solid var(--border-color) !important;
}

.content-text {
    color: var(--text-secondary) !important;
}

/* Choice Items */
.choice-item {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.choice-item.correct {
    border-color: var(--color-success) !important;
    background: {{ hexToRgba($colorSuccess, 0.1) }} !important;
}

body.dark-mode .choice-item.correct {
    background: {{ hexToRgba($colorSuccess, 0.2) }} !important;
}

.choice-label {
    color: var(--text-secondary) !important;
}

.choice-text {
    color: var(--text-primary) !important;
}

/* Question Items */
.question-item {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

/* Timestamps Section */
.timestamps-section {
    background: var(--bg-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.timestamp-item {
    background: var(--bg-primary) !important;
}

.timestamp-label {
    color: var(--text-secondary) !important;
}

.timestamp-value {
    color: var(--text-primary) !important;
}

/* Form Actions */
.form-actions {
    background: var(--bg-secondary) !important;
    border-top: 1px solid var(--border-color) !important;
}

/* Section Actions Bar */
.section-actions-bar {
    background: var(--bg-secondary) !important;
    border-bottom: 1px solid var(--border-color) !important;
}

/* Empty State */
.empty-state {
    color: var(--text-secondary) !important;
}

.empty-title {
    color: var(--text-primary) !important;
}

.empty-icon {
    color: var(--border-color) !important;
}

/* Alert Messages */
.alert-info {
    background: {{ hexToRgba($colorPrimary, 0.15) }} !important;
    border: 2px solid var(--color-primary) !important;
    color: var(--color-primary-dark) !important;
}

body.dark-mode .alert-info {
    background: {{ hexToRgba($colorPrimary, 0.25) }} !important;
}

body.dark-mode .alert-success,
body.dark-mode .success-message {
    background: rgba(16, 185, 129, 0.2) !important;
    color: #6ee7b7 !important;
}

body.dark-mode .alert-warning,
body.dark-mode .warning-message {
    background: rgba(245, 158, 11, 0.2) !important;
    color: #fcd34d !important;
}

body.dark-mode .alert-danger {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #fca5a5 !important;
    border: 1px solid rgba(239, 68, 68, 0.3) !important;
}

/* Related Content Link */
.related-content-link {
    background: var(--color-primary) !important;
}

.related-content-link:hover {
    background: var(--color-primary-dark) !important;
    box-shadow: 0 5px 15px {{ hexToRgba($colorPrimary, 0.3) }} !important;
}

/* Smooth transitions for all themed elements */
* {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

/* Accent Color Elements */
.highlight-number,
.notification-badge,
.count-badge {
    background: var(--color-accent) !important;
    color: white !important;
}

.highlight-text {
    color: var(--color-accent) !important;
}

/* Icon Colors */
.info-item i,
.course-meta-item i,
.detail-icon i {
    color: inherit !important;
}

/* Drag Handle */
.drag-handle {
    color: var(--border-color) !important;
}

.drag-handle:hover {
    color: var(--color-primary) !important;
    background: {{ hexToRgba($colorPrimary, 0.05) }} !important;
}

body.dark-mode .drag-handle:hover {
    background: {{ hexToRgba($colorPrimary, 0.15) }} !important;
}

/* Ensure proper contrast in dark mode */
body.dark-mode .stat-number {
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
}

/* Card shadows in dark mode */
body.dark-mode .section-card {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
}

/* Special Override for Toggle Buttons */
.toggle-extra-choices-btn,
#toggleFileLinks {
    background: {{ hexToRgba($colorNeutral, 0.3) }} !important;
    color: var(--text-secondary) !important;
    border: 2px solid var(--border-color) !important;
}

.toggle-extra-choices-btn:hover,
#toggleFileLinks:hover {
    background: {{ hexToRgba($colorNeutral, 0.5) }} !important;
}

/* Remove Question Button */
.remove-question-btn {
    background: var(--color-error) !important;
}

.remove-question-btn:hover {
    background: #dc2626 !important;
}

@media (max-width: 768px) {
    .dark-mode-toggle {
        bottom: 20px;
        left: 20px;
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
    }
}
</style>

{{-- Dark Mode Script --}}
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check for saved dark mode preference
    const isDarkMode = localStorage.getItem('darkMode') === 'enabled';
    if (isDarkMode) {
        document.body.classList.add('dark-mode');
    }
});
</script>
