@php
    $categoryIcons = [
        'task' => 'bi-clipboard-plus',
        'worklog' => 'bi-arrow-repeat',
        'comment' => 'bi-chat-dots',
        'review' => 'bi-patch-check',
        'meeting' => 'bi-calendar-event',
        'deadline' => 'bi-alarm',
        'system' => 'bi-bell',
    ];
@endphp
<i class="bi {{ $categoryIcons[$category] ?? $categoryIcons['system'] }}" aria-hidden="true"></i>
