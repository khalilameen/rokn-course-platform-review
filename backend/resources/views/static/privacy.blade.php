@extends('layouts.landing')

@section('title', __('privacy.title') . ' — ' . ($setting ? $setting->{'site_name_' . $locale} : 'Rokn'))
@section('meta_description', __('privacy.meta_description'))

@section('content')
    <div class="page-content">
        <h1>{{ __('privacy.heading') }}</h1>
        <div class="policy-content">
            @if(is_array(__('privacy.sections')))
                <div class="terms-header">
                    <p class="terms-brand">{{ __('privacy.brand') }}</p>
                    <p class="terms-version">{{ __('privacy.version_label') }} {{ __('privacy.version') }}</p>
                </div>

                <h2>{{ __('privacy.intro_title') }}</h2>
                <p>{{ __('privacy.intro_text') }}</p>

                @foreach(__('privacy.sections') as $section)
                    <h2>{{ $section['title'] ?? '' }}</h2>

                    @if(!empty($section['body']))
                        @if(is_array($section['body']))
                            @foreach($section['body'] as $bodyParagraph)
                                <p>{{ $bodyParagraph }}</p>
                            @endforeach
                        @else
                            <p>{{ $section['body'] }}</p>
                        @endif
                    @endif

                    @if(!empty($section['points']) && is_array($section['points']))
                        <ul>
                            @foreach($section['points'] as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach

                <hr class="terms-divider">
                <p class="terms-meta">{{ __('privacy.last_updated') }}</p>
                <p class="terms-meta">{{ __('privacy.copyright') }}</p>
            @else
                <p>{{ __('privacy.fallback') }}</p>
            @endif
        </div>
    </div>
@endsection
