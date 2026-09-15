@php
    $gridStart = $calendarMonth->copy()->startOfMonth()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
    $gridEnd = $calendarMonth->copy()->endOfMonth()->endOfWeek(\Carbon\CarbonInterface::SUNDAY);
    $selectedItems = $calendarEntries[$calendarSelectedDate] ?? [];
    $calendarQuery = ['view' => 'calendar', 'scope' => $calendarScope, 'kind' => $calendarKind ?: null, 'category' => $calendarCategory ?: null];
@endphp
<section class="daily-plan @if($calendarView === 'calendar') daily-plan--expanded @endif" aria-labelledby="dailyPlanTitle" data-plan-calendar>
    <div class="daily-plan__scope">
        <div>
            <h2 class="daily-plan__title" id="dailyPlanTitle">ปฏิทินงาน</h2>
            <p class="daily-plan__subtitle">
                @if($calendarScope === 'team')
                    ดูว่าใครรับผิดชอบงานประจำและงานนอกสถานที่ในแต่ละวัน
                @else
                    แผนงานและบันทึกงานของคุณ
                @endif
            </p>
        </div>
        <div class="daily-plan__scope-actions">
            @if($capabilities['canViewTeamCalendar'])
                <div class="daily-plan__scope-tabs" role="group" aria-label="ขอบเขตปฏิทินงาน">
                    <a class="@if($calendarScope === 'mine') is-active @endif" href="{{ route('daily-logs.index', [...$calendarQuery, 'scope' => 'mine', 'month' => $calendarMonthValue]) }}">ของฉัน</a>
                    <a class="@if($calendarScope === 'team') is-active @endif" href="{{ route('daily-logs.index', [...$calendarQuery, 'scope' => 'team', 'month' => $calendarMonthValue]) }}">แผนก</a>
                </div>
            @endif
            @if($calendarView === 'calendar')
                <details class="daily-plan__filter-menu">
                    <summary>
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        ตัวกรอง
                        <i class="bi bi-chevron-down daily-plan__filter-chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="daily-plan__filter-popover">
                        <form method="GET" action="{{ route('daily-logs.index') }}" class="daily-plan__filters">
                            <input type="hidden" name="view" value="calendar">
                            <input type="hidden" name="scope" value="{{ $calendarScope }}">
                            <input type="hidden" name="month" value="{{ $calendarMonthValue }}">
                            <label data-sg-select>ประเภท
                                <select class="form-select" name="kind" data-plan-filter>
                                    <option value="">ทั้งหมด</option>
                                    <option value="routine" @selected($calendarKind === 'routine')>งานประจำ</option>
                                    <option value="field" @selected($calendarKind === 'field')>งานนอกสถานที่</option>
                                </select>
                            </label>
                            <label data-sg-select>หมวดงาน
                                <select class="form-select" name="category" data-plan-filter>
                                    <option value="">ทั้งหมด</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" @selected($calendarCategory === (int) $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <noscript><button type="submit" class="btn btn-outline-primary">กรอง</button></noscript>
                        </form>
                    </div>
                </details>
            @endif
        </div>
    </div>
    <div class="daily-plan__toolbar">
        <strong>{{ $calendarMonth->locale('th')->translatedFormat('F') }} {{ $calendarMonth->year + 543 }}</strong>
        <div class="daily-plan__month-nav">
            <a href="{{ route('daily-logs.index', [...$calendarQuery, 'month' => $calendarMonth->copy()->subMonth()->format('Y-m')]) }}" aria-label="เดือนก่อนหน้า"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
            <a href="{{ route('daily-logs.index', [...$calendarQuery, 'month' => $calendarMonth->copy()->addMonth()->format('Y-m')]) }}" aria-label="เดือนถัดไป"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </div>
    </div>
    <div class="daily-plan__calendar-scroll">
    <div class="daily-plan__weekdays" aria-hidden="true">
        @foreach(\App\Support\WorkLogWeekdays::WEEKDAYS as $weekday)
            <span>{{ $weekday['short'] }}</span>
        @endforeach
    </div>
    <div class="daily-plan__grid" role="group" aria-label="เลือกวันเพื่อดูแผนงาน">
        @for($day = $gridStart->copy(); $day->lessThanOrEqualTo($gridEnd); $day->addDay())
            @php
                $date = $day->format('Y-m-d');
                $items = $calendarEntries[$date] ?? [];
                $inMonth = $day->format('Y-m') === $calendarMonthValue;
                $dayPeople = collect($items)->unique('owner_id')->values();
            @endphp
            <button type="button" class="daily-plan__day @unless($inMonth) daily-plan__day--outside @endunless"
                data-plan-day="{{ $date }}" @if($date === $calendarSelectedDate) aria-pressed="true" @else aria-pressed="false" @endif
                aria-label="{{ $day->locale('th')->translatedFormat('j F') }} {{ $day->year + 543 }} · {{ count($items) }} รายการ"
                @unless($inMonth) disabled @endunless>
                <span>{{ $day->day }}</span>
                @if($dayPeople->isNotEmpty())
                    <span class="daily-plan__day-avatars" aria-hidden="true">
                        @foreach($dayPeople->take(3) as $person)
                            <span class="daily-plan__avatar">
                                @if($person['avatar_url'])
                                    <img src="{{ $person['avatar_url'] }}" alt="" loading="lazy">
                                @else
                                    {{ $person['owner_initial'] }}
                                @endif
                            </span>
                        @endforeach
                        @if($dayPeople->count() > 3)
                            <span class="daily-plan__avatar daily-plan__avatar--more">+{{ $dayPeople->count() - 3 }}</span>
                        @endif
                    </span>
                    <small class="daily-plan__day-count" aria-hidden="true">{{ count($items) }} งาน</small>
                @endif
            </button>
        @endfor
    </div>
    </div>
    <div class="daily-plan__day-list">
        <h3 data-plan-selected-title>งานในวันที่ {{ $calendarMonth->copy()->day((int) substr($calendarSelectedDate, 8, 2))->locale('th')->translatedFormat('j M') }} {{ $calendarMonth->year + 543 }} <small>({{ count($selectedItems) }} รายการ)</small></h3>
        <div data-plan-selected-items>
            @forelse($selectedItems as $item)
                <div class="daily-plan__item">
                    <span class="daily-plan__item-time">{{ $item['time'] ?? '—' }}</span>
                    <span class="daily-plan__item-body">
                        <strong>
                            <i class="bi {{ $item['kind'] === 'field' ? 'bi-geo-alt' : 'bi-arrow-repeat' }}" aria-hidden="true"></i>
                            {{ $item['title'] }}
                        </strong>
                        <small>
                            {{ $item['kind'] === 'field' ? 'งานนอกสถานที่' : 'งานประจำ' }}
                            @if($item['category']) · {{ $item['category'] }}@endif
                            @if($item['project']) · {{ $item['project'] }}@endif
                        </small>
                        {{-- สถานะที่ทุกคนในแผนกเห็น: ยังไม่เริ่ม / กำลังทำ / เสร็จแล้ว / พบปัญหา / ไม่ได้ทำ --}}
                        <span class="daily-plan__item-status daily-plan__item-status--{{ $item['status_tone'] }}">{{ $item['status_label'] }}</span>
                    </span>
                    <span class="daily-plan__item-owner" title="{{ $item['owner'] }}">
                        <span class="daily-plan__avatar">
                            @if($item['avatar_url'])
                                <img src="{{ $item['avatar_url'] }}" alt="" loading="lazy">
                            @else
                                {{ $item['owner_initial'] }}
                            @endif
                        </span>
                        <span>{{ $item['owner'] }}</span>
                    </span>
                </div>
            @empty
                <p class="daily-plan__empty">ไม่มีงานที่ลงไว้ในวันนี้</p>
            @endforelse
        </div>
    </div>
    <script type="application/json" data-plan-entries>@json($calendarEntries)</script>

    {{--
        กดวันในปฏิทินแล้วเปิดกล่องนี้ — ดูว่าวันนั้นใครทำงานอะไร และสถานะถึงไหนแล้ว
        เนื้อในวาดด้วยตัววาดรายการชุดเดียวกับ "งานในวันที่ …" ด้านบน (plan-calendar.js)
        เปิด/ปิด โฟกัส Escape และ backdrop เป็นของ modal-stack เหมือนกล่องเพิ่มงาน
    --}}
    <div class="log-modal daily-plan-modal" role="dialog" aria-modal="true" aria-labelledby="dailyPlanDayModalTitle"
        data-plan-day-modal hidden>
        <div class="log-modal__panel">
            <header class="log-modal__header">
                <div>
                    <h2 class="log-modal__title" id="dailyPlanDayModalTitle" data-plan-day-modal-title>งานในวันนี้</h2>
                    <p class="daily-plan-modal__summary" data-plan-day-modal-summary></p>
                </div>
                <button type="button" class="log-modal__close" aria-label="ปิด" data-plan-day-modal-close>
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>
            <div class="log-modal__body daily-plan-modal__body" data-plan-day-modal-items></div>
        </div>
    </div>
</section>
