{{--
    กราฟของรายงานภาระงานปฏิบัติการ

    ใช้ markup shape เดียวกับ reports.components.charts ทุกจุด
    (data-report-chart / data-chart-kind / data-chart-state="loading" พร้อม
    โหนด skeleton, empty และ error) เพื่อให้ resources/js/pages/reports/chart-lifecycle.js
    ทำงานกับหน้านี้ได้โดยไม่ต้องแก้อะไรเลย

    เขียนเป็นไฟล์แยกแทนการเติมเงื่อนไขเข้าไปใน charts.blade.php เพราะไฟล์นั้น
    ฮาร์ดโค้ดกราฟห้าอันของงานโครงการไว้ และการยัดสองโดเมนไว้ไฟล์เดียวจะทำให้
    ทั้งสองอ่านยากขึ้นโดยไม่ได้อะไรกลับมา
--}}
@php
    /*
     * $chartKeys เลือกได้ว่าหน้านั้นแสดงกราฟไหนบ้าง (ค่าเริ่มต้น = ทั้งหมด)
     *
     * รายงานของพนักงานคนเดียวไม่ต้องการกราฟ "รายคน" และกราฟที่ไว้ใช้อธิบายภาพรวม
     * ของทีม การแสดงทุกกราฟให้ทุกคนทำให้หน้าที่ควรตอบคำถามเดียวกลายเป็นแดชบอร์ด
     * ที่ต้องไล่อ่าน chart-lifecycle.js ข้าม canvas ที่ไม่มีอยู่ให้เองอยู่แล้ว
     * จึงไม่ต้องแก้ฝั่ง JavaScript
     */
    $chartKeys = $chartKeys ?? ['todayMembers'];

    $charts = [
        [
            'key' => 'todayMembers',
            'id' => 'operationalTodayMemberChart',
            'kind' => 'stacked-bar',
            'class' => 'report-dashboard-card--priority',
            'title' => 'งานของวันนี้ รายคน',
            'description' => 'ข้อมูลชุดเดียวกับการ์ดด้านล่าง — ความสูงคือภาระของวันนี้ สีคือผลการทำ',
            'label' => 'กราฟแท่งซ้อนจำนวนงานของวันนี้รายคน แยกเป็นทำแล้วและยังไม่เสร็จ',
        ],
        [
            'key' => 'daily',
            'id' => 'operationalDailyChart',
            'kind' => 'stacked-bar',
            'class' => 'report-dashboard-card--trend',
            'title' => 'ชั่วโมงงานตามประเภท ต่อช่วงเวลา',
            'description' => 'เปรียบเทียบเวลางานประจำกับงานนอกสถานที่ในช่วงที่เลือก',
            'label' => 'กราฟแท่งซ้อนชั่วโมงงานประจำและงานนอกสถานที่',
        ],
        [
            'key' => 'categories',
            'id' => 'operationalCategoryChart',
            'kind' => 'doughnut',
            'class' => 'report-dashboard-card--status',
            'title' => 'เวลาหมดไปกับหมวดงานไหน',
            'description' => 'สัดส่วนชั่วโมงตามหมวดงานในช่วงที่เลือก',
            'label' => 'กราฟวงกลมสัดส่วนชั่วโมงตามหมวดงาน',
        ],
        [
            'key' => 'members',
            'id' => 'operationalMemberChart',
            'kind' => 'bar',
            'class' => 'report-dashboard-card--priority',
            'title' => 'ชั่วโมงงานปฏิบัติการรายคน',
            'description' => 'เรียงจากมากไปน้อย แสดงสูงสุด 8 คนแรก',
            'label' => 'กราฟแท่งแนวนอนชั่วโมงงานปฏิบัติการรายคน',
        ],
    ];

    $charts = array_values(array_filter(
        $charts,
        fn (array $chart): bool => in_array($chart['key'], $chartKeys, true)
    ));
@endphp

@foreach($charts as $chart)
    <article class="report-panel report-dashboard-card report-card-order-{{ $loop->index }} {{ $chart['class'] }}"
        data-report-chart data-chart-kind="{{ $chart['kind'] }}" data-chart-state="loading">
        <div class="report-panel__heading">
            <div><h2>{{ $chart['title'] }}</h2><p>{{ $chart['description'] }}</p></div>
            <span class="report-panel__period">{{ $filters['period_label'] }}</span>
        </div>
        <div class="report-chart-shell">
            <div class="report-chart-skeleton" data-chart-skeleton aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
            <div class="report-chart-wrap"><canvas id="{{ $chart['id'] }}" aria-label="{{ $chart['label'] }}" role="img">{{ $chart['label'] }}</canvas></div>
            <div class="report-chart-state report-chart-state--empty" data-chart-empty role="status"><i class="bi bi-bar-chart" aria-hidden="true"></i><strong>ยังไม่มีข้อมูลในช่วงเวลานี้</strong><span>ลองเปลี่ยนช่วงเวลาหรือตัวกรอง</span></div>
            <div class="report-chart-state report-chart-state--error" data-chart-error role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><strong>ไม่สามารถแสดงกราฟนี้ได้</strong><span>ข้อมูลส่วนอื่นยังใช้งานได้ตามปกติ</span></div>
        </div>
    </article>
@endforeach
