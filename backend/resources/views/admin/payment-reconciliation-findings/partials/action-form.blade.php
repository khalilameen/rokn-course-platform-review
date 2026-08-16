<form method="POST" action="{{ $actionRoute }}" class="admin-review-action mb-3">
    @csrf
    @method('PATCH')
    <div class="form-group mb-2">
        <label for="{{ $noteId }}" class="sr-only">{{ $noteLabel }}</label>
        <textarea
            id="{{ $noteId }}"
            name="note"
            rows="2"
            minlength="3"
            maxlength="2000"
            required
            class="form-control"
            placeholder="{{ $notePlaceholder }}"
        ></textarea>
    </div>
    <button type="submit" class="btn btn-sm {{ $actionClass }}">
        <i class="fa {{ $actionIcon }}"></i> {{ $actionLabel }}
    </button>
</form>
