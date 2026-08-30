<section class="report-filter" aria-labelledby="report-filter-title">
    <div class="report-filter__heading"><div><h2 id="report-filter-title"><i class="bi bi-funnel" aria-hidden="true"></i> ตัวกรองรายงาน</h2><p>ใช้ช่วงเวลา แผนก และความสำคัญกับข้อมูลทุกส่วนในรายงาน</p></div></div>
    <form method="GET" action="{{ route('reports.organization') }}" class="report-filter__form">
        <div><label class="form-label" for="reportPeriod">ช่วงเวลา</label><select class="form-select" id="reportPeriod" name="period" data-report-period>@foreach($filterOptions['periods'] as $value => $label)<option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="reportDepartment">แผนก</label><select class="form-select" id="reportDepartment" name="department"><option value="">ทุกแผนก</option>@foreach($filterOptions['departments'] as $department)<option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->department_name }}</option>@endforeach</select></div>
        <div><label class="form-label" for="reportPriority">ความสำคัญ</label><select class="form-select" id="reportPriority" name="priority"><option value="">ทุกระดับ</option>@foreach($filterOptions['priorities'] as $value => $meta)<option value="{{ $value }}" @selected($filters['priority'] === $value)>{{ $meta['label'] }}</option>@endforeach</select></div>
        <div class="report-filter__custom-dates" data-report-custom-dates @if($filters['period'] !== 'custom') hidden @endif>
            <div><label class="form-label" for="reportStartDate">ตั้งแต่วันที่</label><input class="form-control" type="date" id="reportStartDate" name="start_date" value="{{ $filters['start_date'] }}"></div>
            <div><label class="form-label" for="reportEndDate">ถึงวันที่</label><input class="form-control" type="date" id="reportEndDate" name="end_date" value="{{ $filters['end_date'] }}"></div>
        </div>
        <div class="report-filter__actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i> แสดงผล</button>
            @if(request()->query())<a href="{{ route('reports.organization') }}" class="btn btn-outline-secondary">ล้าง</a>@endif
            <a href="{{ route('reports.exportCsv', request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
        </div>
    </form>
</section>
