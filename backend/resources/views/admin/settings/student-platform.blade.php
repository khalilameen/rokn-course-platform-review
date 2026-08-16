@extends('admin.layouts.app')
@section('page.title', 'منصة الطالب')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/settings-student-platform.css') }}">
@endsection

@section('content')

<div class="admin-page student-platform-wrapper">
    <!-- Animated background particles -->
    <div class="particles" id="particles"></div>

    <!-- Main container -->
    <div class="platform-container">
        <div class="decoration decoration-1"></div>
        <div class="decoration decoration-2"></div>

        <div class="logo-container">
            <div class="logo">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
            </div>
        </div>

        <h1 class="platform-title">مرحباً بك في {{ $designSettings->name_ar ?? 'منصة التعليم' }}</h1>
        <p class="subtitle">هذا هو رابط منصتك التعليمية. يمكنك مشاركة هذا الرابط مع طلابك للوصول إلى الدورات التعليمية، ومتابعة تقدمهم الدراسي، والتواصل معك.</p>

        <div class="url-display">
            <div class="url-label">رابط {{ $designSettings->name_ar ?? 'منصة الطالب' }}</div>
            <div class="url-box">
                <div id="urlText" class="url-text">
                    @if(isset($platformUrl))
                        {{ $platformUrl }}
                    @else
                        <div class="loading-spinner"></div>
                    @endif
                </div>
            </div>
        </div>

        <div class="error-message" id="errorMessage"></div>

        <div class="action-buttons">
            <a href="{{ $platformUrl ?? '#' }}" id="visitBtn" class="platform-btn btn-primary-custom {{ isset($platformUrl) ? '' : 'btn-hidden' }}" target="_blank">
                <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/>
                </svg>
                زيارة المنصة
            </a>
            <button id="copyBtn" class="platform-btn btn-secondary-custom {{ isset($platformUrl) ? '' : 'btn-hidden' }}" data-url="{{ $platformUrl ?? '' }}">
                <svg class="btn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                </svg>
                نسخ الرابط
            </button>
        </div>
    </div>

    <!-- Copy notification -->
    <div class="copy-notification" id="copyNotification">
        <svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
        </svg>
        تم نسخ الرابط!
    </div>
</div>

<script>
    // Create animated particles
    function createParticles() {
        const particles = document.getElementById('particles');
        const particleCount = 30;

        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';

            const size = Math.random() * 60 + 20;
            particle.style.width = size + 'px';
            particle.style.height = size + 'px';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.top = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 15 + 's';
            particle.style.animationDuration = (Math.random() * 10 + 10) + 's';

            particles.appendChild(particle);
        }
    }

    // Copy URL to clipboard
    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            showCopyNotification();
        } catch (err) {
            // Fallback for older browsers
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.className = 'clipboard-fallback';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            showCopyNotification();
        }
    }

    // Show copy notification
    function showCopyNotification() {
        const notification = document.getElementById('copyNotification');
        notification.classList.add('show');

        setTimeout(() => {
            notification.classList.remove('show');
        }, 3000);
    }

    // Event listeners
    document.getElementById('copyBtn').addEventListener('click', function() {
        const url = this.dataset.url;
        if (url) {
            copyToClipboard(url);
        }
    });

    // Initialize
    createParticles();
</script>
@endsection
