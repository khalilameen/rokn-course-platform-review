<link rel="stylesheet" href="{{ asset('admin/assets/css/exam-results-theme.css') }}">
<meta name="admin-identity-theme"
      content="{{ $designSettings->color_1 ?? '#2563eb' }}"
      data-secondary="{{ $designSettings->color_2 ?? '#16a34a' }}"
      data-neutral="{{ $designSettings->color_3 ?? '#f5f7fa' }}"
      data-accent="{{ $designSettings->color_4 ?? '#f97316' }}">

<script>
document.addEventListener('DOMContentLoaded', function() {
    const theme = document.querySelector('meta[name="admin-identity-theme"]');
    const root = document.documentElement;

    function normalizeHex(value, fallback) {
        return /^#[0-9a-f]{6}$/i.test(value || '') ? value : fallback;
    }

    function adjustBrightness(hex, percent) {
        const number = parseInt(hex.slice(1), 16);
        const factor = 1 + (percent / 100);
        const red = Math.max(0, Math.min(255, Math.round(((number >> 16) & 255) * factor)));
        const green = Math.max(0, Math.min(255, Math.round(((number >> 8) & 255) * factor)));
        const blue = Math.max(0, Math.min(255, Math.round((number & 255) * factor)));
        return '#' + [red, green, blue].map(channel => channel.toString(16).padStart(2, '0')).join('');
    }

    function rgbChannels(hex) {
        const number = parseInt(hex.slice(1), 16);
        return [(number >> 16) & 255, (number >> 8) & 255, number & 255].join(', ');
    }

    if (theme) {
        const colors = {
            primary: normalizeHex(theme.content, '#2563eb'),
            secondary: normalizeHex(theme.dataset.secondary, '#16a34a'),
            neutral: normalizeHex(theme.dataset.neutral, '#f5f7fa'),
            accent: normalizeHex(theme.dataset.accent, '#f97316')
        };

        Object.entries(colors).forEach(([role, color]) => {
            root.style.setProperty('--color-' + role, color);
            root.style.setProperty('--color-' + role + '-light', adjustBrightness(color, 30));
            root.style.setProperty('--color-' + role + '-dark', adjustBrightness(color, -20));
            root.style.setProperty('--admin-identity-' + role + '-rgb', rgbChannels(color));
        });
    }

    document.querySelectorAll('[data-progress-value]').forEach(progressBar => {
        const value = Math.min(100, Math.max(0, Number(progressBar.dataset.progressValue) || 0));
        progressBar.style.width = value + '%';
    });

    if (localStorage.getItem('darkMode') === 'enabled') {
        document.body.classList.add('dark-mode');
    }
});
</script>
