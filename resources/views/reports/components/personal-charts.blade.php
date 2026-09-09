    <div class="personal-report__analytics">
        <section class="personal-report__panel personal-report__chart-card report-card-order-0" data-report-chart data-chart-kind="bar" data-chart-state="loading">
            <div class="personal-report__section-head"><div><h2>อีก 3 เดือนข้างหน้ามีงานครบกำหนดเดือนละเท่าไร</h2><p>นับจากวันครบกำหนดของงานที่ยังไม่เสร็จ</p></div></div>
            <div class="report-chart-shell">
                <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                <div class="report-chart-wrap"><canvas id="personalWorkloadChart" aria-label="กราฟภาระงาน 3 เดือนข้างหน้า" role="img">กราฟภาระงาน 3 เดือนข้างหน้า</canvas></div>
                <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีงานครบกำหนดใน 3 เดือนนี้</strong><span>ไม่พบงานที่ยังไม่เสร็จและมีกำหนดส่งในช่วงนี้</span></div>
                <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
            </div>
            <ul class="personal-report__chart-summary" aria-label="ข้อมูลภาระงานแบบข้อความ">@foreach($workloadSummary as $item)<li><span>{{ $item['label'] }}</span><strong>{{ $item['value'] }} งาน</strong></li>@endforeach</ul>
        </section>
        <section class="personal-report__panel personal-report__chart-card report-card-order-1" data-report-chart data-chart-kind="doughnut" data-chart-state="loading">
            <div class="personal-report__section-head"><div><h2>สัดส่วนงานตามความสำคัญ</h2><p>งานทุกสถานะในช่วง {{ $filters['period_label'] }} แยกตามระดับความสำคัญ</p></div></div>
            <div class="personal-report__priority-layout">
                <div class="report-chart-shell">
                    <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span></div>
                    <div class="report-chart-wrap"><canvas id="personalPriorityChart" aria-label="กราฟสัดส่วนงานตามความสำคัญ" role="img">ดูจำนวนงานแต่ละระดับได้จากคำอธิบายข้างกราฟ</canvas></div>
                    <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-pie-chart" aria-hidden="true"></i><strong>ยังไม่มีข้อมูลความสำคัญของงาน</strong><span>ไม่พบงานในช่วงเวลาที่เลือก</span></div>
                    <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ดูจำนวนงานได้จากคำอธิบายข้างกราฟ</span></div>
                </div>
                <ul class="personal-report__legend" aria-label="จำนวนงานตามความสำคัญ">
                    @foreach($prioritySummary as $priority)
                        <li><span class="personal-report__priority-dot personal-report__tone--{{ $priority['tone'] }}" aria-hidden="true"></span><span>{{ $priority['label'] }}</span><strong>{{ number_format($priority['count']) }} งาน</strong></li>
                    @endforeach
                </ul>
            </div>
        </section>
    </div>
