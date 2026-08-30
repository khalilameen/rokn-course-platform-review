@extends('layouts.landing')

@php
    $pageTitle = $setting ? ($setting->{'seo_meta_title_' . $locale} ?: ($setting->{'site_name_' . $locale} ?? 'Rokn')) : 'Rokn';
@endphp

@section('title', $pageTitle)

@if($setting && $setting->{'seo_meta_description_' . $locale})
    @section('meta_description', $setting->{'seo_meta_description_' . $locale})
@endif

@section('content')
    {{-- HERO SECTION --}}
    @php
        $hasDownloads = collect($downloadChannels ?? [])->filter()->isNotEmpty();
    @endphp
    <section class="landing-hero">
        <div class="landing-container hero-content">
            <h1>{{ $designSetting->{'slogan_1_' . $locale} ?? $siteName }}</h1>
            <p class="hero-slogan">{{ $designSetting->{'slogan_2_' . $locale} ?? '' }}</p>
            <p class="hero-description">{{ __('landing.hero_description') }}</p>

            @if($hasDownloads)
                <p class="available-on-text">{{ __('landing.available_on') }}</p>
                @include('landing.partials.download-buttons')
            @endif
        </div>
    </section>

    {{-- FEATURES SECTION --}}
    <section class="landing-features">
        <div class="landing-container">
            <h2 class="section-title">{{ __('landing.features_title') }}</h2>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></div>
                    <h3>{{ __('landing.feature_1_title') }}</h3>
                    <p>{{ __('landing.feature_1_desc') }}</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg></div>
                    <h3>{{ __('landing.feature_2_title') }}</h3>
                    <p>{{ __('landing.feature_2_desc') }}</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg></div>
                    <h3>{{ __('landing.feature_3_title') }}</h3>
                    <p>{{ __('landing.feature_3_desc') }}</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg></div>
                    <h3>{{ __('landing.feature_4_title') }}</h3>
                    <p>{{ __('landing.feature_4_desc') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Educational paths preview. Published catalog data replaces these cards. --}}
    <section class="landing-paths">
        <div class="landing-container">
            <h2 class="section-title">المسارات التعليمية<br><small style="font-size: 1.1rem; color: var(--rokn-text-muted); font-weight: 400; display: block; margin-top: 10px;">اختر مهارتك وانطلق<br>كل مسار مصمم لجعلك جاهزاً لسوق العمل في أقصر وقت ممكن.</small></h2>
            
            <div class="paths-grid">
                {{-- Path 1 --}}
                <div class="path-card">
                    <span class="path-category">تسويق رقمي</span>
                    <h3>احتراف إعلانات ميتا</h3>
                    <p>تعلم إطلاق حملات إعلانية ناجحة على فيسبوك وانستجرام من الصفر.</p>
                    <div class="path-footer">
                        <span class="path-price">599 ج.م</span>
                        <button onclick="openAppModal(event)" class="path-btn" style="border: none; cursor: pointer; font-family: inherit;">احصل عليه</button>
                    </div>
                </div>

                {{-- Path 2 --}}
                <div class="path-card">
                    <span class="path-category">صناعة محتوى</span>
                    <h3>مونتاج الريلز المتقدم</h3>
                    <p>أسرار الاحتفاظ بالمشاهد والتعديل السريع باستخدام الهاتف.</p>
                    <div class="path-footer">
                        <span class="path-price">399 ج.م</span>
                        <button onclick="openAppModal(event)" class="path-btn" style="border: none; cursor: pointer; font-family: inherit;">احصل عليه</button>
                    </div>
                </div>

                {{-- Path 3 --}}
                <div class="path-card">
                    <span class="path-category">تجارة إلكترونية</span>
                    <h3>إدارة المتاجر الإلكترونية</h3>
                    <p>كيف تدير متجرك، ترفع المنتجات، وتتعامل مع طلبات العملاء.</p>
                    <div class="path-footer">
                        <span class="path-price">449 ج.م</span>
                        <button onclick="openAppModal(event)" class="path-btn" style="border: none; cursor: pointer; font-family: inherit;">احصل عليه</button>
                    </div>
                </div>
            </div>

            <div class="paths-cta">
                <h3>مستعد لبدء رحلتك؟</h3>
                <p>حمّل التطبيق الآن، افتح مسارك الأول، واحصل على 20 عملة ركن كهدية ترحيبية.</p>
               
                @include('landing.partials.download-buttons')
               
            </div>
        </div>
    </section>

    {{-- SKILLS SECTION --}}
    <section class="landing-skills">
        <div class="landing-container">
            <h2 class="section-title">{{ __('landing.skills_title') }}</h2>
            <div class="skills-grid">
                @foreach(__('landing.skills') as $skill)
                    <span class="skill-tag">{{ $skill }}</span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- HOW IT WORKS SECTION --}}
    @if($designSetting->show_how_platform_works)
        <section class="landing-how-it-works">
            <div class="landing-container">
                @php $howTitle = $designSetting->{'how_platform_works_title_' . $locale}; @endphp
                <h2 class="section-title">{{ $howTitle ?: __('landing.how_it_works_default_title') }}</h2>
                @if($designSetting->how_platform_works_video_link)
                    <div class="video-wrapper">
                        <iframe src="{{ $designSetting->how_platform_works_video_link }}" allowfullscreen loading="lazy" title="{{ $howTitle ?: __('landing.how_it_works_default_title') }}"></iframe>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- APP MODAL --}}
    <div id="appModal" class="app-modal-overlay" style="display: none;">
        <div class="app-modal-content">
            <button class="app-modal-close" onclick="closeAppModal()">&times;</button>
            <h3>حمّل التطبيق الآن</h3>
            <p style="color: var(--rokn-text-muted); margin-bottom: 1.5rem;">اختر منصتك المفضلة لتحميل التطبيق والبدء.</p>
            @include('landing.partials.download-buttons', ['vertical' => true])
        </div>
    </div>

    <script>
        function openAppModal(e) {
            e.preventDefault();
            document.getElementById('appModal').style.display = 'flex';
        }
        function closeAppModal() {
            document.getElementById('appModal').style.display = 'none';
        }
        window.onclick = function(event) {
            var modal = document.getElementById('appModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
@endsection
