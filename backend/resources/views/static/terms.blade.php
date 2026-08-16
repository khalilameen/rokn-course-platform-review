@extends('layouts.landing')

@section('title', __('terms.title') . ' — ' . ($setting ? $setting->{'site_name_' . $locale} : 'Rokn'))
@section('meta_description', __('terms.meta_description'))

@section('content')
    <div class="page-content">
        <h1>{{ __('terms.heading') }}</h1>

        <div class="policy-content">
            @if(is_array(__('terms.sections')))
                <div class="terms-header">
                    <p class="terms-brand">{{ __('terms.brand') }}</p>
                    <p class="terms-version">{{ __('terms.version_label') }} {{ __('terms.version') }}</p>
                </div>

                <h2>{{ __('terms.intro_title') }}</h2>
                <p>{{ __('terms.intro_text') }}</p>

                @foreach(__('terms.sections') as $section)
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

                <p class="terms-final-note">{{ __('terms.final_acknowledgement') }}</p>
                <p class="terms-meta">{{ __('terms.last_updated') }}</p>
                <p class="terms-meta">{{ __('terms.copyright') }}</p>
            @else
                <h2>{{ __('terms.general_title') }}</h2>
                <p>{{ __('terms.general_text') }}</p>

                <h2>{{ __('terms.user_responsibilities_title') }}</h2>
                <p>{{ __('terms.user_responsibilities_text') }}</p>

                <h2>{{ __('terms.intellectual_property_title') }}</h2>
                <p>{{ __('terms.intellectual_property_text') }}</p>

                <h2>{{ __('terms.liability_title') }}</h2>
                <p>{{ __('terms.liability_text') }}</p>

                <h2>{{ __('terms.governing_law_title') }}</h2>
                <p>{{ __('terms.governing_law_text') }}</p>
            @endif
        </div>
    </div>
@endsection
