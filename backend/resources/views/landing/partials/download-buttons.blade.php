@php
    $vertical = (bool) ($vertical ?? false);
    $buttonStyle = $vertical ? 'width: 100%; justify-content: center;' : '';
    $discountLabel = rtrim(rtrim(number_format((float) ($directDiscountPercent ?? 0), 2, '.', ''), '0'), '.');
@endphp

@if(collect($downloadChannels ?? [])->filter()->isNotEmpty())
    <div class="store-buttons{{ $vertical ? ' store-buttons--vertical' : '' }}">
        @if($downloadChannels['play'] ?? null)
            <a href="{{ $downloadChannels['play'] }}" target="_blank" rel="noopener noreferrer" class="store-btn" style="{{ $buttonStyle }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 2.5v19l10.7-9.5L3 2.5zm12.2 8.2 2.8-2.5L6.6 1.9l8.6 8.8zm0 2.6-8.6 8.8L18 15.8l-2.8-2.5zm1.5-1.3 3.5 2c1 .6 1 1.4 0 2l-3.5-3 3.5-3c1 .6 1 1.4 0 2l-3.5 2z"/></svg>
                Google Play
            </a>
        @endif
        @if($downloadChannels['appstore'] ?? null)
            <a href="{{ $downloadChannels['appstore'] }}" target="_blank" rel="noopener noreferrer" class="store-btn" style="{{ $buttonStyle }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.7 19.5c-.8 1.2-1.7 2.5-3 2.5s-1.8-.8-3.3-.8-2 .8-3.3.8c-1.3.1-2.3-1.3-3.1-2.5-1.7-2.5-3-7-.3-10.1.9-1.5 2.4-2.5 4.1-2.5 1.3 0 2.5.9 3.3.9s2.3-1.1 3.8-.9c.7 0 2.5.3 3.6 2-.1.1-2.2 1.3-2.1 3.8 0 3 2.6 4 2.7 4-.1.1-.5 1.5-1.4 2.8zM13 3.5c.7-.8 1.9-1.5 2.9-1.5.1 1.2-.3 2.4-1 3.2-.7.9-1.8 1.5-3 1.4-.1-1.1.5-2.3 1.1-3.1z"/></svg>
                App Store
            </a>
        @endif
        @if($downloadChannels['direct'] ?? null)
            <a href="{{ $downloadChannels['direct'] }}" class="store-btn direct-download-btn" style="{{ $buttonStyle }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.6 9.5 19 8.1a.8.8 0 0 0-1.1-1.1l-1.6 1.6A8 8 0 0 0 12 7.3c-1.6 0-3.1.5-4.3 1.3L6.1 7A.8.8 0 1 0 5 8.1l1.4 1.4A7.7 7.7 0 0 0 4 15h16a7.7 7.7 0 0 0-2.4-5.5zM8 13a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm8 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM4 16h16v1a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-1z"/></svg>
                <span>
                    Android مباشر
                    @if((float) ($directDiscountPercent ?? 0) > 0)
                        <small>وفر {{ $discountLabel }}٪ على باقات الشحن</small>
                    @endif
                </span>
            </a>
        @endif
    </div>
@endif
