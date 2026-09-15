{{--
    แถวกราฟของรายงานปฏิบัติงาน — ชั่วโมงงานรายวัน และสัดส่วนประเภทงาน
    ใช้ร่วมกันทั้งภาพรวมทีมและ Overview รายบุคคล ข้อมูลอยู่ใน JSON island id="report-chart-data" ของหน้า

    @param OperationalReportScope $scope
    @param Collection $workTypes
    @param string $totalHoursText
    @param string $dailyDescription
--}}
<div class="operational-grid operational-grid--charts">
    <article class="report-panel operational-card operational-chart-card operational-chart-card--daily"
        data-report-chart data-chart-kind="bar" data-chart-state="loading" aria-labelledby="operational-daily-hours-title">
        <div class="operational-card__heading">
            <div>
                <h2 id="operational-daily-hours-title"><i class="bi bi-bar-chart" aria-hidden="true"></i> ชั่วโมงงานรายวัน</h2>
                <p>{{ $dailyDescription }}</p>
            </div>
        </div>
        <div class="report-chart-shell">
            <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
            <div class="report-chart-wrap"><canvas id="operationalDailyHoursChart" role="img" aria-label="ชั่วโมงงานรายวันแยกงานประจำและงานนอกสถานที่">ชั่วโมงงานรายวัน</canvas></div>
            <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีชั่วโมงงานในเดือนนี้</strong><span>ลองเลือกเดือนอื่น</span></div>
            <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
        </div>
    </article>

    <article class="report-panel operational-card operational-chart-card operational-chart-card--types"
        data-report-chart data-chart-kind="doughnut" data-chart-state="loading" aria-labelledby="operational-work-type-title">
        <div class="operational-card__heading">
            <div>
                <h2 id="operational-work-type-title"><i class="bi bi-pie-chart" aria-hidden="true"></i> สัดส่วนประเภทงาน</h2>
                <p>คิดจากชั่วโมงงานของ{{ $scope->monthLabel() }}</p>
            </div>
        </div>
        <div class="report-chart-shell operational-donut">
            <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
            <div class="report-chart-wrap"><canvas id="operationalWorkTypeChart" role="img" aria-label="สัดส่วนชั่วโมงงานประจำและงานนอกสถานที่">สัดส่วนประเภทงาน</canvas></div>
            <div class="operational-donut__total" aria-hidden="true"><strong>{{ $totalHoursText }}</strong><span>รวมทั้งเดือน</span></div>
            <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-pie-chart" aria-hidden="true"></i><strong>ยังไม่มีชั่วโมงงานในเดือนนี้</strong><span>ลองเลือกเดือนอื่น</span></div>
            <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
        </div>
        <ul class="operational-legend">
            @foreach($workTypes as $type)
                <li class="operational-legend__item operational-legend__item--{{ $type['key'] }}">
                    <span class="operational-legend__swatch" aria-hidden="true"></span>
                    <span>{{ $type['label'] }}</span>
                    <strong>{{ $type['hours_text'] }} ({{ $type['share'] }}%)</strong>
                </li>
            @endforeach
        </ul>
    </article>
</div>
