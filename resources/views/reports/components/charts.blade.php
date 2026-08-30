@php
    $charts = [
        ['id' => 'reportTrendChart', 'kind' => 'line', 'class' => 'report-dashboard-card--trend', 'title' => 'แนวโน้มงานองค์กร', 'description' => 'งานที่สร้างเทียบกับงานที่เสร็จจริง', 'label' => 'กราฟแนวโน้มงานที่สร้างและเสร็จ'],
        ['id' => 'reportStatusChart', 'kind' => 'doughnut', 'class' => 'report-dashboard-card--status', 'title' => 'สถานะงานทั้งหมด', 'description' => 'สถานะปัจจุบันของชุดงานในรายงาน', 'label' => 'กราฟสัดส่วนสถานะงาน'],
        ['id' => 'reportDepartmentChart', 'kind' => 'bar', 'class' => 'report-dashboard-card--department', 'title' => 'ผลงานแต่ละแผนก', 'description' => 'เปรียบเทียบงานทั้งหมด งานเสร็จ และงานล่าช้า', 'label' => 'กราฟเปรียบเทียบผลงานแต่ละแผนก'],
        ['id' => 'reportCompletedChart', 'kind' => 'bar', 'class' => 'report-dashboard-card--completed', 'title' => 'งานเสร็จรายเดือน', 'description' => 'นับจากวันที่งานเสร็จจริง', 'label' => 'กราฟงานเสร็จรายเดือน'],
        ['id' => 'reportOnTimeChart', 'kind' => 'horizontal-bar', 'class' => 'report-dashboard-card--ontime', 'title' => 'อัตราส่งงานตรงเวลา', 'description' => 'เฉพาะงานเสร็จที่มีกำหนดส่ง', 'label' => 'กราฟอัตราส่งงานตรงเวลาแต่ละแผนก'],
        ['id' => 'reportPriorityChart', 'kind' => 'doughnut', 'class' => 'report-dashboard-card--priority', 'title' => 'ความสำคัญของงาน', 'description' => 'สัดส่วนตามระดับความสำคัญจริงของระบบ', 'label' => 'กราฟสัดส่วนความสำคัญของงาน'],
        ['id' => 'reportWorkloadChart', 'kind' => 'stacked-bar', 'class' => 'report-dashboard-card--workload', 'title' => 'ภาระงานแต่ละแผนก', 'description' => 'งานกำลังทำ รอตรวจสอบ และล่าช้า', 'label' => 'กราฟภาระงานแต่ละแผนก'],
    ];
@endphp

@foreach($charts as $chart)
    <article class="report-panel report-dashboard-card report-card-order-{{ $loop->index }} {{ $chart['class'] }}" data-report-chart data-chart-kind="{{ $chart['kind'] }}" data-chart-state="loading">
        <div class="report-panel__heading"><div><h2>{{ $chart['title'] }}</h2><p>{{ $chart['description'] }}</p></div><span class="report-panel__period">{{ $filters['period_label'] }}</span></div>
        <div class="report-chart-shell">
            <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
            <div class="report-chart-wrap"><canvas id="{{ $chart['id'] }}" aria-label="{{ $chart['label'] }}" role="img">{{ $chart['label'] }}</canvas></div>
            <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีข้อมูลในช่วงเวลานี้</strong><span>ลองเปลี่ยนช่วงเวลาหรือตัวกรอง</span></div>
            <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
        </div>
    </article>
@endforeach
