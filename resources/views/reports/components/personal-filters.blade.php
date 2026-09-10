{{--
    ตัวกรองของหน้ารายงานของฉัน

    ใช้ <select> ของเบราว์เซอร์ตรง ๆ ไม่ใช่ dropdown ที่วาดเองแล้วสไลด์เปิด
    เพราะรายการสั้นและผู้ใช้คุ้นกับ dropdown ปกติอยู่แล้ว การวาดเองต้องดูแล
    การเปิดปิด โฟกัส Escape และการกดนอกกรอบเองทั้งหมดโดยไม่ได้อะไรกลับมา

    ส่งเป็น GET ธรรมดา ค่าที่เลือกจึงอยู่ใน URL แชร์ลิงก์และกดย้อนกลับได้
--}}
@php
    $hasActiveFilter = $filters['status'] || $filters['priority'] || $filters['search'] !== '';
@endphp
<form method="GET" action="{{ route('reports.my') }}" class="personal-report__filters" role="search" aria-label="ตัวกรองรายงานของฉัน">
    <div class="personal-report__filter" data-sg-select>
        <label for="personalReportPeriod">ช่วงเวลา</label>
        <select id="personalReportPeriod" name="period">
            @foreach($filterOptions['periods'] as $value => $label)
                <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="personal-report__filter" data-sg-select>
        <label for="personalReportStatus">สถานะงาน</label>
        <select id="personalReportStatus" name="status">
            <option value="">ทุกสถานะ</option>
            @foreach($filterOptions['statuses'] as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="personal-report__filter" data-sg-select>
        <label for="personalReportPriority">ความสำคัญ</label>
        <select id="personalReportPriority" name="priority">
            <option value="">ทุกระดับ</option>
            @foreach($filterOptions['priorities'] as $value => $meta)
                <option value="{{ $value }}" @selected($filters['priority'] === $value)>{{ $meta['label'] }}</option>
            @endforeach
        </select>
    </div>

    <div class="personal-report__filter personal-report__filter--search">
        <label for="personalReportSearch">ค้นหาชื่องาน</label>
        <input id="personalReportSearch" type="search" name="search" value="{{ $filters['search'] }}" placeholder="พิมพ์ชื่องาน" maxlength="100">
    </div>

    <div class="personal-report__filter-actions">
        <button type="submit" class="personal-report__button personal-report__button--primary">แสดงผล</button>
        @if($hasActiveFilter)
            <a href="{{ route('reports.my', ['period' => $filters['period']]) }}" class="personal-report__button">ล้างตัวกรอง</a>
        @endif
    </div>
</form>
