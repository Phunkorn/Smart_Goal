@php
    // เหลือเฉพาะกราฟที่ตอบคำถามต่างกันจริง
    // กราฟเปรียบเทียบรายแผนกสามอันเดิมถูกแทนด้วยตารางแผนก ซึ่งอ่านเทียบข้ามแผนกได้ในบรรทัดเดียว
    $charts = [
        [
            'id' => 'reportTrendChart',
            'kind' => 'line',
            'class' => 'report-dashboard-card--trend',
            'title' => 'งานเข้าเทียบกับงานที่ปิดได้',
            'description' => 'ถ้าเส้นงานเข้าสูงกว่างานที่เสร็จต่อเนื่อง แปลว่างานค้างกำลังสะสม',
            'label' => 'กราฟเส้นเปรียบเทียบงานที่สร้างกับงานที่เสร็จในแต่ละเดือน',
        ],
        [
            'id' => 'reportStatusChart',
            'kind' => 'doughnut',
            'class' => 'report-dashboard-card--status',
            'title' => 'ตอนนี้งานค้างอยู่ที่ขั้นไหน',
            'description' => 'สัดส่วนสถานะปัจจุบันของงานทั้งหมดในรายงาน',
            'label' => 'กราฟวงกลมสัดส่วนสถานะงาน',
        ],
        [
            'id' => 'reportCompletedChart',
            'kind' => 'bar',
            'class' => 'report-dashboard-card--completed',
            'title' => 'ปิดงานได้เดือนละเท่าไร',
            'description' => 'นับจากวันที่งานเสร็จจริง ไม่ใช่วันครบกำหนด',
            'label' => 'กราฟแท่งจำนวนงานที่เสร็จในแต่ละเดือน',
        ],
        [
            'id' => 'reportPriorityChart',
            'kind' => 'doughnut',
            'class' => 'report-dashboard-card--priority',
            'title' => 'งานส่วนใหญ่เป็นงานระดับไหน',
            'description' => 'สัดส่วนตามระดับความสำคัญจริงที่ใช้ในระบบ',
            'label' => 'กราฟวงกลมสัดส่วนความสำคัญของงาน',
        ],
        [
            'id' => 'reportWorkloadChart',
            'kind' => 'stacked-bar',
            'class' => 'report-dashboard-card--workload',
            'title' => 'แผนกไหนมีงานค้างมากที่สุด',
            'description' => 'นับเฉพาะงานที่ยังไม่เสร็จ แยกเป็นกำลังทำ รอตรวจสอบ และล่าช้า',
            'label' => 'กราฟแท่งซ้อนแสดงงานค้างของแต่ละแผนก',
        ],
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
