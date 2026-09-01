@extends('layouts.landing')

@section('title', __('about.title') . ' — ' . ($setting ? $setting->{'site_name_' . $locale} : 'Rokn'))
@section('meta_description', __('about.meta_description'))

@section('content')
    <div class="page-content">
        <h1>{{ __('about.heading') }}</h1>

        @if(!empty($managedBody))
            <div class="policy-content">{!! nl2br(e($managedBody)) !!}</div>
        @else

        <h2>{{ __('about.mission_title') }}</h2>
        <p>{{ __('about.mission_text') }}</p>

        <h2>{{ __('about.vision_title') }}</h2>
        <p>{{ __('about.vision_text') }}</p>

        <h2>{{ __('about.what_we_offer_title') }}</h2>
        <div class="offers-grid">
            <div class="offer-card">
                <h3>{{ __('about.offer_1_title') }}</h3>
                <p>{{ __('about.offer_1_text') }}</p>
            </div>
            <div class="offer-card">
                <h3>{{ __('about.offer_2_title') }}</h3>
                <p>{{ __('about.offer_2_text') }}</p>
            </div>
            <div class="offer-card">
                <h3>{{ __('about.offer_3_title') }}</h3>
                <p>{{ __('about.offer_3_text') }}</p>
            </div>
        </div>

        <h2>{{ __('about.audience_title') }}</h2>
        <p>{{ __('about.audience_text') }}</p>

        <h2>{{ __('about.skills_title') }}</h2>
        <div class="skills-grid" style="justify-content: flex-start;">
            @foreach(__('about.skills') as $skill)
                <span class="skill-tag">{{ $skill }}</span>
            @endforeach
        </div>
        @endif
    </div>
@endsection
