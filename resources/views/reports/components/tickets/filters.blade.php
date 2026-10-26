{{--
    หัวรายงานและตัวกรอง Smart Ticket — ฟอร์ม GET เดียว ตรวจค่าซ้ำที่ server (TicketReportService::filters)

    ฟอร์มเดียวครอบหัวรายงานและแผงตัวกรอง — ช่วงเวลาเป็นช่องแรกของแผงตัวกรอง ตัวกรองทั้งหมดจึงอยู่แถวเดียวกัน
    ช่วงเวลาใช้ ReportPeriod ชุดเดียวกับรายงานโปรเจกต์: เลือกเดือนแล้วส่งทันที หรือ "กำหนดช่วงวันที่เอง"
    (data-auto-submit-skip) ซึ่งเปิดช่องวันเริ่ม/วันสิ้นสุดในแถวถัดไปแล้วรอปุ่ม "แสดงผล" (components/period-range.js)
    ช่องอื่นส่งฟอร์มทันทีเมื่อเปลี่ยนค่า (components/auto-submit-filter.js)

    ตัวกรองหลัก: ช่วงเวลา · สถานะ · ช่องทางที่แจ้ง · แผนกผู้แจ้ง — ตัวกรองรอง (สาขา · เจ้าหน้าที่) อยู่ในแผง "ตัวกรองเพิ่มเติม"
    ปุ่มพับแผงซ่อนไว้ก่อน (hidden) ไม่มี JavaScript แผงแสดงอยู่เสมอ แผงเปิดเองเมื่อมีตัวกรองรองใช้อยู่
    คำค้นหาในรายการ Ticket ส่งต่อเป็น hidden input เพื่อไม่หายเมื่อเปลี่ยนตัวกรอง
--}}
@php
    $extraActive = collect($filters)->only(['location', 'agent'])->filter(fn ($value) => $value !== '')->count();
@endphp
<form method="GET" action="{{ route('reports.tickets') }}" class="ticket-report__controls" data-auto-submit-form aria-label="ช่วงเวลาและตัวกรองรายงาน">
    @if ($search !== '')
        <input type="hidden" name="q" value="{{ $search }}">
    @endif

    <header class="ticket-report__header">
        <div class="ticket-report__heading">
            <p class="ticket-report__eyebrow">SMART TICKET</p>
            <h1 id="ticket-report-title">รายงาน Smart Ticket</h1>
            <p class="ticket-report__lead">สรุป Ticket ที่แจ้งใน{{ $periodPhrase }} เพื่อติดตามงานและดูแนวโน้มการให้บริการ</p>
        </div>
    </header>

    <div class="ticket-report__filters">
        <div class="ticket-report__field ticket-report__field--period">
            <label for="ticketReportMonth">ช่วงเวลา (วันที่แจ้ง)</label>
            <select id="ticketReportMonth" class="form-select" name="month" data-auto-submit data-period-select>
                @foreach ($monthOptions as $key => $label)
                    <option value="{{ $key }}" @selected($key === $monthKey) @if($key === \App\Support\ReportPeriod::CUSTOM) data-auto-submit-skip @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="ticket-report__field">
            <label for="ticketReportStatus">สถานะปัจจุบัน</label>
            <select id="ticketReportStatus" class="form-select" name="status" data-auto-submit>
                <option value="">ทุกสถานะ</option>
                @foreach ($options['statuses'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="ticket-report__field">
            <label for="ticketReportSource">ช่องทางที่แจ้ง</label>
            <select id="ticketReportSource" class="form-select" name="source" data-auto-submit>
                <option value="">ทุกช่องทาง</option>
                @foreach ($options['sources'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['source'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="ticket-report__field">
            <label for="ticketReportDepartment">แผนกผู้แจ้ง</label>
            <select id="ticketReportDepartment" class="form-select" name="department" data-auto-submit>
                <option value="">ทุกแผนก</option>
                @foreach ($options['departments'] as $department)
                    <option value="{{ $department->id }}" @selected($filters['department'] === (string) $department->id)>{{ $department->department_name }}</option>
                @endforeach
                <option value="{{ \App\Services\TicketReportService::DEPARTMENT_NONE }}" @selected($filters['department'] === \App\Services\TicketReportService::DEPARTMENT_NONE)>ไม่ระบุแผนก</option>
                <option value="{{ \App\Services\TicketReportService::DEPARTMENT_PUBLIC }}" @selected($filters['department'] === \App\Services\TicketReportService::DEPARTMENT_PUBLIC)>บุคคลภายนอก</option>
            </select>
        </div>

        <div class="ticket-report__filter-actions">
            <button type="button" class="ticket-report__button ticket-report__button--quiet" hidden
                aria-expanded="{{ $extraActive > 0 ? 'true' : 'false' }}" aria-controls="ticketReportExtraFilters" data-ticket-filter-toggle>
                ตัวกรองเพิ่มเติม
                @if ($extraActive > 0)
                    <span class="ticket-report__badge" aria-label="ใช้อยู่ {{ $extraActive }} ตัวกรอง">{{ $extraActive }}</span>
                @endif
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <noscript><button type="submit" class="ticket-report__button ticket-report__button--primary">แสดงผล</button></noscript>
            @if ($hasFilters)
                <a class="ticket-report__button" href="{{ route('reports.tickets', array_filter([...$period->query(), 'q' => $search], fn ($value) => $value !== '')) }}">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> ล้างตัวกรอง
                </a>
            @endif
        </div>

        {{-- ช่วงวันที่กำหนดเอง — แถวเต็มใต้ตัวกรอง เปิดเมื่อเลือก "กำหนดช่วงวันที่เอง" ในช่องช่วงเวลา --}}
        <div class="ticket-report__range" data-period-range @unless($isCustomPeriod) hidden @endunless>
            @foreach (['from' => ['ตั้งแต่', $periodFrom], 'to' => ['ถึง', $periodTo]] as $name => [$caption, $value])
                <label class="ticket-report__date">
                    <span class="ticket-report__date-caption">{{ $caption }}</span>
                    <span class="ticket-report__date-value" data-period-label="{{ $name }}">{{ \App\Support\ReportPeriod::dayLabel($value) }}</span>
                    <i class="bi bi-calendar-event" aria-hidden="true"></i>
                    <input type="date" name="{{ $name }}" value="{{ $value }}" data-date-picker data-period-input="{{ $name }}"
                        aria-label="วันที่{{ $caption }}ของช่วงรายงาน" @unless($isCustomPeriod) disabled @endunless>
                </label>
            @endforeach
            <button type="submit" class="ticket-report__button ticket-report__button--primary">แสดงผล</button>
        </div>

        <div class="ticket-report__extra" id="ticketReportExtraFilters" data-ticket-filter-panel @if ($extraActive > 0) data-open @endif>
            <div class="ticket-report__field">
                <label for="ticketReportLocation">สาขา/สถานที่</label>
                <select id="ticketReportLocation" class="form-select" name="location" data-auto-submit>
                    <option value="">ทุกสาขา</option>
                    @foreach ($options['locations'] as $location)
                        <option value="{{ $location->id }}" @selected($filters['location'] === (string) $location->id)>{{ $location->name }}</option>
                    @endforeach
                    <option value="{{ \App\Services\TicketQueryService::LOCATION_OTHER }}" @selected($filters['location'] === \App\Services\TicketQueryService::LOCATION_OTHER)>อื่น ๆ (ระบุเอง)</option>
                </select>
            </div>

            <div class="ticket-report__field">
                <label for="ticketReportAgent">เจ้าหน้าที่</label>
                <select id="ticketReportAgent" class="form-select" name="agent" data-auto-submit>
                    <option value="">ทุกคน</option>
                    @foreach ($options['agents'] as $agent)
                        {{-- id คือตัวตนทางประวัติ (TicketReportService::identities) — บัญชีที่ลบถาวรแล้วยังเลือกได้ --}}
                        <option value="{{ $agent['id'] }}" @selected($filters['agent'] === (string) $agent['id'])>{{ $agent['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</form>
