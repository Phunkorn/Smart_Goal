<section class="mytasks-calendar" id="mytasks-calendar" data-calendar data-view-panel="calendar" role="tabpanel" aria-hidden="true" aria-labelledby="mytasks-calendar-title">
    <header class="mytasks-calendar__toolbar">
        <div>
            <span>MONTH VIEW</span>
            <h2 id="mytasks-calendar-title" data-calendar-title aria-live="polite"></h2>
        </div>
        <div class="mytasks-calendar__navigation" aria-label="เปลี่ยนเดือน">
            <button type="button" class="mytasks-calendar__reset" data-calendar-reset>คืนค่า</button>
            <button type="button" data-calendar-today>วันนี้</button>
            <button type="button" data-calendar-previous aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <button type="button" data-calendar-next aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            <label><span class="visually-hidden">เลือกเดือน</span><select data-calendar-month aria-label="เลือกเดือน">@foreach(['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'] as $index => $month)<option value="{{ $index }}">{{ $month }}</option>@endforeach</select></label>
            <label><span class="visually-hidden">เลือกปี</span><select data-calendar-year aria-label="เลือกปี"></select></label>
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

<div class="notion-modal mytasks-calendar-detail" data-calendar-detail hidden>
        <section class="mytasks-calendar-detail__card" role="dialog" aria-modal="true" aria-labelledby="calendar-detail-title">
            <header><div><span>CALENDAR TASK</span><h2 id="calendar-detail-title" data-calendar-detail-title></h2><small data-calendar-detail-project></small></div><button type="button" data-calendar-detail-close aria-label="ปิดรายละเอียดงาน"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
            <div class="mytasks-calendar-detail__body">
                <dl class="mytasks-calendar-detail__meta"><div><dt>สถานะ</dt><dd data-calendar-detail-status></dd></div><div><dt>ความสำคัญ</dt><dd data-calendar-detail-priority></dd></div><div><dt>วันที่เริ่ม</dt><dd data-calendar-detail-start></dd></div><div><dt>กำหนดส่ง</dt><dd data-calendar-detail-due></dd></div><div><dt>ผู้รับผิดชอบ</dt><dd data-calendar-detail-assignee></dd></div><div><dt>ผู้ร่วมงาน</dt><dd data-calendar-detail-collaborators></dd></div></dl>
                <section><h3>รายละเอียดงาน</h3><p data-calendar-detail-description></p></section>
                <section><h3>ไฟล์แนบ</h3><div data-calendar-detail-attachments></div></section>
            </div>
            <footer><button type="button" class="task-secondary" data-calendar-detail-close>ปิด</button></footer>
        </section>
    </div>
