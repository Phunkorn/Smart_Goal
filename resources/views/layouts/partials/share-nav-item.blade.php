{{--
    เมนู "แชร์งาน" บนแถบข้าง — ใช้ร่วมกันทั้งเมนูของพนักงาน/หัวหน้า และเมนูของ admin

    ป้ายตัวเลขแสดงเฉพาะเรื่องที่รอผู้ใช้ลงมือ (ค่าจาก AppServiceProvider — view composer ของ layouts.app):
    - data-share-request-count  คำขอร่วมงานที่รอเราตัดสินในฐานะผู้แชร์
    จำนวนงานที่แชร์เปิดรับเป็นข้อมูล ไม่ใช่เรื่องรอทำ จึงไม่ขึ้นป้ายบนเมนู — ดูได้ในแท็บฟีดของหน้าแชร์งาน
--}}
<a href="{{ route('shares.index') }}"
    class="nav-item {{ request()->routeIs('shares.*') ? 'active' : '' }}" @if(request()->routeIs('shares.*')) aria-current="page" @endif>
    <i class="bi bi-share"></i>
    <span class="nav-item__label">แชร์งาน</span>
    @if(($shareRequestCount ?? 0) > 0)
        <span class="nav-item__count nav-item__count--request" data-share-request-count
            title="คำขอร่วมงานรอคุณอนุมัติ {{ $shareRequestCount }} รายการ"
            aria-label="คำขอร่วมงานรอคุณอนุมัติ {{ $shareRequestCount }} รายการ">{{ $shareRequestCount }}</span>
    @endif
</a>
