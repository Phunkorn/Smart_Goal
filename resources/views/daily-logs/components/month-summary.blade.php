{{--
    สรุปรายเดือน — แดชบอร์ดสามชั้น: ตัวเลขหลัก 4 ใบ → กิจกรรมรายวัน → สัดส่วนงาน

    ตัวเลขทุกตัวมาจาก WorkLogSummary (สูตรเดียวกับรายงานภาระงาน) และ
    WorkLogMonthActivity ซึ่งจัดกลุ่ม $monthLogs ชุดเดียวกันตาม work_date
    ไฟล์นี้ไม่คิดกติกาเอง ทำแค่จัดวางและคำนวณสัดส่วน

    ภาพกราฟมีแห่งเดียวคือกิจกรรมรายวัน ส่วนอื่นเป็นตัวเลขล้วน ไม่ใช้แถบ progress
    เพื่อให้หน้าไม่รก (ผู้ใช้ขอให้ลดแถบลง)
--}}
@php
    $monthTotal = $monthSummary['total_count'];
    $doneCount = $monthSummary['done_count'];
    $unfinishedCount = $monthTotal - $doneCount;
    $percentLabel = fn (float $value): string => rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $completionRate = $monthTotal > 0 ? round($doneCount / $monthTotal * 100, 1) : 0.0;
    // แสดงสองสถานะที่มากที่สุดบนการ์ด ส่วนที่เหลือรวมเป็น "+N" รายการเต็มอยู่ใน title
    $unfinishedStatuses = collect($monthSummary['by_status'])->except('done')->sortByDesc('count');
    $unfinishedLabels = $unfinishedStatuses->map(fn (array $status): string => $status['label'].' '.$status['count']);
    $monthTitle = \App\Support\ThaiDate::month($calendarMonth);
    $activity = \App\Support\WorkLogMonthActivity::build($monthLogs, $calendarMonth);
    $lastDay = count($activity['days']);
    $totalMinutes = $monthSummary['total_minutes'];
    $topCategories = array_slice($monthSummary['by_category'], 0, 5, true);
    $hiddenCategoryCount = max(0, count($monthSummary['by_category']) - count($topCategories));
    // สัดส่วนของหมวดคือส่วนแบ่งของเวลาทั้งเดือน ถ้าทั้งเดือนไม่ได้ระบุเวลาเลยจึงใช้จำนวนรายการแทน
    $categoryShare = fn (array $category): float => $totalMinutes > 0
        ? $category['minutes'] / $totalMinutes
        : ($monthTotal > 0 ? $category['count'] / $monthTotal : 0);
@endphp
<section class="daily-month" aria-labelledby="dailyMonthTitle">
    <header class="daily-month__intro">
        <h2 class="daily-month__title" id="dailyMonthTitle">ภาพรวมเดือน{{ $monthTitle }}</h2>
        @if($monthTotal > 0)
            <p class="daily-month__lead">บันทึกงาน {{ $activity['active_days'] }} วัน จาก {{ $activity['elapsed_days'] }} วันที่ผ่านมาของเดือน</p>
        @endif
    </header>

    @if($monthTotal === 0)
        <div class="daily-month__empty">
            <span class="daily-month__empty-icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span>
            <h3>ยังไม่มีบันทึกงานในเดือนนี้</h3>
            <p>เมื่อมีการบันทึกงานประจำหรืองานนอกสถานที่ ภาพรวมของเดือนจะแสดงที่นี่</p>
        </div>
    @else
        <ul class="daily-month__kpis" aria-label="ตัวเลขหลักของเดือน">
            <li class="daily-month__kpi" data-tone="blue">
                <span class="daily-month__kpi-label"><i class="bi bi-list-check" aria-hidden="true"></i>งานทั้งหมด</span>
                <span class="daily-month__kpi-value">{{ number_format($monthTotal) }} <small>รายการ</small></span>
                <span class="daily-month__kpi-meta">บันทึกใน {{ $activity['active_days'] }} วัน</span>
            </li>
            <li class="daily-month__kpi" data-tone="teal">
                <span class="daily-month__kpi-label"><i class="bi bi-check2-circle" aria-hidden="true"></i>งานเสร็จสิ้น</span>
                <span class="daily-month__kpi-value">{{ number_format($doneCount) }} <span class="daily-month__kpi-badge">{{ $percentLabel($completionRate) }}</span></span>
                <span class="daily-month__kpi-meta">จาก {{ number_format($monthTotal) }} รายการ</span>
            </li>
            <li class="daily-month__kpi" data-tone="indigo">
                <span class="daily-month__kpi-label"><i class="bi bi-clock-history" aria-hidden="true"></i>ชั่วโมงทำงานรวม</span>
                <span class="daily-month__kpi-value">{{ $monthDurationLabel }}</span>
                <span class="daily-month__kpi-meta">
                    @if($activity['average_label'] !== null)
                        เฉลี่ย {{ $activity['average_label'] }} ต่อวันที่บันทึก
                    @else
                        ยังไม่มีรายการที่ระบุเวลา
                    @endif
                </span>
            </li>
            <li class="daily-month__kpi" data-tone="amber">
                <span class="daily-month__kpi-label"><i class="bi bi-hourglass-split" aria-hidden="true"></i>งานที่ยังไม่เสร็จ</span>
                <span class="daily-month__kpi-value">{{ number_format($unfinishedCount) }} <small>รายการ</small></span>
                @if($unfinishedCount === 0)
                    <span class="daily-month__kpi-meta">ปิดงานครบทุกรายการ</span>
                @else
                    <span class="daily-month__kpi-meta" title="{{ $unfinishedLabels->implode(' · ') }}">
                        {{ $unfinishedLabels->take(2)->implode(' · ') }}@if($unfinishedLabels->count() > 2) · อีก {{ $unfinishedLabels->count() - 2 }} สถานะ@endif
                    </span>
                @endif
            </li>
        </ul>

        <div class="daily-month__grid">
            <section class="daily-month__card daily-month__card--activity" aria-labelledby="dailyMonthActivityTitle">
                <header class="daily-month__card-head">
                    <div>
                        <h3 class="daily-month__card-title" id="dailyMonthActivityTitle">กิจกรรมรายวัน</h3>
                        <p class="daily-month__card-sub">ชั่วโมงที่บันทึกในแต่ละวันของเดือน</p>
                    </div>
                    @if($activity['peak'] !== null)
                        <p class="daily-month__peak">
                            <small>วันที่ทำงานมากที่สุด</small>
                            <strong>{{ $activity['peak']['label'] }}</strong>
                            <span>{{ $activity['peak']['duration_label'] }} · {{ $activity['peak']['count'] }} รายการ</span>
                        </p>
                    @endif
                </header>

                <div class="daily-month__chart" style="--day-count: {{ $lastDay }};">
                    @if($activity['max_minutes'] > 0)
                        <span class="daily-month__chart-max" aria-hidden="true">{{ \App\Support\WorkLogDesign::durationLabel($activity['max_minutes']) }}</span>
                    @endif
                    <ol class="daily-month__days" aria-label="ชั่วโมงทำงานรายวัน">
                        @foreach($activity['days'] as $day)
                            @php
                                $dayState = match (true) {
                                    $day['minutes'] > 0 => 'timed',
                                    $day['count'] > 0 => 'untimed',
                                    default => 'empty',
                                };
                                $edge = $loop->index < 3 ? 'start' : ($loop->remaining < 3 ? 'end' : 'mid');
                                $isTick = in_array($day['day'], [1, 5, 10, 15, 20, 25, $lastDay], true);
                            @endphp
                            <li @class(['daily-month__day', 'is-weekend' => $day['is_weekend'], 'is-today' => $day['is_today'], 'is-future' => $day['is_future'], 'is-tick' => $isTick])
                                data-state="{{ $dayState }}"
                                data-edge="{{ $edge }}"
                                data-date="{{ $day['date'] }}" data-minutes="{{ $day['minutes'] }}"
                                style="--bar-value: {{ $day['ratio'] }}; --day-index: {{ $loop->index }};"
                                @if($day['count'] > 0) tabindex="0" @endif>
                                <span class="daily-month__bar-track" aria-hidden="true"><span class="daily-month__bar-fill"></span></span>
                                <span class="daily-month__day-num" aria-hidden="true">{{ $day['day'] }}</span>
                                <span class="daily-month__tip" role="tooltip">
                                    <strong>{{ $day['weekday'] }} {{ $day['label'] }}</strong>
                                    @if($day['count'] > 0)
                                        {{ $day['count'] }} รายการ · {{ $day['minutes'] > 0 ? $day['duration_label'] : 'ไม่ระบุเวลา' }}
                                    @else
                                        ไม่มีบันทึก
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <ul class="daily-month__legend" aria-hidden="true">
                    <li><span class="daily-month__legend-bar"></span>ชั่วโมงที่บันทึก</li>
                    <li><span class="daily-month__legend-dot"></span>มีบันทึกแต่ไม่ระบุเวลา</li>
                    <li><span class="daily-month__legend-today"></span>วันนี้</li>
                </ul>
            </section>

            <section class="daily-month__card daily-month__card--breakdown" aria-labelledby="dailyMonthBreakdownTitle">
                <header class="daily-month__card-head">
                    <div>
                        <h3 class="daily-month__card-title" id="dailyMonthBreakdownTitle">สัดส่วนงาน</h3>
                        <p class="daily-month__card-sub">แยกตามประเภทและหมวดงาน</p>
                    </div>
                </header>

                <div class="daily-month__group">
                    <h4 class="daily-month__group-title">ประเภทงาน</h4>
                    <ul class="daily-month__kinds">
                        @foreach($monthSummary['by_kind'] as $kindKey => $kind)
                            <li data-tone="{{ $kind['tone'] }}">
                                <span class="daily-month__kind-dot" aria-hidden="true"></span>
                                <span class="daily-month__kind-label">{{ $kind['label'] }}</span>
                                <strong>{{ $kind['count'] }} รายการ</strong>
                                <small>{{ $monthKindDurationLabels[$kindKey] }}</small>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="daily-month__group">
                    <h4 class="daily-month__group-title">หมวดงานที่ใช้เวลามากที่สุด</h4>
                    <ol class="daily-month__categories">
                        @foreach($topCategories as $categoryIndex => $category)
                            @php $share = $categoryShare($category); @endphp
                            <li style="--row-index: {{ $loop->index }};">
                                <span class="daily-month__category-rank" aria-hidden="true">{{ $loop->iteration }}</span>
                                <span class="daily-month__category-main">
                                    <span class="daily-month__category-name" title="{{ $category['name'] }}">{{ $category['name'] }}</span>
                                    <small>{{ $category['count'] }} รายการ</small>
                                </span>
                                <span class="daily-month__category-value">
                                    <strong>{{ $monthCategoryDurationLabels[$categoryIndex] }}</strong>
                                    <small>{{ $percentLabel(round($share * 100, 1)) }}</small>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                    @if($hiddenCategoryCount > 0)
                        <p class="daily-month__more">และอีก {{ $hiddenCategoryCount }} หมวด</p>
                    @endif
                </div>
            </section>
        </div>
    @endif
</section>
