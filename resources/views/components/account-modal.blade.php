@props(['id', 'title', 'size' => null])

<div {{ $attributes->class(['modal fade account-modal']) }} id="{{ $id }}" tabindex="-1"
    aria-labelledby="{{ $id }}Title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable {{ $size ? 'modal-'.$size : '' }}">
        <div class="modal-content">
            <div class="modal-header">
                @isset($header)
                    {{ $header }}
                @else
                    <h2 class="modal-title fs-5" id="{{ $id }}Title">{{ $title }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                @endisset
            </div>
            {{ $slot }}
        </div>
    </div>
</div>
