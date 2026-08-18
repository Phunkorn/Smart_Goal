@if(filled($adminSenderName ?? null))
    <span class="admin-assignment-marker" data-admin-assignment-marker="{{ $adminSenderName }}">
        <i class="bi bi-shield-check" aria-hidden="true"></i>มอบหมายโดย {{ $adminSenderName }}
    </span>
@endif
