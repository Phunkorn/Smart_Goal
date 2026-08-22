<section class="report-charts" aria-label="กราฟรายงาน">
    <article class="report-panel report-panel--trend">
        <div class="report-panel__heading">
            <div><h2>แนวโน้มงาน</h2><p>เปรียบเทียบวันที่สร้างกับวันที่เสร็จจริง</p></div>
            <span class="report-panel__period">{{ $filters['period_label'] }}</span>
        </div>
        <div class="report-chart-wrap report-chart-wrap--trend">
            <canvas id="reportTrendChart" aria-label="กราฟแนวโน้มงานที่สร้างและเสร็จ" role="img"></canvas>
        </div>
    </article>

    <article class="report-panel report-panel--status">
        <div class="report-panel__heading">
            <div><h2>สถานะงาน</h2><p>สถานะปัจจุบันของชุดงานที่กรอง</p></div>
        </div>
        <div class="report-chart-wrap report-chart-wrap--status">
            <canvas id="reportStatusChart" aria-label="กราฟสัดส่วนสถานะงาน" role="img"></canvas>
        </div>
        <div class="report-chart-legend">
            @foreach($statusSummary as $status)
                <div><i class="report-chart-legend__dot report-tone-{{ $status['tone'] }}" aria-hidden="true"></i><span>{{ $status['label'] }}</span><strong>{{ $status['value'] }}</strong></div>
            @endforeach
        </div>
    </article>
</section>
