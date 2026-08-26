{{--
    Calendar Event Popover — กล่องอ่านข้อมูลย่อที่เปิดติดกับ Event ที่คลิกบนปฏิทิน

    นี่ไม่ใช่ modal: ไม่มี backdrop, ไม่ล็อก body, ไม่ trap focus และปฏิทินด้านหลังยังมองเห็น/ใช้งาน
    ต่อได้ตามปกติ ใช้ instance เดียวทั้งหน้า เนื้อหาถูก fetch มาใส่ [data-quick-view-body] ตอนคลิก
    เท่านั้น ไม่ฝังมากับหน้า ตำแหน่งและโหมด (popover ข้าง Event / bottom sheet บนจอแคบ) คำนวณโดย
    resources/js/pages/mytasks/calendar-quick-view.js
--}}
<div class="calendar-quick-view-popover" id="calendar-quick-view-popover" data-quick-view-popover hidden>
    <span class="calendar-quick-view-popover__caret" data-quick-view-caret aria-hidden="true"></span>

    <section class="calendar-quick-view-popover__card" role="dialog" aria-modal="false" aria-labelledby="calendar-quick-view-title" tabindex="-1">
        <header class="calendar-quick-view-popover__header">
            <div>
                <span class="calendar-quick-view-popover__kicker" data-quick-view-kicker>ดูอย่างย่อ</span>
                <strong id="calendar-quick-view-title" data-quick-view-title>กำลังโหลด...</strong>
            </div>
            <button type="button" class="calendar-quick-view-popover__close" data-close-quick-view aria-label="ปิด">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>

        <div class="calendar-quick-view-popover__body" data-quick-view-body aria-live="polite">
            <p class="calendar-quick-view__state" data-quick-view-loading><i class="bi bi-arrow-repeat" aria-hidden="true"></i> กำลังโหลดข้อมูล...</p>
        </div>

        <a class="calendar-quick-view-popover__link" data-quick-view-detail href="#" hidden>
            ดูรายละเอียดทั้งหมด <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </section>
</div>
