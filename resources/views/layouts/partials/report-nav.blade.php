{{--
    กลุ่ม "รายงาน" ในแถบข้าง — รายงานทุกชนิดที่บัญชีนี้มีสิทธิ์อยู่ในกลุ่มเดียว (submenu ชั้นเดียว)
      รายงานปฏิบัติงาน   WorkLogPolicy::viewReport
      รายงานโปรเจกต์      ทุกบัญชี (ReportController จำกัดขอบเขตข้อมูลเอง)
      รายงานแจ้งปัญหา     TicketPolicy::viewReport (admin / Handler) เมื่อติดตั้ง Smart Ticket แล้ว

    กลุ่มเปิดเองและไฮไลต์เฉพาะเมื่อ route ปัจจุบันตรงกับลิงก์ที่ "แสดงอยู่" ในกลุ่มนี้เท่านั้น
    (เดิมเปิดทุก reports.* ทำให้หน้ารายงานที่ไม่มีลิงก์ในกลุ่มเปิดกลุ่มค้างโดยไม่มีรายการไฮไลต์)
--}}
@php
    $reportUser = auth()->user();
    $reportLinks = array_values(array_filter([
        $reportUser->can('viewReport', \App\Models\WorkLog::class)
            ? ['route' => 'reports.operational', 'label' => 'รายงานปฏิบัติงาน'] : null,
        ['route' => 'reports.projects', 'label' => 'รายงานโปรเจกต์'],
        ($ticketsInstalled ?? false) && $reportUser->can('viewReport', \App\Models\Ticket::class)
            ? ['route' => 'reports.tickets', 'label' => 'รายงานแจ้งปัญหา'] : null,
    ]));
    $currentReport = collect($reportLinks)->first(fn (array $link) => request()->routeIs($link['route'], $link['route'].'.*'));
@endphp
<details class="report-nav{{ $currentReport ? ' report-nav--active' : '' }}" {{ $currentReport ? 'open' : '' }}>
    <summary class="nav-item report-nav__toggle">
        <i class="bi bi-clipboard-data" aria-hidden="true"></i>
        <span class="nav-item__label">รายงาน</span>
        <i class="bi bi-chevron-down report-nav__chevron" aria-hidden="true"></i>
    </summary>
    <div class="report-nav__submenu">
        @foreach ($reportLinks as $link)
            @php($isCurrent = $currentReport === $link)
            <a href="{{ route($link['route']) }}"
                class="nav-item report-nav__link {{ $isCurrent ? 'active' : '' }}"
                @if($isCurrent) aria-current="{{ request()->routeIs($link['route']) ? 'page' : 'location' }}" @endif>
                <span class="nav-item__label">{{ $link['label'] }}</span>
            </a>
        @endforeach
    </div>
</details>
