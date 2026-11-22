{{--
    ป้ายตัวเลขบนเมนู — hooks คือ attribute ที่สคริปต์เดิมใช้หาป้าย (data-notification-count ของ realtime-sync.js,
    data-approval-count, data-share-request-count) ค่ามาจาก App\Support\TopNavigation ไม่ใช่ข้อมูลผู้ใช้
--}}
<span class="top-nav__badge top-nav__badge--{{ $badge['tone'] }}" {{ implode(' ', $badge['hooks']) }}@if($badge['label']) title="{{ $badge['label'] }}" aria-label="{{ $badge['label'] }}"@endif @if($badge['hidden']) hidden @endif>{{ $badge['display'] }}</span>
