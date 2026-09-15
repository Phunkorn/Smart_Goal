{{--
    หัวของรายงานปฏิบัติงาน — ใช้ร่วมกันทั้งภาพรวมทีม Overview รายบุคคล และหน้า detail ทั้งสาม

    ตัวกรองเหลือ "เดือน" (และ "พนักงาน" สำหรับหัวหน้าแผนก/admin) ใช้ดร็อปดาวน์ของระบบ
    (components/select-dropdown.js) เสริมบน <select> เดิม ค่าที่ส่งยังมาจาก <select> ตัวเดียว

    รายชื่อพนักงานมาจาก scope ที่ server ตรวจแล้ว การซ่อนหรือแสดงช่องนี้ไม่ใช่การบังคับสิทธิ์
    ปลายทางทุก route ตรวจ owner ซ้ำเสมอ

    @param OperationalReportScope $scope
    @param string $routeName     route ของหน้าปัจจุบัน (ตัวกรองส่งกลับมาที่หน้าเดิม)
    @param string $title
    @param string|null $subtitle
    @param string|null $detailTitle  ชื่อหน้า detail สำหรับ breadcrumb
    @param string|null $csvReport    daily|frequent|delays — ปุ่ม CSV ของหน้า detail
    @param bool $csvMenu             Overview รายบุคคล: เมนู CSV สามรายการ
    @param bool $teamOption          หน้า Overview: ตัวเลือก "ภาพรวมทีม" ในช่องพนักงาน
--}}
@php
    $detailTitle = $detailTitle ?? null;
    $csvReport = $csvReport ?? null;
    $csvMenu = $csvMenu ?? false;
    $teamOption = $teamOption ?? false;
    $ownerDepartment = $scope->owner?->department?->department_name;
    $subtitle = $subtitle ?? (($scope->isOwnReport ? 'ของคุณ' : $scope->owner?->name).($ownerDepartment ? ' · '.$ownerDepartment : ''));
    $csvReports = [
        'daily' => ['label' => 'สรุปรายวัน', 'description' => 'รายการงานทั้งหมดของเดือน'],
        'frequent' => ['label' => 'งานที่ทำบ่อยที่สุด', 'description' => 'จำนวนครั้งและเวลารวม'],
        'delays' => ['label' => 'เหตุผลที่ล่าช้าหรือเกินกำหนด', 'description' => 'ทุกเหตุการณ์พร้อมวันที่และงาน'],
    ];
@endphp

<nav class="report-breadcrumb" aria-label="breadcrumb">
    <a href="{{ route('reports.index') }}">รายงาน</a>
    <i class="bi bi-chevron-right" aria-hidden="true"></i>
    @if($detailTitle)
        <a href="{{ route('reports.operational', $scope->query()) }}">รายงานปฏิบัติงาน</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span>{{ $detailTitle }}</span>
    @else
        <span>รายงานปฏิบัติงาน</span>
    @endif
</nav>

<header class="operational-header">
    <div class="operational-header__copy">
        <h1 id="operational-report-title">{{ $title }}</h1>
        <p>{{ $subtitle }} · {{ $scope->monthLabel() }}</p>
    </div>

    <div class="operational-header__controls">
        <form method="GET" action="{{ route($routeName) }}" class="operational-filter" data-operational-filter>
            @if($scope->canChooseOwner)
                <div class="operational-filter__field operational-filter__field--owner" data-sg-select>
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <label for="operationalOwner" class="visually-hidden">พนักงาน</label>
                    <select id="operationalOwner" name="owner" class="operational-filter__select" data-auto-submit>
                        @if($teamOption)
                            <option value="" @selected($scope->isTeamView())>ภาพรวมทีม</option>
                        @endif
                        @foreach($scope->owners as $person)
                            <option value="{{ $person->id }}" @selected((int) $person->id === (int) $scope->owner?->id)>
                                {{ $person->name }}{{ (int) $person->id === (int) auth()->id() ? ' (ฉัน)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="operational-filter__field operational-filter__field--month" data-sg-select>
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <label for="operationalMonth" class="visually-hidden">เดือน</label>
                <select id="operationalMonth" name="month" class="operational-filter__select" data-auto-submit>
                    @foreach($scope->monthOptions as $key => $label)
                        <option value="{{ $key }}" @selected($key === $scope->monthKey())>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <noscript><button type="submit" class="operational-button">แสดงผล</button></noscript>
        </form>

        @if($csvMenu)
            <div class="operational-csv" data-csv-menu>
                <button type="button" class="operational-button operational-button--primary" data-csv-menu-trigger aria-haspopup="true" aria-expanded="false" aria-controls="operationalCsvMenu">
                    <i class="bi bi-download" aria-hidden="true"></i> Export CSV
                </button>
                <div class="operational-csv__panel" id="operationalCsvMenu" data-csv-menu-panel hidden>
                    <p class="operational-csv__hint">ข้อมูลเดือน {{ $scope->monthLabel() }}</p>
                    @foreach($csvReports as $key => $meta)
                        <a href="{{ route('reports.operational.'.$key.'.csv', $scope->query()) }}">
                            <strong>{{ $meta['label'] }}</strong>
                            <small>{{ $meta['description'] }}</small>
                        </a>
                    @endforeach
                </div>
            </div>
        @elseif($csvReport)
            <a href="{{ route('reports.operational.'.$csvReport.'.csv', $scope->query()) }}" class="operational-button operational-button--primary">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        @endif
    </div>
</header>
