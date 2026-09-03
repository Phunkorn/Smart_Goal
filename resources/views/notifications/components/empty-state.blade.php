<div class="notification-center__empty" data-notification-empty>
    @if(($status ?? 'all') === 'unread' && ! ($hasAdvancedFilters ?? false))
        <span class="notification-center__empty-icon is-success"><i class="bi bi-check2" aria-hidden="true"></i></span>
        <strong>อ่านครบแล้ว</strong>
        <span>ตอนนี้ไม่มีรายการใหม่ที่ต้องติดตาม</span>
        <a class="btn btn-outline-primary btn-sm" href="{{ route('notifications.index') }}">ดูการแจ้งเตือนทั้งหมด</a>
    @elseif($hasAdvancedFilters ?? false)
        <span class="notification-center__empty-icon"><i class="bi bi-funnel" aria-hidden="true"></i></span>
        <strong>ไม่พบรายการที่ตรงกับตัวกรอง</strong>
        <span>ลองเลือกประเภทหรือโปรเจกต์อื่น</span>
        <a class="btn btn-outline-primary btn-sm" href="{{ route('notifications.index', ($status ?? 'all') === 'unread' ? ['status' => 'unread'] : []) }}">ล้างตัวกรอง</a>
    @else
        <span class="notification-center__empty-icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
        <strong>ยังไม่มีการแจ้งเตือน</strong>
        <span>ความเคลื่อนไหวและรายการที่ต้องติดตามจะแสดงที่นี่</span>
    @endif
</div>
