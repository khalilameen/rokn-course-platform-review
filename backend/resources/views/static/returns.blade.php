@extends('layouts.landing')

@section('title', __('returns.title') . ' — ' . ($setting ? $setting->{'site_name_' . $locale} : 'Rokn'))
@section('meta_description', __('returns.meta_description'))

@section('content')
    <div class="page-content">
        <h1>{{ __('returns.heading') }}</h1>

        <div class="policy-content">
            @if(is_array(__('returns.sections')))
                <div class="terms-header">
                    <p class="terms-brand">{{ __('returns.brand') }}</p>
                    <p class="terms-version">{{ __('returns.version_label') }} {{ __('returns.version') }}</p>
                </div>

                <h2>1. {{ __('returns.intro_title') }}</h2>
                <p>{{ __('returns.intro_text') }}</p>

                @foreach(__('returns.sections') as $section)
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

                    @if(!empty($section['footer']))
                        <p>{{ $section['footer'] }}</p>
                    @endif
                @endforeach

                <hr class="terms-divider">
                <p class="terms-meta">{{ __('returns.last_updated') }}</p>
                <p class="terms-meta">{{ __('returns.copyright') }}</p>
            @else
                <p>{{ __('returns.fallback') }}</p>
            @endif
        </div>
    </div>
@endsection
