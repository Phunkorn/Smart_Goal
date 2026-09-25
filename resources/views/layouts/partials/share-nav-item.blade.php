{{--
    เมนู "แชร์งาน" บนแถบข้าง — ใช้ร่วมกันทั้งเมนูของพนักงาน/หัวหน้า และเมนูของ admin

    ป้ายตัวเลขสองอัน ค่ามาจาก AppServiceProvider (view composer ของ layouts.app):
    - data-share-feed-count     งานที่แชร์อยู่และเรากดขอเข้าร่วมได้ (เท่ากับการ์ดในแท็บฟีด)
                                เป็นแค่ตัวนับบนเมนู ไม่ได้สร้างการแจ้งเตือน
    - data-share-request-count  คำขอร่วมงานที่รอเราตัดสินในฐานะผู้แชร์
--}}
<a href="{{ route('shares.index') }}"
    class="nav-item {{ request()->routeIs('shares.*') ? 'active' : '' }}">
    <i class="bi bi-share"></i>
    <span class="nav-item__label">แชร์งาน</span>
    @if(($shareFeedCount ?? 0) > 0)
        <span class="nav-item__count" data-share-feed-count
            title="งานที่แชร์เปิดรับ {{ $shareFeedCount }} งาน"
            aria-label="งานที่แชร์เปิดรับ {{ $shareFeedCount }} งาน">{{ $shareFeedCount > 99 ? '99+' : $shareFeedCount }}</span>
    @endif
    @if(($shareRequestCount ?? 0) > 0)
        <span class="nav-item__count nav-item__count--request" data-share-request-count
            title="คำขอร่วมงานรอคุณอนุมัติ {{ $shareRequestCount }} รายการ"
            aria-label="คำขอร่วมงานรอคุณอนุมัติ {{ $shareRequestCount }} รายการ">{{ $shareRequestCount }}</span>
    @endif
</a>
