{{-- Dynamic Styles Component for Course PDFs Pages --}}
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
    if (!function_exists('adjustBrightnessPdf')) {
        function adjustBrightnessPdf($hex, $percent) {
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

    if (!function_exists('hexToRgbaPdf')) {
        function hexToRgbaPdf($hex, $alpha = 1) {
            $hex = str_replace('#', '', $hex);
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return "rgba($r, $g, $b, $alpha)";
        }
    }

    // Generate variations for each color
    $colorPrimaryLight = adjustBrightnessPdf($colorPrimary, 30);
    $colorPrimaryDark = adjustBrightnessPdf($colorPrimary, -20);

    $colorSecondaryLight = adjustBrightnessPdf($colorSecondary, 30);
    $colorSecondaryDark = adjustBrightnessPdf($colorSecondary, -20);

    $colorAccentLight = adjustBrightnessPdf($colorAccent, 30);
    $colorAccentDark = adjustBrightnessPdf($colorAccent, -20);
@endphp

<style id="dynamic-theme-styles-pdfs">
:root {
    /* PRIMARY COLOR (Brand Identity) */
    --color-primary: {{ $colorPrimary }};
    --color-primary-light: {{ $colorPrimaryLight }};
    --color-primary-dark: {{ $colorPrimaryDark }};
    --color-primary-darker: {{ adjustBrightnessPdf($colorPrimary, -35) }};
    --color-primary-transparent: {{ hexToRgbaPdf($colorPrimary, 0.1) }};
    --color-primary-10: {{ hexToRgbaPdf($colorPrimary, 0.1) }};
    --color-primary-20: {{ hexToRgbaPdf($colorPrimary, 0.2) }};
    --color-primary-30: {{ hexToRgbaPdf($colorPrimary, 0.3) }};

    /* SECONDARY COLOR */
    --color-secondary: {{ $colorSecondary }};
    --color-secondary-light: {{ $colorSecondaryLight }};
    --color-secondary-dark: {{ $colorSecondaryDark }};
    --color-secondary-darker: {{ adjustBrightnessPdf($colorSecondary, -35) }};
    --color-secondary-transparent: {{ hexToRgbaPdf($colorSecondary, 0.1) }};
    --color-secondary-10: {{ hexToRgbaPdf($colorSecondary, 0.1) }};

    /* NEUTRAL COLOR */
    --color-neutral: {{ $colorNeutral }};

    /* ACCENT COLOR */
    --color-accent: {{ $colorAccent }};
    --color-accent-light: {{ $colorAccentLight }};
    --color-accent-dark: {{ $colorAccentDark }};
    --color-accent-darker: {{ adjustBrightnessPdf($colorAccent, -35) }};
    --color-accent-transparent: {{ hexToRgbaPdf($colorAccent, 0.1) }};

    /* FEEDBACK COLORS (Fixed) */
    --color-success: {{ $colorSuccess }};
    --color-success-light: {{ adjustBrightnessPdf($colorSuccess, 80) }};
    --color-success-dark: {{ adjustBrightnessPdf($colorSuccess, -20) }};
    --color-success-bg: {{ hexToRgbaPdf($colorSuccess, 0.8) }};
    
    --color-warning: {{ $colorWarning }};
    --color-warning-light: {{ adjustBrightnessPdf($colorWarning, 80) }};
    --color-warning-dark: {{ adjustBrightnessPdf($colorWarning, -20) }};
    
    --color-danger: {{ $colorError }};
    --color-danger-light: {{ adjustBrightnessPdf($colorError, 80) }};
    --color-danger-dark: {{ adjustBrightnessPdf($colorError, -20) }};

    --color-info: #4299e1;
    --color-info-dark: #3182ce;

    /* Light Mode Variables */
    --bg-primary: #ffffff;
    --bg-light: #f8f9fa;
    --bg-muted: #edf2f7;
    --bg-secondary: #f8f9fa;
    --bg-tertiary: #e9ecef;
    --card-bg: #ffffff;
    --input-bg: #ffffff;
    --text-primary: #2d3748;
    --text-secondary: #4a5568;
    --text-muted: #718096;
    --text-tertiary: #a0aec0;
    --border-color: #e2e8f0;
    --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
    --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.1);
    --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.15);
}

/* Dark Mode Variables */
body.dark-mode {
    --bg-primary: #1a202c;
    --bg-light: #2d3748;
    --bg-muted: #4a5568;
    --bg-secondary: #2d3748;
    --bg-tertiary: #4a5568;
    --card-bg: #2d3748;
    --input-bg: #1a202c;
    --text-primary: #f7fafc;
    --text-secondary: #e2e8f0;
    --text-muted: #cbd5e0;
    --text-tertiary: #a0aec0;
    --border-color: #4a5568;
    --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.3);
    --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.5);
}

/* ============================================
   PAGE BODY BACKGROUND
   ============================================ */
/* Override default body background for PDF admin pages */
body {
    background: var(--bg-muted) !important;
}

body.dark-mode {
    background: #111827 !important;
}

.pdf-wrapper {
    min-height: 100vh;
    padding: 2rem;
    background: var(--bg-muted);
}

body.dark-mode .pdf-wrapper {
    background: #111827;
}

.form-wrapper {
    min-height: 100vh;
    padding: 2rem;
    background: var(--bg-muted);
}

body.dark-mode .form-wrapper {
    background: #111827;
}

/* ============================================
   PDF MANAGEMENT HEADER STYLES
   ============================================ */
.pdf-management-header {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%) !important;
    color: white;
    border-radius: 20px 20px 0 0;
    padding: 2rem;
    position: relative;
    overflow: hidden;
}

.pdf-management-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
}

/* Form Header (Create/Edit) */
.form-header {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%) !important;
    color: white;
    border-radius: 20px 20px 0 0;
    padding: 2rem;
    position: relative;
    overflow: hidden;
}

.form-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
}

/* Course Info Banner */
.course-info-banner {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 1.5rem;
    margin-top: 1.5rem;
}

/* ============================================
   BUTTON STYLES
   ============================================ */
.btn-modern {
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    border: 2px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.1);
    color: white;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-modern:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.5);
    transform: translateY(-2px);
    color: white;
    text-decoration: none;
}

.btn-modern.btn-success {
    background: {{ hexToRgbaPdf($colorSecondary, 0.8) }};
    border-color: {{ hexToRgbaPdf($colorSecondary, 0.5) }};
}

.btn-modern.btn-success:hover {
    background: {{ $colorSecondary }};
}

.btn-primary-gradient {
    background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%) !important;
    border: none !important;
    color: white !important;
    box-shadow: 0 4px 15px var(--color-primary-30) !important;
}

.btn-primary-gradient:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px var(--color-primary-30) !important;
}

.btn-secondary-gradient {
    background: linear-gradient(135deg, var(--color-secondary) 0%, var(--color-secondary-dark) 100%) !important;
    border: none !important;
    color: white !important;
}

/* ============================================
   CARD STYLES
   ============================================ */
.pdf-card {
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.pdf-card:hover {
    border-color: var(--color-primary);
    box-shadow: 0 10px 30px var(--color-primary-10);
}

.pdf-icon {
    background: linear-gradient(135deg, var(--color-danger) 0%, #b91c1c 100%);
}

/* ============================================
   FORM STYLES
   ============================================ */
.form-container {
    background: var(--bg-primary);
    border-radius: 0 0 20px 20px;
    box-shadow: var(--shadow-md);
    padding: 2rem;
}

.form-label {
    color: var(--text-primary);
}

.form-label .required {
    color: var(--color-danger);
}

.form-control {
    background: var(--bg-secondary);
    border: 2px solid var(--border-color);
    color: var(--text-primary);
}

.form-control:focus {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px var(--color-primary-10) !important;
    background: var(--bg-primary);
}

.form-control::placeholder {
    color: var(--text-tertiary);
}

/* File Upload Zone */
.upload-zone {
    border: 2px dashed var(--border-color);
    background: var(--bg-secondary);
}

.upload-zone:hover,
.upload-zone.dragover {
    border-color: var(--color-primary);
    background: var(--color-primary-10);
}

.upload-icon {
    color: var(--color-primary);
}

/* Current File Info */
.current-file-info {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
}

.current-file-icon {
    background: linear-gradient(135deg, var(--color-danger) 0%, #b91c1c 100%);
}

/* ============================================
   TABLE STYLES
   ============================================ */
.table-container {
    background: var(--bg-primary);
    border-radius: 0 0 20px 20px;
    box-shadow: var(--shadow-md);
}

.pdf-table {
    color: var(--text-primary);
}

.pdf-table th {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
}

.pdf-table td {
    border-bottom: 1px solid var(--border-color);
}

.pdf-table tbody tr:hover {
    background: var(--color-primary-10);
}

/* ============================================
   STATUS BADGES
   ============================================ */
.status-badge.active {
    background: var(--color-secondary-10);
    color: var(--color-success);
}

.status-badge.inactive {
    background: {{ hexToRgbaPdf($colorError, 0.1) }};
    color: var(--color-danger);
}

/* ============================================
   ACTION BUTTONS
   ============================================ */
.action-btn.btn-info {
    background: var(--color-primary-10);
    color: var(--color-primary);
}

.action-btn.btn-info:hover {
    background: var(--color-primary);
    color: white;
}

.action-btn.btn-warning {
    background: {{ hexToRgbaPdf($colorWarning, 0.1) }};
    color: var(--color-warning);
}

.action-btn.btn-warning:hover {
    background: var(--color-warning);
    color: white;
}

.action-btn.btn-danger {
    background: {{ hexToRgbaPdf($colorError, 0.1) }};
    color: var(--color-danger);
}

.action-btn.btn-danger:hover {
    background: var(--color-danger);
    color: white;
}

.action-btn.btn-success {
    background: var(--color-secondary-10);
    color: var(--color-success);
}

.action-btn.btn-success:hover {
    background: var(--color-success);
    color: white;
}

/* ============================================
   EMPTY STATE
   ============================================ */
.empty-state {
    background: var(--bg-secondary);
    color: var(--text-secondary);
}

.empty-icon {
    color: var(--text-tertiary);
}

.empty-state .btn-success {
    background: linear-gradient(135deg, var(--color-secondary) 0%, var(--color-secondary-dark) 100%);
}

/* ============================================
   ALERTS & MESSAGES
   ============================================ */
.alert-success {
    background: var(--color-secondary-10);
    border: 1px solid {{ hexToRgbaPdf($colorSecondary, 0.3) }};
    color: var(--color-success);
}

.alert-danger {
    background: {{ hexToRgbaPdf($colorError, 0.1) }};
    border: 1px solid {{ hexToRgbaPdf($colorError, 0.3) }};
    color: var(--color-danger);
}

/* ============================================
   PROGRESS BAR
   ============================================ */
.progress-bar {
    background: linear-gradient(90deg, var(--color-primary), var(--color-primary-light));
}

/* ============================================
   MODAL STYLES
   ============================================ */
.modal-content {
    background: var(--bg-primary);
    color: var(--text-primary);
}

.modal-header {
    border-bottom-color: var(--border-color);
}

.modal-footer {
    border-top-color: var(--border-color);
}

/* ============================================
   DARK MODE SPECIFIC OVERRIDES
   ============================================ */
body.dark-mode .pdf-management-header,
body.dark-mode .form-header {
    background: linear-gradient(135deg, var(--color-primary-dark) 0%, var(--color-primary) 100%) !important;
}

body.dark-mode .form-container,
body.dark-mode .table-container,
body.dark-mode .pdf-card {
    background: var(--bg-primary);
    border-color: var(--border-color);
}

body.dark-mode .form-control {
    background: var(--bg-tertiary);
    border-color: var(--border-color);
    color: var(--text-primary);
}

body.dark-mode .form-control:focus {
    background: var(--bg-secondary);
}

body.dark-mode .pdf-table th {
    background: var(--bg-tertiary);
}

body.dark-mode .upload-zone {
    background: var(--bg-tertiary);
    border-color: var(--border-color);
}

body.dark-mode .current-file-info {
    background: var(--bg-tertiary);
}

body.dark-mode .empty-state {
    background: var(--bg-tertiary);
}

body.dark-mode .course-info-banner {
    background: rgba(0, 0, 0, 0.2);
}

body.dark-mode .course-meta-item {
    background: rgba(255, 255, 255, 0.1);
}

body.dark-mode .btn-modern {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.2);
}

/* ============================================
   TRANSITIONS
   ============================================ */
.pdf-management-header,
.form-header,
.form-container,
.table-container,
.pdf-card,
.form-control,
.btn-modern,
.action-btn,
.status-badge {
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}
</style>
