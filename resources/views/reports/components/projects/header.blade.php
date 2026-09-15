{{--
    หัวของรายงานโปรเจกต์ — ใช้ร่วมกันระหว่างหน้าหลักและหน้า "ดูรายละเอียดทั้งหมด"

    ช่องพนักงานและเดือนส่งฟอร์มกลับมาที่หน้าเดิม ($routeName) รายชื่อผ่านการตรวจสิทธิ์ใน
    ReportController::projectReportSubjects() แล้ว การซ่อนหรือแสดงชื่อไม่ใช่การบังคับสิทธิ์
    พนักงานทั่วไป ($isPersonalReport) เห็นชื่อตัวเองแทนช่องเลือก — server บังคับขอบเขตเป็นตัวเอง

    @param string      $routeName
    @param string      $title
    @param string|null $detailTitle  ชื่อหน้าย่อยสำหรับ breadcrumb
--}}
@php
    $detailTitle = $detailTitle ?? null;
    $isOwnReport = $owner !== null && (int) $owner->id === (int) auth()->id();
    $subject = $isOwnReport ? 'คุณ' : $owner?->name;
@endphp
<nav class="report-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('reports.index') }}">รายงาน</a>
    <i class="bi bi-chevron-right" aria-hidden="true"></i>
    @if($detailTitle)
        <a href="{{ route('reports.projects', $query) }}">รายงานโปรเจกต์</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span>{{ $detailTitle }}</span>
    @else
        <span>รายงานโปรเจกต์</span>
    @endif
</nav>

<header class="project-report__header">
    <div class="project-report__heading">
        <h1 id="project-report-title">{{ $title }}</h1>
        <p>
            @if($isTeamView)
                สรุปโปรเจกต์ งานที่ได้รับมอบหมาย งานข้ามแผนก และผลการดำเนินงานของ{{ $scopeName }}ในรอบเดือนนี้
            @else
                สรุปโปรเจกต์และงานที่{{ $subject }}รับผิดชอบ งานข้ามแผนก และผลการดำเนินงานในรอบเดือนนี้
            @endif
        </p>
    </div>

    <div class="project-report__controls">
        <form method="GET" action="{{ route($routeName) }}" class="project-report__period" data-auto-submit-form>
            @if($isPersonalReport)
                {{-- พนักงานทั่วไปเห็นเฉพาะงานของตัวเอง — server บังคับ owner เป็นตัวเอง จึงไม่มีช่องเลือกคน --}}
                <span class="project-report__control project-report__control--owner project-report__owner-fixed" data-project-owner>
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <span>{{ $owner->name }} (ฉัน)</span>
                </span>
            @else
                <div class="project-report__control project-report__control--owner" data-sg-select data-project-owner>
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <label for="projectReportOwner" class="visually-hidden">พนักงาน</label>
                    <select id="projectReportOwner" name="owner" data-auto-submit>
                        {{--
                            ภาพรวมก่อน รายบุคคลตามหลัง
                            หัวหน้าแผนกมีภาพรวมเดียวคือแผนกตัวเอง ($overviewLabel = "ภาพรวมแผนก IT")
                            admin/viewer มีภาพรวมทุกแผนก ตามด้วยภาพรวมทีละแผนก ($departmentOptions)
                            ค่าของภาพรวมแผนกเข้ารหัสเป็น department-ID ในช่อง owner ช่องเดียว
                            (ReportController::requestedOverviewDepartment ตรวจกับรายการแผนกที่อนุญาตอีกชั้น)
                        --}}
                        <option value="" @selected($isTeamView && $overviewDepartmentId === null)>{{ $isForcedDepartment ? $overviewLabel : 'ภาพรวมทุกแผนก' }}</option>
                        @foreach($departmentOptions as $departmentId => $departmentName)
                            <option value="department-{{ $departmentId }}" @selected($isTeamView && $overviewDepartmentId === (int) $departmentId)>ภาพรวมแผนก {{ $departmentName }}</option>
                        @endforeach
                        @foreach($owners as $person)
                            <option value="{{ $person->id }}" @selected((int) $person->id === (int) $owner?->id)>{{ $person->name }}{{ (int) $person->id === (int) auth()->id() ? ' (ฉัน)' : '' }}{{ $isForcedDepartment ? '' : ' · '.($person->department?->department_name ?? 'ไม่ระบุแผนก') }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{--
                ช่วงเวลา: เลือกเดือนแล้วส่งทันที หรือ "กำหนดช่วงวันที่เอง" (ReportPeriod::CUSTOM)
                ซึ่งติด data-auto-submit-skip จึงแค่เปิดช่องวันเริ่ม/วันสิ้นสุด แล้วรอปุ่ม "แสดงผล"
                ช่องวันที่ถูก disable เมื่อไม่ได้เลือกช่วงกำหนดเอง ไม่อย่างนั้น from/to จะติดไปกับการเปลี่ยนเดือน
                แล้ว server เลือกช่วงเดิมแทน (resources/js/pages/reports/project-period.js)
            --}}
            <div class="project-report__control project-report__control--month" data-sg-select>
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <label for="projectReportMonth" class="visually-hidden">ช่วงเวลา</label>
                <select id="projectReportMonth" name="month" data-auto-submit data-period-select>
                    @foreach($monthOptions as $key => $label)
                        <option value="{{ $key }}" @selected($key === $monthKey) @if($key === \App\Support\ReportPeriod::CUSTOM) data-auto-submit-skip @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="project-report__range" data-period-range @unless($isCustomPeriod) hidden @endunless>
                @foreach(['from' => ['ตั้งแต่', $periodFrom], 'to' => ['ถึง', $periodTo]] as $name => [$caption, $value])
                    <label class="project-report__control project-report__range-field">
                        <span class="project-report__range-caption">{{ $caption }}</span>
                        <span class="project-report__range-value" data-period-label="{{ $name }}">{{ \App\Support\ReportPeriod::dayLabel($value) }}</span>
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                        <input type="date" name="{{ $name }}" value="{{ $value }}" data-date-picker data-period-input="{{ $name }}"
                            aria-label="วันที่{{ $caption }}ของช่วงรายงาน" @unless($isCustomPeriod) disabled @endunless>
                    </label>
                @endforeach
                <button type="submit" class="project-report__button project-report__button--solid">แสดงผล</button>
            </div>

            <noscript><button type="submit" class="project-report__button">แสดงผล</button></noscript>
        </form>

        @if(auth()->user()->role === 'viewer')
            <span class="project-report__readonly"><i class="bi bi-eye" aria-hidden="true"></i> ดูข้อมูลเท่านั้น</span>
        @endif

        <a href="{{ route('reports.projects.csv', $query) }}" class="project-report__button project-report__button--primary">
            <i class="bi bi-download" aria-hidden="true"></i> Export CSV
        </a>
    </div>
</header>
