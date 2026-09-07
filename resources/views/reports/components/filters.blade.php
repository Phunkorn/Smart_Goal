{{--
    ตัวกรองรายงานที่ใช้ร่วมกัน

    เดิมไฟล์นี้ผูกกับ reports.organization ตรง ๆ (action, ปุ่มล้าง และลิงก์ export)
    จึงนำไปใช้กับรายงานอื่นไม่ได้ ตอนนี้รับพารามิเตอร์ได้ โดยค่าเริ่มต้นทั้งหมด
    ยังเป็นของหน้าภาพรวมองค์กรเหมือนเดิมทุกประการ หน้าที่ include อยู่แล้วจึงไม่ต้องแก้

    @param string $action       URL ปลายทางของฟอร์ม (ค่าเริ่มต้น: รายงานภาพรวมองค์กร)
    @param string $exportRoute  ชื่อ route ของปุ่ม Export CSV
    @param bool   $showPriority แสดงตัวกรองความสำคัญของงานโครงการหรือไม่
    @param string $description  ข้อความอธิบายใต้หัวข้อ
    @param string $extraFilters ชื่อ Blade partial ของตัวกรองเพิ่มเติมเฉพาะหน้า
--}}
@php
    $action = $action ?? route('reports.organization');
    $exportRoute = $exportRoute ?? 'reports.exportCsv';
    $showPriority = $showPriority ?? true;
    $description = $description ?? 'ใช้ช่วงเวลา แผนก และความสำคัญกับข้อมูลทุกส่วนในรายงาน';
    $extraFilters = $extraFilters ?? null;
@endphp

<section class="report-filter" aria-labelledby="report-filter-title">
    <div class="report-filter__heading"><div><h2 id="report-filter-title"><i class="bi bi-funnel" aria-hidden="true"></i> ตัวกรองรายงาน</h2><p>{{ $description }}</p></div></div>
    <form method="GET" action="{{ $action }}" class="report-filter__form">
        <div><label class="form-label" for="reportPeriod">ช่วงเวลา</label><select class="form-select" id="reportPeriod" name="period" data-report-period>@foreach($filterOptions['periods'] as $value => $label)<option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="reportDepartment">แผนก</label><select class="form-select" id="reportDepartment" name="department"><option value="">ทุกแผนก</option>@foreach($filterOptions['departments'] as $department)<option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->department_name }}</option>@endforeach</select></div>
        @if($showPriority)
            <div><label class="form-label" for="reportPriority">ความสำคัญ</label><select class="form-select" id="reportPriority" name="priority"><option value="">ทุกระดับ</option>@foreach($filterOptions['priorities'] as $value => $meta)<option value="{{ $value }}" @selected($filters['priority'] === $value)>{{ $meta['label'] }}</option>@endforeach</select></div>
        @endif
        @if($extraFilters)
            @include($extraFilters)
        @endif
        <div class="report-filter__custom-dates" data-report-custom-dates @if($filters['period'] !== 'custom') hidden @endif>
            <div><label class="form-label" for="reportStartDate">ตั้งแต่วันที่</label><input class="form-control" type="date" id="reportStartDate" name="start_date" value="{{ $filters['start_date'] }}"></div>
            <div><label class="form-label" for="reportEndDate">ถึงวันที่</label><input class="form-control" type="date" id="reportEndDate" name="end_date" value="{{ $filters['end_date'] }}"></div>
        </div>
        <div class="report-filter__actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i> แสดงผล</button>
            @if(request()->query())<a href="{{ $action }}" class="btn btn-outline-secondary">ล้าง</a>@endif
            <a href="{{ route($exportRoute, request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
        </div>
    </form>
</section>
