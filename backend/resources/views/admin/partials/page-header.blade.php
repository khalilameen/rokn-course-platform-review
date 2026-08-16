<div class="admin-page__header">
    <div class="admin-page__heading">
        <span class="admin-page__icon" aria-hidden="true"><i class="fa {{ $pageIcon ?? 'fa-circle-o' }}"></i></span>
        <div>
            <h1 class="admin-page__title">{{ $pageTitle }}</h1>
            @if(!empty($pageDescription))
                <p class="admin-page__description">{{ $pageDescription }}</p>
            @endif
        </div>
    </div>

    @if(!empty($pageActionUrl) && !empty($pageActionLabel))
        <div class="admin-page__actions">
            <a href="{{ $pageActionUrl }}" class="btn {{ $pageActionClass ?? 'btn-outline-primary' }}">
                @if(!empty($pageActionIcon))<i class="fa {{ $pageActionIcon }}"></i>@endif
                {{ $pageActionLabel }}
            </a>
        </div>
    @endif
</div>
