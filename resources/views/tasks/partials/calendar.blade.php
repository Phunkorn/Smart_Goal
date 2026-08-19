<section class="mytasks-calendar" id="mytasks-calendar" data-calendar data-view-panel="calendar" role="tabpanel" aria-hidden="true" aria-labelledby="mytasks-calendar-title">
    <header class="mytasks-calendar__toolbar">
        <div>
            <span>MONTH VIEW</span>
            <h2 id="mytasks-calendar-title" data-calendar-title aria-live="polite"></h2>
        </div>
        <div class="mytasks-calendar__navigation" aria-label="เปลี่ยนเดือน">
            <button type="button" data-calendar-previous aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <button type="button" data-calendar-today>วันนี้</button>
            <button type="button" data-calendar-next aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </div>
    </header>

    <div class="mytasks-calendar__viewport">
        <div class="mytasks-calendar__canvas" role="grid" aria-labelledby="mytasks-calendar-title">
            <div class="mytasks-calendar__weekdays" role="row">
                @foreach(['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'] as $day)
                    <span role="columnheader">{{ $day }}</span>
                @endforeach
            </div>
            <div class="mytasks-calendar__grid" data-calendar-grid></div>
        </div>
    </div>

    <div class="mytasks-calendar__popover" data-calendar-popover role="dialog" aria-modal="false" aria-labelledby="mytasks-calendar-popover-title" hidden>
        <header>
            <strong id="mytasks-calendar-popover-title" data-calendar-popover-title></strong>
            <button type="button" data-calendar-popover-close aria-label="ปิดรายการงาน"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div data-calendar-popover-list></div>
    </div>
</section>
