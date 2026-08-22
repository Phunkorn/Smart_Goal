<section class="report-filter" aria-labelledby="report-filter-title">
    <div class="report-filter__heading">
        <div>
            <h2 id="report-filter-title"><i class="bi bi-funnel" aria-hidden="true"></i> ตัวกรองรายงาน</h2>
            <p>ช่วงเวลาจะกำหนดชุดงานจากวันที่สร้าง และใช้วันเสร็จ/วันครบกำหนดกับตัวชี้วัดที่เกี่ยวข้อง</p>
        </div>
        @if(request()->query())
            <a href="{{ route('reports.index') }}" class="btn btn-sm btn-outline-secondary">ล้างตัวกรอง</a>
        @endif
    </div>

    <form method="GET" action="{{ route('reports.index') }}" class="report-filter__form">
        <div>
            <label class="form-label" for="reportPeriod">ช่วงเวลา</label>
            <select class="form-select" id="reportPeriod" name="period" data-report-period>
                @foreach($filterOptions['periods'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="reportDepartment">แผนก</label>
            <select class="form-select" id="reportDepartment" name="department">
                <option value="">ทุกแผนก</option>
                @foreach($filterOptions['departments'] as $department)
                    <option value="{{ $department->id }}" @selected($filters['department_id'] === $department->id)>{{ $department->department_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="reportStatus">สถานะ</label>
            <select class="form-select" id="reportStatus" name="status">
                <option value="">ทุกสถานะ</option>
                @foreach($filterOptions['statuses'] as $value => $meta)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="reportPriority">Task Priority</label>
            <select class="form-select" id="reportPriority" name="priority">
                <option value="">ทุกระดับ</option>
                @foreach($filterOptions['priorities'] as $value => $meta)
                    <option value="{{ $value }}" @selected($filters['priority'] === $value)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="report-filter__custom-dates" data-report-custom-dates @if($filters['period'] !== 'custom') hidden @endif>
            <div>
                <label class="form-label" for="reportStartDate">ตั้งแต่วันที่</label>
                <input class="form-control" type="date" id="reportStartDate" name="start_date" value="{{ $filters['start_date'] }}">
            </div>
            <div>
                <label class="form-label" for="reportEndDate">ถึงวันที่</label>
                <input class="form-control" type="date" id="reportEndDate" name="end_date" value="{{ $filters['end_date'] }}">
            </div>
        </div>
        <button type="submit" class="btn btn-primary report-filter__submit"><i class="bi bi-search" aria-hidden="true"></i> แสดงผล</button>
    </form>
</section>
