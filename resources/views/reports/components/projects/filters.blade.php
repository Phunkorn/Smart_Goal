{{--
    การ์ดตัวกรองของรายงานโปรเจกต์ — ใช้ร่วมกันระหว่างหน้าหลักและหน้า "ดูรายละเอียดทั้งหมด"

    ตัวเลือกมาจากงานของเดือนนั้นจริง (ProjectReportService::filterOptions) ค่าที่ส่งมาถูกตรวจซ้ำที่ server

    @param string $routeName
--}}
@php
    // ค่าที่ไม่ใช่ตัวกรองของการ์ดนี้ ต้องติดไปกับการค้นหาและ "ล้างตัวกรอง" เสมอ
    $baseQuery = array_filter([
        'owner' => $owner?->id,
        'department' => $isTeamView ? $overviewDepartmentId : null,
        ...$periodQuery,
    ]);
    $carried = [...$baseQuery, ...array_intersect_key($query, ['sort' => true])];
@endphp
<section class="project-report__card project-report__filter-card" aria-label="ตัวกรองรายการงาน">
    <form method="GET" action="{{ route($routeName) }}" class="project-report__filters" role="search">
        @foreach($carried as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <div class="project-report__field" data-sg-select>
            <label for="projectReportProject">โปรเจกต์</label>
            <select id="projectReportProject" name="project">
                <option value="">ทั้งหมด</option>
                @foreach($filterOptions['projects'] as $value => $label)
                    <option value="{{ $value }}" @selected((string) $value === $filters['project'])>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="project-report__field" data-sg-select>
            <label for="projectReportStatus">สถานะ</label>
            <select id="projectReportStatus" name="status">
                <option value="">ทั้งหมด</option>
                @foreach($filterOptions['statuses'] as $value => $label)
                    <option value="{{ $value }}" @selected($value === $filters['status'])>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{--
            ขอบเขตงาน — ในแผนก / งานข้ามแผนกทั้งหมด / ข้ามแผนก · ชื่อแผนก (ProjectReportService::scopeOptions)
            เดิมเป็นรายชื่อแผนกปลายทางล้วน ๆ ซึ่งไม่บอกว่าอันไหนคืองานข้ามแผนก
        --}}
        <div class="project-report__field" data-sg-select>
            <label for="projectReportScope">ขอบเขตงาน</label>
            <select id="projectReportScope" name="scope">
                <option value="">ทั้งหมด</option>
                @foreach($filterOptions['scopes'] as $value => $label)
                    <option value="{{ $value }}" @selected((string) $value === $filters['scope'])>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- บทบาท — ทั้งหมด / ที่รับผิดชอบ / ที่ร่วมทำ (ProjectReportService::ROLE_FILTER_LABELS) --}}
        <div class="project-report__field" data-sg-select>
            <label for="projectReportRole">บทบาท</label>
            <select id="projectReportRole" name="role">
                <option value="">ทั้งหมด</option>
                @foreach($filterOptions['roles'] as $value => $label)
                    <option value="{{ $value }}" @selected((string) $value === $filters['role'])>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="project-report__field project-report__field--search">
            <label for="projectReportSearch" class="visually-hidden">ค้นหา</label>
            <div class="project-report__search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="projectReportSearch" type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="ค้นหาชื่อโปรเจกต์, งาน หรือชื่องานย่อย...">
            </div>
        </div>

        <div class="project-report__filter-actions">
            <button type="submit" class="project-report__button project-report__button--solid">
                <i class="bi bi-search" aria-hidden="true"></i> ค้นหา
            </button>
            @if($hasActiveFilters)
                <a href="{{ route($routeName, $baseQuery) }}" class="project-report__clear">ล้างตัวกรอง</a>
            @endif
        </div>
    </form>
</section>
